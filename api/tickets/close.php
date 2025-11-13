<?php
/**
 * Ticket Close API Endpoint
 * POST /api/tickets/close.php
 *
 * Closes a ticket permanently (admin+ only)
 * Prevents reopening and sends email notification
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

// Require admin+ role
requireApiRole('admin');

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

    $ticketId = (int)$data['ticket_id'];
    $resolutionNotes = isset($data['resolution_notes']) ? trim($data['resolution_notes']) : null;

    // Fetch ticket with tenant isolation
    $ticket = $db->fetchOne(
        'SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$ticketId, $userInfo['tenant_id']]
    );

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if already closed
    if ($ticket['status'] === 'closed') {
        api_error('Il ticket è già chiuso', 400);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Update ticket to closed status
        $updateData = [
            'status' => 'closed',
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $userInfo['user_id'],
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add resolution notes if provided
        if ($resolutionNotes) {
            $updateData['resolution_notes'] = $resolutionNotes;
        }

        // Set resolved_at if not already set
        if (!$ticket['resolved_at']) {
            $updateData['resolved_at'] = date('Y-m-d H:i:s');

            // Calculate resolution time in minutes
            $createdTime = strtotime($ticket['created_at']);
            $resolvedTime = time();
            $resolutionMinutes = round(($resolvedTime - $createdTime) / 60, 2);
            $updateData['resolution_time_minutes'] = $resolutionMinutes;
        }

        $db->update('tickets', $updateData, [
            'id' => $ticketId,
            'tenant_id' => $userInfo['tenant_id']
        ]);

        // Log to ticket history
        $db->insert('ticket_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => 'closed',
            'field_name' => 'status',
            'old_value' => $ticket['status'],
            'new_value' => 'closed',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // BUG-047: Audit log ticket close (non-blocking)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logGeneric(
                $userInfo['user_id'],
                $userInfo['tenant_id'],
                'close',
                'ticket',
                $ticketId,
                "Chiuso ticket #{$ticket['ticket_number']}",
                ['status' => $ticket['status']],
                ['status' => 'closed', 'closed_at' => $closedAt],
                null,
                'info',
                'success'
            );
        } catch (Exception $auditEx) {
            error_log('[TICKET_CLOSE] Audit log failed: ' . $auditEx->getMessage());
        }

        // Fetch updated ticket
        $updatedTicket = $db->fetchOne(
            "SELECT
                t.*,
                u_assigned.name as assigned_to_name,
                u_creator.name as created_by_name,
                u_closed.name as closed_by_name
            FROM tickets t
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            LEFT JOIN users u_creator ON t.created_by = u_creator.id
            LEFT JOIN users u_closed ON t.closed_by = u_closed.id
            WHERE t.id = ?",
            [$ticketId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();
            $notifier->sendTicketClosedNotification($ticketId);
        } catch (Exception $e) {
            error_log("Ticket notification error (close): " . $e->getMessage());
        }

        api_success([
            'ticket' => $updatedTicket
        ], 'Ticket chiuso con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket close error: " . $e->getMessage());
    api_error('Errore nella chiusura del ticket: ' . $e->getMessage(), 500);
}
