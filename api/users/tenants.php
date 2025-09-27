<?php
/**
 * API Endpoint: Get Tenants
 * Retrieves list of available tenants/companies
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
    require_once '../../includes/auth.php';

    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Get current user role
    $currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    // CSRF validation
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Build query based on user role
    if ($currentUserRole === 'super_admin') {
        // Super admin can see all tenants
        $query = "
            SELECT
                id,
                name,
                code,
                status,
                plan_type,
                (SELECT COUNT(*) FROM users WHERE tenant_id = tenants.id) as current_users
            FROM tenants
            WHERE status IN ('active', 'trial')
            ORDER BY name ASC
        ";
        $params = [];
    } else {
        // Regular users only see their tenant
        $query = "
            SELECT
                id,
                name,
                code,
                status,
                plan_type,
                (SELECT COUNT(*) FROM users WHERE tenant_id = tenants.id) as current_users
            FROM tenants
            WHERE id = :tenant_id
            AND status IN ('active', 'trial')
        ";
        $params = [':tenant_id' => $tenant_id];
    }

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format tenants data
    $formattedTenants = [];
    foreach ($tenants as $tenant) {
        // Default max users based on plan type
        $maxUsers = 10; // Default
        if ($tenant['plan_type'] === 'premium') {
            $maxUsers = 50;
        } elseif ($tenant['plan_type'] === 'enterprise') {
            $maxUsers = 999;
        }

        $formattedTenants[] = [
            'id' => (int)$tenant['id'],
            'name' => $tenant['name'],
            'code' => $tenant['code'],
            'status' => $tenant['status'],
            'plan_type' => $tenant['plan_type'],
            'max_users' => $maxUsers,
            'current_users' => (int)$tenant['current_users'],
            'can_add_users' => ((int)$tenant['current_users'] < $maxUsers)
        ];
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'data' => $formattedTenants,
        'message' => 'Tenant recuperati con successo'
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Get Tenants PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Get Tenants Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}