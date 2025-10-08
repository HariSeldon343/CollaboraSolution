<?php
/**
 * Applica configurazione email Nexio Solution direttamente al database
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== Aggiornamento Configurazione Email Nexio Solution ===\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Configurazione email Nexio Solution
    $emailSettings = [
        ['key' => 'smtp_host', 'value' => 'mail.nexiosolution.it', 'type' => 'string', 'desc' => 'SMTP server hostname'],
        ['key' => 'smtp_port', 'value' => '465', 'type' => 'integer', 'desc' => 'SMTP server port (465 for SSL)'],
        ['key' => 'smtp_encryption', 'value' => 'ssl', 'type' => 'string', 'desc' => 'SMTP encryption type'],
        ['key' => 'smtp_username', 'value' => 'info@nexiosolution.it', 'type' => 'string', 'desc' => 'SMTP username'],
        ['key' => 'smtp_password', 'value' => 'Ricord@1991', 'type' => 'string', 'desc' => 'SMTP password'],
        ['key' => 'from_email', 'value' => 'info@nexiosolution.it', 'type' => 'string', 'desc' => 'From email address'],
        ['key' => 'from_name', 'value' => 'Nexio Solution', 'type' => 'string', 'desc' => 'From name'],
        ['key' => 'reply_to', 'value' => 'info@nexiosolution.it', 'type' => 'string', 'desc' => 'Reply-to address'],
        ['key' => 'smtp_enabled', 'value' => '1', 'type' => 'boolean', 'desc' => 'Enable email functionality']
    ];

    echo "Applicazione configurazione:\n";
    foreach ($emailSettings as $setting) {
        $displayValue = ($setting['key'] === 'smtp_password') ? str_repeat('*', strlen($setting['value'])) : $setting['value'];
        echo "  • {$setting['key']}: {$displayValue}\n";
    }
    echo "\n";

    $conn->beginTransaction();

    foreach ($emailSettings as $setting) {
        $stmt = $conn->prepare("
            INSERT INTO system_settings
                (tenant_id, category, setting_key, setting_value, value_type, description, updated_at)
            VALUES
                (NULL, 'email', :key, :value, :type, :desc, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = :value2,
                value_type = :type2,
                description = :desc2,
                updated_at = NOW()
        ");

        $stmt->execute([
            'key' => $setting['key'],
            'value' => $setting['value'],
            'type' => $setting['type'],
            'desc' => $setting['desc'],
            'value2' => $setting['value'],
            'type2' => $setting['type'],
            'desc2' => $setting['desc']
        ]);

        $displayValue = ($setting['key'] === 'smtp_password') ? str_repeat('*', strlen($setting['value'])) : $setting['value'];
        echo "  ✓ {$setting['key']}: {$displayValue}\n";
    }

    $conn->commit();

    echo "\n✓ Configurazione salvata con successo!\n\n";

    // Verifica
    echo "Verifica configurazione salvata:\n";
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value, updated_at
        FROM system_settings
        WHERE category = 'email' AND tenant_id IS NULL
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
    echo "Il sistema ora userà:\n";
    echo "  Server: mail.nexiosolution.it:465 (SSL)\n";
    echo "  Account: info@nexiosolution.it\n\n";

    echo "Per testare l'invio email:\n";
    echo "  - Accedi a: http://localhost:8888/CollaboraNexio/test_real_email_infomaniak.php\n";
    echo "  - Oppure usa il sistema di notifiche dell'applicazione\n\n";

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}
?>
