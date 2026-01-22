<?php
/**
 * Ticket Notification Helper
 *
 * Manages email notifications for ticket events in CollaboraNexio
 * - Ticket created (to super_admins)
 * - Ticket assigned (to assigned user)
 * - Ticket response (to ticket creator)
 * - Status changed (to ticket creator)
 * - Ticket resolved (to ticket creator)
 * - Ticket closed (to ticket creator)
 *
 * Pattern: Non-blocking (< 5ms overhead per notification)
 * All attempts logged in ticket_notifications table
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_layout.php';
require_once __DIR__ . '/email_template_renderer.php';

class TicketNotification {

    private $db;
    private $baseUrl;
    private $templateDir;
    private array $tenantNameCache = [];
    private string $supportSuperUserEmail = 'asamodeo@fortibyte.it';

    /**
     * Resolve user by email (best-effort; may return null if not found)
     */
    private function getUserInfoByEmail(string $email): ?array {
        $email = trim($email);
        if ($email === '') return null;
        $row = $this->db->fetchOne(
            "SELECT id, name, email, tenant_id
             FROM users
             WHERE email = ? AND deleted_at IS NULL
             LIMIT 1",
            [$email]
        );
        return $row ? $row : null;
    }

    /**
     * Get distinct assignee user IDs for a ticket (current + assignment history)
     * @return int[]
     */
    private function getTicketAssigneeUserIds(int $ticketId, array $ticketRow): array {
        $ids = [];
        if (!empty($ticketRow['assigned_to'])) $ids[] = (int)$ticketRow['assigned_to'];

        try {
            $has = $this->db->fetchOne("SHOW TABLES LIKE 'ticket_assignments'");
            if ($has) {
                $rows = $this->db->fetchAll(
                    "SELECT DISTINCT assigned_to
                     FROM ticket_assignments
                     WHERE ticket_id = ?
                       AND deleted_at IS NULL
                       AND assigned_to IS NOT NULL",
                    [$ticketId]
                ) ?: [];
                foreach ($rows as $r) {
                    $v = (int)($r['assigned_to'] ?? 0);
                    if ($v > 0) $ids[] = $v;
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        $ids = array_values(array_unique(array_filter($ids, static fn($v) => (int)$v > 0)));
        return $ids;
    }

    /**
     * Build recipient list for status/response notifications (STRICT policy):
     * - ticket creator (opener)
     * - action actor (who changed status OR wrote the response)
     * - support super user email (always, unless already included above)
     *
     * IMPORTANT:
     * - We do NOT notify assignees (current + history) anymore.
     * - Support address MUST always be included unless it is already one of the two above.
     *
     * @return array<int,array{id:int|null,name:string,email:string}>
     */
    private function buildRecipientsForTicket(int $ticketId, array $ticketRow, ?int $actorUserId = null): array {
        $recipients = [];
        $seen = [];

        $add = function (?array $u) use (&$recipients, &$seen) {
            if (!$u) return;
            $email = strtolower(trim((string)($u['email'] ?? '')));
            if ($email === '') return;
            if (isset($seen[$email])) return;
            $seen[$email] = true;
            $recipients[] = [
                'id' => isset($u['id']) ? (int)$u['id'] : null,
                'name' => (string)($u['name'] ?? $email),
                'email' => (string)($u['email'] ?? $email),
            ];
        };

        // Ticket opener (creator)
        $creatorId = (int)($ticketRow['created_by'] ?? 0);
        if ($creatorId > 0) {
            $add($this->getUserInfo($creatorId));
        }

        // Actor (best-effort)
        $actorUserId = (int)($actorUserId ?? 0);
        if ($actorUserId > 0 && $actorUserId !== $creatorId) {
            $add($this->getUserInfo($actorUserId));
        }

        // Support super user email: ALWAYS include unless already present via opener/actor.
        // Force id=null so user preferences cannot suppress this recipient.
        $add(['id' => null, 'name' => 'Super User', 'email' => $this->supportSuperUserEmail]);

        return $recipients;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $this->templateDir = __DIR__ . '/email_templates/tickets/';
    }

    private function getTenantName(?int $tenantId): string {
        if (!$tenantId) return '';
        if (isset($this->tenantNameCache[$tenantId])) return $this->tenantNameCache[$tenantId];
        try {
            $row = $this->db->fetchOne('SELECT name FROM tenants WHERE id = ? LIMIT 1', [$tenantId]);
            $name = is_array($row) ? (string)($row['name'] ?? '') : '';
            $this->tenantNameCache[$tenantId] = $name;
            return $name;
        } catch (Exception $e) {
            $this->tenantNameCache[$tenantId] = '';
            return '';
        }
    }

    /**
     * Send notification when ticket is created
     * Notifies all super_admin users
     *
     * @param int $ticketId Ticket ID
     * @return bool Success status
     */
    public function sendTicketCreatedNotification($ticketId) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                error_log("TicketNotification: Ticket $ticketId not found");
                return false;
            }

            // Get ALL super_admin users (regardless of tenant)
            // BUG-148b FIX: super_admins have their own tenant_id but should receive ALL ticket notifications
            $superAdmins = $this->db->fetchAll(
                "SELECT id, name, email
                 FROM users
                 WHERE role = 'super_admin'
                   AND is_active = 1
                   AND deleted_at IS NULL"
            );

            if (empty($superAdmins)) {
                error_log("TicketNotification: No super_admins found in system");
                return false;
            }

            $successCount = 0;

            foreach ($superAdmins as $admin) {
                // Check user preferences
                if (!$this->shouldNotify($admin['id'], 'notify_ticket_created')) {
                    continue;
                }

                // Prepare template data
                $tenantName = $this->getTenantName((int)($ticket['tenant_id'] ?? 0));
                $templateData = [
                    'EMAIL_TITLE' => 'Nuovo ticket',
                    'USER_NAME' => $admin['name'],
                    'TICKET_NUMBER' => $ticket['ticket_number'],
                    'TICKET_SUBJECT' => $ticket['subject'],
                    'TICKET_DESCRIPTION' => $this->truncateText($ticket['description'], 300),
                    'TICKET_CATEGORY_LABEL' => $this->getCategoryLabel($ticket['category']),
                    'TICKET_URGENCY' => $ticket['urgency'],
                    'TICKET_URGENCY_LABEL' => $this->getUrgencyLabel($ticket['urgency']),
                    'TICKET_URGENCY_COLOR' => $this->getUrgencyColor($ticket['urgency']),
                    'TICKET_STATUS_LABEL' => $this->getStatusLabel($ticket['status'] ?? 'open'),
                    'CREATED_BY_NAME' => $ticket['created_by_name'] ?? 'Utente',
                    'CREATED_BY_EMAIL' => $ticket['created_by_email'] ?? '',
                    'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                    'TICKET_LIST_URL' => $this->baseUrl . '/ticket.php',
                    'BASE_URL' => $this->baseUrl,
                    'TENANT_NAME' => $tenantName ?: null,
                    'YEAR' => date('Y')
                ];

                // Render email
                $html = $this->renderTemplate('ticket_created.html', $templateData);
                $subject = "[Nuovo Ticket] {$ticket['ticket_number']}: {$ticket['subject']}";

                // Send email (non-blocking)
                $sent = sendEmail(
                    $admin['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $ticket['tenant_id'],
                            'user_id' => $admin['id'],
                            'action' => 'ticket_created_notification'
                        ]
                    ]
                );

                // Log notification
                $this->logNotification(
                    $ticket['tenant_id'],
                    $ticketId,
                    $admin['id'],
                    'ticket_created',
                    $admin['email'],
                    $subject,
                    $sent ? 'sent' : 'failed',
                    $ticket['created_by']
                );

                if ($sent) {
                    $successCount++;
                }
            }

            return $successCount > 0;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketCreatedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send confirmation email to ticket creator
     *
     * @param int $ticketId Ticket ID
     * @return bool Success status
     */
    public function sendTicketCreatedConfirmation($ticketId) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                return false;
            }

            // Get creator info
            $creatorInfo = $this->getUserInfo($ticket['created_by']);
            if (!$creatorInfo) {
                error_log("TicketNotification: Creator user {$ticket['created_by']} not found");
                return false;
            }

            // Prepare template variables
            $templateData = [
                'EMAIL_TITLE' => 'Conferma ticket',
                'CREATED_BY_NAME' => $creatorInfo['name'],
                'CREATED_BY_EMAIL' => $creatorInfo['email'],
                'TICKET_NUMBER' => $ticket['ticket_number'],
                'TICKET_SUBJECT' => $ticket['subject'],
                'TICKET_DESCRIPTION' => $this->truncateText($ticket['description'], 300),
                'TICKET_CATEGORY' => $this->getCategoryLabel($ticket['category']),
                'TICKET_URGENCY' => $ticket['urgency'],
                'TICKET_URGENCY_LABEL' => $this->getUrgencyLabel($ticket['urgency']),
                'TICKET_STATUS' => $ticket['status'],
                'TICKET_STATUS_LABEL' => $this->getStatusLabel($ticket['status']),
                'URGENCY_HIGH' => in_array($ticket['urgency'], ['high', 'critical']),
                'RESPONSE_TIME' => in_array($ticket['urgency'], ['high', 'critical']) ? 'Entro 4 ore' : 'Entro 24 ore',
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'TICKET_LIST_URL' => $this->baseUrl . '/ticket.php',
                'BASE_URL' => $this->baseUrl,
                'TENANT_NAME' => ($this->getTenantName((int)($ticket['tenant_id'] ?? 0)) ?: null),
                'YEAR' => date('Y')
            ];

            // Render template
            $html = $this->renderTemplate('ticket_created_confirmation.html', $templateData);

            // Email subject
            $subject = "Conferma Ticket {$ticket['ticket_number']} - {$ticket['subject']}";

            // Send email (non-blocking)
            $sent = sendEmail(
                $creatorInfo['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $ticket['tenant_id'],
                        'user_id' => $creatorInfo['id'],
                        'action' => 'ticket_created_confirmation'
                    ]
                ]
            );

            // Log notification attempt
            // BUG-148b FIX: logNotification expects individual params, not an array
            $this->logNotification(
                $ticket['tenant_id'],
                $ticketId,
                $creatorInfo['id'],
                'ticket_created_confirmation',
                $creatorInfo['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $ticket['created_by']
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketCreatedConfirmation): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when ticket is assigned to a user
     *
     * @param int $ticketId Ticket ID
     * @param int $assignedToUserId User ID who was assigned
     * @return bool Success status
     */
    public function sendTicketAssignedNotification($ticketId, $assignedToUserId) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                return false;
            }

            // Get assigned user info
            $assignedUser = $this->getUserInfo($assignedToUserId);
            if (!$assignedUser) {
                error_log("TicketNotification: Assigned user $assignedToUserId not found");
                return false;
            }

            // Check user preferences
            if (!$this->shouldNotify($assignedUser['id'], 'notify_ticket_assigned')) {
                return true;
            }

            // Get assigner info (current session user or from ticket history)
            $assignerId = $_SESSION['user_id'] ?? $ticket['created_by'];
            $assigner = $this->getUserInfo($assignerId);

            // Prepare template data
            $templateData = [
                'EMAIL_TITLE' => 'Ticket assegnato',
                'USER_NAME' => $assignedUser['name'],
                'TICKET_NUMBER' => $ticket['ticket_number'],
                'TICKET_SUBJECT' => $ticket['subject'],
                'TICKET_DESCRIPTION' => $this->truncateText($ticket['description'], 300),
                'TICKET_CATEGORY_LABEL' => $this->getCategoryLabel($ticket['category']),
                'TICKET_URGENCY_LABEL' => $this->getUrgencyLabel($ticket['urgency']),
                'TICKET_URGENCY_COLOR' => $this->getUrgencyColor($ticket['urgency']),
                'ASSIGNED_BY_NAME' => $assigner['name'] ?? 'Sistema',
                'CREATED_BY_NAME' => $ticket['created_by_name'] ?? 'Utente',
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'BASE_URL' => $this->baseUrl,
                'TENANT_NAME' => ($this->getTenantName((int)($ticket['tenant_id'] ?? 0)) ?: null),
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('ticket_assigned.html', $templateData);
            $subject = "[Ticket Assegnato] {$ticket['ticket_number']}: {$ticket['subject']}";

            // Send email (non-blocking)
            $sent = sendEmail(
                $assignedUser['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $ticket['tenant_id'],
                        'user_id' => $assignedUser['id'],
                        'action' => 'ticket_assigned_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $ticket['tenant_id'],
                $ticketId,
                $assignedUser['id'],
                'ticket_assigned',
                $assignedUser['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $assignerId
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketAssignedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when a new response is added to ticket
     *
     * @param int $ticketId Ticket ID
     * @param int $responseId Response ID
     * @return bool Success status
     */
    public function sendTicketResponseNotification($ticketId, $responseId) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                return false;
            }

            // Get response details
            $response = $this->db->fetchOne(
                "SELECT tr.*, u.name as user_name
                 FROM ticket_responses tr
                 JOIN users u ON tr.user_id = u.id
                 WHERE tr.id = ?",
                [$responseId]
            );

            if (!$response) {
                return false;
            }

            // Don't send notification for internal notes
            if ($response['is_internal_note']) {
                return true;
            }

            $actorId = (int)($response['user_id'] ?? 0);
            $recipients = $this->buildRecipientsForTicket((int)$ticketId, $ticket, $actorId);
            $successCount = 0;

            foreach ($recipients as $rcpt) {
                // Respect preferences only when we have a user_id
                if (!empty($rcpt['id']) && !$this->shouldNotify((int)$rcpt['id'], 'notify_ticket_response')) {
                    continue;
                }

                $templateData = [
                    'EMAIL_TITLE' => 'Nuova risposta',
                    'USER_NAME' => $rcpt['name'],
                    'TICKET_NUMBER' => $ticket['ticket_number'],
                    'TICKET_SUBJECT' => $ticket['subject'],
                    'RESPONSE_TEXT' => $this->truncateText($response['response_text'], 500),
                    'RESPONSE_TEXT_FULL' => strlen($response['response_text']) > 500,
                    'RESPONDER_NAME' => $response['user_name'],
                    'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                    'BASE_URL' => $this->baseUrl,
                    'TENANT_NAME' => ($this->getTenantName((int)($ticket['tenant_id'] ?? 0)) ?: null),
                    'YEAR' => date('Y')
                ];

                $html = $this->renderTemplate('ticket_response.html', $templateData);
                $subject = "[Nuova Risposta] {$ticket['ticket_number']}: {$ticket['subject']}";

                $sent = sendEmail(
                    $rcpt['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $ticket['tenant_id'],
                            'user_id' => $rcpt['id'] ?? null,
                            'action' => 'ticket_response_notification'
                        ]
                    ]
                );

                if (!empty($rcpt['id'])) {
                    $this->logNotification(
                        $ticket['tenant_id'],
                        $ticketId,
                        (int)$rcpt['id'],
                        'ticket_response',
                        $rcpt['email'],
                        $subject,
                        $sent ? 'sent' : 'failed',
                        (int)$response['user_id'],
                        $html
                    );
                }

                if ($sent) $successCount++;
            }

            return $successCount > 0;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketResponseNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send ONE consolidated notification when a reply is added together with other updates
     * (status change and/or assignment change).
     *
     * Recipients:
     * - ticket creator (always)
     * - support super user email (always)
     * - assignees (current + history)
     *
     * @return bool
     */
    public function sendTicketCombinedUpdateNotification(
        int $ticketId,
        int $responseId,
        ?string $oldStatus,
        ?string $newStatus,
        ?int $oldAssignedTo,
        ?int $newAssignedTo
    ): bool {
        try {
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) return false;

            $response = $this->db->fetchOne(
                "SELECT tr.*, u.name as user_name
                 FROM ticket_responses tr
                 JOIN users u ON tr.user_id = u.id
                 WHERE tr.id = ?",
                [$responseId]
            );
            if (!$response) return false;

            // Internal notes must not email the response content (caller should have handled this)
            if (!empty($response['is_internal_note'])) {
                return true;
            }

            $actorId = (int)($response['user_id'] ?? 0);
            $recipients = $this->buildRecipientsForTicket($ticketId, $ticket, $actorId);
            $successCount = 0;

            $ticketUrl = $this->baseUrl . '/ticket.php?id=' . $ticketId;
            $tenantName = ($this->getTenantName((int)($ticket['tenant_id'] ?? 0)) ?: null);

            $statusBlock = '';
            if ($oldStatus !== null && $newStatus !== null) {
                $statusBlock = '
                    <div style="margin: 10px 0; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb;">
                        <div style="font-weight:700; margin-bottom:6px;">Stato aggiornato</div>
                        <div><strong>' . htmlspecialchars($this->getStatusLabel($oldStatus)) . '</strong> → <strong>' . htmlspecialchars($this->getStatusLabel($newStatus)) . '</strong></div>
                    </div>
                ';
            }

            $assignBlock = '';
            if ($oldAssignedTo !== null || $newAssignedTo !== null) {
                $oldName = $oldAssignedTo ? (($this->getUserInfo($oldAssignedTo)['name'] ?? null) ?: ('#' . $oldAssignedTo)) : 'Non assegnato';
                $newName = $newAssignedTo ? (($this->getUserInfo($newAssignedTo)['name'] ?? null) ?: ('#' . $newAssignedTo)) : 'Non assegnato';
                $assignBlock = '
                    <div style="margin: 10px 0; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb;">
                        <div style="font-weight:700; margin-bottom:6px;">Assegnazione</div>
                        <div><strong>' . htmlspecialchars($oldName) . '</strong> → <strong>' . htmlspecialchars($newName) . '</strong></div>
                    </div>
                ';
            }

            $replySnippet = $this->truncateText((string)($response['response_text'] ?? ''), 700);
            $replyBlock = '
                <div style="margin: 10px 0; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px;">
                    <div style="font-weight:700; margin-bottom:6px;">Nuova risposta</div>
                    <div style="color:#6b7280; font-size: 13px; margin-bottom: 8px;">Da: ' . htmlspecialchars((string)($response['user_name'] ?? '')) . '</div>
                    <div style="white-space: pre-wrap; line-height: 1.5;">' . nl2br(htmlspecialchars($replySnippet)) . '</div>
                </div>
            ';

            foreach ($recipients as $rcpt) {
                if (!empty($rcpt['id']) && !$this->shouldNotify((int)$rcpt['id'], 'notify_ticket_response')) {
                    continue;
                }

                $bodyHtml = '
                    <div style="font-size:14px; color:#111827;">
                        <div style="font-weight:800; font-size: 18px; margin-bottom: 6px;">Aggiornamento ticket</div>
                        <div style="color:#6b7280; margin-bottom: 12px;">' . htmlspecialchars((string)($ticket['ticket_number'] ?? '')) . ' — ' . htmlspecialchars((string)($ticket['subject'] ?? '')) . ($tenantName ? (' — ' . htmlspecialchars($tenantName)) : '') . '</div>
                        ' . $statusBlock . $assignBlock . $replyBlock . '
                        <div style="margin-top: 14px;">
                            ' . renderEmailPrimaryButton($ticketUrl, 'Apri ticket') . '
                        </div>
                    </div>
                ';

                // Title handled in body; avoid duplicate layout heading.
                $html = renderEmailLayout('', $bodyHtml);
                $subject = "[Aggiornamento Ticket] {$ticket['ticket_number']}: {$ticket['subject']}";

                $sent = sendEmail(
                    $rcpt['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $ticket['tenant_id'],
                            'user_id' => $rcpt['id'] ?? null,
                            'action' => 'ticket_combined_update_notification'
                        ]
                    ]
                );

                if (!empty($rcpt['id'])) {
                    $this->logNotification(
                        $ticket['tenant_id'],
                        $ticketId,
                        (int)$rcpt['id'],
                        'combined_update',
                        $rcpt['email'],
                        $subject,
                        $sent ? 'sent' : 'failed',
                        (int)($response['user_id'] ?? 0),
                        $html
                    );
                }

                if ($sent) $successCount++;
            }

            return $successCount > 0;
        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketCombinedUpdateNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when ticket status changes
     *
     * @param int $ticketId Ticket ID
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     * @return bool Success status
     */
    public function sendStatusChangedNotification($ticketId, $oldStatus, $newStatus) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                return false;
            }

            // Get ticket creator info
            $creator = $this->getUserInfo($ticket['created_by']);
            if (!$creator) {
                return false;
            }

            // Check user preferences
            if (!$this->shouldNotify($creator['id'], 'notify_ticket_status')) {
                return true;
            }

            // Get changer info
            $changerId = $_SESSION['user_id'] ?? null;
            $changer = $changerId ? $this->getUserInfo($changerId) : null;

            // Prepare template data
            $templateData = [
                'EMAIL_TITLE' => 'Stato ticket aggiornato',
                'USER_NAME' => $creator['name'],
                'TICKET_NUMBER' => $ticket['ticket_number'],
                'TICKET_SUBJECT' => $ticket['subject'],
                'OLD_STATUS' => $this->getStatusLabel($oldStatus),
                'NEW_STATUS' => $this->getStatusLabel($newStatus),
                'NEW_STATUS_COLOR' => $this->getStatusColor($newStatus),
                'CHANGED_BY_NAME' => $changer['name'] ?? 'Sistema',
                'RESOLUTION_NOTES' => $ticket['resolution_notes'] ?? null,
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'BASE_URL' => $this->baseUrl,
                'TENANT_NAME' => ($this->getTenantName((int)($ticket['tenant_id'] ?? 0)) ?: null),
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('status_changed.html', $templateData);
            $subject = "[Stato Aggiornato] {$ticket['ticket_number']}: " . $this->getStatusLabel($newStatus);

            // Send email (non-blocking)
            $sent = sendEmail(
                $creator['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $ticket['tenant_id'],
                        'user_id' => $creator['id'],
                        'action' => 'ticket_status_changed_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $ticket['tenant_id'],
                $ticketId,
                $creator['id'],
                'ticket_status_changed',
                $creator['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $changerId
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendStatusChangedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when ticket is closed
     *
     * @param int $ticketId Ticket ID
     * @return bool Success status
     */
    public function sendTicketClosedNotification($ticketId) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                return false;
            }

            // Get ticket creator info
            $creator = $this->getUserInfo($ticket['created_by']);
            if (!$creator) {
                return false;
            }

            // Check user preferences
            if (!$this->shouldNotify($creator['id'], 'notify_ticket_closed')) {
                return true;
            }

            // Get closer info
            $closerId = $_SESSION['user_id'] ?? $ticket['closed_by'];
            $closer = $this->getUserInfo($closerId);

            // Prepare template data
            $templateData = [
                'EMAIL_TITLE' => 'Ticket chiuso',
                'USER_NAME' => $creator['name'],
                'TICKET_NUMBER' => $ticket['ticket_number'],
                'TICKET_SUBJECT' => $ticket['subject'],
                'CLOSED_BY_NAME' => $closer['name'] ?? 'Sistema',
                'RESOLUTION_NOTES' => $ticket['resolution_notes'] ?? null,
                'RESOLUTION_TIME' => $ticket['resolution_time'] ? round($ticket['resolution_time'], 1) . ' ore' : null,
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'BASE_URL' => $this->baseUrl,
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('status_changed.html', $templateData);
            $subject = "[Ticket Chiuso] {$ticket['ticket_number']}: {$ticket['subject']}";

            // Send email (non-blocking)
            $sent = sendEmail(
                $creator['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $ticket['tenant_id'],
                        'user_id' => $creator['id'],
                        'action' => 'ticket_closed_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $ticket['tenant_id'],
                $ticketId,
                $creator['id'],
                'ticket_closed',
                $creator['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $closerId
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketClosedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get ticket details with related user information
     *
     * @param int $ticketId Ticket ID
     * @return array|null Ticket data or null if not found
     */
    private function getTicketDetails($ticketId) {
        return $this->db->fetchOne(
            "SELECT
                t.*,
                u_creator.name as created_by_name,
                u_creator.email as created_by_email,
                u_assigned.name as assigned_to_name,
                u_closed.name as closed_by_name
            FROM tickets t
            LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
            LEFT JOIN users u_closed ON t.closed_by = u_closed.id AND u_closed.deleted_at IS NULL
            WHERE t.id = ? AND t.deleted_at IS NULL",
            [$ticketId]
        );
    }

    /**
     * Get user information
     *
     * @param int $userId User ID
     * @return array|null User data or null if not found
     */
    private function getUserInfo($userId) {
        return $this->db->fetchOne(
            "SELECT id, name, email, tenant_id
             FROM users
             WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );
    }

    /**
     * Check if user should receive this type of notification
     *
     * @param int $userId User ID
     * @param string $notificationType Notification type preference key
     * @return bool True if user should be notified
     */
    private function shouldNotify($userId, $notificationType) {
        // Check if user_notification_preferences table exists
        $prefResult = $this->db->fetchOne(
            "SELECT $notificationType
             FROM user_notification_preferences
             WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );

        // If no preference set, default to TRUE (opt-in by default)
        if (!$prefResult) {
            return true;
        }

        return (bool)$prefResult[$notificationType];
    }

    /**
     * Render email template with data
     *
     * @param string $templateName Template filename
     * @param array $data Template data
     * @return string Rendered HTML
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = $this->templateDir . $templateName;

        if (!file_exists($templatePath)) {
            error_log("TicketNotification: Template not found: $templatePath");
            return '';
        }

        $html = cnx_render_email_template_file($templatePath, (array)$data, [
            'remove_unknown_placeholders' => true
        ]);

        // If the template is content-only, wrap it with the shared Nexio email layout.
        if ($html !== '' && !cnx_email_is_full_document($html)) {
            $title = (string)($data['EMAIL_TITLE'] ?? 'Notifica');
            $layoutVars = [
                'BASE_URL' => $this->baseUrl,
                'TENANT_NAME' => (string)($data['TENANT_NAME'] ?? ''),
                'YEAR' => (string)($data['YEAR'] ?? date('Y'))
            ];
            $html = renderEmailLayout($title, $html, $layoutVars, ['brandColor' => '#1a2332']);
        }

        return $html;
    }

    /**
     * Log notification attempt to database
     *
     * @param int $tenantId Tenant ID
     * @param int $ticketId Ticket ID
     * @param int $userId Recipient user ID
     * @param string $notificationType Notification type
     * @param string $recipientEmail Recipient email
     * @param string $subject Email subject
     * @param string $status Status (sent/failed)
     * @param int|null $triggeredBy User who triggered the notification
     */
    private function logNotification($tenantId, $ticketId, $userId, $notificationType, $recipientEmail, $subject, $status, $triggeredBy = null, $bodyHtml = '') {
        try {
            // Schema-drift safe insert: support both legacy and current schemas
            $cols = [];
            try {
                $colRows = $this->db->fetchAll("SHOW COLUMNS FROM ticket_notifications") ?: [];
                foreach ($colRows as $r) $cols[strtolower((string)$r['Field'])] = (string)($r['Type'] ?? '');
            } catch (Exception $e) {
                $cols = [];
            }

            $data = [];
            $data['tenant_id'] = $tenantId;
            $data['ticket_id'] = $ticketId;
            $data['user_id'] = $userId;

            // Normalize notification_type for ENUM schema
            $type = (string)$notificationType;
            $allowed = ['ticket_created','ticket_assigned','ticket_response','status_changed','ticket_resolved','ticket_closed','urgency_changed'];
            if (in_array(strtolower($type), $allowed, true)) {
                $typeNorm = strtolower($type);
            } else {
                // Map legacy names
                $map = [
                    'ticket_status_changed' => 'status_changed',
                    'ticket_status_changed_assigned' => 'status_changed',
                    'ticket_created_confirmation' => 'ticket_created',
                ];
                $typeNorm = $map[$type] ?? 'status_changed';
            }

            if (isset($cols['notification_type'])) {
                $data['notification_type'] = $typeNorm;
            } else {
                $data['notification_type'] = $type;
            }

            if (isset($cols['email_to'])) $data['email_to'] = $recipientEmail;
            if (isset($cols['recipient_email'])) $data['recipient_email'] = $recipientEmail;

            if (isset($cols['email_subject'])) $data['email_subject'] = $subject;
            if (isset($cols['subject'])) $data['subject'] = $subject;

            $body = is_string($bodyHtml) ? $bodyHtml : '';
            if (isset($cols['email_body'])) $data['email_body'] = $body;

            if (isset($cols['delivery_status'])) {
                $data['delivery_status'] = $status === 'sent' ? 'sent' : 'failed';
            }
            if (isset($cols['status'])) {
                $data['status'] = $status;
            }

            if (isset($cols['sent_at'])) $data['sent_at'] = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
            if (isset($cols['triggered_by'])) $data['triggered_by'] = $triggeredBy;
            if (isset($cols['created_at']) && !isset($cols['updated_at'])) $data['created_at'] = date('Y-m-d H:i:s');

            $this->db->insert('ticket_notifications', $data);
        } catch (Exception $e) {
            error_log("Failed to log ticket notification: " . $e->getMessage());
        }
    }

    /**
     * Truncate text to specified length
     *
     * @param string $text Text to truncate
     * @param int $length Maximum length
     * @return string Truncated text
     */
    private function truncateText($text, $length) {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . '...';
    }

    /**
     * Get human-readable label for category
     *
     * @param string $category Category code
     * @return string Localized label
     */
    private function getCategoryLabel($category) {
        $labels = [
            'technical' => 'Tecnico',
            'billing' => 'Fatturazione',
            'feature_request' => 'Richiesta Funzionalità',
            'bug_report' => 'Segnalazione Bug',
            'general' => 'Generale'
        ];

        return $labels[$category] ?? ucfirst($category);
    }

    /**
     * Get human-readable label for urgency
     *
     * @param string $urgency Urgency level
     * @return string Localized label
     */
    private function getUrgencyLabel($urgency) {
        $labels = [
            'low' => 'Bassa',
            'normal' => 'Normale',
            'high' => 'Alta',
            'critical' => 'Critica'
        ];

        return $labels[$urgency] ?? ucfirst($urgency);
    }

    /**
     * Get color for urgency badge
     *
     * @param string $urgency Urgency level
     * @return string Hex color code
     */
    private function getUrgencyColor($urgency) {
        $colors = [
            'low' => '#28a745',      // Green
            'normal' => '#ffc107',   // Yellow
            'high' => '#fd7e14',     // Orange
            'critical' => '#dc3545'  // Red
        ];

        return $colors[$urgency] ?? '#6c757d';
    }

    /**
     * Get human-readable label for status
     *
     * @param string $status Status code
     * @return string Localized label
     */
    private function getStatusLabel($status) {
        $labels = [
            'open' => 'Aperto',
            'in_progress' => 'In Lavorazione',
            'waiting_response' => 'In Attesa di Risposta',
            'resolved' => 'Risolto',
            'closed' => 'Chiuso'
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Get color for status badge
     *
     * @param string $status Status
     * @return string Hex color code
     */
    private function getStatusColor($status) {
        $colors = [
            'open' => '#17a2b8',           // Info blue
            'in_progress' => '#ffc107',     // Warning yellow
            'waiting_response' => '#6c757d', // Gray
            'resolved' => '#28a745',        // Success green
            'closed' => '#343a40'           // Dark
        ];

        return $colors[$status] ?? '#6c757d';
    }

    /**
     * Send comprehensive notification when ticket status changes
     * (NEW METHOD - 2025-10-26)
     *
     * Sends email to:
     * - Ticket creator (ALWAYS)
     * - Assigned user (if assigned_to IS NOT NULL)
     *
     * Includes next steps based on new status
     *
     * @param int $ticketId Ticket ID
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     * @return bool Success status
     */
    public function sendTicketStatusChangedNotification($ticketId, $oldStatus, $newStatus, ?int $actorUserId = null) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                error_log("TicketNotification: Ticket $ticketId not found");
                return false;
            }

            // Prepare next steps based on new status
            $nextSteps = $this->getNextStepsByStatus($newStatus);

            // Get changer info (explicit actor preferred; fallback to current session)
            $changerId = $actorUserId !== null ? (int)$actorUserId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
            $changer = $changerId > 0 ? $this->getUserInfo($changerId) : null;

            $successCount = 0;
            $recipients = $this->buildRecipientsForTicket((int)$ticketId, $ticket, $changerId > 0 ? $changerId : null);

            foreach ($recipients as $rcpt) {
                // Respect preferences only when we have a user_id
                if (!empty($rcpt['id']) && !$this->shouldNotify((int)$rcpt['id'], 'notify_ticket_status')) {
                    continue;
                }

                $templateData = [
                    'EMAIL_TITLE' => 'Stato ticket aggiornato',
                    'USER_NAME' => $rcpt['name'],
                    'TICKET_NUMBER' => $ticket['ticket_number'],
                    'TICKET_SUBJECT' => $ticket['subject'],
                    'OLD_STATUS' => $oldStatus,
                    'OLD_STATUS_LABEL' => $this->getStatusLabel($oldStatus),
                    'NEW_STATUS' => $newStatus,
                    'NEW_STATUS_LABEL' => $this->getStatusLabel($newStatus),
                    'NEW_STATUS_COLOR' => $this->getStatusColor($newStatus),
                    'URGENCY' => $ticket['urgency'],
                    'URGENCY_LABEL' => $this->getUrgencyLabel($ticket['urgency']),
                    'CHANGED_BY_NAME' => $changer['name'] ?? 'Sistema',
                    'NEXT_STEPS' => $nextSteps,
                    'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                    'BASE_URL' => $this->baseUrl,
                    'YEAR' => date('Y')
                ];

                $html = $this->renderTemplate('ticket_status_changed.html', $templateData);
                $subject = "Ticket #{$ticket['ticket_number']} - Stato aggiornato a: " . $this->getStatusLabel($newStatus);

                $sent = sendEmail(
                    $rcpt['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $ticket['tenant_id'],
                            'user_id' => $rcpt['id'] ?? null,
                            'action' => 'ticket_status_changed_notification'
                        ]
                    ]
                );

                if (!empty($rcpt['id'])) {
                    $this->logNotification(
                        $ticket['tenant_id'],
                        $ticketId,
                        (int)$rcpt['id'],
                        'status_changed',
                        $rcpt['email'],
                        $subject,
                        $sent ? 'sent' : 'failed',
                        $changerId,
                        $html
                    );
                }

                if ($sent) $successCount++;
            }

            return $successCount > 0;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketStatusChangedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get next steps message based on new ticket status
     *
     * @param string $newStatus New status
     * @return string Next steps message
     */
    private function getNextStepsByStatus($newStatus) {
        $nextSteps = [
            'open' => 'Il ticket è stato riaperto. Un operatore prenderà in carico la richiesta appena possibile.',
            'in_progress' => 'Il nostro team sta lavorando attivamente alla risoluzione del tuo problema. Ti aggiorneremo appena ci saranno novità.',
            'waiting_response' => 'Siamo in attesa di ulteriori informazioni da parte tua. Per favore, rispondi al ticket con i dettagli richiesti per consentirci di proseguire.',
            'resolved' => 'Il ticket è stato risolto. Verifica la soluzione proposta e, se tutto è ok, conferma la chiusura. In caso contrario, rispondi per riaprire il ticket.',
            'closed' => 'Il ticket è stato chiuso. Grazie per averci contattato! Se hai bisogno di ulteriore assistenza, non esitare ad aprire un nuovo ticket.'
        ];

        return $nextSteps[$newStatus] ?? 'Il ticket è stato aggiornato. Visualizza i dettagli per maggiori informazioni.';
    }
}
