<?php
/**
 * Change Password Page
 * Used when user password has expired (90-day policy)
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/password_expiry_helper.php';
require_once __DIR__ . '/includes/mailer.php';

$error = '';
$info = '';
$success = false;
$userId = $_GET['user_id'] ?? $_SESSION['user_id'] ?? null;
$userData = null;
$isExpired = false;
$maxAgeDays = 90;

// Must have user_id
if (!$userId) {
    header('Location: index.php');
    exit;
}

// Get user data
try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Try with password_max_age_days (migration 20); fallback if column doesn't exist yet.
    try {
        $query = "SELECT id, email, name, password_expires_at, password_max_age_days
                  FROM users
                  WHERE id = :user_id
                  AND is_active = 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $qe) {
        $query = "SELECT id, email, name, password_expires_at
                  FROM users
                  WHERE id = :user_id
                  AND is_active = 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$userData) {
        $error = 'Utente non trovato';
    } else {
        $expiresAt = $userData['password_expires_at'] ?? null;
        $isExpired = ($expiresAt !== null && $expiresAt !== '' && strtotime((string)$expiresAt) !== false && strtotime((string)$expiresAt) < time());
        $maxAgeDays = isset($userData['password_max_age_days']) ? (int)$userData['password_max_age_days'] : 90;
        if ($maxAgeDays <= 0) {
            $maxAgeDays = 90;
        }
    }
} catch (Exception $e) {
    error_log('Change password error: ' . $e->getMessage());
    $error = 'Si è verificato un errore. Riprova più tardi.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userData) {
    $action = (string)($_POST['action'] ?? 'change_password');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $expiryCode = $_POST['expiry_code'] ?? '';

    // Validazione
    $errors = [];

    if (empty($currentPassword)) {
        $errors[] = 'Inserisci la password attuale';
    }

    // Allow self-service resend of the 6-digit code (for expired passwords)
    if ($action === 'resend_code') {
        if (!$isExpired) {
            $errors[] = 'Il reinvio codice è disponibile solo quando la password è scaduta.';
        }

        // Verify current password before sending anything
        if (empty($errors)) {
            try {
                $verifyQuery = "SELECT password_hash, email, name, tenant_id, password_expires_at FROM users WHERE id = :user_id AND is_active = 1";
                $verifyStmt = $conn->prepare($verifyQuery);
                $verifyStmt->bindParam(':user_id', $userId);
                $verifyStmt->execute();
                $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);

                if (!$row || !password_verify($currentPassword, (string)($row['password_hash'] ?? ''))) {
                    $errors[] = 'Password attuale non corretta';
                } else {
                    // Ensure codes table exists (auto-create if missing; schema drift safe)
                    if (!cnx_ensure_password_expiry_codes_table($db)) {
                        $errors[] = 'Sistema codici scadenza non configurato (manca tabella password_expiry_codes).';
                    } else {
                        // Rate limit: prevent spamming (min 60 seconds between sends)
                        $recent = $db->fetchOne(
                            "SELECT id
                             FROM password_expiry_codes
                             WHERE user_id = ?
                               AND sent_at IS NOT NULL
                               AND sent_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
                             ORDER BY id DESC
                             LIMIT 1",
                            [(int)$userId]
                        );
                        if ($recent) {
                            $errors[] = 'Codice già inviato di recente. Attendi 60 secondi e riprova.';
                        } else {
                            $expiresAt = (string)($row['password_expires_at'] ?? '');
                            $codeExpiresAt = date('Y-m-d H:i:s', strtotime($expiresAt . ' +1 day'));
                            $created = cnx_create_password_expiry_code($db, (int)$userId, $codeExpiresAt, null);

                            $tenantName = '';
                            $tenantId = (int)($row['tenant_id'] ?? 0);
                            if ($tenantId > 0) {
                                $t = $db->fetchOne("SELECT name FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
                                $tenantName = (string)($t['name'] ?? '');
                            }

                            $ok = sendPasswordExpiryNoticeEmail(
                                (string)($row['email'] ?? ''),
                                (string)($row['name'] ?? ''),
                                $tenantName,
                                $expiresAt,
                                (string)$created['code'],
                                defined('BASE_URL') ? BASE_URL : null,
                                ['tenant_id' => $tenantId, 'user_id' => (int)$userId, 'action' => 'password_expiry_code_self_resend']
                            );

                            if (!$ok) {
                                $errors[] = 'Invio email fallito. Verifica configurazione SMTP o riprova più tardi.';
                            } else {
                                cnx_mark_password_expiry_code_sent($db, (int)$created['code_id'], null);
                                $info = 'Codice reinviato. Controlla la tua email (anche spam).';
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('Password expiry resend error: ' . $e->getMessage());
                $errors[] = 'Errore durante il reinvio del codice';
            }
        }

        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }

        // Stop here: do not proceed with password change on resend action
    } else {

    // When password is expired, require 6-digit code received by email (T-1 notice / resend)
    $verifiedCodeId = null;
    if ($isExpired) {
        // Ensure code table exists; auto-create if missing (schema drift safe)
        if (!cnx_ensure_password_expiry_codes_table($db)) {
            $errors[] = 'Sistema codici scadenza non configurato (manca tabella password_expiry_codes).';
        }

        if (empty($expiryCode)) {
            $errors[] = 'Inserisci il codice a 6 cifre ricevuto via email';
        } else {
            try {
                $verifiedCodeId = cnx_verify_password_expiry_code($db, (int)$userId, (string)$expiryCode);
                if ($verifiedCodeId === null) {
                    $errors[] = 'Codice non valido o scaduto';
                }
            } catch (Exception $e) {
                error_log('Password expiry code verify error: ' . $e->getMessage());
                $errors[] = 'Errore durante la verifica del codice';
            }
        }
    }

    if (strlen($newPassword) < 8) {
        $errors[] = 'La nuova password deve contenere almeno 8 caratteri';
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        $errors[] = 'La nuova password deve contenere almeno una lettera maiuscola';
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        $errors[] = 'La nuova password deve contenere almeno una lettera minuscola';
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        $errors[] = 'La nuova password deve contenere almeno un numero';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Le password non coincidono';
    }

    if (empty($errors)) {
        try {
            // Verify current password
            $verifyQuery = "SELECT password_hash FROM users WHERE id = :user_id";
            $verifyStmt = $conn->prepare($verifyQuery);
            $verifyStmt->bindParam(':user_id', $userId);
            $verifyStmt->execute();
            $currentHash = $verifyStmt->fetchColumn();

            if (!password_verify($currentPassword, $currentHash)) {
                $errors[] = 'Password attuale non corretta';
            } else {
                // Hash new password
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Calculate new expiration (per-user max age; default 90 days)
                $passwordExpiresAt = date('Y-m-d H:i:s', strtotime('+' . $maxAgeDays . ' days'));

                // Build update query based on available columns (avoid breaking older DBs)
                $hasPasswordSetAt = true;
                $hasPasswordExpiresAt = true;
                try {
                    $cols = $db->fetchAll(
                        "SELECT COLUMN_NAME
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'users'
                           AND COLUMN_NAME IN ('password_set_at', 'password_expires_at')"
                    );
                    $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $cols);
                    $hasPasswordSetAt = in_array('password_set_at', $names, true);
                    $hasPasswordExpiresAt = in_array('password_expires_at', $names, true);
                } catch (Exception $e) {
                    // default true
                }

                $setParts = ["password_hash = :password_hash"];
                if ($hasPasswordSetAt) {
                    $setParts[] = "password_set_at = NOW()";
                }
                if ($hasPasswordExpiresAt) {
                    $setParts[] = "password_expires_at = :password_expires_at";
                }

                $updateQuery = "UPDATE users SET " . implode(", ", $setParts) . " WHERE id = :user_id";

                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bindParam(':password_hash', $newPasswordHash);
                if (strpos($updateQuery, ':password_expires_at') !== false) {
                    $updateStmt->bindParam(':password_expires_at', $passwordExpiresAt);
                }
                $updateStmt->bindParam(':user_id', $userId);

                if ($updateStmt->execute()) {
                    $success = true;

                    // Mark code as used only after successful password change
                    if ($isExpired && $verifiedCodeId !== null) {
                        try {
                            cnx_mark_password_expiry_code_used($db, (int)$verifiedCodeId);
                        } catch (Exception $e) {
                            error_log('Password expiry code mark used failed: ' . $e->getMessage());
                        }
                    }

                    // Log audit using correct schema (description, not details)
                    try {
                        $desc = 'Password cambiata (policy ' . $maxAgeDays . ' giorni)';
                        if ($isExpired) {
                            $desc = 'Password cambiata (policy ' . $maxAgeDays . ' giorni, cambio forzato con codice)';
                        }

                        $auditQuery = "INSERT INTO audit_logs (
                                        tenant_id, user_id, action, entity_type, entity_id,
                                        description, ip_address, severity, status, created_at
                                      )
                                      SELECT
                                        tenant_id,
                                        :user_id_ins,
                                        'password_change',
                                        'user',
                                        :entity_user_id,
                                        :description_txt,
                                        :ip_addr,
                                        'info',
                                        'success',
                                        NOW()
                                      FROM users WHERE id = :user_id_sel";
                        $auditStmt = $conn->prepare($auditQuery);
                        // NOTE: PDO (with emulation off) may not allow reusing the same named placeholder multiple times.
                        // Use distinct placeholder names to avoid HY093.
                        $auditStmt->bindValue(':user_id_ins', (int)$userId, PDO::PARAM_INT);
                        $auditStmt->bindValue(':entity_user_id', (int)$userId, PDO::PARAM_INT);
                        $auditStmt->bindValue(':user_id_sel', (int)$userId, PDO::PARAM_INT);
                        $auditStmt->bindValue(':description_txt', (string)$desc, PDO::PARAM_STR);
                        $auditStmt->bindValue(':ip_addr', (string)($_SERVER['REMOTE_ADDR'] ?? ''), PDO::PARAM_STR);
                        $auditStmt->execute();
                    } catch (Exception $auditEx) {
                        // Log error but don't fail the password change
                        error_log("Audit log failed: " . $auditEx->getMessage());
                    }

                    // Set session if not already logged in
                    if (!isset($_SESSION['user_id'])) {
                        $_SESSION['user_id'] = $userData['id'];
                        $_SESSION['user_name'] = $userData['name'];
                        $_SESSION['user_email'] = $userData['email'];
                    }
                } else {
                    $errors[] = 'Errore durante il cambio password. Riprova.';
                }
            }
        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            $errors[] = 'Si è verificato un errore. Riprova più tardi.';
        }
    }

    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    // Reuse platform styles (same look as login page)
    $pageTitle = 'Cambio Password - Nexio';
    $pageCss = ['assets/css/login.css'];
    $pageMeta = [
        // Small theme-aware banners for this page (avoid hardcoded bright colors)
        '<style>
            .cnx-banner{border:1px solid rgba(255,255,255,.10);border-radius:var(--radius-md);padding:10px 12px;margin:12px 0;font-size:14px;line-height:1.35}
            .cnx-banner strong{font-weight:700}
            .cnx-banner--warning{background:rgba(245,158,11,.16);border-color:rgba(245,158,11,.35);color:rgba(255,255,255,.92)}
            .cnx-banner--error{background:rgba(239,68,68,.16);border-color:rgba(239,68,68,.35);color:rgba(255,255,255,.92)}
            .cnx-banner--success{background:rgba(16,185,129,.16);border-color:rgba(16,185,129,.35);color:rgba(255,255,255,.92)}
            .cnx-banner--info{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.30);color:rgba(255,255,255,.92)}
            .cnx-hint{margin-top:6px;font-size:12px;line-height:18px;color:rgba(255,255,255,.75)}
        </style>'
    ];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <img src="assets/images/logo.png" alt="Nexio Logo" class="logo-img">
                    <h1 class="logo-text">NEXIO</h1>
                </div>
                <p class="login-subtitle">Cambio password</p>
                <?php if ($userData): ?>
                    <p class="login-subtitle">Ciao, <strong><?php echo htmlspecialchars($userData['name']); ?></strong></p>
                <?php endif; ?>
            </div>

        <?php if (!$userData && $error): ?>
            <div class="cnx-banner cnx-banner--error">
                <strong>Errore.</strong> <?php echo $error; ?>
            </div>
            <div class="login-footer">
                <a href="index.php" class="footer-link">Torna al Login</a>
            </div>
        <?php elseif ($success): ?>
            <div class="cnx-banner cnx-banner--success">
                Password cambiata con successo! La tua nuova password scadrà tra <?php echo (int)$maxAgeDays; ?> giorni.
            </div>
            <div class="login-footer">
                <a href="dashboard.php" class="footer-link">Vai alla Dashboard</a>
            </div>
        <?php else: ?>
            <div class="cnx-banner cnx-banner--warning">
                <?php if ($isExpired): ?>
                    <strong>Password Scaduta</strong>
                    <div>La tua password è scaduta. Per motivi di sicurezza, devi impostare una nuova password e inserire il codice a 6 cifre ricevuto via email.</div>
                <?php else: ?>
                    <strong>Cambio Password</strong>
                    <div>Imposta una nuova password. La policy prevede una scadenza di <?php echo (int)$maxAgeDays; ?> giorni.</div>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="cnx-banner cnx-banner--error"><strong>Errore.</strong> <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (!empty($info)): ?>
                <div class="cnx-banner cnx-banner--success"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form class="login-form" method="POST" action="" novalidate>
                <div class="form-group">
                    <label for="current_password" class="form-label">Password Attuale</label>
                    <input type="password" id="current_password" name="current_password" class="form-input" required autofocus>
                </div>

                <?php if ($isExpired): ?>
                    <div class="form-group">
                        <label for="expiry_code" class="form-label">Codice 6 cifre</label>
                        <input type="text" id="expiry_code" name="expiry_code" class="form-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="Es. 123456" required>
                        <div class="cnx-hint">
                            Inserisci il codice ricevuto via email di avviso scadenza.
                        </div>
                        <div style="margin-top:10px;">
                            <button type="submit" name="action" value="resend_code" formnovalidate class="btn btn-secondary btn-full">
                                Reinvia codice via email
                            </button>
                            <div class="cnx-hint">
                                Se non ricevi il codice, controlla anche Spam/Promozioni. Puoi reinviare ogni 60 secondi.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="cnx-banner cnx-banner--info">
                    <strong>Requisiti nuova password:</strong> minimo 8 caratteri, 1 maiuscola, 1 minuscola, 1 numero.
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">Nuova Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-input" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Conferma Nuova Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                </div>

                <button type="submit" class="btn btn-primary btn-full" name="action" value="change_password">Cambia Password</button>
            </form>
        <?php endif; ?>
        </div>
    </div>
</body>
</html>
