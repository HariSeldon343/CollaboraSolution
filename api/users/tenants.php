<?php
/**
 * API Endpoint: Get Tenants
 * Retrieves list of available tenants/companies
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

    // Get current user info
    $userInfo = getApiUserInfo();
    $currentUserRole = $userInfo['role'];
    $tenant_id = $userInfo['tenant_id'];

    // Verify CSRF token
    verifyApiCsrfToken();

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