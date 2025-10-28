<?php
/**
 * API endpoint to list managers and admins
 * Returns users with role: manager, admin, or super_admin
 */

// Initialize API environment
require_once '../../includes/api_auth.php';
initializeApiEnvironment();

// Force no-cache headers to prevent 403 stale errors (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Verify authentication
verifyApiAuthentication();
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

// Check if user is manager, admin or super_admin (BUG-040 FIX)
if (!in_array($userInfo['role'], ['manager', 'admin', 'super_admin'])) {
    api_error('Accesso non autorizzato', 403);
}

try {
    // Get database connection
    require_once '../../includes/db.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Query to get managers, admins and super_admins
    // These are the users who can manage companies
    $query = "
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            t.name as tenant_name
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        WHERE u.role IN ('manager', 'admin', 'super_admin')
            AND u.deleted_at IS NULL
        ORDER BY u.role DESC, u.name ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();

    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $formattedManagers = array_map(function($manager) {
        return [
            'id' => $manager['id'],
            'name' => $manager['name'],
            'email' => $manager['email'],
            'role' => $manager['role'],
            'tenant_name' => $manager['tenant_name'] ?? 'N/A',
            'display_name' => sprintf(
                '%s (%s)',
                $manager['name'],
                ucfirst(str_replace('_', ' ', $manager['role']))
            )
        ];
    }, $managers);

    // BUG-040 FIX: Wrap in 'users' key for frontend compatibility (data.data.users)
    api_success(['users' => $formattedManagers], 'Lista manager caricata con successo');

} catch (Exception $e) {
    error_log('Error in list_managers.php: ' . $e->getMessage());
    api_error('Si è verificato un errore durante il recupero dei manager', 500);
}