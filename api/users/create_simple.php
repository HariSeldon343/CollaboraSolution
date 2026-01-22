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
    require_once __DIR__ . '/../../includes/locations_municipalities.php';
    require_once __DIR__ . '/../../includes/EmailSender.php';
    require_once __DIR__ . '/../../includes/mailer.php';
    require_once __DIR__ . '/../../includes/email_layout.php';
    require_once __DIR__ . '/../../includes/email_template_renderer.php';
    require_once __DIR__ . '/../../includes/user_docs.php';

    // 2. Sessione già gestita da session_init.php (incluso all'inizio)
    // Non è necessario fare nulla qui, la sessione è già attiva

    // 3. Verifica autenticazione
    if (!isset($_SESSION['user_id'])) {
        jsonOut(['success' => false, 'error' => 'Non autenticato'], 401);
    }

    // 4. Verifica ruolo
    $userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user';
    if (!in_array($userRole, ['admin', 'super_admin', 'manager'], true)) {
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
    $required = ['name', 'email', 'role'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonOut(['success' => false, 'error' => "Campo obbligatorio: $field"], 400);
        }
    }

    // 9. Sanitizza input base
    $name = trim($input['name']);
    $email = trim(strtolower($input['email']));
    $role = $input['role'];
    $home_city = isset($input['home_city']) ? trim((string)$input['home_city']) : '';
    $job_title = isset($input['job_title']) ? trim((string)$input['job_title']) : '';
    $skills_text = isset($input['skills_text']) ? trim((string)$input['skills_text']) : '';
    $certifications_text = isset($input['certifications_text']) ? trim((string)$input['certifications_text']) : '';
    $tenant_role_id = isset($input['tenant_role_id']) && $input['tenant_role_id'] !== '' ? (int)$input['tenant_role_id'] : null;
    $password_max_age_days = isset($input['password_max_age_days']) && $input['password_max_age_days'] !== '' ? (int)$input['password_max_age_days'] : null;

    if ($password_max_age_days !== null && ($password_max_age_days < 1 || $password_max_age_days > 3650)) {
        jsonOut(['success' => false, 'error' => 'Durata massima password non valida (1-3650 giorni)'], 400);
    }

    // Validazione lunghezza nome
    if (strlen($name) < 2) {
        jsonOut(['success' => false, 'error' => 'Il nome completo deve essere almeno 2 caratteri'], 400);
    }

    // 10. Gestione tenant in base al ruolo
    $tenantIds = [];

    // Manager security: can only create users in their own tenant, with role=user
    $sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if ($userRole === 'manager') {
        if ($sessionTenantId <= 0) {
            jsonOut(['success' => false, 'error' => 'Tenant di sessione non valido'], 403);
        }
        if (!in_array($role, ['user', 'manager'], true)) {
            jsonOut(['success' => false, 'error' => 'Un manager può creare solo utenti o manager (role=user|manager)'], 403);
        }
        // Force tenant_id to manager's tenant regardless of input
        $input['tenant_id'] = $sessionTenantId;
        unset($input['tenant_ids'], $_POST['tenant_ids']);
    }

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
    // IMPORTANT: users.email è UNIQUE. Su installazioni con FK / storico, fare hard-delete può fallire.
    // Quindi: se l'utente esiste ma è soft-deleted, lo RIPRISTINIAMO (restore) in modo idempotente.
    $stmt = $conn->prepare("SELECT id, deleted_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $restoreUserId = 0;
    if ($existingUser) {
        if ($existingUser['deleted_at'] === null) {
            jsonOut(['success' => false, 'error' => 'Email già esistente'], 409);
        } else {
            $restoreUserId = (int)($existingUser['id'] ?? 0);
            if ($restoreUserId <= 0) {
                jsonOut(['success' => false, 'error' => 'Record utente esistente non valido'], 500);
            }
            error_log("Email $email found in deleted user ID " . $restoreUserId . " - will RESTORE user instead of hard delete");
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

    // Validate tenant_role_id assignment (only for manager/user roles) with per-tenant permission rules
    if ($tenant_role_id !== null && $tenant_role_id > 0 && in_array($role, ['manager', 'user'], true)) {
        // Must have tenant_roles table
        $trTable = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenant_roles'
             LIMIT 1"
        );
        if (!$trTable || (int)($trTable['ok'] ?? 0) !== 1) {
            jsonOut(['success' => false, 'error' => 'Ruoli aziendali non disponibili su questa installazione'], 503);
        }

        // Permission: super_admin always, otherwise check tenants.tenant_role_assignment_roles
        $canAssign = ($userRole === 'super_admin');
        if (!$canAssign) {
            $tenantMeta = $db->fetchOne("SELECT tenant_role_assignment_roles FROM tenants WHERE id = ?", [$defaultTenantId]);
            $raw = $tenantMeta['tenant_role_assignment_roles'] ?? null;
            $allowed = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $allowed = array_map('strval', $decoded);
                }
            }
            $canAssign = in_array((string)$userRole, $allowed, true);
        }
        if (!$canAssign) {
            jsonOut(['success' => false, 'error' => 'Non hai i permessi per assegnare ruoli aziendali in questo tenant'], 403);
        }

        // Validate role exists in tenant
        $tr = $db->fetchOne(
            "SELECT id
             FROM tenant_roles
             WHERE id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL
               AND is_active = 1
             LIMIT 1",
            [$tenant_role_id, $defaultTenantId]
        );
        if (!$tr) {
            jsonOut(['success' => false, 'error' => 'Ruolo aziendale non valido per questo tenant'], 400);
        }
    } else {
        // Normalize invalid values
        if ($tenant_role_id !== null && $tenant_role_id <= 0) {
            $tenant_role_id = null;
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
        // 18. Inserimento utente (o restore se già esistente soft-deleted)
        $now = date('Y-m-d H:i:s');

        // Detect optional users.password_max_age_days column (migration 20)
        $hasMaxAgeCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'password_max_age_days'
                 LIMIT 1"
            );
            $hasMaxAgeCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasMaxAgeCol = false;
        }

        // Detect optional users.home_city column (migration 54)
        $hasHomeCityCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'home_city'
                 LIMIT 1"
            );
            $hasHomeCityCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasHomeCityCol = false;
        }

        // Detect optional professional profile columns (migration 58)
        $hasJobTitleCol = false;
        $hasSkillsTextCol = false;
        $hasCertificationsTextCol = false;
        try {
            $rows = $db->fetchAll(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME IN ('job_title','skills_text','certifications_text')"
            ) ?: [];
            $names = array_map(static fn($r) => (string)($r['COLUMN_NAME'] ?? ''), $rows);
            $hasJobTitleCol = in_array('job_title', $names, true);
            $hasSkillsTextCol = in_array('skills_text', $names, true);
            $hasCertificationsTextCol = in_array('certifications_text', $names, true);
        } catch (Exception $e) {
            $hasJobTitleCol = false;
            $hasSkillsTextCol = false;
            $hasCertificationsTextCol = false;
        }

        // Normalize home_city input (optional)
        $home_city_norm = trim((string)$home_city);
        if (mb_strlen($home_city_norm, 'UTF-8') > 120) {
            $home_city_norm = mb_substr($home_city_norm, 0, 120, 'UTF-8');
        }

        // Normalize professional profile inputs (optional)
        $job_title_norm = trim((string)$job_title);
        if (mb_strlen($job_title_norm, 'UTF-8') > 160) {
            $job_title_norm = mb_substr($job_title_norm, 0, 160, 'UTF-8');
        }
        $skills_text_norm = trim((string)$skills_text);
        if (mb_strlen($skills_text_norm, 'UTF-8') > 8000) {
            $skills_text_norm = mb_substr($skills_text_norm, 0, 8000, 'UTF-8');
        }
        $certifications_text_norm = trim((string)$certifications_text);
        if (mb_strlen($certifications_text_norm, 'UTF-8') > 8000) {
            $certifications_text_norm = mb_substr($certifications_text_norm, 0, 8000, 'UTF-8');
        }

        // Validate home_city (if provided and column exists) against Italian municipalities dataset (if installed).
        // Prevent storing unusable city names for travel optimization defaults.
        if ($hasHomeCityCol && $home_city_norm !== '') {
            $hcNorm = cnx_locations_normalize_city_input($home_city_norm);
            if ($hcNorm !== '' && !cnx_locations_city_exists($db, $hcNorm)) {
                jsonOut([
                    'success' => false,
                    'error' => 'Città di residenza non riconosciuta. Seleziona un comune italiano valido.',
                    'suggestions' => cnx_locations_city_suggestions($db, $hcNorm, 8),
                ], 400);
            }
            $home_city_norm = $hcNorm;
        }

        // Detect optional users.updated_at / users.deleted_at (restore path)
        $hasUpdatedAtCol = false;
        $hasDeletedAtCol = false;
        try {
            $rows = $db->fetchAll(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME IN ('updated_at', 'deleted_at')"
            );
            $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $rows);
            $hasUpdatedAtCol = in_array('updated_at', $names, true);
            $hasDeletedAtCol = in_array('deleted_at', $names, true);
        } catch (Exception $e) {
            // ignore
        }

        if ($restoreUserId > 0) {
            // RESTORE existing user
            $setCols = [
                'tenant_id' => $defaultTenantId,
                'name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash,
                'password_reset_token' => $resetToken,
                'password_reset_expires' => $tokenExpiry,
                'first_login' => 1,
                'role' => $role,
                'is_active' => 1,
            ];
            if ($hasHomeCityCol) {
                $setCols['home_city'] = ($home_city_norm !== '' ? $home_city_norm : null);
            }
            if ($hasJobTitleCol) {
                $setCols['job_title'] = ($job_title_norm !== '' ? $job_title_norm : null);
            }
            if ($hasSkillsTextCol) {
                $setCols['skills_text'] = ($skills_text_norm !== '' ? $skills_text_norm : null);
            }
            if ($hasCertificationsTextCol) {
                $setCols['certifications_text'] = ($certifications_text_norm !== '' ? $certifications_text_norm : null);
            }
            if ($hasMaxAgeCol) {
                $setCols['password_max_age_days'] = ($password_max_age_days !== null && $password_max_age_days > 0) ? $password_max_age_days : 90;
            }
            if ($hasDeletedAtCol) {
                $setCols['deleted_at'] = null;
            }
            if ($hasUpdatedAtCol) {
                $setCols['updated_at'] = $now;
            }

            // Build UPDATE
            $pairs = [];
            $vals = [];
            foreach ($setCols as $k => $v) {
                if ($v === null) {
                    $pairs[] = "$k = NULL";
                } else {
                    $pairs[] = "$k = ?";
                    $vals[] = $v;
                }
            }
            $vals[] = $restoreUserId;

            $sql = "UPDATE users SET " . implode(', ', $pairs) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $ok = $stmt->execute($vals);
            if (!$ok) {
                throw new Exception('Errore durante il ripristino utente');
            }
            $userId = (string)$restoreUserId;

            // Restore any existing user_tenant_access rows (soft-deleted) so access comes back immediately.
            // NOTE: uk_user_tenant is unique even when deleted_at is set, so INSERT IGNORE alone is not enough.
            try {
                $conn->prepare("UPDATE user_tenant_access SET deleted_at = NULL WHERE user_id = ?")->execute([(int)$restoreUserId]);
            } catch (Exception $e) {
                // best-effort; do not block restore
                error_log("WARN: failed to restore user_tenant_access rows for user_id={$restoreUserId}: " . $e->getMessage());
            }
        } else {
            // INSERT new user
            $cols = [
                'tenant_id', 'name', 'email', 'password_hash',
                'password_reset_token', 'password_reset_expires',
                'first_login', 'role', 'is_active', 'created_at'
            ];
            $vals = [
                $defaultTenantId, $name, $email, $passwordHash,
                $resetToken, $tokenExpiry,
                1, $role, 1, $now
            ];

            if ($hasHomeCityCol) {
                $cols[] = 'home_city';
                $vals[] = ($home_city_norm !== '' ? $home_city_norm : null);
            }
            if ($hasJobTitleCol) {
                $cols[] = 'job_title';
                $vals[] = ($job_title_norm !== '' ? $job_title_norm : null);
            }
            if ($hasSkillsTextCol) {
                $cols[] = 'skills_text';
                $vals[] = ($skills_text_norm !== '' ? $skills_text_norm : null);
            }
            if ($hasCertificationsTextCol) {
                $cols[] = 'certifications_text';
                $vals[] = ($certifications_text_norm !== '' ? $certifications_text_norm : null);
            }

            if ($hasMaxAgeCol) {
                $cols[] = 'password_max_age_days';
                $vals[] = ($password_max_age_days !== null && $password_max_age_days > 0) ? $password_max_age_days : 90;
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO users (" . implode(', ', $cols) . ") VALUES ($placeholders)";

            // Note: password_expires_at will be set when user sets their first password
            // in set_password.php (90 days from password setup date)

            $stmt = $conn->prepare($sql);
            $result = $stmt->execute($vals);

            if (!$result) {
                throw new Exception('Errore durante l\'inserimento nel database');
            }

            $userId = $conn->lastInsertId();
        }

        // Detect optional columns on user_tenant_access
        $utaHasGrantedBy = false;
        $utaHasTenantRoleId = false;
        try {
            $rows = $db->fetchAll(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_tenant_access'
                   AND COLUMN_NAME IN ('granted_by', 'tenant_role_id')"
            );
            $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $rows);
            $utaHasGrantedBy = in_array('granted_by', $names, true);
            $utaHasTenantRoleId = in_array('tenant_role_id', $names, true);
        } catch (Exception $e) {
            // ignore
        }

        // 19. Se è admin con più tenant, inserisci in user_tenant_access (idempotente: IGNORE dup)
        if ($role === 'admin' && count($tenantIds) > 0) {
            $cols = ['user_id', 'tenant_id', 'granted_at'];
            if ($utaHasGrantedBy) {
                $cols[] = 'granted_by';
            }
            if ($utaHasTenantRoleId) {
                $cols[] = 'tenant_role_id';
            }
            $accessSql = "INSERT IGNORE INTO user_tenant_access (" . implode(', ', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            $accessStmt = $conn->prepare($accessSql);

            foreach ($tenantIds as $tid) {
                $rowVals = [$userId, $tid, $now];
                if ($utaHasGrantedBy) {
                    $rowVals[] = (int)($_SESSION['user_id'] ?? 0);
                }
                if ($utaHasTenantRoleId) {
                    $rowVals[] = null;
                }
                $accessStmt->execute($rowVals);
            }
        }

        // For manager/user, ensure a single user_tenant_access row exists and store tenant_role_id if provided (idempotente)
        if (in_array($role, ['manager', 'user'], true) && !empty($defaultTenantId)) {
            $cols = ['user_id', 'tenant_id', 'granted_at'];
            if ($utaHasGrantedBy) {
                $cols[] = 'granted_by';
            }
            if ($utaHasTenantRoleId) {
                $cols[] = 'tenant_role_id';
            }
            $sqlUta = "INSERT IGNORE INTO user_tenant_access (" . implode(', ', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            $stmtUta = $conn->prepare($sqlUta);
            $rowVals = [$userId, (int)$defaultTenantId, $now];
            if ($utaHasGrantedBy) {
                $rowVals[] = (int)($_SESSION['user_id'] ?? 0);
            }
            if ($utaHasTenantRoleId) {
                $rowVals[] = ($tenant_role_id !== null && $tenant_role_id > 0) ? $tenant_role_id : null;
            }
            $stmtUta->execute($rowVals);
        }

        // 20. Se è super_admin, dagli accesso a tutti i tenant (idempotente)
        if ($role === 'super_admin') {
            // Recupera tutti i tenant attivi
            $tenantStmt = $conn->prepare("SELECT id FROM tenants");
            $tenantStmt->execute();
            $allTenants = $tenantStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($allTenants)) {
                $cols = ['user_id', 'tenant_id', 'granted_at'];
                if ($utaHasGrantedBy) {
                    $cols[] = 'granted_by';
                }
                if ($utaHasTenantRoleId) {
                    $cols[] = 'tenant_role_id';
                }
                $accessSql = "INSERT IGNORE INTO user_tenant_access (" . implode(', ', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
                $accessStmt = $conn->prepare($accessSql);

                foreach ($allTenants as $tid) {
                    $rowVals = [$userId, (int)$tid, $now];
                    if ($utaHasGrantedBy) {
                        $rowVals[] = (int)($_SESSION['user_id'] ?? 0);
                    }
                    if ($utaHasTenantRoleId) {
                        $rowVals[] = null;
                    }
                    $accessStmt->execute($rowVals);
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
        $docsEmailSent = false;
        $docsEmailError = null;
        $docsAttached = [];

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
            $emailSent = $emailSender->sendWelcomeEmail($email, $name, $resetToken, $tenantName);

            if (!$emailSent) {
                $emailError = 'Errore durante l\'invio dell\'email di benvenuto';
                error_log("Failed to send welcome email to: $email");
            }

        } catch (Exception $e) {
            $emailError = 'Errore EmailSender: ' . $e->getMessage();
            error_log("EmailSender exception: " . $e->getMessage());
        }

        // 24b. Seconda email: invio PDF onboarding + docs/*.pdf (best-effort; non blocca la creazione utente)
        try {
            $docs = cnx_list_user_doc_pdfs();
            $docsAttached = array_map(fn($d) => (string)($d['name'] ?? ''), $docs);
            if (!empty($docs)) {
                $templatePath = __DIR__ . '/../../includes/email_templates/users/user_docs_email.html';
                $tpl = file_exists($templatePath) ? (string)file_get_contents($templatePath) : '';
                if ($tpl !== '') {
                    $bodyInner = emailRenderTemplate($tpl, [
                        'USER_NAME' => $name,
                        'DOC_NAMES' => array_values(array_filter($docsAttached))
                    ]);

                    $htmlBody = renderEmailLayout(
                        'Documentazione Nexio (PDF)',
                        $bodyInner,
                        [
                            'BASE_URL' => defined('BASE_URL') ? BASE_URL : '',
                            'TENANT_NAME' => (string)$tenantName
                        ],
                        [
                            'preheader' => 'In allegato la documentazione PDF di Nexio.'
                        ]
                    );

                    $attachments = array_map(fn($d) => ['path' => (string)$d['path'], 'name' => (string)$d['name']], $docs);
                    $docsEmailSent = sendEmail(
                        $email,
                        'Nexio — Documentazione (PDF)',
                        $htmlBody,
                        '',
                        [
                            'attachments' => $attachments,
                            'context' => [
                                'action' => 'welcome_docs_email',
                                'tenant_id' => $_SESSION['tenant_id'] ?? null,
                                'user_id' => $_SESSION['user_id'] ?? null
                            ]
                        ]
                    );

                    if (!$docsEmailSent) {
                        $docsEmailError = 'Invio email PDF fallito';
                        error_log("Failed to send docs PDFs email to: $email");
                    }
                } else {
                    $docsEmailError = 'Template PDF email mancante';
                }
            }
        } catch (Exception $e) {
            $docsEmailError = 'Errore invio PDF: ' . $e->getMessage();
            error_log("Docs PDF email exception: " . $e->getMessage());
        }

        // 25. Risposta successo
        $response = [
            'success' => true,
            'message' => 'Utente creato con successo',
            'data' => [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'tenant_ids' => $tenantIds,
                'reset_link' => $resetLink,
                'email_sent' => $emailSent, // Spostato dentro data per consistenza
                'docs_email_sent' => $docsEmailSent,
                'docs_count' => is_array($docsAttached) ? count(array_filter($docsAttached)) : 0
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

        if (!$docsEmailSent && $docsEmailError) {
            // Non bloccare: aggiungi solo warning informativo
            $response['docs_warning'] = $docsEmailError;
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