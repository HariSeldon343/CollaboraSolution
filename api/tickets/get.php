<?php
/**
 * Ticket Get API Endpoint
 * GET /api/tickets/get.php?ticket_id=123
 *
 * Returns single ticket with full conversation thread
 * RBAC: Users can only view their own tickets unless admin+
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Get ticket_id parameter (accept both 'id' and 'ticket_id' for compatibility)
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

    if (!$ticketId) {
        api_error('ID ticket obbligatorio', 400);
    }

    // Build WHERE clause with RBAC-based tenant isolation
    $where = ['t.id = ?', 't.deleted_at IS NULL'];
    $params = [$ticketId];

    // RBAC: Different visibility rules based on role
    if ($userInfo['role'] === 'super_admin') {
        // Super admins can view ANY ticket across all tenants (no tenant filter)
    } elseif ($userInfo['role'] === 'admin') {
        // Admins can view all tickets in their tenant
        $where[] = 't.tenant_id = ?';
        $params[] = $userInfo['tenant_id'];
    } else {
        // Regular users can only view their own tickets in their tenant
        $where[] = 't.tenant_id = ?';
        $where[] = 't.created_by = ?';
        $params[] = $userInfo['tenant_id'];
        $params[] = $userInfo['user_id'];
    }

    $whereClause = implode(' AND ', $where);

    $ticket = $db->fetchOne(
        "SELECT
            t.*,
            u_assigned.name as assigned_to_name,
            u_assigned.email as assigned_to_email,
            u_creator.name as created_by_name,
            u_creator.email as created_by_email,
            u_closed.name as closed_by_name
        FROM tickets t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
        LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
        LEFT JOIN users u_closed ON t.closed_by = u_closed.id AND u_closed.deleted_at IS NULL
        WHERE $whereClause",
        $params
    );

    if (!$ticket) {
        api_error('Ticket non trovato o non accessibile', 404);
    }

    // Get all responses for this ticket (no tenant filter needed - ticket RBAC already verified)
    $responses = $db->fetchAll(
        "SELECT
            tr.*,
            u.name as user_name,
            u.email as user_email,
            u.id as user_id
        FROM ticket_responses tr
        JOIN users u ON tr.user_id = u.id AND u.deleted_at IS NULL
        WHERE tr.ticket_id = ?
          AND tr.deleted_at IS NULL
        ORDER BY tr.created_at ASC",
        [$ticketId]
    );

    // Hide internal notes from non-admin users
    if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
        $responses = array_filter($responses, function($r) {
            return !$r['is_internal_note'];
        });
        $responses = array_values($responses); // Re-index array
    }

    // Get assignment history (no tenant filter needed - ticket RBAC already verified)
    $assignments = $db->fetchAll(
        "SELECT
            ta.*,
            u_assigned.name as assigned_to_name,
            u_assigner.name as assigned_by_name
        FROM ticket_assignments ta
        LEFT JOIN users u_assigned ON ta.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
        LEFT JOIN users u_assigner ON ta.assigned_by = u_assigner.id AND u_assigner.deleted_at IS NULL
        WHERE ta.ticket_id = ?
          AND ta.deleted_at IS NULL
        ORDER BY ta.assigned_at DESC",
        [$ticketId]
    );

    // Get change history (no tenant filter needed - ticket RBAC already verified)
    $history = $db->fetchAll(
        "SELECT
            th.*,
            u.name as user_name
        FROM ticket_history th
        LEFT JOIN users u ON th.user_id = u.id AND u.deleted_at IS NULL
        WHERE th.ticket_id = ?
        ORDER BY th.created_at DESC",
        [$ticketId]
    );

    api_success([
        'ticket' => $ticket,
        'responses' => $responses,
        'assignments' => $assignments,
        'history' => $history,
        'response_count' => count($responses)
    ], 'Ticket recuperato con successo');

} catch (Exception $e) {
    error_log("Ticket get error: " . $e->getMessage());
    api_error('Errore nel recupero del ticket: ' . $e->getMessage(), 500);
}
