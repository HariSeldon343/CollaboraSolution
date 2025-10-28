<?php
/**
 * Ticket Update API Endpoint
 * POST /api/tickets/update.php
 *
 * Updates an existing ticket (admin+ only)
 * Can modify: status, assigned_to, urgency, resolution_notes
 * Tracks changes in ticket_history and sends notifications
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

    // Fetch existing ticket with tenant isolation
    $ticket = $db->fetchOne(
        'SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$ticketId, $userInfo['tenant_id']]
    );

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if ticket is closed
    if ($ticket['status'] === 'closed') {
        api_error('Non è possibile modificare un ticket chiuso', 403);
    }

    // Prepare update data and track changes
    $updateData = [];
    $changes = [];

    // Status change
    if (isset($data['status']) && $data['status'] !== $ticket['status']) {
        $validStatuses = ['open', 'in_progress', 'waiting_response', 'resolved', 'closed'];
        if (!in_array($data['status'], $validStatuses)) {
            api_error('Status non valido', 400);
        }

        $updateData['status'] = $data['status'];
        $changes['status'] = [
            'field' => 'status',
            'old' => $ticket['status'],
            'new' => $data['status']
        ];

        // Calculate resolution time if marking as resolved
        if ($data['status'] === 'resolved' && !$ticket['resolved_at']) {
            $updateData['resolved_at'] = date('Y-m-d H:i:s');

            // Calculate resolution time in minutes
            $createdTime = strtotime($ticket['created_at']);
            $resolvedTime = time();
            $resolutionMinutes = round(($resolvedTime - $createdTime) / 60, 2);
            $updateData['resolution_time_minutes'] = $resolutionMinutes;
        }
    }

    // Assignment change
    if (isset($data['assigned_to'])) {
        $assignedTo = $data['assigned_to'] ? (int)$data['assigned_to'] : null;

        if ($assignedTo && $assignedTo !== $ticket['assigned_to']) {
            // Validate user exists
            $assignedUser = $db->fetchOne(
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$assignedTo, $userInfo['tenant_id']]
            );

            if (!$assignedUser) {
                api_error('Utente assegnatario non trovato', 404);
            }

            $updateData['assigned_to'] = $assignedTo;
            $changes['assigned_to'] = [
                'field' => 'assigned_to',
                'old' => $ticket['assigned_to'],
                'new' => $assignedTo
            ];

            // Set first_response_time if this is first admin assignment
            if (!$ticket['first_response_at']) {
                $updateData['first_response_at'] = date('Y-m-d H:i:s');

                // Calculate first response time in minutes
                $createdTime = strtotime($ticket['created_at']);
                $responseTime = time();
                $responseMinutes = round(($responseTime - $createdTime) / 60, 2);
                $updateData['first_response_time'] = $responseMinutes;
            }
        }
    }

    // Urgency change
    if (isset($data['urgency']) && $data['urgency'] !== $ticket['urgency']) {
        $validUrgencies = ['low', 'normal', 'high', 'critical'];
        if (!in_array($data['urgency'], $validUrgencies)) {
            api_error('Urgenza non valida', 400);
        }

        $updateData['urgency'] = $data['urgency'];
        $changes['urgency'] = [
            'field' => 'urgency',
            'old' => $ticket['urgency'],
            'new' => $data['urgency']
        ];
    }

    // Resolution notes
    if (isset($data['resolution_notes']) && $data['resolution_notes'] !== $ticket['resolution_notes']) {
        $updateData['resolution_notes'] = trim($data['resolution_notes']);
        $changes['resolution_notes'] = [
            'field' => 'resolution_notes',
            'old' => $ticket['resolution_notes'],
            'new' => $updateData['resolution_notes']
        ];
    }

    // If no changes, return early
    if (empty($updateData)) {
        api_success([
            'ticket' => $ticket,
            'message' => 'Nessuna modifica da applicare'
        ], 'Nessuna modifica richiesta');
    }

    // Add updated_at timestamp
    $updateData['updated_at'] = date('Y-m-d H:i:s');

    // Start transaction
    $db->beginTransaction();

    try {
        // Update ticket
        $updated = $db->update('tickets', $updateData, [
            'id' => $ticketId,
            'tenant_id' => $userInfo['tenant_id']
        ]);

        if (!$updated) {
            throw new Exception('Errore durante l\'aggiornamento del ticket');
        }

        // Log each change to ticket_history
        foreach ($changes as $change) {
            $db->insert('ticket_history', [
                'tenant_id' => $userInfo['tenant_id'],
                'ticket_id' => $ticketId,
                'user_id' => $userInfo['user_id'],
                'action' => 'updated',
                'field_name' => $change['field'],
                'old_value' => $change['old'],
                'new_value' => $change['new'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        // If assignment changed, log to ticket_assignments
        if (isset($changes['assigned_to'])) {
            $db->insert('ticket_assignments', [
                'tenant_id' => $userInfo['tenant_id'],
                'ticket_id' => $ticketId,
                'assigned_to' => $changes['assigned_to']['new'],
                'assigned_by' => $userInfo['user_id'],
                'assigned_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

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
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            // Notify on assignment
            if (isset($changes['assigned_to'])) {
                $notifier->sendTicketAssignedNotification($ticketId, $changes['assigned_to']['new']);
            }

            // Notify on status change
            if (isset($changes['status'])) {
                $notifier->sendStatusChangedNotification(
                    $ticketId,
                    $changes['status']['old'],
                    $changes['status']['new']
                );
            }
        } catch (Exception $e) {
            error_log("Ticket notification error (update): " . $e->getMessage());
        }

        api_success([
            'ticket' => $updatedTicket,
            'changes' => $changes
        ], 'Ticket aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket update error: " . $e->getMessage());
    api_error('Errore nell\'aggiornamento del ticket: ' . $e->getMessage(), 500);
}
