<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Start output buffering for clean error handling
ob_start();

try {
    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Tenant isolation
    $tenant_id = $_SESSION['tenant_id'];
    $current_user_role = $_SESSION['role'];

    // Check permissions - only super_admin and admin can manage users
    if (!in_array($current_user_role, ['super_admin', 'admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non autorizzato a gestire utenti']));
    }

    // Input sanitization - Get user_id from query params
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // CSRF token validation for GET requests (via header)
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $auth = new Auth();
    if (!$auth->verifyCSRFToken($csrf_token)) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Validation
    if ($user_id <= 0) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'ID utente non valido']));
    }

    $db = Database::getInstance();

    // First check if the user exists and get their role (only non-deleted users)
    $user = $db->fetchOne(
        "SELECT id, role, email, tenant_id FROM users WHERE id = :id AND deleted_at IS NULL",
        [':id' => $user_id]
    );

    if (!$user) {
        ob_clean();
        http_response_code(404);
        die(json_encode(['error' => 'Utente non trovato o eliminato']));
    }

    // If current user is admin, verify they have permission to view this user
    if ($current_user_role === 'admin') {
        // Admin cannot view super_admin users
        if ($user['role'] === 'super_admin') {
            ob_clean();
            http_response_code(403);
            die(json_encode(['error' => 'Non puoi visualizzare i dati di un super admin']));
        }

        // If the target user is an admin, check if they share at least one company
        if ($user['role'] === 'admin') {
            $shared_companies = $db->fetchOne(
                "SELECT COUNT(*) as count FROM user_companies uc1
                INNER JOIN user_companies uc2 ON uc1.company_id = uc2.company_id
                WHERE uc1.user_id = :current_user AND uc2.user_id = :target_user",
                [':current_user' => $_SESSION['user_id'], ':target_user' => $user_id]
            );

            if ($shared_companies['count'] == 0) {
                ob_clean();
                http_response_code(403);
                die(json_encode(['error' => 'Non hai accesso a questo utente']));
            }
        }
        // If the target user is not an admin, check if admin has access to their tenant
        else if ($user['tenant_id'] !== null) {
            $has_access = $db->fetchOne(
                "SELECT 1 FROM user_companies WHERE user_id = :admin_id AND company_id = :tenant_id",
                [':admin_id' => $_SESSION['user_id'], ':tenant_id' => $user['tenant_id']]
            );

            if (!$has_access) {
                ob_clean();
                http_response_code(403);
                die(json_encode(['error' => 'Non hai accesso a questo utente']));
            }
        }
    }

    // Create user_companies table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS user_companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_company (user_id, company_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES tenants(id) ON DELETE CASCADE
    )";
    $db->query($create_table);

    // Get user companies from junction table
    // Only admin users should have entries in user_companies table
    $companies = [];

    if ($user['role'] === 'admin') {
        $company_list = $db->fetchAll(
            "SELECT uc.company_id, t.name as company_name, t.status
            FROM user_companies uc
            INNER JOIN tenants t ON uc.company_id = t.id
            WHERE uc.user_id = :user_id
            ORDER BY t.name",
            [':user_id' => $user_id]
        );

        foreach ($company_list as $company) {
            $companies[] = [
                'id' => $company['company_id'],
                'name' => $company['company_name'],
                'status' => $company['status']
            ];
        }
    }

    // For super_admin, return all companies (they have access to all)
    if ($user['role'] === 'super_admin') {
        $all_companies = $db->fetchAll(
            "SELECT id, name, status FROM tenants ORDER BY name"
        );

        foreach ($all_companies as $company) {
            $companies[] = [
                'id' => $company['id'],
                'name' => $company['name'],
                'status' => $company['status'],
                'access_type' => 'super_admin_all'
            ];
        }
    }

    // For manager/user roles, return their assigned tenant
    if (in_array($user['role'], ['manager', 'user']) && $user['tenant_id']) {
        $tenant = $db->fetchOne(
            "SELECT id, name, status FROM tenants WHERE id = :id",
            [':id' => $user['tenant_id']]
        );

        if ($tenant) {
            $companies[] = [
                'id' => $tenant['id'],
                'name' => $tenant['name'],
                'status' => $tenant['status'],
                'access_type' => 'single_tenant'
            ];
        }
    }

    // If current user is admin, filter to only show companies they have access to
    if ($current_user_role === 'admin' && !empty($companies)) {
        $admin_companies = $db->fetchAll(
            "SELECT company_id FROM user_companies WHERE user_id = :user_id",
            [':user_id' => $_SESSION['user_id']]
        );

        $admin_company_ids = array_column($admin_companies, 'company_id');

        // Filter companies to only those the admin has access to
        $companies = array_filter($companies, function($company) use ($admin_company_ids) {
            return in_array($company['id'], $admin_company_ids);
        });

        // Reset array keys after filtering
        $companies = array_values($companies);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $user_id,
            'user_email' => $user['email'],
            'user_role' => $user['role'],
            'companies' => $companies,
            'total' => count($companies)
        ]
    ]);

} catch (Exception $e) {
    ob_clean();
    error_log('Get User Companies Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Errore nel recupero delle aziende']));
}

ob_end_flush();
?>