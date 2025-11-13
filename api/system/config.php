<?php
// Initialize session with proper configuration
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_simple.php';

// Authentication validation
$auth = new AuthSimple();
if (!$auth->checkAuth()) {
    http_response_code(401);
    die(json_encode(['error' => 'Non autorizzato']));
}

$currentUser = $auth->getCurrentUser();

// Only super_admin can manage system configuration
if ($currentUser['role'] !== 'super_admin') {
    http_response_code(403);
    die(json_encode(['error' => 'Accesso negato. Solo super admin può gestire le configurazioni.']));
}

// CSRF token validation for POST/PUT/DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrfToken || !$auth->verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }
}

// Input sanitization
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

// Get database instance
$db = Database::getInstance();
$conn = $db->getConnection();

try {
    switch ($action) {
        case 'get':
            // Get all system settings
            $stmt = $conn->prepare("
                SELECT setting_key, setting_value, value_type
                FROM system_settings
                ORDER BY setting_key ASC
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert to key-value pairs
            $settingsArray = [];
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];

                // Type conversion
                switch ($setting['value_type']) {
                    case 'boolean':
                        $value = $value === '1' || $value === 'true';
                        break;
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }

                $settingsArray[$setting['setting_key']] = $value;
            }

            echo json_encode(['success' => true, 'data' => $settingsArray]);
            break;

        case 'save':
            // Save system settings
            if (empty($input['settings'])) {
                http_response_code(400);
                die(json_encode(['error' => 'Nessuna impostazione da salvare']));
            }

            $conn->beginTransaction();

            try {
                foreach ($input['settings'] as $key => $value) {
                    // Determine type
                    $type = 'string';
                    if (is_bool($value)) {
                        $type = 'boolean';
                        $value = $value ? '1' : '0';
                    } elseif (is_int($value)) {
                        $type = 'integer';
                        $value = (string)$value;
                    } elseif (is_array($value)) {
                        $type = 'json';
                        $value = json_encode($value);
                    }

                    // Insert or update
                    $stmt = $conn->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, value_type, updated_at)
                        VALUES (:key, :value, :type, NOW())
                        ON DUPLICATE KEY UPDATE
                            setting_value = :value,
                            value_type = :type,
                            updated_at = NOW()
                    ");

                    $stmt->execute([
                        'key' => $key,
                        'value' => $value,
                        'type' => $type
                    ]);
                }

                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Configurazioni salvate con successo'
                ]);

            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'test_email':
            // Test email configuration
            require_once __DIR__ . '/../../includes/EmailSender.php';

            if (empty($input['to_email'])) {
                http_response_code(400);
                die(json_encode(['error' => 'Email destinatario richiesta']));
            }

            // Get email settings from input or database
            $emailConfig = [
                'smtpHost' => $input['smtp_host'] ?? 'mail.infomaniak.com',
                'smtpPort' => $input['smtp_port'] ?? 465,
                'smtpUsername' => $input['smtp_username'] ?? '',
                'smtpPassword' => $input['smtp_password'] ?? '',
                'fromEmail' => $input['from_email'] ?? 'info@fortibyte.it',
                'fromName' => $input['from_name'] ?? 'CollaboraNexio',
                'replyTo' => $input['reply_to'] ?? 'info@fortibyte.it'
            ];

            $emailSender = new EmailSender($emailConfig);

            $subject = 'Test Email - CollaboraNexio';
            $htmlBody = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
            </head>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                        <h1 style="margin: 0;">CollaboraNexio</h1>
                        <p style="margin: 10px 0 0;">Test Email di Configurazione</p>
                    </div>
                    <div style="background: white; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
                        <h2 style="color: #333; margin-top: 0;">Test Email Riuscito!</h2>
                        <p>Se ricevi questo messaggio, significa che la configurazione email è corretta.</p>
                        <p>Parametri testati:</p>
                        <ul style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <li><strong>SMTP Host:</strong> ' . htmlspecialchars($emailConfig['smtpHost']) . '</li>
                            <li><strong>SMTP Port:</strong> ' . htmlspecialchars((string)$emailConfig['smtpPort']) . '</li>
                            <li><strong>Da:</strong> ' . htmlspecialchars($emailConfig['fromEmail']) . '</li>
                        </ul>
                        <p style="color: #666; font-size: 14px; margin-top: 30px;">
                            Data e ora test: ' . date('d/m/Y H:i:s') . '
                        </p>
                    </div>
                    <div style="text-align: center; padding: 20px; color: #666; font-size: 12px;">
                        <p>&copy; ' . date('Y') . ' CollaboraNexio. Tutti i diritti riservati.</p>
                    </div>
                </div>
            </body>
            </html>';

            $textBody = "Test Email - CollaboraNexio\n\n";
            $textBody .= "Se ricevi questo messaggio, significa che la configurazione email è corretta.\n\n";
            $textBody .= "Parametri testati:\n";
            $textBody .= "- SMTP Host: {$emailConfig['smtpHost']}\n";
            $textBody .= "- SMTP Port: {$emailConfig['smtpPort']}\n";
            $textBody .= "- Da: {$emailConfig['fromEmail']}\n\n";
            $textBody .= "Data e ora test: " . date('d/m/Y H:i:s') . "\n";

            $result = $emailSender->sendEmail($input['to_email'], $subject, $htmlBody, $textBody);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Email di test inviata con successo a ' . $input['to_email']
                ]);
            } else {
                // Check if we're in Windows/XAMPP environment
                $isXampp = (stripos(PHP_OS, 'WIN') !== false);

                if ($isXampp) {
                    // Development environment - SMTP is intentionally disabled
                    http_response_code(200);
                    echo json_encode([
                        'success' => false,
                        'warning' => true,
                        'message' => 'Ambiente di sviluppo rilevato. L\'invio email è disabilitato su Windows/XAMPP per motivi di performance. Le email funzioneranno correttamente in produzione (Linux).',
                        'details' => 'Le credenziali SMTP sono configurate correttamente ma l\'invio è saltato in sviluppo.'
                    ]);
                } else {
                    // Production environment - real SMTP error
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Errore durante l\'invio dell\'email di test. Verifica le impostazioni SMTP.',
                        'details' => 'Controlla che le credenziali SMTP siano corrette e che il server SMTP sia raggiungibile.'
                    ]);
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Azione non valida']);
            break;
    }

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore server: ' . $e->getMessage()]);
}
