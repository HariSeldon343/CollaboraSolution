<?php
/**
 * Aggiorna configurazione email con credenziali Nexio Solution
 *
 * Nuova configurazione:
 * - Server SMTP: mail.nexiosolution.it
 * - Porta: 465 (SSL)
 * - Username: info@nexiosolution.it
 * - Password: Ricord@1991
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/email_config.php';

echo "=== Aggiornamento Configurazione Email Nexio Solution ===\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Verifica se la tabella system_settings esiste
    $stmt = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($stmt->rowCount() === 0) {
        echo "❌ ERRORE: Tabella system_settings non trovata!\n";
        echo "   Eseguire prima: php database/manage_database.php full\n\n";
        exit(1);
    }

    echo "✓ Tabella system_settings trovata\n\n";

    // Configurazione email Nexio Solution
    $emailConfig = [
        'smtp_host' => 'mail.nexiosolution.it',
        'smtp_port' => '465',
        'smtp_username' => 'info@nexiosolution.it',
        'smtp_password' => 'Ricord@1991',
        'from_email' => 'info@nexiosolution.it',
        'from_name' => 'Nexio Solution',
        'reply_to' => 'info@nexiosolution.it'
    ];

    echo "Configurazione da applicare:\n";
    echo "  SMTP Host: {$emailConfig['smtp_host']}\n";
    echo "  SMTP Port: {$emailConfig['smtp_port']} (SSL)\n";
    echo "  Username: {$emailConfig['smtp_username']}\n";
    echo "  Password: " . str_repeat('*', strlen($emailConfig['smtp_password'])) . "\n";
    echo "  From Email: {$emailConfig['from_email']}\n";
    echo "  From Name: {$emailConfig['from_name']}\n";
    echo "  Reply-To: {$emailConfig['reply_to']}\n\n";

    // Mostra configurazione attuale
    echo "Configurazione attuale nel database:\n";
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value, value_type
        FROM system_settings
        WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'reply_to')
        ORDER BY setting_key
    ");
    $stmt->execute();
    $currentSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($currentSettings)) {
        echo "  Nessuna configurazione email presente\n\n";
    } else {
        foreach ($currentSettings as $setting) {
            $value = $setting['setting_value'];
            if ($setting['setting_key'] === 'smtp_password') {
                $value = str_repeat('*', strlen($value));
            }
            echo "  {$setting['setting_key']}: {$value}\n";
        }
        echo "\n";
    }

    // Salva la nuova configurazione
    echo "Salvataggio nuova configurazione...\n";

    $success = saveEmailConfigToDatabase($emailConfig);

    if ($success) {
        echo "✓ Configurazione email aggiornata con successo!\n\n";

        // Verifica il salvataggio
        echo "Verifica configurazione salvata:\n";
        $stmt = $conn->prepare("
            SELECT setting_key, setting_value, value_type, updated_at
            FROM system_settings
            WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'reply_to')
            ORDER BY setting_key
        ");
        $stmt->execute();
        $savedSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($savedSettings as $setting) {
            $value = $setting['setting_value'];
            if ($setting['setting_key'] === 'smtp_password') {
                $value = str_repeat('*', strlen($value));
            }
            echo "  ✓ {$setting['setting_key']}: {$value} (updated: {$setting['updated_at']})\n";
        }

        echo "\n=== CONFIGURAZIONE COMPLETATA ===\n\n";
        echo "La nuova configurazione sarà utilizzata automaticamente da:\n";
        echo "  - EmailSender.php (tramite getEmailConfigFromDatabase())\n";
        echo "  - Tutti i moduli di invio email del sistema\n\n";

        echo "Test consigliato:\n";
        echo "  php test_real_email_infomaniak.php\n\n";

    } else {
        echo "❌ ERRORE durante il salvataggio della configurazione\n";
        echo "   Controllare i log in: logs/php_errors.log\n\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Linea: " . $e->getLine() . "\n\n";
    exit(1);
}

echo "Done.\n";
?>
