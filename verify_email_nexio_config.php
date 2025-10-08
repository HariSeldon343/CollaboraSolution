<?php
/**
 * Script di Verifica Configurazione Email Nexio Solution
 *
 * Verifica che tutte le configurazioni email siano correttamente
 * impostate con le credenziali Nexio Solution
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "==========================================================\n";
echo "  VERIFICA CONFIGURAZIONE EMAIL NEXIO SOLUTION\n";
echo "==========================================================\n\n";

// STEP 1: Verifica file config_email.php
echo "STEP 1: Verifica file config_email.php\n";
echo "-----------------------------------------------------------\n";

$configFile = __DIR__ . '/includes/config_email.php';
if (!file_exists($configFile)) {
    echo "❌ ERRORE: File config_email.php NON trovato!\n";
    echo "   Percorso atteso: $configFile\n\n";
} else {
    echo "✅ File config_email.php trovato\n";

    // Carica configurazione
    require_once $configFile;

    $checks = [
        'EMAIL_SMTP_HOST' => 'mail.nexiosolution.it',
        'EMAIL_SMTP_PORT' => 465,
        'EMAIL_SMTP_USERNAME' => 'info@nexiosolution.it',
        'EMAIL_FROM_EMAIL' => 'info@nexiosolution.it',
        'EMAIL_REPLY_TO' => 'info@nexiosolution.it'
    ];

    $allOk = true;
    foreach ($checks as $constant => $expectedValue) {
        if (!defined($constant)) {
            echo "   ❌ $constant NON definita\n";
            $allOk = false;
        } else {
            $actualValue = constant($constant);
            if ($actualValue == $expectedValue) {
                echo "   ✅ $constant = $actualValue\n";
            } else {
                echo "   ❌ $constant = $actualValue (atteso: $expectedValue)\n";
                $allOk = false;
            }
        }
    }

    // Verifica password (senza mostrare valore)
    if (defined('EMAIL_SMTP_PASSWORD')) {
        $password = EMAIL_SMTP_PASSWORD;
        if ($password === 'Ricord@1991') {
            echo "   ✅ EMAIL_SMTP_PASSWORD configurata correttamente\n";
        } else {
            echo "   ❌ EMAIL_SMTP_PASSWORD NON corretta\n";
            $allOk = false;
        }
    } else {
        echo "   ❌ EMAIL_SMTP_PASSWORD NON definita\n";
        $allOk = false;
    }

    echo "\n";
    if ($allOk) {
        echo "✅ File config_email.php: CONFIGURAZIONE CORRETTA\n\n";
    } else {
        echo "❌ File config_email.php: ERRORI TROVATI\n\n";
    }
}

// STEP 2: Verifica fallback in email_config.php
echo "STEP 2: Verifica fallback in email_config.php\n";
echo "-----------------------------------------------------------\n";

$emailConfigFile = __DIR__ . '/includes/email_config.php';
if (!file_exists($emailConfigFile)) {
    echo "❌ ERRORE: File email_config.php NON trovato!\n\n";
} else {
    echo "✅ File email_config.php trovato\n";

    // Cerca pattern nel file
    $content = file_get_contents($emailConfigFile);

    $patterns = [
        'mail.nexiosolution.it' => 'SMTP Host Nexio',
        'info@nexiosolution.it' => 'Email Nexio',
        'Ricord@1991' => 'Password Nexio'
    ];

    $allFound = true;
    foreach ($patterns as $pattern => $description) {
        if (strpos($content, $pattern) !== false) {
            echo "   ✅ $description trovato\n";
        } else {
            echo "   ❌ $description NON trovato\n";
            $allFound = false;
        }
    }

    // Verifica che NON ci siano vecchie credenziali
    $oldPatterns = [
        'mail.infomaniak.com' => 'SMTP Infomaniak (vecchio)',
        'info@fortibyte.it' => 'Email Fortibyte (vecchio)',
        'Cartesi@1987' => 'Password Fortibyte (vecchio)'
    ];

    $noOldFound = true;
    foreach ($oldPatterns as $oldPattern => $description) {
        if (strpos($content, $oldPattern) !== false) {
            echo "   ⚠️  $description ancora presente (dovrebbe essere rimosso)\n";
            $noOldFound = false;
        }
    }

    echo "\n";
    if ($allFound && $noOldFound) {
        echo "✅ File email_config.php: FALLBACK CORRETTO\n\n";
    } else {
        echo "⚠️  File email_config.php: CONTROLLARE MANUALMENTE\n\n";
    }
}

// STEP 3: Verifica funzione loadEmailConfig()
echo "STEP 3: Test funzione loadEmailConfig()\n";
echo "-----------------------------------------------------------\n";

try {
    require_once __DIR__ . '/includes/mailer.php';

    $config = loadEmailConfig();

    if ($config === null) {
        echo "❌ ERRORE: loadEmailConfig() ritorna NULL\n\n";
    } else {
        echo "✅ loadEmailConfig() funziona\n";

        $expectedConfig = [
            'smtp_host' => 'mail.nexiosolution.it',
            'smtp_port' => 465,
            'smtp_username' => 'info@nexiosolution.it',
            'from_email' => 'info@nexiosolution.it',
            'reply_to' => 'info@nexiosolution.it'
        ];

        $configOk = true;
        foreach ($expectedConfig as $key => $expectedValue) {
            if (!isset($config[$key])) {
                echo "   ❌ Chiave '$key' mancante\n";
                $configOk = false;
            } elseif ($config[$key] != $expectedValue) {
                echo "   ❌ $key = {$config[$key]} (atteso: $expectedValue)\n";
                $configOk = false;
            } else {
                echo "   ✅ $key = {$config[$key]}\n";
            }
        }

        // Verifica password senza mostrare
        if (isset($config['smtp_password']) && $config['smtp_password'] === 'Ricord@1991') {
            echo "   ✅ smtp_password configurata\n";
        } else {
            echo "   ❌ smtp_password NON corretta\n";
            $configOk = false;
        }

        echo "\n";
        if ($configOk) {
            echo "✅ Configurazione caricata: CORRETTA\n\n";
        } else {
            echo "❌ Configurazione caricata: ERRORI TROVATI\n\n";
        }
    }
} catch (Exception $e) {
    echo "❌ ERRORE nel caricamento: " . $e->getMessage() . "\n\n";
}

// STEP 4: Verifica database (opzionale)
echo "STEP 4: Verifica database system_settings (opzionale)\n";
echo "-----------------------------------------------------------\n";

try {
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Verifica se tabella esiste
    $stmt = $conn->query("SHOW TABLES LIKE 'system_settings'");

    if ($stmt->rowCount() === 0) {
        echo "⚠️  Tabella system_settings NON esiste\n";
        echo "   Configurazione file sarà usata (OK)\n";
        echo "   Per creare tabella: mysql -u root collaboranexio < database/create_system_settings.sql\n\n";
    } else {
        echo "✅ Tabella system_settings esiste\n";

        // Verifica configurazione email
        $emailSettings = $conn->query("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE category = 'email'
            ORDER BY setting_key
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($emailSettings)) {
            echo "   ⚠️  Nessuna configurazione email nel database\n";
            echo "   Per aggiungere: mysql -u root collaboranexio < database/update_nexio_email_config.sql\n\n";
        } else {
            echo "   Configurazione trovata:\n";

            $dbChecks = [
                'smtp_host' => 'mail.nexiosolution.it',
                'smtp_port' => '465',
                'smtp_username' => 'info@nexiosolution.it',
                'from_email' => 'info@nexiosolution.it',
                'reply_to' => 'info@nexiosolution.it'
            ];

            $dbOk = true;
            foreach ($dbChecks as $key => $expectedValue) {
                if (!isset($emailSettings[$key])) {
                    echo "   ⚠️  $key non trovato nel database\n";
                } elseif ($emailSettings[$key] == $expectedValue) {
                    echo "   ✅ $key = {$emailSettings[$key]}\n";
                } else {
                    echo "   ❌ $key = {$emailSettings[$key]} (atteso: $expectedValue)\n";
                    $dbOk = false;
                }
            }

            // Password (senza mostrare)
            if (isset($emailSettings['smtp_password'])) {
                if ($emailSettings['smtp_password'] === 'Ricord@1991') {
                    echo "   ✅ smtp_password configurata nel database\n";
                } else {
                    echo "   ❌ smtp_password NON corretta nel database\n";
                    $dbOk = false;
                }
            } else {
                echo "   ⚠️  smtp_password non trovata nel database\n";
            }

            echo "\n";
            if ($dbOk) {
                echo "✅ Database configurato correttamente\n\n";
            } else {
                echo "❌ Database: ERRORI TROVATI\n";
                echo "   Esegui: mysql -u root collaboranexio < database/update_nexio_email_config.sql\n\n";
            }
        }
    }
} catch (Exception $e) {
    echo "⚠️  Impossibile verificare database: " . $e->getMessage() . "\n\n";
}

// STEP 5: Verifica PHPMailer
echo "STEP 5: Verifica PHPMailer\n";
echo "-----------------------------------------------------------\n";

$phpMailerFiles = [
    __DIR__ . '/includes/PHPMailer/PHPMailer.php',
    __DIR__ . '/includes/PHPMailer/SMTP.php',
    __DIR__ . '/includes/PHPMailer/Exception.php'
];

$phpMailerOk = true;
foreach ($phpMailerFiles as $file) {
    $basename = basename($file);
    if (file_exists($file)) {
        echo "   ✅ $basename trovato\n";
    } else {
        echo "   ❌ $basename NON trovato\n";
        $phpMailerOk = false;
    }
}

echo "\n";
if ($phpMailerOk) {
    echo "✅ PHPMailer installato correttamente\n\n";
} else {
    echo "❌ PHPMailer incompleto - email NON funzioneranno\n\n";
}

// RIEPILOGO FINALE
echo "==========================================================\n";
echo "  RIEPILOGO FINALE\n";
echo "==========================================================\n\n";

echo "COSA FARE ADESSO:\n\n";

echo "1. ✅ CONFIGURAZIONE FILE:\n";
echo "   - config_email.php aggiornato con credenziali Nexio\n";
echo "   - email_config.php fallback configurato\n\n";

echo "2. 🔄 AGGIORNA DATABASE (OPZIONALE MA CONSIGLIATO):\n";
echo "   Esegui da terminale:\n";
echo "   mysql -u root collaboranexio < database/update_nexio_email_config.sql\n\n";

echo "3. 🧪 TEST EMAIL:\n";
echo "   a) Vai su: http://localhost:8888/CollaboraNexio/utenti.php\n";
echo "   b) Crea nuovo utente con la TUA email reale\n";
echo "   c) Controlla di ricevere email da info@nexiosolution.it\n\n";

echo "4. 📋 LOG:\n";
echo "   Monitora: logs/mailer_error.log\n";
echo "   tail -f logs/mailer_error.log\n\n";

echo "5. 🐛 DEBUG (se email non funziona):\n";
echo "   a) Abilita debug in config_email.php:\n";
echo "      define('EMAIL_DEBUG_MODE', true);\n";
echo "   b) Test SMTP: http://localhost:8888/CollaboraNexio/test_mailer_smtp.php\n\n";

echo "==========================================================\n";
echo "  VERIFICA COMPLETATA\n";
echo "==========================================================\n";
