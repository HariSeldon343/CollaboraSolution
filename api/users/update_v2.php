<?php
/**
 * API per l'aggiornamento degli utenti
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';

// Inizializza l'ambiente API
initializeApiEnvironment();

try {
    // Verifica autenticazione
    verifyApiAuthentication();

    // Ottieni informazioni utente
    $userInfo = getApiUserInfo();
    $tenant_id = $userInfo['tenant_id'];
    $current_user_role = $userInfo['role'];

    // Verifica permessi - solo super_admin e admin possono modificare utenti
    requireApiRole('admin');

    // Input sanitization - supporta sia JSON che FormData
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Se non è JSON, prova a leggere da $_POST (FormData)
        $input = $_POST;
    }

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Extract and validate input
    $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
    $first_name = htmlspecialchars(trim($input['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $last_name = htmlspecialchars(trim($input['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    $role = htmlspecialchars(trim($input['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    $single_tenant_id = isset($input['tenant_id']) ? intval($input['tenant_id']) : null;
    $tenant_ids = $input['tenant_ids'] ?? [];

    // Validation
    $errors = [];
    if ($user_id <= 0) {
        $errors[] = 'ID utente non valido';
    }
    if (empty($first_name)) {
        $errors[] = 'Nome richiesto';
    }
    if (empty($last_name)) {
        $errors[] = 'Cognome richiesto';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida';
    }
    if (!empty($password) && strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }
    if (!in_array($role, ['super_admin', 'admin', 'manager', 'user'])) {
        $errors[] = 'Ruolo non valido';
    }

    if (!empty($errors)) {
        apiError('Errori di validazione', 400, ['errors' => $errors]);
    }

    $db = Database::getInstance();

    // Get current user data (only if not deleted)
    $current_user = $db->fetchOne(
        "SELECT * FROM users WHERE id = :id AND deleted_at IS NULL",
        [':id' => $user_id]
    );

    if (!$current_user) {
        apiError('Utente non trovato o già eliminato', 404);
    }

    // Role-specific permission checks
    if ($current_user_role === 'admin') {
        // Admin cannot modify super_admin users
        if ($current_user['role'] === 'super_admin') {
            apiError('Non puoi modificare un super admin', 403);
        }

        // Admin cannot promote to super_admin
        if ($role === 'super_admin') {
            apiError('Non puoi promuovere a super admin', 403);
        }

        // Admin can only modify users in their tenants
        if ($current_user['role'] !== 'admin') {
            // Check if admin has access to user's tenant
            $has_access = $db->fetchOne(
                "SELECT 1 FROM user_companies WHERE user_id = :admin_id AND company_id = :tenant_id",
                [':admin_id' => $_SESSION['user_id'], ':tenant_id' => $current_user['tenant_id']]
            );
            if (!$has_access && $current_user['tenant_id'] !== null) {
                apiError('Non hai accesso a questo utente', 403);
            }
        }
    }

    // Validate tenant assignment based on new role
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
        apiError('Errori di validazione', 400, ['errors' => $errors]);
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // Check if email already exists (excluding current user)
        $existing_user = $db->fetchOne(
            "SELECT id FROM users WHERE email = :email AND id != :id",
            [':email' => $email, ':id' => $user_id]
        );

        if ($existing_user) {
            throw new Exception('Email già utilizzata da un altro utente');
        }

        // Prepare update data
        $update_data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'role' => $role,
            'tenant_id' => $single_tenant_id,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Update password if provided
        if (!empty($password)) {
            $update_data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        // Update user
        $db->update('users', $update_data, ['id' => $user_id]);

        // Handle role change effects on user_companies table
        $old_role = $current_user['role'];
        $role_changed = ($old_role !== $role);

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

        if ($role_changed) {
            // If changing FROM admin role to another, clean up user_companies entries
            if ($old_role === 'admin' && $role !== 'admin') {
                $db->delete('user_companies', ['user_id' => $user_id]);
            }
            // If changing TO admin role from another
            elseif ($old_role !== 'admin' && $role === 'admin') {
                // No existing entries to clean up, will add new ones below
            }
            // If still admin but companies changed
            elseif ($role === 'admin') {
                // Delete existing and re-add
                $db->delete('user_companies', ['user_id' => $user_id]);
            }
        } else {
            // Role not changed but if admin, update companies
            if ($role === 'admin') {
                // Delete existing and re-add
                $db->delete('user_companies', ['user_id' => $user_id]);
            }
        }

        // Insert new company assignments for admin role
        if ($role === 'admin' && !empty($tenant_ids)) {
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
                    'user_id' => $user_id,
                    'company_id' => $company_id
                ]);
            }
        }

        // Log the activity
        $details = "Aggiornato utente: $email";
        if ($role_changed) {
            $details .= " (ruolo cambiato da $old_role a $role)";
        }

        $db->insert('activity_logs', [
            'tenant_id' => $tenant_id,
            'user_id' => $_SESSION['user_id'],
            'action' => 'user_update',
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        apiSuccess(['message' => 'Utente aggiornato con successo'], 'Utente aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('update_v2.php', $e);

    // Check for specific error types
    if (strpos($e->getMessage(), 'Email già utilizzata') !== false) {
        apiError('Email già utilizzata da un altro utente', 409);
    } elseif (strpos($e->getMessage(), 'Non hai accesso') !== false) {
        apiError($e->getMessage(), 403);
    } else {
        apiError('Errore nell\'aggiornamento dell\'utente', 500);
    }
}
?>