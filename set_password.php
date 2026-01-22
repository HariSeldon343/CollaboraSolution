<?php
/**
 * Set Password Page
 * Used for first-time password setup via email link
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$error = '';
$success = false;
$token = $_GET['token'] ?? '';
$userData = null;
$tokenValid = false;

// Verify token first
if ($token) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Find user with this valid token (not expired).
        // IMPORTANT: Build SELECT dynamically based on available columns, so we don't silently drop OTP fields.
        $hasMaxAgeDays = false;
        $hasOtpCols = false;
        try {
            $cols = $db->fetchAll(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME IN ('password_max_age_days', 'password_reset_otp_hash', 'password_reset_otp_expires')"
            );
            $names = array_map(static fn($r) => (string)($r['COLUMN_NAME'] ?? ''), $cols);
            $hasMaxAgeDays = in_array('password_max_age_days', $names, true);
            $hasOtpCols = in_array('password_reset_otp_hash', $names, true) && in_array('password_reset_otp_expires', $names, true);
        } catch (Throwable $e) {
            $hasMaxAgeDays = false;
            $hasOtpCols = false;
        }

        $selectCols = [
            'id',
            'email',
            'name',
            'password_reset_token',
            'password_reset_expires',
        ];
        if ($hasMaxAgeDays) {
            $selectCols[] = 'password_max_age_days';
        }
        if ($hasOtpCols) {
            $selectCols[] = 'password_reset_otp_hash';
            $selectCols[] = 'password_reset_otp_expires';
        }

        $query = "SELECT " . implode(', ', $selectCols) . "
                  FROM users
                  WHERE password_reset_token = :token
                    AND password_reset_expires > NOW()
                    AND deleted_at IS NULL
                    AND is_active = 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $tokenValid = true;
        } else {
            // Check if token exists but is expired
            $expiredQuery = "SELECT id FROM users WHERE password_reset_token = :token AND deleted_at IS NULL";
            $expiredStmt = $conn->prepare($expiredQuery);
            $expiredStmt->bindParam(':token', $token);
            $expiredStmt->execute();

            if ($expiredStmt->fetch()) {
                $error = 'Il link per impostare la password è scaduto. Contatta il tuo amministratore per richiedere un nuovo link.';
            } else {
                $error = 'Link non valido. Verifica di aver copiato correttamente l\'URL dall\'email.';
            }
        }
    } catch (Exception $e) {
        error_log('Set password token verification error: ' . $e->getMessage());
        $error = 'Si è verificato un errore. Riprova più tardi.';
    }
} else {
    $error = 'Nessun token fornito. Usa il link ricevuto via email.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid && $userData) {
    $oneTimePassword = $_POST['one_time_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    $errors = [];

    // If OTP is present on the user record, require OTP in the form.
    $otpRequired = !empty($userData['password_reset_otp_hash']);
    if ($otpRequired) {
        // Check OTP expiry if available
        $otpExpiresAt = $userData['password_reset_otp_expires'] ?? null;
        if (empty($oneTimePassword)) {
            $errors[] = 'Inserisci la password one-time ricevuta via email';
        } elseif (!empty($otpExpiresAt) && strtotime((string)$otpExpiresAt) < time()) {
            $errors[] = 'La password one-time è scaduta. Richiedi un nuovo reset all’amministratore.';
        } elseif (!password_verify((string)$oneTimePassword, (string)$userData['password_reset_otp_hash'])) {
            $errors[] = 'Password one-time non valida';
        }
    }

    if (strlen($newPassword) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        $errors[] = 'La password deve contenere almeno una lettera maiuscola';
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        $errors[] = 'La password deve contenere almeno una lettera minuscola';
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        $errors[] = 'La password deve contenere almeno un numero';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Le password non coincidono';
    }

    if (empty($errors)) {
        try {
            // Hash the new password
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            // Calculate password expiry (per-user max age; default 90 days)
            $maxAgeDays = isset($userData['password_max_age_days']) ? (int)$userData['password_max_age_days'] : 90;
            if ($maxAgeDays <= 0) {
                $maxAgeDays = 90;
            }
            $passwordExpiresAt = date('Y-m-d H:i:s', strtotime('+' . $maxAgeDays . ' days'));

            // Build update query based on available columns (avoid breaking older DBs)
            $hasPasswordSetAt = true;
            $hasPasswordExpiresAt = true;
            $hasOtpCols = false;
            try {
                $cols = $db->fetchAll(
                    "SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME IN ('password_set_at', 'password_expires_at', 'password_reset_otp_hash', 'password_reset_otp_expires')"
                );
                $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $cols);
                $hasPasswordSetAt = in_array('password_set_at', $names, true);
                $hasPasswordExpiresAt = in_array('password_expires_at', $names, true);
                $hasOtpCols = in_array('password_reset_otp_hash', $names, true) && in_array('password_reset_otp_expires', $names, true);
            } catch (Exception $e) {
                // default true
            }

            $setParts = [
                "password_hash = :password_hash",
                "password_reset_token = NULL",
                "password_reset_expires = NULL",
                "first_login = FALSE",
                "updated_at = NOW()"
            ];
            if ($hasPasswordSetAt) {
                $setParts[] = "password_set_at = NOW()";
            }
            if ($hasPasswordExpiresAt) {
                $setParts[] = "password_expires_at = :password_expires_at";
            }
            if ($hasOtpCols) {
                $setParts[] = "password_reset_otp_hash = NULL";
                $setParts[] = "password_reset_otp_expires = NULL";
            }

            $updateQuery = "UPDATE users SET " . implode(", ", $setParts) . " WHERE id = :user_id";

            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bindParam(':password_hash', $passwordHash);
            // Bind only if column exists in query
            if (strpos($updateQuery, ':password_expires_at') !== false) {
                $updateStmt->bindParam(':password_expires_at', $passwordExpiresAt);
            }
            $updateStmt->bindParam(':user_id', $userData['id']);

            if ($updateStmt->execute()) {
                $success = true;

                // Log in the user automatically
                $_SESSION['user_id'] = $userData['id'];
                $_SESSION['email'] = $userData['email'];
                $_SESSION['name'] = $userData['name'];

                // Redirect to dashboard after 3 seconds
                header('refresh:3;url=dashboard.php');
            } else {
                $error = 'Errore durante l\'impostazione della password. Riprova.';
            }
        } catch (Exception $e) {
            error_log('Set password update error: ' . $e->getMessage());
            $error = 'Si è verificato un errore. Riprova più tardi.';
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imposta Password - Nexio</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .set-password-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 0;
            overflow: hidden;
        }

        .set-password-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .set-password-header h1 {
            margin: 0 0 10px;
            font-size: 28px;
            font-weight: 700;
        }

        .set-password-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }

        .set-password-body {
            padding: 40px 30px;
        }

        .user-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 30px;
        }

        .user-info strong {
            color: #667eea;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .password-requirements {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 15px 20px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.8;
        }

        .password-requirements strong {
            color: #856404;
            display: block;
            margin-bottom: 8px;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .success-message {
            text-align: center;
        }

        .success-message .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .success-message h2 {
            color: #2e7d32;
            margin-bottom: 10px;
        }

        .redirect-message {
            color: #666;
            font-size: 14px;
            margin-top: 15px;
        }

        .back-to-login {
            text-align: center;
            margin-top: 20px;
        }

        .back-to-login a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .back-to-login a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="set-password-container">
        <div class="set-password-header">
            <h1>🔐 Imposta Password</h1>
            <p>Completa la configurazione del tuo account</p>
        </div>

        <div class="set-password-body">
            <?php if ($success): ?>
                <div class="success-message">
                    <div class="icon">✅</div>
                    <h2>Password impostata con successo!</h2>
                    <p>Il tuo account è ora attivo e pronto all'uso.</p>
                    <div class="redirect-message">
                        <strong>Sarai reindirizzato alla dashboard tra pochi secondi...</strong>
                    </div>
                    <div class="back-to-login">
                        <a href="dashboard.php">Vai subito alla Dashboard →</a>
                    </div>
                </div>

            <?php elseif ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
                <?php if (!$tokenValid): ?>
                    <div class="back-to-login">
                        <a href="index.php">← Torna al Login</a>
                    </div>
                <?php endif; ?>

            <?php elseif ($tokenValid && $userData): ?>
                <div class="user-info">
                    Stai configurando l'account per: <strong><?php echo htmlspecialchars($userData['name']); ?></strong>
                    <br>
                    Email: <?php echo htmlspecialchars($userData['email']); ?>
                </div>

                <div class="password-requirements">
                    <strong>La password deve contenere:</strong>
                    ✓ Minimo 8 caratteri<br>
                    ✓ Almeno una lettera maiuscola<br>
                    ✓ Almeno una lettera minuscola<br>
                    ✓ Almeno un numero
                </div>

                <form method="POST" action="">
                    <?php if (!empty($userData['password_reset_otp_hash'])): ?>
                    <div class="form-group">
                        <label for="one_time_password">Password one-time</label>
                        <input
                            type="password"
                            id="one_time_password"
                            name="one_time_password"
                            required
                            autocomplete="one-time-code"
                        >
                        <small style="display:block;margin-top:6px;color:#6b7280;font-size:12px;">
                            Inserisci la password one-time ricevuta via email (valida 24 ore).
                        </small>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="new_password">Nuova Password</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            required
                            autocomplete="new-password"
                            minlength="8"
                        >
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Conferma Password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            required
                            autocomplete="new-password"
                            minlength="8"
                        >
                    </div>

                    <button type="submit" class="btn-primary">
                        Imposta Password e Accedi
                    </button>
                </form>

                <div class="back-to-login">
                    <a href="index.php">← Torna al Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
