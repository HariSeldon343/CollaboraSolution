<?php
/**
 * Test Mailer SMTP - Verifica funzionamento sistema email
 *
 * Questo script testa l'invio email usando il nuovo sistema PHPMailer.
 * Eseguire da browser: http://localhost:8888/CollaboraNexio/test_mailer_smtp.php
 */

// Configura output
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Carica il nuovo helper (prima di qualsiasi output)
require_once __DIR__ . '/includes/mailer.php';

// Import PHPMailer class (deve essere in cima)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

echo "<!DOCTYPE html>
<html lang='it'>
<head>
    <meta charset='UTF-8'>
    <title>Test Mailer SMTP</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 5px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .test-button { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .test-button:hover { background: #5568d3; }
    </style>
</head>
<body>
<h1>🔧 Test Sistema Email PHPMailer</h1>";

// Carica il nuovo helper
require_once __DIR__ . '/includes/mailer.php';

echo "<div class='section'>";
echo "<h2>1. Verifica Configurazione</h2>";

$config = loadEmailConfig();

if ($config && !empty($config['smtp_host'])) {
    echo "<div class='success'>";
    echo "<strong>✅ Configurazione caricata con successo!</strong><br>";
    echo "Host SMTP: " . htmlspecialchars($config['smtp_host']) . "<br>";
    echo "Porta: " . $config['smtp_port'] . "<br>";
    echo "Username: " . htmlspecialchars($config['smtp_username']) . "<br>";
    echo "SSL Verify: " . ($config['smtp_verify_ssl'] ? 'Sì' : 'No') . "<br>";
    echo "Debug Mode: " . ($config['debug_mode'] ? 'Attivo' : 'Disattivo') . "<br>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<strong>❌ Configurazione non disponibile!</strong><br>";
    echo "Verifica che il file includes/config_email.php esista e sia configurato correttamente.";
    echo "</div>";
}
echo "</div>";

// Test OpenSSL
echo "<div class='section'>";
echo "<h2>2. Verifica OpenSSL</h2>";

if (extension_loaded('openssl')) {
    echo "<div class='success'>";
    echo "<strong>✅ Estensione OpenSSL caricata</strong><br>";
    echo "Versione OpenSSL: " . OPENSSL_VERSION_TEXT;
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<strong>❌ Estensione OpenSSL NON caricata!</strong><br>";
    echo "Abilita openssl in php.ini: extension=openssl";
    echo "</div>";
}
echo "</div>";

// Test PHPMailer
echo "<div class='section'>";
echo "<h2>3. Verifica PHPMailer</h2>";

$phpmailerPath = __DIR__ . '/includes/PHPMailer/PHPMailer.php';
if (file_exists($phpmailerPath)) {
    echo "<div class='success'>";
    echo "<strong>✅ PHPMailer installato correttamente</strong><br>";
    echo "Path: " . $phpmailerPath;
    echo "</div>";

    $mail = new PHPMailer();
    echo "<div class='info'>";
    echo "Versione PHPMailer: " . PHPMailer::VERSION;
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<strong>❌ PHPMailer NON trovato!</strong><br>";
    echo "Path atteso: " . $phpmailerPath;
    echo "</div>";
}
echo "</div>";

// Test invio email
if (isset($_GET['test_send']) && $_GET['test_send'] === '1') {
    echo "<div class='section'>";
    echo "<h2>4. Test Invio Email</h2>";

    $testEmail = $_GET['email'] ?? 'test@example.com';

    echo "<div class='info'>";
    echo "<strong>📧 Invio email di test a:</strong> " . htmlspecialchars($testEmail) . "<br>";
    echo "Attendere...";
    echo "</div>";

    $subject = "Test Email CollaboraNexio - " . date('Y-m-d H:i:s');
    $htmlBody = "<h1>Test Email</h1>
                 <p>Questa è un'email di test inviata dal sistema CollaboraNexio.</p>
                 <p>Se ricevi questa email, il sistema SMTP funziona correttamente!</p>
                 <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>";
    $textBody = "Test Email\n\nQuesta è un'email di test.\nTimestamp: " . date('Y-m-d H:i:s');

    $context = [
        'action' => 'test_mailer',
        'tenant_id' => 1,
        'user_id' => null
    ];

    $result = sendEmail($testEmail, $subject, $htmlBody, $textBody, ['context' => $context]);

    if ($result) {
        echo "<div class='success'>";
        echo "<strong>✅ Email inviata con successo!</strong><br>";
        echo "Controlla la casella di posta di: " . htmlspecialchars($testEmail);
        echo "</div>";
    } else {
        echo "<div class='error'>";
        echo "<strong>❌ Errore durante l'invio!</strong><br>";
        echo "Controlla i log in logs/mailer_error.log per dettagli.";
        echo "</div>";
    }

    echo "</div>";

    // Mostra log
    echo "<div class='section'>";
    echo "<h2>5. Log Recenti</h2>";

    $logFile = __DIR__ . '/logs/mailer_error.log';
    if (file_exists($logFile)) {
        $logs = file($logFile);
        $recentLogs = array_slice($logs, -10); // Ultime 10 righe

        echo "<div class='info'>";
        echo "<strong>Ultimi 10 log entries:</strong>";
        echo "<pre>";
        foreach ($recentLogs as $log) {
            $logData = json_decode($log, true);
            if ($logData) {
                echo "[" . $logData['timestamp'] . "] ";
                echo "[" . strtoupper($logData['status']) . "] ";
                if (isset($logData['to'])) echo "To: " . $logData['to'] . " - ";
                if (isset($logData['subject'])) echo "Subject: " . $logData['subject'] . " - ";
                if (isset($logData['error'])) echo "Error: " . $logData['error'];
                echo "\n";
            }
        }
        echo "</pre>";
        echo "</div>";
    } else {
        echo "<div class='info'>Nessun log disponibile ancora.</div>";
    }

    echo "</div>";
}

// Form test
echo "<div class='section'>";
echo "<h2>Test Invio Email</h2>";
echo "<form method='get' action=''>";
echo "<label>Inserisci email di test:</label><br>";
echo "<input type='email' name='email' value='info@fortibyte.it' required style='width: 300px; padding: 8px; margin: 10px 0;'><br>";
echo "<input type='hidden' name='test_send' value='1'>";
echo "<button type='submit' class='test-button'>🚀 Invia Email di Test</button>";
echo "</form>";
echo "</div>";

echo "<div class='section'>";
echo "<h2>📋 Checklist di Verifica</h2>";
echo "<ul>";
echo "<li>✅ PHPMailer installato in includes/PHPMailer/</li>";
echo "<li>✅ File includes/config_email.php configurato</li>";
echo "<li>✅ Password SMTP inserita in config_email.php</li>";
echo "<li>✅ OpenSSL abilitato in php.ini</li>";
echo "<li>📝 Test invio email completato con successo</li>";
echo "<li>📝 Email ricevuta nella casella di posta</li>";
echo "</ul>";
echo "</div>";

echo "</body></html>";
?>
