<?php
/**
 * API per la creazione di nuovi utenti
 * Supporta il sistema di prima password con invio email di benvenuto
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/EmailSender.php';

// Inizializza l'ambiente API (gestisce sessione, headers, output buffering)
initializeApiEnvironment();

try {
    // Verifica autenticazione
    verifyApiAuthentication();

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Ottieni informazioni utente
    $userInfo = getApiUserInfo();
    $tenant_id = $userInfo['tenant_id'];
    $current_user_role = $userInfo['role'];

    // Verifica permessi - solo super_admin e admin possono creare utenti
    requireApiRole('admin');

    // Input sanitization - supporta sia JSON che FormData
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Se non è JSON, prova a leggere da $_POST (FormData)
        $input = $_POST;
    }

    // Extract and validate input
    $first_name = htmlspecialchars(trim($input['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $last_name = htmlspecialchars(trim($input['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    // Password non più richiesta - verrà generato un token
    $role = htmlspecialchars(trim($input['role'] ?? 'user'), ENT_QUOTES, 'UTF-8');
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
    // Password non più validata - verrà impostata dall'utente
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
        apiError('Errori di validazione', 400, ['errors' => $errors]);
    }

    $db = Database::getInstance();

    // Debug logging
    error_log('[CREATE_USER] Starting user creation for email: ' . $email);
    error_log('[CREATE_USER] Role: ' . $role);
    error_log('[CREATE_USER] Current user role: ' . $current_user_role);

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

        // Genera token sicuro per il reset password
        $reset_token = EmailSender::generateSecureToken();
        $reset_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Insert new user con token per primo accesso
        $user_data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password_hash' => null, // Nessuna password iniziale
            'role' => $role,
            'tenant_id' => $single_tenant_id,
            'status' => 'active',
            'password_reset_token' => $reset_token,
            'password_reset_expires' => $reset_expires,
            'first_login' => 1,
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

        // Log the activity - usa audit_logs non activity_logs
        $db->insert('audit_logs', [
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

        // Ottieni il nome del tenant per l'email
        $tenant_name = '';
        if ($single_tenant_id) {
            $tenant_data = $db->fetchOne(
                "SELECT name FROM tenants WHERE id = :id",
                [':id' => $single_tenant_id]
            );
            if ($tenant_data) {
                $tenant_name = $tenant_data['name'];
            }
        } elseif ($role === 'admin' && !empty($tenant_ids)) {
            // Per gli admin, usa la prima azienda
            $first_tenant = $db->fetchOne(
                "SELECT name FROM tenants WHERE id = :id",
                [':id' => $tenant_ids[0]]
            );
            if ($first_tenant) {
                $tenant_name = $first_tenant['name'];
            }
        }

        // Invia email di benvenuto - gestione errori migliorata
        $email_sent = false;
        $email_error = '';

        // Su Windows/XAMPP l'invio email potrebbe non funzionare
        if (stripos(PHP_OS, 'WIN') !== false) {
            error_log('[CREATE_USER] Windows detected - Email might not work without proper SMTP configuration');
        }

        try {
            // Verifica che la classe EmailSender esista
            if (!class_exists('EmailSender')) {
                throw new Exception('Classe EmailSender non trovata');
            }

            $emailSender = new EmailSender();
            $full_name = trim($first_name . ' ' . $last_name);

            error_log('[CREATE_USER] Attempting to send welcome email to: ' . $email);
            $email_sent = $emailSender->sendWelcomeEmail($email, $full_name, $reset_token, $tenant_name);

            if ($email_sent) {
                // Aggiorna timestamp invio email
                $db->query(
                    "UPDATE users SET welcome_email_sent_at = NOW() WHERE id = :user_id",
                    [':user_id' => $new_user_id]
                );
                error_log('[CREATE_USER] Welcome email sent successfully');
            } else {
                $email_error = 'Utente creato ma email non inviata. L\'utente dovrà richiedere un nuovo link.';
                error_log('[CREATE_USER] Email send failed but user created');
            }
        } catch (Exception $e) {
            error_log('[CREATE_USER] Email sending exception: ' . $e->getMessage());
            $email_error = 'Utente creato. Email non configurata su questo server.';
            // Non fallire l'intera operazione per un errore email
        }

        // Prepara la risposta
        $responseData = [
            'user_id' => $new_user_id,
            'email_sent' => $email_sent,
            'message' => 'Utente creato con successo'
        ];

        if ($email_error) {
            $responseData['warning'] = $email_error;
            $responseData['reset_link'] = BASE_URL . '/set_password.php?token=' . urlencode($reset_token);
        } else {
            $responseData['message'] .= '. Email di benvenuto inviata.';
        }

        apiSuccess($responseData, $responseData['message']);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('create_v2.php', $e);

    // Check for specific error types
    if (strpos($e->getMessage(), 'Email già registrata') !== false) {
        apiError('Email già registrata', 409);
    } elseif (strpos($e->getMessage(), 'Non hai accesso') !== false) {
        apiError($e->getMessage(), 403);
    } else {
        apiError('Errore nella creazione dell\'utente', 500);
    }
}
?>