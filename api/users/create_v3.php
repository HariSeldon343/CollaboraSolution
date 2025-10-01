<?php
/**
 * API per la creazione utenti - Versione semplificata e sicura
 * Risolve il problema dell'errore 500
 */

// Disabilita completamente l'output degli errori
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Pulisce qualsiasi output precedente
if (ob_get_level()) {
    ob_end_clean();
}

// Avvia output buffering pulito
ob_start();

// Headers JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Funzione per output JSON sicuro
function jsonResponse($data, $httpCode = 200) {
    // Pulisce tutto l'output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Imposta il codice HTTP
    http_response_code($httpCode);

    // Headers JSON (ripeti per sicurezza)
    header('Content-Type: application/json; charset=utf-8');

    // Output JSON e termina
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Include necessari
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';

    // Gestione sessione
    if (session_status() === PHP_SESSION_NONE) {
        // Configura la sessione prima di avviarla
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    // Verifica autenticazione
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Non autorizzato', 'success' => false], 401);
    }

    // Verifica ruolo
    $current_user_role = $_SESSION['role'] ?? '';
    if (!in_array($current_user_role, ['admin', 'super_admin'])) {
        jsonResponse(['error' => 'Permessi insufficienti', 'success' => false], 403);
    }

    // Verifica CSRF
    $csrf_provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    $csrf_session = $_SESSION['csrf_token'] ?? '';

    if (empty($csrf_provided) || empty($csrf_session) || $csrf_provided !== $csrf_session) {
        jsonResponse(['error' => 'Token CSRF non valido', 'success' => false], 403);
    }

    // Leggi input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Valida input richiesti
    $first_name = trim($input['first_name'] ?? '');
    $last_name = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $role = trim($input['role'] ?? 'user');
    $tenant_id = isset($input['tenant_id']) ? intval($input['tenant_id']) : null;
    $tenant_ids = $input['tenant_ids'] ?? [];

    // Validazione base
    if (empty($first_name) || empty($last_name)) {
        jsonResponse(['error' => 'Nome e cognome sono richiesti', 'success' => false], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email non valida', 'success' => false], 400);
    }

    if (!in_array($role, ['super_admin', 'admin', 'manager', 'user'])) {
        jsonResponse(['error' => 'Ruolo non valido', 'success' => false], 400);
    }

    // Validazione tenant in base al ruolo
    if ($role === 'super_admin') {
        $tenant_id = null;
        $tenant_ids = [];
    } elseif ($role === 'admin') {
        if (empty($tenant_ids) || !is_array($tenant_ids)) {
            jsonResponse(['error' => 'Gli admin devono essere assegnati ad almeno una azienda', 'success' => false], 400);
        }
        $tenant_id = null;
    } else {
        // Manager e user
        if (empty($tenant_id)) {
            jsonResponse(['error' => 'Manager e utenti devono essere assegnati a una azienda', 'success' => false], 400);
        }
        $tenant_ids = [];
    }

    // Database
    $db = Database::getInstance();

    // Verifica email duplicata
    $existing = $db->fetchOne(
        "SELECT id FROM users WHERE email = :email",
        [':email' => $email]
    );

    if ($existing) {
        jsonResponse(['error' => 'Email già registrata', 'success' => false], 409);
    }

    // Genera token per reset password
    $reset_token = bin2hex(random_bytes(32));
    $reset_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Inizia transazione
    $db->beginTransaction();

    try {
        // Prepara dati utente
        $user_data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password_hash' => null, // Nessuna password iniziale
            'role' => $role,
            'tenant_id' => $tenant_id,
            'status' => 'active',
            'password_reset_token' => $reset_token,
            'password_reset_expires' => $reset_expires,
            'first_login' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Inserisci utente
        $new_user_id = $db->insert('users', $user_data);

        if (!$new_user_id) {
            throw new Exception('Impossibile creare utente');
        }

        // Se admin, aggiungi le aziende
        if ($role === 'admin' && !empty($tenant_ids)) {
            // Crea tabella se non esiste (per sicurezza)
            $db->query("CREATE TABLE IF NOT EXISTS user_companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                company_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_company (user_id, company_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (company_id) REFERENCES tenants(id) ON DELETE CASCADE
            )");

            foreach ($tenant_ids as $company_id) {
                $company_id = intval($company_id);

                // Verifica che l'azienda esista
                $tenant_exists = $db->fetchOne(
                    "SELECT id FROM tenants WHERE id = :id AND status = 'active'",
                    [':id' => $company_id]
                );

                if (!$tenant_exists) {
                    throw new Exception("Azienda con ID $company_id non valida");
                }

                // Inserisci associazione
                $db->insert('user_companies', [
                    'user_id' => $new_user_id,
                    'company_id' => $company_id
                ]);
            }
        }

        // Log attività (crea tabella se non esiste)
        $db->query("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_user (tenant_id, user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        )");

        $db->insert('audit_logs', [
            'tenant_id' => $_SESSION['tenant_id'] ?? null,
            'user_id' => $_SESSION['user_id'],
            'action' => 'user_create',
            'details' => "Creato nuovo utente: $email con ruolo $role",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transazione
        $db->commit();

        // Tentativo invio email (non bloccante)
        $email_sent = false;
        $email_warning = '';

        // Verifica se EmailSender esiste
        $emailSenderPath = __DIR__ . '/../../includes/EmailSender.php';
        if (file_exists($emailSenderPath)) {
            try {
                require_once $emailSenderPath;
                if (class_exists('EmailSender')) {
                    $emailSender = new EmailSender();
                    $full_name = trim($first_name . ' ' . $last_name);

                    // Ottieni nome tenant
                    $tenant_name = '';
                    if ($tenant_id) {
                        $tenant_data = $db->fetchOne(
                            "SELECT name FROM tenants WHERE id = :id",
                            [':id' => $tenant_id]
                        );
                        $tenant_name = $tenant_data['name'] ?? '';
                    }

                    // Tenta invio email
                    if (method_exists($emailSender, 'sendWelcomeEmail')) {
                        $email_sent = @$emailSender->sendWelcomeEmail($email, $full_name, $reset_token, $tenant_name);
                    }
                }
            } catch (Exception $e) {
                // Ignora errori email
                error_log('Email send error: ' . $e->getMessage());
            }
        }

        if (!$email_sent) {
            $email_warning = 'Utente creato. Email di benvenuto non inviata (configurazione email mancante).';
        }

        // Risposta successo
        $response = [
            'success' => true,
            'user_id' => $new_user_id,
            'email_sent' => $email_sent,
            'message' => 'Utente creato con successo'
        ];

        if ($email_warning) {
            $response['warning'] = $email_warning;
            $response['reset_link'] = BASE_URL . '/set_password.php?token=' . urlencode($reset_token);
        }

        jsonResponse($response, 200);

    } catch (Exception $e) {
        $db->rollback();
        error_log('User creation error: ' . $e->getMessage());
        jsonResponse(['error' => 'Errore nella creazione: ' . $e->getMessage(), 'success' => false], 500);
    }

} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    jsonResponse(['error' => 'Errore interno del server', 'success' => false], 500);
}
?>