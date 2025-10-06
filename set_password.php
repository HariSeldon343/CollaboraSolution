<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$error = '';
$success = false;
$tokenValid = false;
$token = $_GET['token'] ?? '';
$userData = null;

// Verifica token se presente
if ($token) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Verifica token e scadenza
        $query = "SELECT id, email, name, password_reset_expires
                  FROM users
                  WHERE password_reset_token = :token
                  AND password_reset_expires > NOW()";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();

        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $tokenValid = true;
        } else {
            // Controlla se il token esiste ma è scaduto
            $expiredQuery = "SELECT password_reset_expires
                           FROM users
                           WHERE password_reset_token = :token";
            $expiredStmt = $conn->prepare($expiredQuery);
            $expiredStmt->bindParam(':token', $token);
            $expiredStmt->execute();

            if ($expiredStmt->fetch()) {
                $error = 'Il link è scaduto. Contatta il tuo amministratore per riceverne uno nuovo.';
            } else {
                $error = 'Link non valido. Verifica di aver copiato correttamente l\'URL.';
            }
        }
    } catch (Exception $e) {
        error_log('Set password error: ' . $e->getMessage());
        $error = 'Si è verificato un errore. Riprova più tardi.';
    }
}

// Gestione form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validazione password
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'La password deve contenere almeno una lettera maiuscola';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'La password deve contenere almeno una lettera minuscola';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'La password deve contenere almeno un numero';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Le password non coincidono';
    }

    if (empty($errors)) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();

            // Calculate password expiration: 90 days from now
            $passwordExpiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

            // Hash della password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Aggiorna utente con password e data scadenza (90 giorni)
            $updateQuery = "UPDATE users
                          SET password_hash = :password_hash,
                              password_reset_token = NULL,
                              password_reset_expires = NULL,
                              first_login = FALSE,
                              password_set_at = NOW(),
                              password_expires_at = :password_expires_at,
                              is_active = TRUE
                          WHERE id = :user_id";

            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bindParam(':password_hash', $passwordHash);
            $updateStmt->bindParam(':password_expires_at', $passwordExpiresAt);
            $updateStmt->bindParam(':user_id', $userData['id']);

            if ($updateStmt->execute()) {
                $success = true;

                // Log audit
                $auditQuery = "INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, details)
                             SELECT tenant_id, :user_id, 'password_set', 'user', :user_id, 'Password impostata per il primo accesso'
                             FROM users WHERE id = :user_id";
                $auditStmt = $conn->prepare($auditQuery);
                $auditStmt->bindParam(':user_id', $userData['id']);
                $auditStmt->execute();
            } else {
                $error = 'Errore durante l\'impostazione della password. Riprova.';
            }
        } catch (Exception $e) {
            error_log('Password update error: ' . $e->getMessage());
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
    <title>Imposta Password - CollaboraNexio</title>
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

        .container {
            max-width: 440px;
            width: 100%;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
        }

        .app-name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .tagline {
            font-size: 14px;
            opacity: 0.9;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .form-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-subtitle {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }

        .user-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            text-align: center;
        }

        .user-email {
            font-weight: 600;
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-requirements {
            background: #f0f4ff;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
        }

        .requirements-title {
            font-size: 13px;
            font-weight: 600;
            color: #4c5a81;
            margin-bottom: 8px;
        }

        .requirement {
            font-size: 12px;
            color: #666;
            padding: 2px 0;
            display: flex;
            align-items: center;
        }

        .requirement.valid {
            color: #10b981;
        }

        .requirement.valid::before {
            content: "✓";
            margin-right: 8px;
            font-weight: bold;
        }

        .requirement.invalid::before {
            content: "•";
            margin-right: 8px;
            color: #999;
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            text-align: center;
        }

        .success-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .success-message {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .redirect-message {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }

        .login-link {
            display: inline-block;
            padding: 10px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .login-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            transition: width 0.3s, background 0.3s;
            width: 0%;
        }

        .strength-weak {
            background: #ef4444;
            width: 33%;
        }

        .strength-medium {
            background: #f59e0b;
            width: 66%;
        }

        .strength-strong {
            background: #10b981;
            width: 100%;
        }

        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
        }

        .toggle-password:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo">N</div>
            <div class="app-name">CollaboraNexio</div>
            <div class="tagline">Semplifica, Connetti, Cresci Insieme</div>
        </div>

        <div class="form-card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <div class="success-icon">✅</div>
                    <div class="success-message">Password impostata con successo!</div>
                    <div class="redirect-message">Ora puoi accedere con le tue credenziali.</div>
                    <a href="index.php" class="login-link">Vai al Login</a>
                </div>
                <script>
                    // Auto redirect dopo 3 secondi
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 3000);
                </script>
            <?php elseif (!$tokenValid): ?>
                <div class="form-header">
                    <h1 class="form-title">Link Non Valido</h1>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <a href="index.php" class="btn btn-primary">Torna al Login</a>
            <?php else: ?>
                <div class="form-header">
                    <h1 class="form-title">Imposta la tua Password</h1>
                    <p class="form-subtitle">Crea una password sicura per il tuo account</p>
                </div>

                <?php if ($userData): ?>
                    <div class="user-info">
                        Account: <span class="user-email"><?php echo htmlspecialchars($userData['email']); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" id="setPasswordForm">
                    <div class="password-requirements">
                        <div class="requirements-title">La password deve contenere:</div>
                        <div class="requirement" id="req-length">Almeno 8 caratteri</div>
                        <div class="requirement" id="req-uppercase">Una lettera maiuscola</div>
                        <div class="requirement" id="req-lowercase">Una lettera minuscola</div>
                        <div class="requirement" id="req-number">Un numero</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Nuova Password</label>
                        <div class="password-toggle">
                            <input type="password" id="password" name="password" class="form-input" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('password')">
                                <span id="password-icon">👁️</span>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="strength-bar"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Conferma Password</label>
                        <div class="password-toggle">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                                <span id="confirm-icon">👁️</span>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">Imposta Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId === 'password' ? 'password-icon' : 'confirm-icon');

            if (field.type === 'password') {
                field.type = 'text';
                icon.textContent = '👁️‍🗨️';
            } else {
                field.type = 'password';
                icon.textContent = '👁️';
            }
        }

        // Password validation
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        const strengthBar = document.getElementById('strength-bar');

        if (passwordInput) {
            passwordInput.addEventListener('input', validatePassword);
            confirmInput.addEventListener('input', validatePassword);

            function validatePassword() {
                const password = passwordInput.value;
                const confirm = confirmInput.value;

                // Check requirements
                const requirements = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /[0-9]/.test(password)
                };

                // Update requirement indicators
                document.getElementById('req-length').className = 'requirement ' + (requirements.length ? 'valid' : 'invalid');
                document.getElementById('req-uppercase').className = 'requirement ' + (requirements.uppercase ? 'valid' : 'invalid');
                document.getElementById('req-lowercase').className = 'requirement ' + (requirements.lowercase ? 'valid' : 'invalid');
                document.getElementById('req-number').className = 'requirement ' + (requirements.number ? 'valid' : 'invalid');

                // Calculate strength
                const strength = Object.values(requirements).filter(Boolean).length;

                // Update strength bar
                strengthBar.className = 'password-strength-bar';
                if (strength <= 2) {
                    strengthBar.classList.add('strength-weak');
                } else if (strength === 3) {
                    strengthBar.classList.add('strength-medium');
                } else {
                    strengthBar.classList.add('strength-strong');
                }

                // Enable/disable submit button
                const allValid = Object.values(requirements).every(Boolean) &&
                                (confirm === '' || password === confirm);
                submitBtn.disabled = !allValid;
            }

            // Form submission
            document.getElementById('setPasswordForm').addEventListener('submit', function(e) {
                const password = passwordInput.value;
                const confirm = confirmInput.value;

                if (password !== confirm) {
                    e.preventDefault();
                    alert('Le password non coincidono!');
                    return false;
                }
            });
        }
    </script>
</body>
</html>