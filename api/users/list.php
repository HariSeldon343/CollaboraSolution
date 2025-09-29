<?php
/**
 * API Endpoint: List Users
 * Retrieves paginated list of users with search and filtering capabilities
 */

// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Get current user info from session
    $currentUserId = $_SESSION['user_id'];
    // Check both possible session keys for role
    $currentUserRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user';
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    // Debug logging
    error_log('List API - User ID: ' . $currentUserId . ', Role: ' . $currentUserRole . ', Tenant: ' . $tenant_id);

    // CSRF validation for security - check multiple header formats
    $headers = getallheaders();
    $csrfToken = null;

    // Check various header formats (case-insensitive)
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-csrf-token') {
            $csrfToken = $value;
            break;
        }
    }

    // Fallback to $_SERVER if not found
    if (empty($csrfToken)) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    // Debug logging
    error_log('List API - CSRF Token received: ' . substr($csrfToken, 0, 10) . '...');
    error_log('List API - Session CSRF Token: ' . substr($_SESSION['csrf_token'] ?? '', 0, 10) . '...');

    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido', 'debug' => 'CSRF mismatch']));
    }

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