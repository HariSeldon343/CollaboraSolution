<?php
/**
 * Ticket List API Endpoint
 * GET /api/tickets/list.php
 *
 * Returns list of tickets with filtering, sorting, and pagination
 * Filters: status, category, urgency, assigned_to, created_by, search
 * Sort: created_at, updated_at, ticket_number, urgency
 * Pagination: page, limit
 *
 * RBAC: Users see only their tickets, admin/super_admin see all
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
    // Get query parameters
    $status = $_GET['status'] ?? null;
    $category = $_GET['category'] ?? null;
    $urgency = $_GET['urgency'] ?? null;
    $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
    $createdBy = isset($_GET['created_by']) ? (int)$_GET['created_by'] : null;
    $search = $_GET['search'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    // Validate sort order
    if (!in_array($sortOrder, ['ASC', 'DESC'])) {
        $sortOrder = 'DESC';
    }

    // Validate sort field
    $validSortFields = ['created_at', 'updated_at', 'ticket_number', 'urgency', 'status', 'category'];
    if (!in_array($sortBy, $validSortFields)) {
        $sortBy = 'created_at';
    }

    // Build WHERE clause with tenant isolation
    $where = ['t.deleted_at IS NULL'];
    $params = [];

    // RBAC: Different visibility rules based on role
    if ($userInfo['role'] === 'super_admin') {
        // Super admins see ALL tickets across ALL tenants (no tenant filter)
        // This allows centralized support and management
    } elseif ($userInfo['role'] === 'admin') {
        // Admins see all tickets in their tenant
        $where[] = 't.tenant_id = ?';
        $params[] = $userInfo['tenant_id'];
    } else {
        // Regular users see only their tickets in their tenant
        $where[] = 't.tenant_id = ?';
        $where[] = 't.created_by = ?';
        $params[] = $userInfo['tenant_id'];
        $params[] = $userInfo['user_id'];
    }

    // Apply filters
    if ($status) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }

    if ($category) {
        $where[] = 't.category = ?';
        $params[] = $category;
    }

    if ($urgency) {
        $where[] = 't.urgency = ?';
        $params[] = $urgency;
    }

    if ($assignedTo !== null) {
        if ($assignedTo === 0) {
            $where[] = 't.assigned_to IS NULL';
        } else {
            $where[] = 't.assigned_to = ?';
            $params[] = $assignedTo;
        }
    }

    if ($createdBy !== null) {
        $where[] = 't.created_by = ?';
        $params[] = $createdBy;
    }

    if ($search) {
        $where[] = '(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ?)';
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = implode(' AND ', $where);

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM tickets t WHERE $whereClause";
    $totalResult = $db->fetchOne($countSql, $params);
    $total = (int)$totalResult['total'];

    // Get tickets with assignee and creator info
    $sql = "
        SELECT
            t.*,
            u_assigned.name as assigned_to_name,
            u_assigned.email as assigned_to_email,
            u_creator.name as created_by_name,
            u_creator.email as created_by_email,
            u_closed.name as closed_by_name,
            (SELECT COUNT(*) FROM ticket_responses tr
             WHERE tr.ticket_id = t.id AND tr.deleted_at IS NULL) as response_count,
            (SELECT MAX(tr.created_at) FROM ticket_responses tr
             WHERE tr.ticket_id = t.id AND tr.deleted_at IS NULL) as last_response_at
        FROM tickets t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
        LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
        LEFT JOIN users u_closed ON t.closed_by = u_closed.id AND u_closed.deleted_at IS NULL
        WHERE $whereClause
        ORDER BY t.$sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $tickets = $db->fetchAll($sql, $params);

    // Calculate pagination metadata
    $totalPages = ceil($total / $limit);

    // Count by status for dashboard (with same RBAC filtering)
    $statusCounts = [];
    $statusWhere = ['t.deleted_at IS NULL'];
    $statusParams = [];

    // Apply same RBAC rules as main query
    if ($userInfo['role'] === 'super_admin') {
        // Super admins - no tenant filter
    } elseif ($userInfo['role'] === 'admin') {
        $statusWhere[] = 't.tenant_id = ?';
        $statusParams[] = $userInfo['tenant_id'];
    } else {
        $statusWhere[] = 't.tenant_id = ?';
        $statusWhere[] = 't.created_by = ?';
        $statusParams[] = $userInfo['tenant_id'];
        $statusParams[] = $userInfo['user_id'];
    }

    $statusSql = "SELECT status, COUNT(*) as count FROM tickets t WHERE " . implode(' AND ', $statusWhere) . " GROUP BY status";
    $statusResults = $db->fetchAll($statusSql, $statusParams);
    foreach ($statusResults as $row) {
        $statusCounts[$row['status']] = (int)$row['count'];
    }

    // Return response (BUG-022 compliant - nested array structure)
    api_success([
        'tickets' => $tickets,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ],
        'filters' => [
            'status' => $status,
            'category' => $category,
            'urgency' => $urgency,
            'assigned_to' => $assignedTo,
            'created_by' => $createdBy,
            'search' => $search
        ],
        'sort' => [
            'by' => $sortBy,
            'order' => $sortOrder
        ],
        'status_counts' => $statusCounts
    ], 'Tickets retrieved successfully');

} catch (Exception $e) {
    error_log("Ticket list error: " . $e->getMessage());
    api_error('Errore nel recupero dei ticket: ' . $e->getMessage(), 500);
}
