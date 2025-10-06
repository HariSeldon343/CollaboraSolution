<?php
/**
 * API Creazione Utenti - Versione Ultra Semplificata
 * Senza invio email, solo creazione in database
 */

// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../../includes/session_init.php';


// Disabilita output errori
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Pulizia output
while (ob_get_level()) ob_end_clean();
ob_start();

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Funzione output JSON - Garantisce sempre output JSON valido
function jsonOut($data, $code = 200) {
    // Pulisce completamente il buffer di output
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Imposta codice di risposta e headers
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // Codifica JSON e verifica validità
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        // Fallback se la codifica JSON fallisce
        $json = json_encode([
            'success' => false,
            'error' => 'Errore nella codifica JSON',
            'json_error' => json_last_error_msg()
        ]);
    }

    die($json);
}

try {
    // 1. Include base
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/EmailSender.php';

    // 2. Sessione già gestita da session_init.php (incluso all'inizio)
    // Non è necessario fare nulla qui, la sessione è già attiva

    // 3. Verifica autenticazione
    if (!isset($_SESSION['user_id'])) {
        jsonOut(['success' => false, 'error' => 'Non autenticato'], 401);
    }

    // 4. Verifica ruolo
    $userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user';
    if (!in_array($userRole, ['admin', 'super_admin'])) {
        jsonOut(['success' => false, 'error' => 'Permessi insufficienti'], 403);
    }

    // 5. Verifica metodo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(['success' => false, 'error' => 'Metodo non consentito'], 405);
    }

    // 6. CSRF Token (semplificato - accetta da varie fonti)
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
        jsonOut(['success' => false, 'error' => 'Token CSRF non valido', 'debug' => 'CSRF failed'], 403);
    }

    // 7. Leggi input
    $input = $_POST;
    if (empty($input)) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?? [];
    }

    // Debug: log degli input ricevuti
    error_log("Create user input received: " . json_encode($input));

    // 8. Validazione campi base richiesti
    $required = ['first_name', 'last_name', 'email', 'role'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonOut(['success' => false, 'error' => "Campo obbligatorio: $field"], 400);
        }
    }

    // 9. Sanitizza input base
    $firstName = trim($input['first_name']);
    $lastName = trim($input['last_name']);
    $email = trim(strtolower($input['email']));
    $role = $input['role'];

    // 10. Gestione tenant in base al ruolo
    $tenantIds = [];

    switch ($role) {
        case 'super_admin':
            // Super admin non ha bisogno di tenant_id
            // Verrà assegnato un tenant_id di default (1) solo per il record users
            $defaultTenantId = 1; // Tenant di default per super admin
            break;

        case 'admin':
            // Admin può avere più tenant (multi-tenant)
            if (isset($input['tenant_ids']) && is_array($input['tenant_ids'])) {
                $tenantIds = array_map('intval', $input['tenant_ids']);
            } elseif (!empty($_POST['tenant_ids'])) {
                // Gestione caso form multipart
                $tenantIds = array_map('intval', $_POST['tenant_ids']);
            }

            if (empty($tenantIds)) {
                jsonOut(['success' => false, 'error' => 'Seleziona almeno un\'azienda per l\'admin'], 400);
            }

            // Il primo tenant sarà quello principale nel record users
            $defaultTenantId = $tenantIds[0];
            break;

        case 'manager':
        case 'user':
            // Manager e User hanno un singolo tenant
            if (empty($input['tenant_id'])) {
                jsonOut(['success' => false, 'error' => "Campo obbligatorio: tenant_id per ruolo $role"], 400);
            }
            $defaultTenantId = (int)$input['tenant_id'];
            $tenantIds = [$defaultTenantId];
            break;

        default:
            jsonOut(['success' => false, 'error' => 'Ruolo non valido'], 400);
    }

    // 11. Valida email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonOut(['success' => false, 'error' => 'Email non valida'], 400);
    }

    // 12. Connessione DB
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // 13. Verifica email non esistente (controlla anche utenti eliminati per sicurezza)
    $stmt = $conn->prepare("SELECT id, deleted_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser) {
        if ($existingUser['deleted_at'] === null) {
            jsonOut(['success' => false, 'error' => 'Email già esistente'], 409);
        } else {
            // Email esiste ma utente eliminato - hard delete per permettere riutilizzo
            error_log("Email $email found in deleted user ID " . $existingUser['id'] . ", performing hard delete to allow reuse");

            $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL");
            $deleteStmt->execute([$existingUser['id']]);

            error_log("Deleted user ID " . $existingUser['id'] . " permanently to reuse email $email");
        }
    }

    // 14. Verifica che i tenant esistano (solo se non super_admin senza tenant)
    if (!empty($tenantIds)) {
        $placeholders = str_repeat('?,', count($tenantIds) - 1) . '?';
        $stmt = $conn->prepare("SELECT id FROM tenants WHERE id IN ($placeholders)");
        $stmt->execute($tenantIds);
        $foundTenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($foundTenants) !== count($tenantIds)) {
            jsonOut(['success' => false, 'error' => 'Uno o più tenant non trovati'], 404);
        }
    }

    // 15. Genera token password
    $resetToken = bin2hex(random_bytes(32));
    $tokenExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // 16. Password temporanea hashata
    $tempPassword = bin2hex(random_bytes(16));
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    // 17. Inizia transazione
    $conn->beginTransaction();

    try {
        // 18. Inserimento utente
        $fullName = $firstName . ' ' . $lastName;
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO users (
            tenant_id, name, email, password_hash,
            password_reset_token, password_reset_expires,
            first_login, role, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?)";

        // Note: password_expires_at will be set when user sets their first password
        // in set_password.php (90 days from password setup date)

        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([
            $defaultTenantId, $fullName, $email, $passwordHash,
            $resetToken, $tokenExpiry, $role, $now
        ]);

        if (!$result) {
            throw new Exception('Errore durante l\'inserimento nel database');
        }

        $userId = $conn->lastInsertId();

        // 19. Se è admin con più tenant, inserisci in user_tenant_access
        if ($role === 'admin' && count($tenantIds) > 0) {
            $accessSql = "INSERT INTO user_tenant_access (user_id, tenant_id, granted_at) VALUES (?, ?, ?)";
            $accessStmt = $conn->prepare($accessSql);

            foreach ($tenantIds as $tid) {
                $accessStmt->execute([$userId, $tid, $now]);
            }
        }

        // 20. Se è super_admin, dagli accesso a tutti i tenant
        if ($role === 'super_admin') {
            // Recupera tutti i tenant attivi
            $tenantStmt = $conn->prepare("SELECT id FROM tenants");
            $tenantStmt->execute();
            $allTenants = $tenantStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($allTenants)) {
                $accessSql = "INSERT INTO user_tenant_access (user_id, tenant_id, granted_at) VALUES (?, ?, ?)";
                $accessStmt = $conn->prepare($accessSql);

                foreach ($allTenants as $tid) {
                    $accessStmt->execute([$userId, $tid, $now]);
                }
            }
        }

        // 21. Commit transazione
        $conn->commit();

        // 22. Log audit (opzionale)
        try {
            $auditSql = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, created_at)
                         VALUES (?, ?, 'create', 'user', ?, NOW())";
            $auditStmt = $conn->prepare($auditSql);
            $auditStmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'] ?? $defaultTenantId, $userId]);
        } catch (Exception $e) {
            // Ignora errori audit log
            error_log("Audit log error: " . $e->getMessage());
        }

        // 23. Genera link per impostare password
        $resetLink = BASE_URL . '/set_password.php?token=' . $resetToken;

        // 24. Tentativo di invio email di benvenuto
        $emailSent = false;
        $emailError = null;

        try {
            // Ottieni il nome del tenant per l'email
            $tenantName = '';
            if ($defaultTenantId) {
                $tenantStmt = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
                $tenantStmt->execute([$defaultTenantId]);
                $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
                $tenantName = $tenant ? (' per ' . $tenant['name']) : '';
            }

            // Inizializza EmailSender con configurazione da database
            // Il costruttore ora carica automaticamente da database se non si passa config
            // Ma è meglio essere espliciti per chiarezza e performance (evita doppio require)
            require_once __DIR__ . '/../../includes/email_config.php';
            $emailConfig = getEmailConfigFromDatabase();
            $emailSender = new EmailSender($emailConfig);

            // Invia email di benvenuto
            $emailSent = $emailSender->sendWelcomeEmail($email, $fullName, $resetToken, $tenantName);

            if (!$emailSent) {
                $emailError = 'Errore durante l\'invio dell\'email di benvenuto';
                error_log("Failed to send welcome email to: $email");
            }

        } catch (Exception $e) {
            $emailError = 'Errore EmailSender: ' . $e->getMessage();
            error_log("EmailSender exception: " . $e->getMessage());
        }

        // 25. Risposta successo
        $response = [
            'success' => true,
            'message' => 'Utente creato con successo',
            'data' => [
                'id' => $userId,
                'name' => $fullName,
                'email' => $email,
                'role' => $role,
                'tenant_ids' => $tenantIds,
                'reset_link' => $resetLink,
                'email_sent' => $emailSent // Spostato dentro data per consistenza
            ]
        ];

        // Aggiungi informazioni sull'email
        if ($emailSent) {
            $response['info'] = 'Email di benvenuto inviata con successo.';
        } else {
            $response['warning'] = $emailError ?: 'Invio email fallito (possibile problema di configurazione SMTP su Windows/XAMPP)';
            $response['info'] = 'Utente creato ma email non inviata. Fornisci manualmente il link all\'utente.';
            $response['data']['manual_link_required'] = true;
        }

        jsonOut($response, 201);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log("Database error in create_simple.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => 'Errore database', 'debug' => DEBUG_MODE ? $e->getMessage() : null], 500);
} catch (Exception $e) {
    error_log("Error in create_simple.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => 'Errore server', 'debug' => DEBUG_MODE ? $e->getMessage() : null], 500);
}