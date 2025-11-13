<?php
/**
 * Task List API Endpoint
 * GET /api/tasks/list.php
 *
 * Returns list of tasks with filtering, sorting, and pagination
 * Filters: status, priority, assigned_to, created_by, search, parent_id
 * Sort: due_date, priority, created_at, updated_at, title
 * Pagination: page, limit
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
    $priority = $_GET['priority'] ?? null;
    $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
    $createdBy = isset($_GET['created_by']) ? (int)$_GET['created_by'] : null;
    $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;
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
    $validSortFields = ['due_date', 'priority', 'created_at', 'updated_at', 'title', 'status'];
    if (!in_array($sortBy, $validSortFields)) {
        $sortBy = 'created_at';
    }

    // Build WHERE clause with tenant isolation
    $where = ['t.tenant_id = ?', 't.deleted_at IS NULL'];
    $params = [$userInfo['tenant_id']];

    // Apply filters
    if ($status) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }

    if ($priority) {
        $where[] = 't.priority = ?';
        $params[] = $priority;
    }

    if ($assignedTo !== null) {
        if ($assignedTo === 0) {
            // Show unassigned tasks
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

    if ($parentId !== null) {
        if ($parentId === 0) {
            // Show top-level tasks only
            $where[] = 't.parent_id IS NULL';
        } else {
            $where[] = 't.parent_id = ?';
            $params[] = $parentId;
        }
    }

    if ($search) {
        $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = implode(' AND ', $where);

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM tasks t WHERE $whereClause";
    $totalResult = $db->fetchOne($countSql, $params);
    $total = (int)$totalResult['total'];

    // Get tasks with assignee and creator info
    $sql = "
        SELECT
            t.*,
            u_assigned.name as assigned_to_name,
            u_assigned.email as assigned_to_email,
            u_creator.name as created_by_name,
            u_creator.email as created_by_email,
            CASE
                WHEN t.due_date IS NOT NULL AND t.due_date < NOW() AND t.status NOT IN ('done', 'cancelled')
                THEN 1 ELSE 0
            END as is_overdue,
            CASE
                WHEN t.due_date IS NOT NULL
                THEN DATEDIFF(t.due_date, NOW())
                ELSE NULL
            END as days_until_due
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
        LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
        WHERE $whereClause
        ORDER BY t.$sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $tasks = $db->fetchAll($sql, $params);

    // Add assignees list for each task (from task_assignments)
    foreach ($tasks as &$task) {
        $assignees = $db->fetchAll(
            "SELECT
                ta.id as assignment_id,
                ta.user_id,
                ta.assigned_at,
                ta.accepted_at,
                u.name,
                u.email
            FROM task_assignments ta
            JOIN users u ON ta.user_id = u.id AND u.deleted_at IS NULL
            WHERE ta.task_id = ? AND ta.tenant_id = ? AND ta.deleted_at IS NULL
            ORDER BY ta.assigned_at ASC",
            [$task['id'], $userInfo['tenant_id']]
        );

        $task['assignees'] = $assignees;
    }

    // Calculate pagination metadata
    $totalPages = ceil($total / $limit);

    // Return response
    api_success([
        'tasks' => $tasks,
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
            'priority' => $priority,
            'assigned_to' => $assignedTo,
            'created_by' => $createdBy,
            'parent_id' => $parentId,
            'search' => $search
        ],
        'sort' => [
            'by' => $sortBy,
            'order' => $sortOrder
        ]
    ], 'Tasks retrieved successfully');

} catch (Exception $e) {
    error_log("Task list error: " . $e->getMessage());
    api_error('Errore nel recupero dei task: ' . $e->getMessage(), 500);
}
