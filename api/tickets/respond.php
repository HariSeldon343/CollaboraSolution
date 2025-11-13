<?php
/**
 * Ticket Respond API Endpoint
 * POST /api/tickets/respond.php
 *
 * Adds a response to an existing ticket
 * Can create internal notes (admin+ only)
 * Sends email notification to ticket creator
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
    // ========================================
    if ($userInfo['role'] === 'super_admin') {
        // Super admin can respond to ANY ticket across all tenants
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [$ticketId]
        );
    } elseif ($userInfo['role'] === 'admin') {
        // Admin can respond to all tickets in their tenant
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$ticketId, $userInfo['tenant_id']]
        );
    } else {
        // Regular users can only respond to their own tickets
        $ticket = $db->fetchOne(
            "SELECT * FROM tickets WHERE id = ? AND tenant_id = ? AND created_by = ? AND deleted_at IS NULL",
            [$ticketId, $userInfo['tenant_id'], $userInfo['user_id']]
        );
    }

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Check if ticket is closed
    if ($ticket['status'] === 'closed') {
        api_error('Non è possibile rispondere a un ticket chiuso', 403);
    }

    // Start transaction
    $db->beginTransaction();

    try {
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
            $createdTime = strtotime($ticket['created_at']);
            $responseTime = time();
            $responseMinutes = round(($responseTime - $createdTime) / 60, 2);
            $updateData['first_response_time'] = $responseMinutes;
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
        // ========================================
        try {
            // Don't send email for internal notes
            if (!$isInternalNote) {
                require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
                $notifier = new TicketNotification();
                $notifier->sendTicketResponseNotification($ticketId, $responseId);
            }
        } catch (Exception $e) {
            error_log("Ticket notification error (respond): " . $e->getMessage());
        }

        api_success([
            'response' => $response,
            'response_id' => $responseId,
            'ticket_updated' => $updateData
        ], 'Risposta aggiunta con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket respond error: " . $e->getMessage());
    api_error('Errore nell\'aggiunta della risposta: ' . $e->getMessage(), 500);
}
