<?php
/**
 * Script di debug per identificare problemi nell'API create_v2.php
 * Esegui questo script per verificare tutti i componenti
 */

echo "=== DEBUG API CREATE USER ===\n\n";

// 1. Verifica che tutti i file richiesti esistano
echo "1. VERIFICA FILE NECESSARI:\n";
$required_files = [
    '/includes/api_auth.php',
    '/includes/db.php',
    '/includes/EmailSender.php',
    '/config.php',
    '/api/users/create_v2.php'
];

foreach ($required_files as $file) {
    $path = __DIR__ . $file;
    if (file_exists($path)) {
        echo "  ✓ $file - OK\n";
    } else {
        echo "  ✗ $file - MANCANTE!\n";
    }
}

// 2. Verifica database
echo "\n2. VERIFICA DATABASE:\n";
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    echo "  ✓ Connessione database OK\n";

    // Verifica tabelle critiche
    $critical_tables = [
        'users' => 'Tabella utenti',
        'audit_logs' => 'Tabella log audit',
        'tenants' => 'Tabella tenants',
        'user_companies' => 'Tabella multi-tenant'
    ];

    foreach ($critical_tables as $table => $desc) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if ($result && count($result) > 0) {
            echo "  ✓ $table ($desc) - EXISTS\n";
        } else {
            echo "  ✗ $table ($desc) - MISSING!\n";
        }
    }

    // Verifica colonne della tabella users
    echo "\n3. STRUTTURA TABELLA USERS:\n";
    $columns = $db->query("SHOW COLUMNS FROM users");
    $required_columns = [
        'password_reset_token',
        'password_reset_expires',
        'first_login',
        'welcome_email_sent_at'
    ];

    foreach ($required_columns as $col) {
        $found = false;
        foreach ($columns as $column) {
            if ($column['Field'] === $col) {
                $found = true;
                break;
            }
        }
        if ($found) {
            echo "  ✓ Colonna '$col' - OK\n";
        } else {
            echo "  ✗ Colonna '$col' - MANCANTE! Deve essere aggiunta\n";
        }
    }

} catch (Exception $e) {
    echo "  ✗ Errore database: " . $e->getMessage() . "\n";
}

// 4. Test classe EmailSender
echo "\n4. TEST EMAIL SENDER:\n";
require_once __DIR__ . '/includes/EmailSender.php';

try {
    if (class_exists('EmailSender')) {
        echo "  ✓ Classe EmailSender caricata\n";

        $emailSender = new EmailSender();
        echo "  ✓ EmailSender istanziato correttamente\n";

        // Test generazione token
        $token = EmailSender::generateSecureToken();
        echo "  ✓ Token generato: " . substr($token, 0, 16) . "...\n";
    } else {
        echo "  ✗ Classe EmailSender non trovata\n";
    }
} catch (Exception $e) {
    echo "  ✗ Errore EmailSender: " . $e->getMessage() . "\n";
}

// 5. Verifica configurazione PHP per email
echo "\n5. CONFIGURAZIONE PHP MAIL:\n";
if (function_exists('mail')) {
    echo "  ✓ Funzione mail() disponibile\n";
} else {
    echo "  ✗ Funzione mail() NON disponibile\n";
}

echo "  - SMTP: " . ini_get('SMTP') . "\n";
echo "  - smtp_port: " . ini_get('smtp_port') . "\n";
echo "  - sendmail_from: " . ini_get('sendmail_from') . "\n";
echo "  - sendmail_path: " . ini_get('sendmail_path') . "\n";

if (stripos(PHP_OS, 'WIN') !== false) {
    echo "  ⚠️ Sistema Windows - mail() potrebbe non funzionare senza configurazione SMTP\n";
}

// 6. Test simulazione creazione utente (senza chiamare l'API)
echo "\n6. TEST SIMULAZIONE CREAZIONE UTENTE:\n";

try {
    $db->beginTransaction();

    $test_data = [
        'first_name' => 'Debug',
        'last_name' => 'Test',
        'email' => 'debug.test.' . time() . '@example.com',
        'password_hash' => null,
        'role' => 'user',
        'tenant_id' => 1,
        'status' => 'active',
        'password_reset_token' => bin2hex(random_bytes(32)),
        'password_reset_expires' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'first_login' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];

    echo "  Tentativo inserimento utente di test...\n";
    $user_id = $db->insert('users', $test_data);

    if ($user_id) {
        echo "  ✓ Utente inserito con ID: $user_id\n";

        // Test inserimento in audit_logs
        $log_data = [
            'tenant_id' => 1,
            'user_id' => 1,
            'action' => 'test_create',
            'details' => 'Test creazione da debug script',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Debug Script',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $log_id = $db->insert('audit_logs', $log_data);

        if ($log_id) {
            echo "  ✓ Log audit inserito con ID: $log_id\n";
        } else {
            echo "  ✗ Errore inserimento audit_logs\n";
        }

    } else {
        echo "  ✗ Errore inserimento utente\n";
    }

    // Rollback per non lasciare dati di test
    $db->rollback();
    echo "  ✓ Rollback eseguito (nessun dato salvato)\n";

} catch (Exception $e) {
    $db->rollback();
    echo "  ✗ Errore durante test: " . $e->getMessage() . "\n";
}

// 7. Verifica log degli errori
echo "\n7. ULTIMI ERRORI PHP:\n";
$error_log = ini_get('error_log');
if (file_exists($error_log)) {
    $logs = file($error_log);
    $recent = array_slice($logs, -5);
    foreach ($recent as $log) {
        if (stripos($log, 'create_v2') !== false || stripos($log, 'CREATE_USER') !== false) {
            echo "  " . trim($log) . "\n";
        }
    }
} else {
    echo "  Log file non trovato: $error_log\n";
}

echo "\n=== FINE DEBUG ===\n";
echo "\nSe tutti i test sono passati (✓), l'API dovrebbe funzionare.\n";
echo "Se ci sono errori (✗), correggili prima di riprovare.\n";
?>