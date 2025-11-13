<?php
/**
 * Ticket Assign API Endpoint
 * POST /api/tickets/assign.php
 *
 * Assigns/reassigns a ticket to a user (admin+ only)
 * Logs to ticket_assignments and sends email notification
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

    if (!isset($data['assigned_to'])) {
        api_error('ID utente assegnatario obbligatorio', 400);
    }

    $ticketId = (int)$data['ticket_id'];
    $assignedTo = $data['assigned_to'] ? (int)$data['assigned_to'] : null;

    // ========================================
    // RBAC: Check ticket access
    // ========================================
    if ($userInfo['role'] === 'super_admin') {
        // Super admin can assign ANY ticket across all tenants
        $ticket = $db->fetchOne(
            'SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL',
            [$ticketId]
        );
    } else {
        // Admin can only assign tickets in their tenant
        $ticket = $db->fetchOne(
            'SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$ticketId, $userInfo['tenant_id']]
        );
    }

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if ticket is closed
    if ($ticket['status'] === 'closed') {
        api_error('Non è possibile assegnare un ticket chiuso', 403);
    }

    // If assigning to a user, validate they exist
    if ($assignedTo) {
        // Super admin can assign cross-tenant, admin only within tenant
        if ($userInfo['role'] === 'super_admin') {
            $assignedUser = $db->fetchOne(
                'SELECT id, name, email, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL',
                [$assignedTo]
            );
        } else {
            $assignedUser = $db->fetchOne(
                'SELECT id, name, email, tenant_id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$assignedTo, $userInfo['tenant_id']]
            );
        }

        if (!$assignedUser) {
            api_error('Utente assegnatario non trovato', 404);
        }
    }

    // Check if already assigned to this user
    if ($ticket['assigned_to'] == $assignedTo) {
        api_error('Il ticket è già assegnato a questo utente', 400);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Update ticket assignment
        $updateData = [
            'assigned_to' => $assignedTo,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // If this is first assignment, set first_response_at
        if ($assignedTo && !$ticket['first_response_at']) {
            $updateData['first_response_at'] = date('Y-m-d H:i:s');

            // Calculate first response time in minutes
            $createdTime = strtotime($ticket['created_at']);
            $responseTime = time();
            $responseMinutes = round(($responseTime - $createdTime) / 60, 2);
            $updateData['first_response_time'] = $responseMinutes;
        }

        $db->update('tickets', $updateData, [
            'id' => $ticketId
        ]);

        // Log to ticket_assignments (use ticket's tenant_id)
        $db->insert('ticket_assignments', [
            'tenant_id' => $ticket['tenant_id'],
            'ticket_id' => $ticketId,
            'assigned_to' => $assignedTo,
            'assigned_by' => $userInfo['user_id'],
            'assigned_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Log to ticket history (use ticket's tenant_id)
        $db->insert('ticket_history', [
            'tenant_id' => $ticket['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => $assignedTo ? 'assigned' : 'unassigned',
            'field_name' => 'assigned_to',
            'old_value' => $ticket['assigned_to'],
            'new_value' => $assignedTo,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // Fetch updated ticket
        $updatedTicket = $db->fetchOne(
            "SELECT
                t.*,
                u_assigned.name as assigned_to_name,
                u_creator.name as created_by_name
            FROM tickets t
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            LEFT JOIN users u_creator ON t.created_by = u_creator.id
            WHERE t.id = ?",
            [$ticketId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        try {
            if ($assignedTo) {
                require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
                $notifier = new TicketNotification();
                $notifier->sendTicketAssignedNotification($ticketId, $assignedTo);
            }
        } catch (Exception $e) {
            error_log("Ticket notification error (assign): " . $e->getMessage());
        }

        api_success([
            'ticket' => $updatedTicket,
            'assigned_to' => $assignedTo
        ], $assignedTo ? 'Ticket assegnato con successo' : 'Ticket non assegnato');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket assign error: " . $e->getMessage());
    api_error('Errore nell\'assegnazione del ticket: ' . $e->getMessage(), 500);
}
