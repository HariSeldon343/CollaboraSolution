<?php
/**
 * Audit Log List API Endpoint
 *
 * Returns paginated list of audit logs with advanced filtering
 *
 * Method: GET
 * Auth: Required (admin, super_admin)
 *
 * Query Parameters:
 * - page (int): Page number (default: 1)
 * - per_page (int): Items per page (default: 50, max: 200)
 * - date_from (datetime): Start date filter
 * - date_to (datetime): End date filter
 * - user_id (int): Filter by specific user
 * - tenant_id (int): Filter by tenant (super_admin only)
 * - action (string): Filter by action type
 * - entity_type (string): Filter by entity type
 * - severity (string): Filter by severity (info, warning, error, critical)
 * - sort (string): Sort column (default: created_at)
 * - order (string): Sort direction (default: DESC)
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "logs": [...],
 *     "pagination": {
 *       "current_page": 1,
 *       "per_page": 50,
 *       "total_pages": 10,
 *       "total_records": 500
 *     }
 *   }
 * }
 */

declare(strict_types=1);

// Load required dependencies
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// 1. Initialize API environment
initializeApiEnvironment();

// 2. IMMEDIATELY verify authentication (BEFORE any operations!)
verifyApiAuthentication();

// 3. Get user info
$userInfo = getApiUserInfo();

// 4. Role-based access control: Only admin and super_admin can view audit logs
if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
    api_error('Accesso negato. Solo admin e super_admin possono visualizzare gli audit log.', 403);
}

// 5. Get database instance
$db = Database::getInstance();

try {
    // Parse and validate query parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $per_page;

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
    $action = $_GET['action'] ?? null;
    $entity_type = $_GET['entity_type'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $sort = $_GET['sort'] ?? 'created_at';
    $order = strtoupper($_GET['order'] ?? 'DESC');

    // Validate sort order
    if (!in_array($order, ['ASC', 'DESC'])) {
        $order = 'DESC';
    }

    // Validate sort column (whitelist to prevent SQL injection)
    $allowed_sort_columns = ['created_at', 'action', 'severity', 'entity_type', 'user_id'];
    if (!in_array($sort, $allowed_sort_columns)) {
        $sort = 'created_at';
    }

    // Validate severity enum
    $valid_severities = ['info', 'warning', 'error', 'critical'];
    if ($severity && !in_array($severity, $valid_severities)) {
        api_error('Severity non valida. Valori permessi: info, warning, error, critical', 400);
    }

    // Build WHERE clause dynamically
    $where_conditions = ['al.deleted_at IS NULL']; // CRITICAL: Only active logs
    $params = [];

    // Tenant isolation (unless super_admin viewing all tenants)
    if ($userInfo['role'] === 'super_admin' && $tenant_id !== null) {
        // Super admin can filter by specific tenant
        $where_conditions[] = 'al.tenant_id = ?';
        $params[] = $tenant_id;
    } elseif ($userInfo['role'] !== 'super_admin') {
        // Regular admin: enforce tenant isolation
        $where_conditions[] = 'al.tenant_id = ?';
        $params[] = $userInfo['tenant_id'];
    }
    // If super_admin and tenant_id is NULL, show all tenants

    // Date range filters
    if ($date_from) {
        $where_conditions[] = 'al.created_at >= ?';
        $params[] = $date_from;
    }
    if ($date_to) {
        $where_conditions[] = 'al.created_at <= ?';
        $params[] = $date_to;
    }

    // User filter
    if ($user_id !== null) {
        $where_conditions[] = 'al.user_id = ?';
        $params[] = $user_id;
    }

    // Action filter
    if ($action) {
        $where_conditions[] = 'al.action = ?';
        $params[] = $action;
    }

    // Entity type filter
    if ($entity_type) {
        $where_conditions[] = 'al.entity_type = ?';
        $params[] = $entity_type;
    }

    // Severity filter
    if ($severity) {
        $where_conditions[] = 'al.severity = ?';
        $params[] = $severity;
    }

    // Combine WHERE conditions
    $where_clause = implode(' AND ', $where_conditions);

    // Count total records (for pagination)
    $count_query = "
        SELECT COUNT(*) as total
        FROM audit_logs al
        WHERE {$where_clause}
    ";

    $count_result = $db->fetchOne($count_query, $params);
    $total_records = (int)$count_result['total'];
    $total_pages = ceil($total_records / $per_page);

    // Fetch paginated logs with user and tenant details
    $logs_query = "
        SELECT
            al.id,
            al.tenant_id,
            al.user_id,
            al.action,
            al.entity_type,
            al.entity_id,
            al.description,
            al.old_values,
            al.new_values,
            al.ip_address,
            al.user_agent,
            al.metadata,
            al.severity,
            al.status,
            al.created_at,
            u.name as user_name,
            u.email as user_email,
            t.name as tenant_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN tenants t ON al.tenant_id = t.id
        WHERE {$where_clause}
        ORDER BY al.{$sort} {$order}
        LIMIT ? OFFSET ?
    ";

    // Add pagination params
    $fetch_params = array_merge($params, [$per_page, $offset]);
    $logs = $db->fetchAll($logs_query, $fetch_params);

    // Parse JSON fields for easier frontend consumption
    $formatted_logs = array_map(function($log) {
        return [
            'id' => (int)$log['id'],
            'tenant_id' => (int)$log['tenant_id'],
            'tenant_name' => $log['tenant_name'],
            'user_id' => $log['user_id'] ? (int)$log['user_id'] : null,
            'user_name' => $log['user_name'],
            'user_email' => $log['user_email'],
            'action' => $log['action'],
            'entity_type' => $log['entity_type'],
            'entity_id' => $log['entity_id'] ? (int)$log['entity_id'] : null,
            'description' => $log['description'],
            'old_values' => $log['old_values'] ? json_decode($log['old_values'], true) : null,
            'new_values' => $log['new_values'] ? json_decode($log['new_values'], true) : null,
            'ip_address' => $log['ip_address'],
            'user_agent' => $log['user_agent'],
            'metadata' => $log['metadata'] ? json_decode($log['metadata'], true) : null,
            'severity' => $log['severity'],
            'status' => $log['status'],
            'created_at' => $log['created_at']
        ];
    }, $logs);

    // Build response
    $response = [
        'logs' => $formatted_logs,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'has_next_page' => $page < $total_pages,
            'has_prev_page' => $page > 1
        ],
        'filters_applied' => [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'user_id' => $user_id,
            'tenant_id' => $tenant_id,
            'action' => $action,
            'entity_type' => $entity_type,
            'severity' => $severity
        ]
    ];

    // Success response
    api_success($response, 'Log recuperati con successo');

} catch (PDOException $e) {
    // Database error
    error_log('Audit Log List Error: ' . $e->getMessage());
    api_error('Errore durante il recupero dei log: ' . $e->getMessage(), 500);

} catch (Exception $e) {
    // Generic error
    error_log('Audit Log List Error: ' . $e->getMessage());
    api_error('Errore imprevisto: ' . $e->getMessage(), 500);
}
