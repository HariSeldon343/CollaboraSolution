<?php
/**
 * Script di test per verificare l'invio email SMTP
 * Esegui da linea di comando: php test_email_smtp.php
 */

// Carica configurazione
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/EmailSender.php';

// Test 1: Verifica che la classe EmailSender sia caricata
echo "=== TEST INVIO EMAIL SMTP ===\n\n";
echo "1. Verifica classe EmailSender: ";
if (class_exists('EmailSender')) {
    echo "✓ OK\n";
} else {
    echo "✗ ERRORE - Classe non trovata\n";
    exit(1);
}

// Test 2: Verifica generazione token
echo "2. Test generazione token: ";
try {
    $token = EmailSender::generateSecureToken();
    echo "✓ OK (Token: " . substr($token, 0, 16) . "...)\n";
} catch (Exception $e) {
    echo "✗ ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Istanziazione EmailSender
echo "3. Istanziazione EmailSender: ";
try {
    $emailSender = new EmailSender();
    echo "✓ OK\n";
} catch (Exception $e) {
    echo "✗ ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Invio email di test
echo "\n=== TEST INVIO EMAIL ===\n";
echo "Destinatario email di test (premi invio per usare test@example.com): ";
$testEmail = trim(fgets(STDIN));
if (empty($testEmail)) {
    $testEmail = 'test@example.com';
}

echo "\nInvio email di benvenuto a: $testEmail\n";
echo "Token: $token\n";

try {
    $result = $emailSender->sendWelcomeEmail(
        $testEmail,
        'Test User',
        $token,
        'Test Company'
    );

    if ($result) {
        echo "\n✓ Email inviata con successo!\n";
        echo "Link generato: " . BASE_URL . '/set_password.php?token=' . urlencode($token) . "\n";
    } else {
        echo "\n✗ Errore nell'invio dell'email\n";

        // Verifica configurazione SMTP
        echo "\n=== CONFIGURAZIONE SMTP ===\n";
        echo "SMTP Host: mail.infomaniak.com\n";
        echo "SMTP Port: 465\n";
        echo "From Email: info@fortibyte.it\n";

        // Verifica se mail() è disponibile
        echo "\n=== VERIFICA FUNZIONE mail() ===\n";
        if (function_exists('mail')) {
            echo "✓ La funzione mail() è disponibile\n";

            // Verifica configurazione PHP
            echo "\nConfigurazione PHP mail:\n";
            echo "- SMTP: " . ini_get('SMTP') . "\n";
            echo "- smtp_port: " . ini_get('smtp_port') . "\n";
            echo "- sendmail_from: " . ini_get('sendmail_from') . "\n";
            echo "- sendmail_path: " . ini_get('sendmail_path') . "\n";

            // Su Windows con XAMPP
            if (stripos(PHP_OS, 'WIN') !== false) {
                echo "\n⚠️  Sistema Windows rilevato.\n";
                echo "La funzione mail() di PHP su Windows non supporta l'autenticazione SMTP.\n";
                echo "Per un invio email funzionante, considera:\n";
                echo "1. Configurare un server SMTP locale (come sendmail per XAMPP)\n";
                echo "2. Utilizzare PHPMailer o SwiftMailer\n";
                echo "3. Configurare un relay SMTP\n";
            }
        } else {
            echo "✗ La funzione mail() NON è disponibile\n";
        }
    }

} catch (Exception $e) {
    echo "\n✗ Eccezione: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Test 5: Verifica errore nel log
echo "\n=== VERIFICA LOG ERRORI ===\n";
$errorLog = ini_get('error_log');
echo "File di log: $errorLog\n";
if (file_exists($errorLog)) {
    echo "Ultimi errori correlati all'email:\n";
    $logs = file($errorLog);
    $emailErrors = array_filter($logs, function($line) {
        return stripos($line, 'EmailSender') !== false || stripos($line, 'mail') !== false;
    });

    $recentErrors = array_slice($emailErrors, -5);
    foreach ($recentErrors as $error) {
        echo "  " . trim($error) . "\n";
    }
} else {
    echo "File di log non trovato\n";
}

echo "\n=== TEST COMPLETATO ===\n";
?>