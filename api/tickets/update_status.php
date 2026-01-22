<?php
/**
 * Ticket Update Status API Endpoint
 * POST /api/tickets/update_status.php
 *
 * Updates the status of a ticket
 * RBAC: Only admin/super_admin can change ticket status
 *
 * Input JSON:
 * {
 *   "ticket_id": 123,
 *   "status": "in_progress"
 * }
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

    if (empty($data['status'])) {
        api_error('Lo stato è obbligatorio', 400);
    }

    // Sanitize input
    $ticketId = (int)$data['ticket_id'];
    $newStatus = trim($data['status']);

    // Validate status
    $validStatuses = ['open', 'in_progress', 'waiting_response', 'resolved', 'closed'];
    if (!in_array($newStatus, $validStatuses)) {
        api_error('Stato non valido. Valori permessi: ' . implode(', ', $validStatuses), 400);
    }

    // ========================================
    // RBAC: Change status allowed for:
    // - super_admin
    // - current assignee (even if non-admin)
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
            $isAssignee = (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$userInfo['user_id']);
            if (!$isAssignee) {
                api_error('Solo il Super User o l’utente assegnato può modificare lo stato del ticket', 403);
            }
        }
    }

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if status is actually changing
    if ($ticket['status'] === $newStatus) {
        // Idempotent: avoid failing on duplicate client submissions
        api_success([
            'ticket' => $ticket,
            'old_status' => $ticket['status'],
            'new_status' => $newStatus
        ], 'Il ticket è già in questo stato');
    }

    $oldStatus = $ticket['status'];

    // Start transaction
    $db->beginTransaction();

    try {
        // Prepare update data
        $updateData = [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // If status is closed, set closed_by and closed_at
        if ($newStatus === 'closed') {
            $updateData['closed_by'] = $userInfo['user_id'];
            $updateData['closed_at'] = date('Y-m-d H:i:s');

            // Calculate resolution time in minutes
            $createdAt = strtotime($ticket['created_at']);
            $closedAt = time();
            $resolutionTime = ($closedAt - $createdAt) / 60;
            $updateData['resolution_time_minutes'] = $resolutionTime;
        }

        // Update ticket status
        $updated = $db->update('tickets', $updateData, ['id' => $ticketId]);

        if (!$updated) {
            throw new Exception('Errore durante l\'aggiornamento dello stato del ticket');
        }

        // Log to ticket history
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

        // Commit transaction
        $db->commit();

        // Fetch updated ticket with related data
        $updatedTicket = $db->fetchOne(
            "SELECT
                t.*,
                u_assigned.name as assigned_to_name,
                u_creator.name as created_by_name,
                u_creator.email as created_by_email,
                u_closed.name as closed_by_name
            FROM tickets t
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
            LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
            LEFT JOIN users u_closed ON t.closed_by = u_closed.id AND u_closed.deleted_at IS NULL
            WHERE t.id = ?",
            [$ticketId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // UPDATED: 2025-10-26 - Now uses comprehensive sendTicketStatusChangedNotification
        // ========================================
        $emailNotificationSent = null;
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            // Send status change notification (strict recipients policy):
            // - ticket opener (creator)
            // - actor (current user)
            // - asamodeo@fortibyte.it (always unless already included)
            $emailNotificationSent = (bool)$notifier->sendTicketStatusChangedNotification(
                $ticketId,
                $oldStatus,
                $newStatus,
                (int)($userInfo['user_id'] ?? 0)
            );

        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Ticket notification error (update_status): " . $e->getMessage());
            $emailNotificationSent = false;
        }

        api_success([
            'ticket' => $updatedTicket,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'email_notification' => [
                'sent' => $emailNotificationSent
            ]
        ], 'Stato aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket update_status error: " . $e->getMessage());
    api_error('Errore nell\'aggiornamento dello stato: ' . $e->getMessage(), 500);
}
