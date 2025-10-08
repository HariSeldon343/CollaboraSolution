<?php
/**
 * Test caricamento configurazione email Nexio Solution
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_config.php';

echo "=== Test Configurazione Email Nexio Solution ===\n\n";

try {
    // Carica configurazione dal database
    $config = getEmailConfigFromDatabase();

    echo "Configurazione caricata da database:\n";
    echo "─────────────────────────────────────────\n";
    echo "SMTP Host:     {$config['smtpHost']}\n";
    echo "SMTP Port:     {$config['smtpPort']}\n";
    echo "SMTP Username: {$config['smtpUsername']}\n";
    echo "SMTP Password: " . str_repeat('*', strlen($config['smtpPassword'])) . " (" . strlen($config['smtpPassword']) . " caratteri)\n";
    echo "From Email:    {$config['fromEmail']}\n";
    echo "From Name:     {$config['fromName']}\n";
    echo "Reply-To:      {$config['replyTo']}\n";
    echo "─────────────────────────────────────────\n\n";

    // Verifica che sia la configurazione Nexio
    $isNexio = (strpos($config['smtpHost'], 'nexiosolution.it') !== false);
    $correctUsername = ($config['smtpUsername'] === 'info@nexiosolution.it');
    $correctPort = ($config['smtpPort'] == 465);

    if ($isNexio && $correctUsername && $correctPort) {
        echo "✅ SUCCESSO: Configurazione Nexio Solution caricata correttamente!\n\n";

        echo "Verifica configurazione:\n";
        echo "  ✓ Server SMTP: mail.nexiosolution.it\n";
        echo "  ✓ Porta: 465 (SSL)\n";
        echo "  ✓ Account: info@nexiosolution.it\n";
        echo "  ✓ Password: configurata (" . strlen($config['smtpPassword']) . " caratteri)\n\n";

        // Test che la configurazione sia nel database (non fallback)
        $isFromDb = isEmailConfiguredInDatabase();
        if ($isFromDb) {
            echo "✅ Configurazione caricata DAL DATABASE (non fallback)\n\n";
        } else {
            echo "⚠️  WARNING: Configurazione caricata da FALLBACK hardcoded\n";
            echo "   Il database potrebbe non avere i record necessari.\n\n";
        }

        echo "Il sistema è pronto per inviare email tramite Nexio Solution.\n";

    } else {
        echo "❌ ERRORE: Configurazione caricata non corrisponde a Nexio Solution\n\n";

        if (!$isNexio) {
            echo "  ✗ Server SMTP errato: {$config['smtpHost']}\n";
            echo "    Atteso: mail.nexiosolution.it\n";
        }

        if (!$correctUsername) {
            echo "  ✗ Username errato: {$config['smtpUsername']}\n";
            echo "    Atteso: info@nexiosolution.it\n";
        }

        if (!$correctPort) {
            echo "  ✗ Porta errata: {$config['smtpPort']}\n";
            echo "    Attesa: 465\n";
        }

        echo "\nEseguire: php apply_nexio_email_config.php\n";
    }

} catch (Exception $e) {
    echo "❌ ERRORE durante il test: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "\n";
?>
