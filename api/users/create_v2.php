<?php
session_start();
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

    // Check permissions - only super_admin and admin can create users
    if (!in_array($current_user_role, ['super_admin', 'admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non autorizzato a creare utenti']));
    }

    // Input sanitization
    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF token validation
    $csrf_token = $input['csrf_token'] ?? '';
    $auth = new Auth();
    if (!$auth->verifyCSRFToken($csrf_token)) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Extract and validate input
    $first_name = filter_var(trim($input['first_name'] ?? ''), FILTER_SANITIZE_STRING);
    $last_name = filter_var(trim($input['last_name'] ?? ''), FILTER_SANITIZE_STRING);
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    $role = filter_var(trim($input['role'] ?? 'user'), FILTER_SANITIZE_STRING);
    $single_tenant_id = isset($input['tenant_id']) ? intval($input['tenant_id']) : null;
    $tenant_ids = $input['tenant_ids'] ?? [];

    // Validation
    $errors = [];
    if (empty($first_name)) {
        $errors[] = 'Nome richiesto';
    }
    if (empty($last_name)) {
        $errors[] = 'Cognome richiesto';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }
    if (!in_array($role, ['super_admin', 'admin', 'manager', 'user'])) {
        $errors[] = 'Ruolo non valido';
    }

    // Role-specific permission checks
    if ($current_user_role === 'admin' && $role === 'super_admin') {
        $errors[] = 'Non puoi creare un super admin';
    }

    // Validate tenant assignment based on role
    if ($role === 'super_admin') {
        // Super admins don't need tenant assignment
        $single_tenant_id = null;
        $tenant_ids = [];
    } elseif ($role === 'admin') {
        // Admins need at least one company through junction table
        if (empty($tenant_ids) || !is_array($tenant_ids)) {
            $errors[] = 'Gli admin devono essere assegnati ad almeno una azienda';
        }
        $single_tenant_id = null; // Admins don't use single tenant_id
    } elseif ($role === 'manager' || $role === 'user') {
        // Managers and users need exactly one tenant
        if (empty($single_tenant_id)) {
            $errors[] = 'Manager e utenti devono essere assegnati a una azienda';
        }

        // If current user is admin, verify they have access to this tenant
        if ($current_user_role === 'admin') {
            $db = Database::getInstance();
            $check_access = $db->fetchOne(
                "SELECT 1 FROM user_companies WHERE user_id = :user_id AND company_id = :company_id",
                [':user_id' => $_SESSION['user_id'], ':company_id' => $single_tenant_id]
            );
            if (!$check_access) {
                $errors[] = 'Non hai accesso a questa azienda';
            }
        }

        $tenant_ids = []; // Clear any multi-tenant assignments
    }

    if (!empty($errors)) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Errori di validazione', 'errors' => $errors]));
    }

    $db = Database::getInstance();

    // Begin transaction
    $db->beginTransaction();

    try {
        // Check if email already exists
        $existing_user = $db->fetchOne(
            "SELECT id FROM users WHERE email = :email",
            [':email' => $email]
        );

        if ($existing_user) {
            throw new Exception('Email già registrata');
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Insert new user
        $user_data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password_hash' => $password_hash,
            'role' => $role,
            'tenant_id' => $single_tenant_id,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $new_user_id = $db->insert('users', $user_data);

        // If admin role, insert company assignments
        if ($role === 'admin' && !empty($tenant_ids)) {
            // First, create user_companies table if it doesn't exist
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

            // Insert company assignments
            foreach ($tenant_ids as $company_id) {
                $company_id = intval($company_id);

                // Verify tenant exists and is active
                $tenant_check = $db->fetchOne(
                    "SELECT id FROM tenants WHERE id = :id AND status = 'active'",
                    [':id' => $company_id]
                );

                if (!$tenant_check) {
                    throw new Exception("Azienda con ID $company_id non valida o non attiva");
                }

                // If current user is admin, verify they have access to this tenant
                if ($current_user_role === 'admin') {
                    $access_check = $db->fetchOne(
                        "SELECT 1 FROM user_companies WHERE user_id = :user_id AND company_id = :company_id",
                        [':user_id' => $_SESSION['user_id'], ':company_id' => $company_id]
                    );
                    if (!$access_check) {
                        throw new Exception("Non hai accesso all'azienda con ID $company_id");
                    }
                }

                $db->insert('user_companies', [
                    'user_id' => $new_user_id,
                    'company_id' => $company_id
                ]);
            }
        }

        // Log the activity
        $db->insert('activity_logs', [
            'tenant_id' => $tenant_id,
            'user_id' => $_SESSION['user_id'],
            'action' => 'user_create',
            'details' => "Creato nuovo utente: $email con ruolo $role",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => [
                'user_id' => $new_user_id,
                'message' => 'Utente creato con successo'
            ]
        ]);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    ob_clean();
    error_log('Create User V2 Error: ' . $e->getMessage());

    // Check for specific error types
    if (strpos($e->getMessage(), 'Email già registrata') !== false) {
        http_response_code(409);
        die(json_encode(['error' => 'Email già registrata']));
    } elseif (strpos($e->getMessage(), 'Non hai accesso') !== false) {
        http_response_code(403);
        die(json_encode(['error' => $e->getMessage()]));
    } else {
        http_response_code(500);
        die(json_encode(['error' => 'Errore nella creazione dell\'utente']));
    }
}

ob_end_flush();
?>