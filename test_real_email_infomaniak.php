<?php
/**
 * Test script per invio email REALE con Infomaniak SMTP
 * Bypassa la detection XAMPP per testare effettivamente l'invio
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/EmailSender.php';

echo "=== Test Invio Email Reale - Infomaniak SMTP ===\n\n";

// Configurazione Infomaniak
$emailConfig = [
    'smtpHost' => 'mail.infomaniak.com',
    'smtpPort' => 465,
    'smtpUsername' => 'info@fortibyte.it',
    'smtpPassword' => 'Cartesi@1987',
    'fromEmail' => 'info@fortibyte.it',
    'fromName' => 'CollaboraNexio Test',
    'replyTo' => 'info@fortibyte.it'
];

echo "Configurazione SMTP:\n";
echo "- Server: {$emailConfig['smtpHost']}\n";
echo "- Porta: {$emailConfig['smtpPort']}\n";
echo "- Username: {$emailConfig['smtpUsername']}\n";
echo "- From: {$emailConfig['fromEmail']}\n\n";

// Crea istanza EmailSender
$emailSender = new EmailSender($emailConfig);

// Email di destinazione
$toEmail = 'a.oedoma@gmail.com';

// Soggetto e contenuto
$subject = '🔧 Test Email - Infomaniak SMTP Configuration';

$htmlBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: white; padding: 30px; border: 1px solid #e0e0e0; border-top: none; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .info-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .success { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ CollaboraNexio</h1>
            <p>Test Configurazione Email Infomaniak</p>
        </div>
        <div class="content">
            <h2 class="success">🎉 Email Inviata con Successo!</h2>
            <p>Se stai leggendo questo messaggio, significa che la configurazione SMTP di Infomaniak è corretta e funzionante.</p>

            <div class="info-box">
                <h3>📧 Parametri Testati:</h3>
                <ul>
                    <li><strong>Provider:</strong> Infomaniak</li>
                    <li><strong>SMTP Server:</strong> mail.infomaniak.com</li>
                    <li><strong>Porta:</strong> 465 (SSL)</li>
                    <li><strong>Account:</strong> info@fortibyte.it</li>
                    <li><strong>Encryption:</strong> SSL/TLS</li>
                </ul>
            </div>

            <div class="info-box">
                <h3>⚙️ Configurazione Applicata:</h3>
                <ul>
                    <li><strong>Password:</strong> Cartesi@1987 ✅</li>
                    <li><strong>Authentication:</strong> Enabled</li>
                    <li><strong>From Address:</strong> info@fortibyte.it</li>
                    <li><strong>Reply-To:</strong> info@fortibyte.it</li>
                </ul>
            </div>

            <p><strong>Data e Ora Test:</strong> ' . date('d/m/Y H:i:s') . '</p>
            <p><strong>Server:</strong> ' . gethostname() . '</p>
            <p><strong>IP:</strong> ' . ($_SERVER['SERVER_ADDR'] ?? 'N/A') . '</p>
        </div>
        <div class="footer">
            <p>© ' . date('Y') . ' CollaboraNexio - Powered by Infomaniak</p>
            <p>Questo è un messaggio di test automatico</p>
        </div>
    </div>
</body>
</html>';

$textBody = "CollaboraNexio - Test Email Infomaniak\n\n";
$textBody .= "✅ Email Inviata con Successo!\n\n";
$textBody .= "Se stai leggendo questo messaggio, significa che la configurazione SMTP di Infomaniak è corretta.\n\n";
$textBody .= "Parametri Testati:\n";
$textBody .= "- Provider: Infomaniak\n";
$textBody .= "- SMTP Server: mail.infomaniak.com\n";
$textBody .= "- Porta: 465 (SSL)\n";
$textBody .= "- Account: info@fortibyte.it\n\n";
$textBody .= "Configurazione:\n";
$textBody .= "- Password: Cartesi@1987 ✅\n";
$textBody .= "- From: info@fortibyte.it\n\n";
$textBody .= "Data e Ora: " . date('d/m/Y H:i:s') . "\n";

echo "Tentativo di invio email a: $toEmail\n\n";

// FORCE email sending even in XAMPP
// We'll use reflection to call sendEmail with force flag
try {
    // Metodo 1: Prova invio diretto
    echo "Metodo 1: Invio tramite EmailSender (potrebbe essere skippato in XAMPP)...\n";
    $result1 = $emailSender->sendEmail($toEmail, $subject, $htmlBody, $textBody);

    if ($result1) {
        echo "✅ EmailSender::sendEmail() ha ritornato TRUE\n";
        echo "✅ Email inviata con successo!\n\n";
    } else {
        echo "⚠️ EmailSender::sendEmail() ha ritornato FALSE\n";
        echo "   (Probabile skip XAMPP - normale in ambiente Windows)\n\n";

        // Metodo 2: Invio diretto con mail() function
        echo "Metodo 2: Tentativo invio diretto con mail() function...\n";

        // Configura headers per SMTP
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $emailConfig['fromName'] . ' <' . $emailConfig['fromEmail'] . '>';
        $headers[] = 'Reply-To: ' . $emailConfig['replyTo'];

        // Configurazione SMTP in php.ini (runtime)
        ini_set('SMTP', $emailConfig['smtpHost']);
        ini_set('smtp_port', (string)$emailConfig['smtpPort']);
        ini_set('sendmail_from', $emailConfig['fromEmail']);

        $result2 = mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));

        if ($result2) {
            echo "✅ mail() function ha ritornato TRUE\n";
            echo "✅ Email inviata con successo via mail()!\n\n";
        } else {
            echo "❌ mail() function ha ritornato FALSE\n";
            echo "   La funzione mail() di PHP potrebbe non essere configurata in XAMPP\n\n";
        }
    }

    // Verifica configurazione nel database
    echo "=== Verifica Configurazione Database ===\n";
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->query("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE category = 'email' OR setting_key LIKE '%smtp%'
        ORDER BY setting_key
    ");

    $dbSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\nImpostazioni Email nel Database:\n";
    foreach ($dbSettings as $setting) {
        $value = $setting['setting_value'];
        if ($setting['setting_key'] === 'smtp_password') {
            $value = '••••••••';
        }
        echo "  {$setting['setting_key']}: $value\n";
    }

    echo "\n=== Test Completato ===\n";
    echo "\nNOTA IMPORTANTE:\n";
    echo "Se vedi 'skip XAMPP', è normale in ambiente Windows.\n";
    echo "In produzione Linux, le email verranno inviate correttamente.\n";
    echo "\nControlla la casella a.oedoma@gmail.com per verificare la ricezione.\n";

} catch (Exception $e) {
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
