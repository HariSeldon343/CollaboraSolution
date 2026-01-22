<?php
/**
 * API per l'aggiornamento degli utenti
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/locations_municipalities.php';

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
    $name = htmlspecialchars(trim($input['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    $role = htmlspecialchars(trim($input['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    $home_city = isset($input['home_city']) ? trim((string)$input['home_city']) : null;
    $job_title = isset($input['job_title']) ? trim((string)$input['job_title']) : null;
    $skills_text = isset($input['skills_text']) ? trim((string)$input['skills_text']) : null;
    $certifications_text = isset($input['certifications_text']) ? trim((string)$input['certifications_text']) : null;
    $single_tenant_id = isset($input['tenant_id']) ? intval($input['tenant_id']) : null;
    $tenant_ids = $input['tenant_ids'] ?? [];
    $tenant_role_id = isset($input['tenant_role_id']) && $input['tenant_role_id'] !== '' ? (int)$input['tenant_role_id'] : null;
    $password_max_age_days = isset($input['password_max_age_days']) && $input['password_max_age_days'] !== ''
        ? (int)$input['password_max_age_days']
        : null;

    // Validation
    $errors = [];
    if ($user_id <= 0) {
        $errors[] = 'ID utente non valido';
    }
    if (empty($name)) {
        $errors[] = 'Nome completo richiesto';
    }
    if (strlen($name) < 2) {
        $errors[] = 'Il nome completo deve essere almeno 2 caratteri';
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
    if ($password_max_age_days !== null) {
        if ($password_max_age_days < 1 || $password_max_age_days > 3650) {
            $errors[] = 'Durata massima password non valida (1-3650 giorni)';
        }
    }

    if (!empty($errors)) {
        apiError('Errori di validazione', 400, ['errors' => $errors]);
    }

    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Optional: validate home_city against Italian municipalities dataset (if installed).
    // This avoids storing unusable cities for travel optimization defaults.
    try {
        $canStoreHomeCity = false;
        $homeCityNorm = ($home_city !== null) ? cnx_locations_normalize_city_input((string)$home_city) : '';
        if ($home_city !== null) {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'home_city'
                 LIMIT 1"
            );
            $canStoreHomeCity = ((int)($col['ok'] ?? 0) === 1);
        }
        if ($canStoreHomeCity && $home_city !== null && $homeCityNorm !== '') {
            if (!cnx_locations_city_exists($db, $homeCityNorm)) {
                apiError(
                    'Città di residenza non riconosciuta. Seleziona un comune italiano valido.',
                    400,
                    ['suggestions' => cnx_locations_city_suggestions($db, $homeCityNorm, 8)]
                );
            }
        }
    } catch (Throwable $e) {
        // non-blocking: if validation infra is unavailable, don't block user updates
    }

    // Get current user data (only if not deleted)
    $current_user = $db->fetchOne(
        "SELECT * FROM users WHERE id = :id AND deleted_at IS NULL",
        [':id' => $user_id]
    );

    if (!$current_user) {
        apiError('Utente non trovato o già eliminato', 404);
    }

    // Helper: detect optional deleted_at column on user_tenant_access (schema variants)
    $utaHasDeletedAt = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaHasDeletedAt = (bool)($col['ok'] ?? false);
    } catch (Exception $e) {
        $utaHasDeletedAt = false;
    }
    $utaNotDeletedSql = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";

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

        // Admin can only modify users in their tenants (via user_tenant_access)
        if ($current_user['role'] !== 'admin') {
            if ($current_user['tenant_id'] !== null) {
                $has_access = $db->fetchOne(
                    "SELECT 1
                     FROM user_tenant_access
                     WHERE user_id = :admin_id
                       AND tenant_id = :tenant_id{$utaNotDeletedSql}
                     LIMIT 1",
                    [':admin_id' => $_SESSION['user_id'], ':tenant_id' => $current_user['tenant_id']]
                );
                if (!$has_access) {
                    apiError('Non hai accesso a questo utente', 403);
                }
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
        // Keep a primary tenant_id on users record (first selected), consistent with create_simple.php
        $single_tenant_id = !empty($tenant_ids) ? (int)$tenant_ids[0] : null;
    } elseif ($role === 'manager' || $role === 'user') {
        // Managers and users need exactly one tenant
        if (empty($single_tenant_id)) {
            $errors[] = 'Manager e utenti devono essere assegnati a una azienda';
        }

        // If current user is admin, verify they have access to this tenant
        if ($current_user_role === 'admin') {
            $check_access = $db->fetchOne(
                "SELECT 1
                 FROM user_tenant_access
                 WHERE user_id = :user_id
                   AND tenant_id = :tenant_id{$utaNotDeletedSql}
                 LIMIT 1",
                [':user_id' => $_SESSION['user_id'], ':tenant_id' => $single_tenant_id]
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
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'tenant_id' => $single_tenant_id,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Optional users.home_city column (migration 54)
        if ($home_city !== null) {
            // Normalize empty to NULL; keep bounded length for DB column
            $hc = trim((string)$home_city);
            if (mb_strlen($hc, 'UTF-8') > 120) {
                $hc = mb_substr($hc, 0, 120, 'UTF-8');
            }
            try {
                $hasCol = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'home_city'
                     LIMIT 1"
                );
                if ((int)($hasCol['ok'] ?? 0) === 1) {
                    $update_data['home_city'] = ($hc !== '') ? $hc : null;
                }
            } catch (Exception $e) {
                // Non-blocking
            }
        }

        // Optional professional profile fields (migration 58)
        // job_title (VARCHAR)
        if ($job_title !== null) {
            $jt = trim((string)$job_title);
            if (mb_strlen($jt, 'UTF-8') > 160) {
                $jt = mb_substr($jt, 0, 160, 'UTF-8');
            }
            try {
                $hasCol = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'job_title'
                     LIMIT 1"
                );
                if ((int)($hasCol['ok'] ?? 0) === 1) {
                    $update_data['job_title'] = ($jt !== '') ? $jt : null;
                }
            } catch (Exception $e) {
                // Non-blocking
            }
        }
        // skills_text (TEXT)
        if ($skills_text !== null) {
            $st = trim((string)$skills_text);
            if (mb_strlen($st, 'UTF-8') > 8000) {
                $st = mb_substr($st, 0, 8000, 'UTF-8');
            }
            try {
                $hasCol = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'skills_text'
                     LIMIT 1"
                );
                if ((int)($hasCol['ok'] ?? 0) === 1) {
                    $update_data['skills_text'] = ($st !== '') ? $st : null;
                }
            } catch (Exception $e) {
                // Non-blocking
            }
        }
        // certifications_text (TEXT)
        if ($certifications_text !== null) {
            $ct = trim((string)$certifications_text);
            if (mb_strlen($ct, 'UTF-8') > 8000) {
                $ct = mb_substr($ct, 0, 8000, 'UTF-8');
            }
            try {
                $hasCol = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'certifications_text'
                     LIMIT 1"
                );
                if ((int)($hasCol['ok'] ?? 0) === 1) {
                    $update_data['certifications_text'] = ($ct !== '') ? $ct : null;
                }
            } catch (Exception $e) {
                // Non-blocking
            }
        }

        // Update per-user password max age if supported by schema
        if ($password_max_age_days !== null) {
            try {
                $hasCol = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'password_max_age_days'
                     LIMIT 1"
                );
                if ((int)($hasCol['ok'] ?? 0) === 1) {
                    $update_data['password_max_age_days'] = $password_max_age_days;
                }
            } catch (Exception $e) {
                // Non-blocking
            }
        }

        // Update password if provided
        if (!empty($password)) {
            // Use per-user max age if available; fallback to existing value or 90
            $effectiveMaxAge = 90;
            if ($password_max_age_days !== null) {
                $effectiveMaxAge = $password_max_age_days;
            } elseif (isset($current_user['password_max_age_days']) && (int)$current_user['password_max_age_days'] > 0) {
                $effectiveMaxAge = (int)$current_user['password_max_age_days'];
            }

            $update_data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $update_data['password_set_at'] = date('Y-m-d H:i:s');
            $update_data['password_expires_at'] = date('Y-m-d H:i:s', strtotime('+' . $effectiveMaxAge . ' days'));
            $update_data['password_reset_token'] = null;
            $update_data['password_reset_expires'] = null;
            $update_data['first_login'] = 0;
        }

        // If max age changes without password change, recompute expires_at from password_set_at if present
        if (empty($password) && $password_max_age_days !== null) {
            if (!empty($current_user['password_set_at'])) {
                $update_data['password_expires_at'] = date(
                    'Y-m-d H:i:s',
                    strtotime((string)$current_user['password_set_at'] . ' +' . (int)$password_max_age_days . ' days')
                );
            }
        }

        // Update user
        $db->update('users', $update_data, ['id' => $user_id]);

        // Handle role change effects on user_tenant_access table
        $old_role = $current_user['role'];
        $role_changed = ($old_role !== $role);

        // For admin role: rewrite user_tenant_access assignments to match tenant_ids
        if ($role === 'admin') {
            // If current user is admin, verify they have access to each tenant being assigned
            if ($current_user_role === 'admin') {
                foreach ((array)$tenant_ids as $tid) {
                    $tid = (int)$tid;
                    $access_check = $db->fetchOne(
                        "SELECT 1
                         FROM user_tenant_access
                         WHERE user_id = :uid AND tenant_id = :tid{$utaNotDeletedSql}
                         LIMIT 1",
                        [':uid' => $_SESSION['user_id'], ':tid' => $tid]
                    );
                    if (!$access_check) {
                        throw new Exception("Non hai accesso all'azienda con ID $tid");
                    }
                }
            }

            // Hard-delete existing access records and insert new ones
            $conn->prepare("DELETE FROM user_tenant_access WHERE user_id = ?")->execute([$user_id]);

            $ins = $conn->prepare("INSERT INTO user_tenant_access (user_id, tenant_id, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            $now = date('Y-m-d H:i:s');
            foreach ((array)$tenant_ids as $tid) {
                $tid = (int)$tid;
                // Verify tenant exists and active (if status column exists)
                $tenant_check = $db->fetchOne(
                    "SELECT id FROM tenants WHERE id = :id AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1",
                    [':id' => $tid]
                );
                if (!$tenant_check) {
                    throw new Exception("Azienda con ID $tid non valida");
                }
                $ins->execute([$user_id, $tid, $_SESSION['user_id'], $now]);
            }
        } else {
            // For non-admin roles, remove extra multi-tenant rows and keep (optional) single tenant access record if it exists.
            // (We don't enforce creation here to avoid unexpected behavior across environments.)
            if ($old_role === 'admin') {
                $conn->prepare("DELETE FROM user_tenant_access WHERE user_id = ?")->execute([$user_id]);
            }

            // For manager/user roles, ensure a user_tenant_access row exists for the assigned tenant and set tenant_role_id if provided
            if (in_array($role, ['manager', 'user'], true) && !empty($single_tenant_id)) {
                // Detect optional columns
                $utaHasGrantedBy = false;
                $utaHasTenantRoleId = false;
                try {
                    $row = $db->fetchAll(
                        "SELECT COLUMN_NAME
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'user_tenant_access'
                           AND COLUMN_NAME IN ('granted_by', 'tenant_role_id')"
                    );
                    $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $row);
                    $utaHasGrantedBy = in_array('granted_by', $names, true);
                    $utaHasTenantRoleId = in_array('tenant_role_id', $names, true);
                } catch (Exception $e) {
                    // ignore
                }

                // Hard-delete existing rows for this user to keep one canonical record
                $conn->prepare("DELETE FROM user_tenant_access WHERE user_id = ?")->execute([$user_id]);

                $cols = ['user_id', 'tenant_id', 'granted_at'];
                $vals = [$user_id, (int)$single_tenant_id, date('Y-m-d H:i:s')];
                if ($utaHasGrantedBy) {
                    $cols[] = 'granted_by';
                    $vals[] = (int)($_SESSION['user_id'] ?? 0);
                }
                if ($utaHasTenantRoleId) {
                    $cols[] = 'tenant_role_id';
                    $vals[] = ($tenant_role_id !== null && $tenant_role_id > 0) ? $tenant_role_id : null;
                }

                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO user_tenant_access (" . implode(',', $cols) . ") VALUES ($placeholders)";
                $stmtUta = $conn->prepare($sql);
                $stmtUta->execute($vals);
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