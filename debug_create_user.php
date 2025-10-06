<?php
/**
 * Script di debug per verificare il problema con create_v2.php
 * Da eseguire via browser: http://localhost:8888/CollaboraNexio/debug_create_user.php
 */

// Abilita tutti gli errori
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Debug Create User</h1>";
echo "<pre>";

// Step 1: Verifica inclusione file
echo "=== STEP 1: Verifica File ===\n";
$files_to_check = [
    '/includes/api_auth.php',
    '/includes/session_init.php',
    '/includes/db.php',
    '/config.php',
    '/includes/EmailSender.php'
];

foreach ($files_to_check as $file) {
    $full_path = __DIR__ . $file;
    if (file_exists($full_path)) {
        echo "✓ File trovato: $file\n";
    } else {
        echo "✗ FILE MANCANTE: $file\n";
    }
}

// Step 2: Prova ad includere i file
echo "\n=== STEP 2: Include File ===\n";
try {
    require_once __DIR__ . '/config.php';
    echo "✓ config.php caricato\n";

    require_once __DIR__ . '/includes/db.php';
    echo "✓ db.php caricato\n";

    require_once __DIR__ . '/includes/session_init.php';
    echo "✓ session_init.php caricato\n";

    require_once __DIR__ . '/includes/api_auth.php';
    echo "✓ api_auth.php caricato\n";

    require_once __DIR__ . '/includes/EmailSender.php';
    echo "✓ EmailSender.php caricato\n";
} catch (Exception $e) {
    echo "✗ Errore nel caricare i file: " . $e->getMessage() . "\n";
}

// Step 3: Verifica connessione database
echo "\n=== STEP 3: Database Connection ===\n";
try {
    $db = Database::getInstance();
    echo "✓ Connessione al database riuscita\n";
} catch (Exception $e) {
    echo "✗ Errore connessione DB: " . $e->getMessage() . "\n";
    exit;
}

// Step 4: Verifica struttura tabella users
echo "\n=== STEP 4: Struttura Tabella Users ===\n";
try {
    $result = $db->query("DESCRIBE users");
    $columns = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
        echo sprintf("  %-30s %s %s %s\n",
            $row['Field'],
            $row['Type'],
            $row['Null'] === 'YES' ? 'NULL' : 'NOT NULL',
            $row['Default'] ? "Default: {$row['Default']}" : ''
        );
    }

    // Verifica colonne critiche per il sistema di prima password
    $required_columns = [
        'password_reset_token',
        'password_reset_expires',
        'first_login',
        'welcome_email_sent_at'
    ];

    echo "\n  Verifica colonne sistema prima password:\n";
    foreach ($required_columns as $col) {
        if (in_array($col, $columns)) {
            echo "  ✓ $col presente\n";
        } else {
            echo "  ✗ $col MANCANTE!\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Errore verifica tabella: " . $e->getMessage() . "\n";
}

// Step 5: Test classe EmailSender
echo "\n=== STEP 5: Test EmailSender ===\n";
try {
    if (class_exists('EmailSender')) {
        echo "✓ Classe EmailSender trovata\n";

        // Verifica metodi
        $methods = get_class_methods('EmailSender');
        if (in_array('generateSecureToken', $methods)) {
            echo "✓ Metodo generateSecureToken presente\n";
        } else {
            echo "✗ Metodo generateSecureToken MANCANTE\n";
        }

        if (in_array('sendWelcomeEmail', $methods)) {
            echo "✓ Metodo sendWelcomeEmail presente\n";
        } else {
            echo "✗ Metodo sendWelcomeEmail MANCANTE\n";
        }

        // Test generazione token
        try {
            $token = EmailSender::generateSecureToken();
            echo "✓ Token generato: " . substr($token, 0, 20) . "...\n";
        } catch (Exception $e) {
            echo "✗ Errore generazione token: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✗ Classe EmailSender NON trovata\n";
    }
} catch (Exception $e) {
    echo "✗ Errore test EmailSender: " . $e->getMessage() . "\n";
}

// Step 6: Simula sessione per test
echo "\n=== STEP 6: Test Sessione ===\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "✓ Sessione avviata\n";
} else {
    echo "✓ Sessione già attiva\n";
}

// Simula utente admin per test
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
echo "✓ Sessione configurata per test\n";

// Step 7: Test creazione utente minimale
echo "\n=== STEP 7: Test Creazione Utente ===\n";
try {
    // Genera dati test
    $test_email = 'test_' . time() . '@example.com';
    $reset_token = bin2hex(random_bytes(32));
    $reset_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Inizia transazione
    $db->beginTransaction();

    // Prova inserimento
    $user_data = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $test_email,
        'password_hash' => null,
        'role' => 'user',
        'tenant_id' => 1,
        'status' => 'active',
        'password_reset_token' => $reset_token,
        'password_reset_expires' => $reset_expires,
        'first_login' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];

    echo "Tentativo inserimento utente con email: $test_email\n";

    $new_user_id = $db->insert('users', $user_data);

    if ($new_user_id) {
        echo "✓ Utente creato con ID: $new_user_id\n";

        // Verifica inserimento
        $check = $db->fetchOne(
            "SELECT id, email FROM users WHERE id = :id",
            [':id' => $new_user_id]
        );

        if ($check) {
            echo "✓ Utente verificato nel database\n";
        }

        // Rollback per non lasciare dati test
        $db->rollback();
        echo "✓ Rollback eseguito (test completato)\n";
    } else {
        echo "✗ Inserimento fallito\n";
        $db->rollback();
    }
} catch (Exception $e) {
    $db->rollback();
    echo "✗ Errore test creazione: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Step 8: Test delle funzioni API
echo "\n=== STEP 8: Test Funzioni API ===\n";
try {
    // Verifica che le funzioni esistano
    $api_functions = [
        'initializeApiEnvironment',
        'verifyApiAuthentication',
        'verifyApiCsrfToken',
        'getApiUserInfo',
        'requireApiRole',
        'apiSuccess',
        'apiError',
        'logApiError'
    ];

    foreach ($api_functions as $func) {
        if (function_exists($func)) {
            echo "✓ Funzione $func disponibile\n";
        } else {
            echo "✗ Funzione $func NON trovata\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Errore test funzioni: " . $e->getMessage() . "\n";
}

// Step 9: Verifica tabelle correlate
echo "\n=== STEP 9: Verifica Tabelle Correlate ===\n";
$tables_to_check = ['tenants', 'user_companies', 'audit_logs'];
foreach ($tables_to_check as $table) {
    try {
        $result = $db->query("SELECT 1 FROM $table LIMIT 1");
        echo "✓ Tabella $table esiste\n";
    } catch (Exception $e) {
        echo "✗ Tabella $table non trovata o errore: " . substr($e->getMessage(), 0, 50) . "\n";
    }
}

echo "\n=== FINE DEBUG ===\n";
echo "</pre>";

// Mostra info PHP
echo "<h2>PHP Info</h2>";
echo "<pre>";
echo "PHP Version: " . phpversion() . "\n";
echo "Error reporting: " . error_reporting() . "\n";
echo "Display errors: " . ini_get('display_errors') . "\n";
echo "Log errors: " . ini_get('log_errors') . "\n";
echo "Error log: " . ini_get('error_log') . "\n";
echo "</pre>";
?>