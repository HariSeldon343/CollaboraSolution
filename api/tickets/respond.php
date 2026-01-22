<?php
/**
 * Ticket Respond API Endpoint
 * POST /api/tickets/respond.php
 *
 * Adds a response to an existing ticket
 * Can create internal notes (admin+ only)
 * Sends email notification to ticket creator
 *
 * UPDATED: allow batching status / assignment updates with the reply so only ONE email is sent.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Verify CSRF token for POST request
verifyApiCsrfToken();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        api_error('Invalid JSON data', 400);
    }

    // Validate required fields
    if (empty($data['ticket_id'])) {
        api_error('ID ticket obbligatorio', 400);
    }

    // Support both 'message' and 'response_text' for compatibility
    $messageField = !empty($data['message']) ? 'message' : 'response_text';

    if (empty($data[$messageField])) {
        api_error('Il messaggio è obbligatorio', 400);
    }

    $ticketId = (int)$data['ticket_id'];
    $responseText = trim($data[$messageField]);
    $isInternalNote = !empty($data['is_internal_note']);
    $attachments = !empty($data['attachments']) ? json_encode($data['attachments']) : null;

    // Optional batched updates (applied in same transaction)
    $newStatus = isset($data['status']) && $data['status'] !== '' ? trim((string)$data['status']) : null;
    $assignedTo = array_key_exists('assigned_to', $data) ? ($data['assigned_to'] ? (int)$data['assigned_to'] : null) : null;

    // Internal notes only for admin+
    if ($isInternalNote && !in_array($userInfo['role'], ['admin', 'super_admin'])) {
        api_error('Solo gli amministratori possono creare note interne', 403);
    }

    // Validate response text length
    if (strlen($responseText) > 10000) {
        api_error('La risposta non può superare 10000 caratteri', 400);
    }

    // ========================================
    // RBAC: Check ticket access
    // Policy:
    // - super_admin: can respond to ANY ticket
    // - others: can respond only if (creator OR currently assigned)
    // ========================================
    if ($userInfo['role'] === 'super_admin') {
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [$ticketId]
        );
    } else {
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$ticketId, $userInfo['tenant_id']]
        );
        if ($ticket) {
            $isCreator = ((int)$ticket['created_by'] === (int)$userInfo['user_id']);
            $isAssignee = (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$userInfo['user_id']);
            if (!$isCreator && !$isAssignee) {
                api_error('Ticket non accessibile: non sei assegnato e non sei il creatore', 403);
            }
        }
    }

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if ticket is closed
    if ($ticket['status'] === 'closed') {
        api_error('Non è possibile rispondere a un ticket chiuso', 403);
    }

    // Validate optional status
    if ($newStatus !== null) {
        $validStatuses = ['open', 'in_progress', 'waiting_response', 'resolved', 'closed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            api_error('Stato non valido. Valori permessi: ' . implode(', ', $validStatuses), 400);
        }
        // RBAC for status change: super_admin OR current assignee
        if ($userInfo['role'] !== 'super_admin') {
            $isAssignee = (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$userInfo['user_id']);
            if (!$isAssignee) {
                api_error('Solo il Super User o l’utente assegnato può modificare lo stato del ticket', 403);
            }
        }
    }

    // Validate optional assignment (super_admin only)
    if (array_key_exists('assigned_to', $data)) {
        if (($userInfo['role'] ?? '') !== 'super_admin') {
            api_error('Solo i Super User possono assegnare/prendere in carico i ticket', 403);
        }
        if ($assignedTo) {
            $assignedUser = $db->fetchOne(
                'SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL',
                [$assignedTo]
            );
            if (!$assignedUser) {
                api_error('Utente assegnatario non trovato', 404);
            }
        }
    }

    // Start transaction
    $db->beginTransaction();

    try {
        $oldStatus = (string)($ticket['status'] ?? '');
        $oldAssignedTo = !empty($ticket['assigned_to']) ? (int)$ticket['assigned_to'] : null;

        $didStatusChange = false;
        $didAssignChange = false;

        // Apply optional assignment
        if (array_key_exists('assigned_to', $data) && ((string)($ticket['assigned_to'] ?? '') !== (string)($assignedTo ?? ''))) {
            $db->update('tickets', [
                'assigned_to' => $assignedTo,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $ticketId]);

            $db->insert('ticket_assignments', [
                'tenant_id' => $ticket['tenant_id'],
                'ticket_id' => $ticketId,
                'assigned_to' => $assignedTo,
                'assigned_by' => $userInfo['user_id'],
                'assigned_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $db->insert('ticket_history', [
                'tenant_id' => $ticket['tenant_id'],
                'ticket_id' => $ticketId,
                'user_id' => $userInfo['user_id'],
                'action' => $assignedTo ? 'assigned' : 'unassigned',
                'field_name' => 'assigned_to',
                'old_value' => $oldAssignedTo,
                'new_value' => $assignedTo,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $didAssignChange = true;
        }

        // Apply optional status change (before response insert)
        if ($newStatus !== null && $oldStatus !== $newStatus) {
            $updateDataStatus = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // If status is closed, set closed_by and closed_at
            if ($newStatus === 'closed') {
                $updateDataStatus['closed_by'] = $userInfo['user_id'];
                $updateDataStatus['closed_at'] = date('Y-m-d H:i:s');
                $createdAt = strtotime($ticket['created_at']);
                $closedAt = time();
                $updateDataStatus['resolution_time_minutes'] = ($closedAt - $createdAt) / 60;
            }

            $db->update('tickets', $updateDataStatus, ['id' => $ticketId]);
            $db->insert('ticket_history', [
                'tenant_id' => $ticket['tenant_id'],
                'ticket_id' => $ticketId,
                'user_id' => $userInfo['user_id'],
                'action' => 'status_changed',
                'field_name' => 'status',
                'old_value' => $oldStatus,
                'new_value' => $newStatus,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $didStatusChange = true;
        }

        // Insert response (use ticket's tenant_id for cross-tenant support)
        $responseId = $db->insert('ticket_responses', [
            'tenant_id' => $ticket['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'response_text' => $responseText,
            'is_internal_note' => $isInternalNote ? 1 : 0,
            'attachments' => $attachments,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if (!$responseId) {
            throw new Exception('Errore durante l\'inserimento della risposta');
        }

        // Update ticket last activity
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        // If this is first admin response, set first_response_at
        if (in_array($userInfo['role'], ['admin', 'super_admin']) && !$ticket['first_response_at']) {
            $updateData['first_response_at'] = date('Y-m-d H:i:s');

            // Calculate first response time in minutes
            // BUG-147c FIX: Column is 'first_response_time_minutes' not 'first_response_time'
            $createdTime = strtotime($ticket['created_at']);
            $responseTime = time();
            $responseMinutes = round(($responseTime - $createdTime) / 60, 2);
            $updateData['first_response_time_minutes'] = $responseMinutes;
        }

        $db->update('tickets', $updateData, [
            'id' => $ticketId
        ]);

        // Log to ticket history (use ticket's tenant_id)
        $db->insert('ticket_history', [
            'tenant_id' => $ticket['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => 'response_added',
            'field_name' => 'response',
            'old_value' => null,
            'new_value' => $isInternalNote ? 'Internal note added' : 'Response added',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // Fetch the created response with user info
        $response = $db->fetchOne(
            "SELECT
                tr.*,
                u.name as user_name,
                u.email as user_email
            FROM ticket_responses tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.id = ?",
            [$responseId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // - If status/assignment are changed together with a (non-internal) reply,
        //   send ONE consolidated email containing all updates.
        // ========================================
        $emailNotificationSent = null;
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            if ($isInternalNote) {
                // Internal note: do not email the message; but status change (if any) should still notify.
                if ($didStatusChange) {
                    $emailNotificationSent = (bool)$notifier->sendTicketStatusChangedNotification(
                        $ticketId,
                        $oldStatus,
                        $newStatus,
                        (int)($userInfo['user_id'] ?? 0)
                    );
                } else {
                    $emailNotificationSent = null;
                }
            } else if ($didStatusChange || $didAssignChange) {
                $emailNotificationSent = (bool)$notifier->sendTicketCombinedUpdateNotification(
                    $ticketId,
                    $responseId,
                    $didStatusChange ? $oldStatus : null,
                    $didStatusChange ? $newStatus : null,
                    $didAssignChange ? $oldAssignedTo : null,
                    $didAssignChange ? $assignedTo : null
                );
            } else {
                $emailNotificationSent = (bool)$notifier->sendTicketResponseNotification($ticketId, $responseId);
            }
        } catch (Exception $e) {
            error_log("Ticket notification error (respond): " . $e->getMessage());
            $emailNotificationSent = false;
        }

        api_success([
            'response' => $response,
            'response_id' => $responseId,
            'ticket_updated' => $updateData,
            'batched_updates' => [
                'status' => $didStatusChange ? ['old' => $oldStatus, 'new' => $newStatus] : null,
                'assigned_to' => $didAssignChange ? ['old' => $oldAssignedTo, 'new' => $assignedTo] : null
            ],
            'email_notification' => [
                'sent' => $emailNotificationSent
            ]
        ], 'Risposta aggiunta con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket respond error: " . $e->getMessage());
    api_error('Errore nell\'aggiunta della risposta: ' . $e->getMessage(), 500);
}
