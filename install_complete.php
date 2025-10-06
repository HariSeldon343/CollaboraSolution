<?php
/**
 * CollaboraNexio - Script di Installazione Completo
 * Esegue l'installazione completa del database e verifica il sistema
 */

// Configurazione
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'collaboranexio');

// Colori per output
$colors = [
    'reset' => "\033[0m",
    'red' => "\033[31m",
    'green' => "\033[32m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m"
];

// Se eseguito da browser
$is_browser = php_sapi_name() !== 'cli';

if ($is_browser) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>CollaboraNexio - Installazione</title>
        <style>
            body { font-family: monospace; background: #1a1a2e; color: #fff; padding: 20px; }
            .success { color: #4caf50; }
            .error { color: #f44336; }
            .warning { color: #ff9800; }
            .info { color: #2196f3; }
            h2 { color: #667eea; }
            pre { background: #0f0f23; padding: 15px; border-radius: 5px; overflow-x: auto; }
        </style>
    </head>
    <body>
    <h1>CollaboraNexio - Installazione Sistema</h1>
    <pre>";
}

function output($message, $type = 'info') {
    global $is_browser, $colors;

    if ($is_browser) {
        $class = $type;
        echo "<span class='$class'>$message</span>\n";
    } else {
        $color = match($type) {
            'success' => $colors['green'],
            'error' => $colors['red'],
            'warning' => $colors['yellow'],
            default => $colors['blue']
        };
        echo $color . $message . $colors['reset'] . "\n";
    }
    flush();
}

function executeSqlFile($pdo, $filepath, $description) {
    output("\n=== $description ===", 'info');

    if (!file_exists($filepath)) {
        output("File non trovato: $filepath", 'error');
        return false;
    }

    $sql = file_get_contents($filepath);
    if (empty($sql)) {
        output("File vuoto: $filepath", 'warning');
        return false;
    }

    // Dividi le query per punto e virgola, ma non quelli dentro stringhe
    $queries = preg_split('/;(?=(?:[^\'"]|[\'"][^\'"]*[\'"])*$)/', $sql);

    $success_count = 0;
    $error_count = 0;

    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) continue;

        // Salta commenti e USE statements
        if (strpos($query, '--') === 0 || stripos($query, 'USE ') === 0) continue;

        try {
            $pdo->exec($query);
            $success_count++;
            output(".", 'success');
        } catch (PDOException $e) {
            $error_count++;
            // Ignora errori "già esistente" per permettere re-installazioni
            if (strpos($e->getMessage(), 'Duplicate') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                output(".", 'warning');
            } else {
                output("\nErrore: " . $e->getMessage(), 'error');
                output("Query: " . substr($query, 0, 100) . "...", 'error');
            }
        }
    }

    output("\nCompletato: $success_count query eseguite, $error_count errori", 'info');
    return $error_count === 0;
}

// Inizio installazione
output("========================================", 'info');
output("CollaboraNexio - Installazione Completa", 'info');
output("========================================", 'info');
output("Timestamp: " . date('Y-m-d H:i:s'), 'info');

// Step 1: Connessione al server MySQL
output("\n[Step 1/5] Connessione al database...", 'info');
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
        ]
    );
    output("Connesso a MySQL", 'success');
} catch (PDOException $e) {
    output("Impossibile connettersi a MySQL: " . $e->getMessage(), 'error');
    exit(1);
}

// Step 2: Crea database se non esiste
output("\n[Step 2/5] Creazione database...", 'info');
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    output("Database '" . DB_NAME . "' pronto", 'success');
} catch (PDOException $e) {
    output("Errore creazione database: " . $e->getMessage(), 'error');
    exit(1);
}

// Step 3: Installa schema
output("\n[Step 3/5] Installazione schema database...", 'info');
$schema_file = __DIR__ . '/database/03_complete_schema.sql';
if (!executeSqlFile($pdo, $schema_file, 'Schema Completo')) {
    output("Alcuni errori durante l'installazione dello schema", 'warning');
}

// Step 4: Fix per demo data e installazione
output("\n[Step 4/5] Installazione dati demo...", 'info');

// Prima imposta una password hash valida per gli utenti demo
$password = 'Admin123!';
$password_hash = password_hash($password, PASSWORD_BCRYPT);

try {
    // Crea gli utenti con password valida
    $stmt = $pdo->prepare("
        INSERT INTO users (
            tenant_id, email, password_hash, first_name, last_name,
            display_name, role, status, email_verified_at, department, position
        ) VALUES
        (1, 'admin@demo.local', :hash, 'Admin', 'User', 'System Admin', 'admin', 'active', NOW(), 'IT', 'System Administrator'),
        (1, 'manager@demo.local', :hash, 'John', 'Manager', 'John Manager', 'manager', 'active', NOW(), 'Management', 'Project Manager'),
        (1, 'user1@demo.local', :hash, 'Alice', 'Johnson', 'Alice Johnson', 'user', 'active', NOW(), 'Development', 'Senior Developer'),
        (1, 'user2@demo.local', :hash, 'Bob', 'Smith', 'Bob Smith', 'user', 'active', NOW(), 'Development', 'Developer'),
        (1, 'designer@demo.local', :hash, 'Carol', 'White', 'Carol White', 'user', 'active', NOW(), 'Design', 'UX Designer'),
        (1, 'tester@demo.local', :hash, 'David', 'Brown', 'David Brown', 'user', 'active', NOW(), 'QA', 'QA Engineer'),
        (2, 'admin@test.local', :hash, 'Test', 'Admin', 'Test Admin', 'admin', 'active', NOW(), 'IT', 'Administrator'),
        (2, 'user@test.local', :hash, 'Test', 'User', 'Test User', 'user', 'active', NOW(), 'General', 'Employee')
        ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash),
            status = VALUES(status)
    ");
    $stmt->execute(['hash' => $password_hash]);
    output("Utenti demo creati (password: Admin123!)", 'success');
} catch (PDOException $e) {
    output("Avviso utenti: " . $e->getMessage(), 'warning');
}

// Ora carica il resto dei dati demo
$demo_file = __DIR__ . '/database/04_demo_data.sql';
if (file_exists($demo_file)) {
    // Leggi il file e sostituisci il placeholder della password
    $demo_sql = file_get_contents($demo_file);
    $demo_sql = str_replace('@password_hash', "'" . $password_hash . "'", $demo_sql);

    // Salva temporaneamente
    $temp_file = __DIR__ . '/database/temp_demo.sql';
    file_put_contents($temp_file, $demo_sql);

    executeSqlFile($pdo, $temp_file, 'Dati Demo');

    // Rimuovi file temporaneo
    @unlink($temp_file);
} else {
    output("File demo data non trovato", 'warning');
}

// Step 5: Verifica installazione
output("\n[Step 5/5] Verifica installazione...", 'info');

$tables_to_check = [
    'tenants', 'users', 'projects', 'tasks', 'files', 'folders',
    'chat_channels', 'chat_messages', 'calendar_events', 'notifications'
];

$all_ok = true;
foreach ($tables_to_check as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        output("Tabella '$table': $count record", 'success');
    } catch (PDOException $e) {
        output("Tabella '$table': ERRORE", 'error');
        $all_ok = false;
    }
}

// Crea directory necessarie
output("\n[Bonus] Creazione directory storage...", 'info');
$directories = [
    __DIR__ . '/storage',
    __DIR__ . '/storage/logs',
    __DIR__ . '/storage/cache',
    __DIR__ . '/storage/tenants',
    __DIR__ . '/storage/tenants/tenant_1',
    __DIR__ . '/storage/tenants/tenant_1/files',
    __DIR__ . '/storage/tenants/tenant_2',
    __DIR__ . '/storage/tenants/tenant_2/files',
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (mkdir($dir, 0755, true)) {
            output("Creata directory: " . basename($dir), 'success');
        } else {
            output("Impossibile creare: " . basename($dir), 'warning');
        }
    }
}

// Risultato finale
output("\n========================================", 'info');
if ($all_ok) {
    output("INSTALLAZIONE COMPLETATA CON SUCCESSO!", 'success');
    output("========================================", 'info');
    output("\nCredenziali di accesso:", 'info');
    output("Email: admin@demo.local", 'info');
    output("Password: Admin123!", 'info');
    output("\nPuoi accedere al sistema:", 'info');
    output("1. Login: http://localhost/CollaboraNexio/login.php", 'info');
    output("2. System Check: http://localhost/CollaboraNexio/system_check.php", 'info');
} else {
    output("INSTALLAZIONE COMPLETATA CON AVVISI", 'warning');
    output("========================================", 'info');
    output("Controlla gli errori sopra e riprova", 'warning');
}

if ($is_browser) {
    echo "</pre>
    <div style='margin-top: 30px; padding: 20px; background: #0f0f23; border-radius: 5px;'>
        <h2>Link Rapidi:</h2>
        <p><a href='/login.php' style='color: #4caf50;'>→ Vai al Login</a></p>
        <p><a href='/system_check.php' style='color: #2196f3;'>→ System Check</a></p>
        <p><a href='/dashboard.php' style='color: #ff9800;'>→ Dashboard (richiede login)</a></p>
    </div>
    </body>
    </html>";
}
?>