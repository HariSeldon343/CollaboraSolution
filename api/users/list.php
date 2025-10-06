<?php
/**
 * API Endpoint: List Users
 * Retrieves paginated list of users with search and filtering capabilities
 */

// Include centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info from session
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $currentUserRole = $userInfo['role'];
    $tenant_id = $userInfo['tenant_id'];

    // Debug logging
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log('List API - User ID: ' . $currentUserId . ', Role: ' . $currentUserRole . ', Tenant: ' . $tenant_id);
    }

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get query parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 10;
    $offset = ($page - 1) * $limit;

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Build query
    $whereConditions = [];
    $params = [];

    // CRITICAL: Only show non-deleted users (soft delete filter)
    $whereConditions[] = "u.deleted_at IS NULL";

    // Add tenant isolation (unless super admin viewing all)
    if ($currentUserRole !== 'super_admin' && $tenant_id) {
        $whereConditions[] = "u.tenant_id = :tenant_id";
        $params[':tenant_id'] = $tenant_id;
    }

    // Add search condition if provided
    if (!empty($search)) {
        $whereConditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
        $params[':search'] = "%$search%";
    }

    // Add role filter if provided (supports comma-separated roles)
    if (!empty($role)) {
        $roles = array_map('trim', explode(',', $role));
        $rolePlaceholders = [];
        foreach ($roles as $index => $r) {
            $key = ':role' . $index;
            $rolePlaceholders[] = $key;
            $params[$key] = $r;
        }
        $whereConditions[] = "u.role IN (" . implode(',', $rolePlaceholders) . ")";
    }

    // Build WHERE clause
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Count total users for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        $whereClause
    ";

    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalUsers / $limit);

    // Get users with pagination
    $query = "
        SELECT
            u.id,
            u.tenant_id,
            u.email,
            u.name,
            u.role,
            u.is_active,
            u.created_at,
            t.name as tenant_name,
            t.code as tenant_code
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        $whereClause
        ORDER BY u.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format users data
    $formattedUsers = [];
    foreach ($users as $user) {
        $formattedUsers[] = [
            'id' => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'is_active' => (bool)$user['is_active'],
            'status' => $user['is_active'] ? 'active' : 'inactive',
            'created_at' => $user['created_at'],
            'tenant_name' => $user['tenant_name'] ?? '',
            'tenant_code' => $user['tenant_code'] ?? ''
        ];
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $formattedUsers,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_users' => (int)$totalUsers
        ],
        'message' => 'Utenti recuperati con successo'
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('User List PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('User List Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}