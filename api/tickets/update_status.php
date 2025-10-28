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
    // RBAC: Only admin+ can change status
    // ========================================
    if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
        api_error('Solo gli amministratori possono modificare lo stato dei ticket', 403);
    }

    // ========================================
    // RBAC: Check ticket access
    // ========================================
    if ($userInfo['role'] === 'super_admin') {
        // Super admin can update ANY ticket across all tenants
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [$ticketId]
        );
    } else {
        // Admin can only update tickets in their tenant
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$ticketId, $userInfo['tenant_id']]
        );
    }

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if status is actually changing
    if ($ticket['status'] === $newStatus) {
        api_error('Il ticket ha già questo stato', 400);
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
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            // Send comprehensive status change notification
            // This sends email to:
            // 1. Ticket creator (ALWAYS)
            // 2. Assigned user (if assigned_to IS NOT NULL)
            // Includes next steps based on new status
            $notifier->sendTicketStatusChangedNotification($ticketId, $oldStatus, $newStatus);

        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Ticket notification error (update_status): " . $e->getMessage());
        }

        api_success([
            'ticket' => $updatedTicket,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ], 'Stato aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket update_status error: " . $e->getMessage());
    api_error('Errore nell\'aggiornamento dello stato: ' . $e->getMessage(), 500);
}
