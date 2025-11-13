<?php
/**
 * Change Password Page
 * Used when user password has expired (90-day policy)
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$error = '';
$success = false;
$userId = $_GET['user_id'] ?? $_SESSION['user_id'] ?? null;
$userData = null;

// Must have user_id
if (!$userId) {
    header('Location: index.php');
    exit;
}

// Get user data
try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $query = "SELECT id, email, name, password_expires_at
              FROM users
              WHERE id = :user_id
              AND is_active = 1";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();

    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        $error = 'Utente non trovato';
    }
} catch (Exception $e) {
    error_log('Change password error: ' . $e->getMessage());
    $error = 'Si è verificato un errore. Riprova più tardi.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userData) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validazione
    $errors = [];

    if (empty($currentPassword)) {
        $errors[] = 'Inserisci la password attuale';
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

                // Calculate new expiration (90 days from now)
                $passwordExpiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

                // Update password
                $updateQuery = "UPDATE users
                              SET password_hash = :password_hash,
                                  password_set_at = NOW(),
                                  password_expires_at = :password_expires_at
                              WHERE id = :user_id";

                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bindParam(':password_hash', $newPasswordHash);
                $updateStmt->bindParam(':password_expires_at', $passwordExpiresAt);
                $updateStmt->bindParam(':user_id', $userId);

                if ($updateStmt->execute()) {
                    $success = true;

                    // Log audit using correct schema (description, not details)
                    try {
                        $auditQuery = "INSERT INTO audit_logs (
                                        tenant_id, user_id, action, entity_type, entity_id,
                                        description, ip_address, severity, status, created_at
                                      )
                                      SELECT
                                        tenant_id,
                                        :user_id,
                                        'password_change',
                                        'user',
                                        :user_id,
                                        'Password cambiata (scadenza policy 90 giorni)',
                                        :ip_address,
                                        'info',
                                        'success',
                                        NOW()
                                      FROM users WHERE id = :user_id";
                        $auditStmt = $conn->prepare($auditQuery);
                        $auditStmt->bindParam(':user_id', $userId);
                        $auditStmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
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
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambio Password - CollaboraNexio</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .password-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .header p {
            color: #718096;
            font-size: 14px;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .warning-box strong {
            color: #856404;
            display: block;
            margin-bottom: 5px;
        }

        .warning-box p {
            color: #856404;
            margin: 0;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            color: #4a5568;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f7fafc;
        }

        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-requirements {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .password-requirements h4 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .password-requirements ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .password-requirements li {
            color: #4a5568;
            padding: 4px 0;
            padding-left: 20px;
            position: relative;
        }

        .password-requirements li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #48bb78;
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fed7d7;
            color: #9b2c2c;
            border-left: 4px solid #f56565;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }

        .success-actions {
            text-align: center;
            margin-top: 20px;
        }

        .success-actions a {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .success-actions a:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="password-container">
        <div class="header">
            <h1>Cambio Password Richiesto</h1>
            <?php if ($userData): ?>
                <p>Ciao, <strong><?php echo htmlspecialchars($userData['name']); ?></strong></p>
            <?php endif; ?>
        </div>

        <?php if (!$userData && $error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
            <div class="success-actions">
                <a href="index.php">Torna al Login</a>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success">
                Password cambiata con successo! La tua nuova password scadrà tra 90 giorni.
            </div>
            <div class="success-actions">
                <a href="dashboard.php">Vai alla Dashboard</a>
            </div>
        <?php else: ?>
            <div class="warning-box">
                <strong>Password Scaduta</strong>
                <p>La tua password è scaduta. Per motivi di sicurezza, devi impostare una nuova password.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="current_password">Password Attuale</label>
                    <input type="password" id="current_password" name="current_password" required autofocus>
                </div>

                <div class="password-requirements">
                    <h4>Requisiti Nuova Password:</h4>
                    <ul>
                        <li>Minimo 8 caratteri</li>
                        <li>Almeno una lettera maiuscola</li>
                        <li>Almeno una lettera minuscola</li>
                        <li>Almeno un numero</li>
                    </ul>
                </div>

                <div class="form-group">
                    <label for="new_password">Nuova Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Conferma Nuova Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn">Cambia Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
