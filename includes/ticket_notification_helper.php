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

class TicketNotification {

    private $db;
    private $baseUrl;
    private $templateDir;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $this->templateDir = __DIR__ . '/email_templates/tickets/';
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

            // Get all super_admin users for this tenant
            $superAdmins = $this->db->fetchAll(
                "SELECT id, name, email
                 FROM users
                 WHERE tenant_id = ?
                   AND role = 'super_admin'
                   AND deleted_at IS NULL",
                [$ticket['tenant_id']]
            );

            if (empty($superAdmins)) {
                error_log("TicketNotification: No super_admins found for tenant {$ticket['tenant_id']}");
                return false;
            }

            $successCount = 0;

            foreach ($superAdmins as $admin) {
                // Check user preferences
                if (!$this->shouldNotify($admin['id'], 'notify_ticket_created')) {
                    continue;
                }

                // Prepare template data
                $templateData = [
                    'USER_NAME' => $admin['name'],
                    'TICKET_NUMBER' => $ticket['ticket_number'],
                    'TICKET_SUBJECT' => $ticket['subject'],
                    'TICKET_DESCRIPTION' => $this->truncateText($ticket['description'], 300),
                    'TICKET_CATEGORY_LABEL' => $this->getCategoryLabel($ticket['category']),
                    'TICKET_URGENCY' => $ticket['urgency'],
                    'TICKET_URGENCY_LABEL' => $this->getUrgencyLabel($ticket['urgency']),
                    'TICKET_URGENCY_COLOR' => $this->getUrgencyColor($ticket['urgency']),
                    'CREATED_BY_NAME' => $ticket['created_by_name'] ?? 'Utente',
                    'CREATED_BY_EMAIL' => $ticket['created_by_email'] ?? '',
                    'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                    'TICKET_LIST_URL' => $this->baseUrl . '/ticket.php',
                    'BASE_URL' => $this->baseUrl,
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
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'TICKET_LIST_URL' => $this->baseUrl . '/ticket.php',
                'BASE_URL' => $this->baseUrl,
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
            $this->logNotification([
                'ticket_id' => $ticketId,
                'user_id' => $creatorInfo['id'],
                'notification_type' => 'ticket_created_confirmation',
                'recipient_email' => $creatorInfo['email'],
                'subject' => $subject,
                'sent_at' => date('Y-m-d H:i:s'),
                'status' => $sent ? 'sent' : 'failed'
            ]);

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

            // Get ticket creator info
            $creator = $this->getUserInfo($ticket['created_by']);
            if (!$creator) {
                return false;
            }

            // Don't notify if the responder is the creator themselves
            if ($response['user_id'] == $creator['id']) {
                return true;
            }

            // Check user preferences
            if (!$this->shouldNotify($creator['id'], 'notify_ticket_response')) {
                return true;
            }

            // Prepare template data
            $templateData = [
                'USER_NAME' => $creator['name'],
                'TICKET_NUMBER' => $ticket['ticket_number'],
                'TICKET_SUBJECT' => $ticket['subject'],
                'RESPONSE_TEXT' => $this->truncateText($response['response_text'], 500),
                'RESPONSE_TEXT_FULL' => strlen($response['response_text']) > 500,
                'RESPONDER_NAME' => $response['user_name'],
                'TICKET_URL' => $this->baseUrl . '/ticket.php?id=' . $ticketId,
                'BASE_URL' => $this->baseUrl,
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('ticket_response.html', $templateData);
            $subject = "[Nuova Risposta] {$ticket['ticket_number']}: {$ticket['subject']}";

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
                        'action' => 'ticket_response_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $ticket['tenant_id'],
                $ticketId,
                $creator['id'],
                'ticket_response',
                $creator['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $response['user_id']
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TicketNotification Error (sendTicketResponseNotification): " . $e->getMessage());
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

        $html = file_get_contents($templatePath);

        // Replace placeholders
        foreach ($data as $key => $value) {
            if ($value === null || $value === false) {
                // Handle conditional blocks
                $html = preg_replace('/<!-- IF_' . $key . ' -->.*?<!-- ENDIF_' . $key . ' -->/s', '', $html);
                continue;
            }

            if ($value === true) {
                // Keep conditional blocks
                $html = str_replace('<!-- IF_' . $key . ' -->', '', $html);
                $html = str_replace('<!-- ENDIF_' . $key . ' -->', '', $html);
                continue;
            }

            // Handle arrays (for loops)
            if (is_array($value)) {
                $loopContent = '';
                if (preg_match('/<!-- LOOP_' . $key . ' -->(.*?)<!-- ENDLOOP_' . $key . ' -->/s', $html, $matches)) {
                    $template = $matches[1];
                    foreach ($value as $item) {
                        $loopContent .= $item;
                    }
                    $html = preg_replace('/<!-- LOOP_' . $key . ' -->.*?<!-- ENDLOOP_' . $key . ' -->/s', $loopContent, $html);
                }
                continue;
            }

            // Simple replacement
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
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
    private function logNotification($tenantId, $ticketId, $userId, $notificationType, $recipientEmail, $subject, $status, $triggeredBy = null) {
        try {
            $this->db->insert('ticket_notifications', [
                'tenant_id' => $tenantId,
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                'status' => $status,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
                'triggered_by' => $triggeredBy,
                'created_at' => date('Y-m-d H:i:s')
            ]);
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
    public function sendTicketStatusChangedNotification($ticketId, $oldStatus, $newStatus) {
        try {
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                error_log("TicketNotification: Ticket $ticketId not found");
                return false;
            }

            // Prepare next steps based on new status
            $nextSteps = $this->getNextStepsByStatus($newStatus);

            // Get changer info (current session user or from history)
            $changerId = $_SESSION['user_id'] ?? null;
            $changer = $changerId ? $this->getUserInfo($changerId) : null;

            $successCount = 0;

            // ========================================
            // RECIPIENT 1: Ticket Creator (ALWAYS)
            // ========================================
            $creator = $this->getUserInfo($ticket['created_by']);
            if ($creator) {
                // Check user preferences
                if ($this->shouldNotify($creator['id'], 'notify_ticket_status')) {
                    // Prepare template data for creator
                    $templateData = [
                        'USER_NAME' => $creator['name'],
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

                    // Render email
                    $html = $this->renderTemplate('ticket_status_changed.html', $templateData);
                    $subject = "Ticket #{$ticket['ticket_number']} - Stato aggiornato a: " . $this->getStatusLabel($newStatus);

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

                    if ($sent) {
                        $successCount++;
                    }
                }
            }

            // ========================================
            // RECIPIENT 2: Assigned User (if assigned_to IS NOT NULL)
            // ========================================
            if (!empty($ticket['assigned_to']) && $ticket['assigned_to'] != $ticket['created_by']) {
                $assignedUser = $this->getUserInfo($ticket['assigned_to']);

                if ($assignedUser && $this->shouldNotify($assignedUser['id'], 'notify_ticket_status')) {
                    // Prepare template data for assigned user
                    $templateData = [
                        'USER_NAME' => $assignedUser['name'],
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

                    // Render email
                    $html = $this->renderTemplate('ticket_status_changed.html', $templateData);
                    $subject = "Ticket #{$ticket['ticket_number']} - Stato aggiornato a: " . $this->getStatusLabel($newStatus);

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
                                'action' => 'ticket_status_changed_notification'
                            ]
                        ]
                    );

                    // Log notification
                    $this->logNotification(
                        $ticket['tenant_id'],
                        $ticketId,
                        $assignedUser['id'],
                        'ticket_status_changed_assigned',
                        $assignedUser['email'],
                        $subject,
                        $sent ? 'sent' : 'failed',
                        $changerId
                    );

                    if ($sent) {
                        $successCount++;
                    }
                }
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
