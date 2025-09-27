<?php
/**
 * CollaboraNexio - Script CLI di Configurazione Automatica
 *
 * Questo script configura automaticamente il file config.php
 * partendo dal template e generando chiavi di sicurezza
 */

// Verifica esecuzione da CLI
if (php_sapi_name() !== 'cli') {
    die("Questo script può essere eseguito solo da linea di comando\n");
}

// Colori per output CLI (Windows supporta ANSI dal Windows 10)
class ConsoleColor {
    const RESET = "\033[0m";
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";
}

// Funzione per output colorato
function console_log($message, $color = ConsoleColor::WHITE) {
    echo $color . $message . ConsoleColor::RESET . "\n";
}

// Funzione per generare chiavi sicure
function generate_secure_key($length = 32) {
    return bin2hex(random_bytes($length));
}

// Funzione per generare salt
function generate_salt() {
    return base64_encode(random_bytes(16));
}

// Funzione per rilevare percorso installazione
function detect_installation_path() {
    $current_dir = __DIR__;

    // Normalizza il percorso per Windows
    $current_dir = str_replace('\\', '/', $current_dir);

    // Estrai il percorso relativo da htdocs
    if (stripos($current_dir, '/htdocs/') !== false) {
        $parts = explode('/htdocs/', $current_dir);
        return '/htdocs/' . $parts[1];
    }

    // Fallback
    return '/htdocs/CollaboraNexio';
}

// Funzione per rilevare URL base
function detect_base_url() {
    $path = detect_installation_path();
    $path = str_replace('/htdocs', '', $path);
    return 'http://localhost' . $path;
}

// Funzione principale di configurazione
function setup_configuration() {
    console_log("\n===========================================", ConsoleColor::CYAN);
    console_log("  CollaboraNexio - Setup Configurazione", ConsoleColor::MAGENTA);
    console_log("===========================================\n", ConsoleColor::CYAN);

    // Percorsi file
    $template_file = __DIR__ . '/config.php.template';
    $config_file = __DIR__ . '/config.php';
    $backup_file = __DIR__ . '/config.php.backup.' . date('YmdHis');

    // 1. Verifica esistenza template
    console_log("[1/7] Verifica template di configurazione...", ConsoleColor::YELLOW);
    if (!file_exists($template_file)) {
        console_log("  ✗ Template non trovato: $template_file", ConsoleColor::RED);
        return false;
    }
    console_log("  ✓ Template trovato", ConsoleColor::GREEN);

    // 2. Backup configurazione esistente
    if (file_exists($config_file)) {
        console_log("\n[2/7] Backup configurazione esistente...", ConsoleColor::YELLOW);
        if (copy($config_file, $backup_file)) {
            console_log("  ✓ Backup creato: " . basename($backup_file), ConsoleColor::GREEN);
        } else {
            console_log("  ✗ Impossibile creare backup", ConsoleColor::RED);
            return false;
        }
    } else {
        console_log("\n[2/7] Nessuna configurazione esistente da salvare", ConsoleColor::YELLOW);
    }

    // 3. Lettura template
    console_log("\n[3/7] Lettura template...", ConsoleColor::YELLOW);
    $template_content = file_get_contents($template_file);
    if ($template_content === false) {
        console_log("  ✗ Impossibile leggere il template", ConsoleColor::RED);
        return false;
    }
    console_log("  ✓ Template caricato (" . strlen($template_content) . " bytes)", ConsoleColor::GREEN);

    // 4. Generazione chiavi di sicurezza
    console_log("\n[4/7] Generazione chiavi di sicurezza...", ConsoleColor::YELLOW);

    $jwt_secret = generate_secure_key(64);
    console_log("  ✓ JWT Secret generato (128 caratteri)", ConsoleColor::GREEN);

    $encryption_key = generate_secure_key(32);
    console_log("  ✓ Encryption Key generata (64 caratteri)", ConsoleColor::GREEN);

    $app_salt = generate_salt();
    console_log("  ✓ Application Salt generato", ConsoleColor::GREEN);

    $session_name = 'CNEXIO_' . strtoupper(substr(md5(time()), 0, 8));
    console_log("  ✓ Session Name: $session_name", ConsoleColor::GREEN);

    // 5. Rilevamento percorsi
    console_log("\n[5/7] Rilevamento percorsi automatici...", ConsoleColor::YELLOW);

    $install_path = detect_installation_path();
    $base_url = detect_base_url();
    $upload_dir = __DIR__ . '/uploads';
    $log_dir = __DIR__ . '/logs';
    $temp_dir = __DIR__ . '/temp';

    console_log("  • Percorso installazione: $install_path", ConsoleColor::CYAN);
    console_log("  • URL base: $base_url", ConsoleColor::CYAN);
    console_log("  • Directory uploads: $upload_dir", ConsoleColor::CYAN);

    // 6. Sostituzione valori nel template
    console_log("\n[6/7] Configurazione parametri...", ConsoleColor::YELLOW);

    $replacements = [
        // Database - valori standard XAMPP
        "'localhost'" => "'localhost'",
        "'your_database_name'" => "'collaboranexio'",
        "'your_database_user'" => "'root'",
        "'your_database_password'" => "''",  // Password vuota per XAMPP standard

        // Sicurezza
        "'your-secret-jwt-key-here'" => "'$jwt_secret'",
        "'your-encryption-key-here'" => "'$encryption_key'",
        "'your-app-salt-here'" => "'$app_salt'",
        "'PHPSESSID'" => "'$session_name'",

        // Percorsi
        "'/path/to/uploads'" => "'" . str_replace('\\', '/', $upload_dir) . "'",
        "'/path/to/logs'" => "'" . str_replace('\\', '/', $log_dir) . "'",
        "'/path/to/temp'" => "'" . str_replace('\\', '/', $temp_dir) . "'",
        "'http://localhost/collaboranexio'" => "'$base_url'",

        // Email (configurazione di default)
        "'smtp.example.com'" => "'localhost'",
        "'587'" => "'25'",
        "'noreply@example.com'" => "'noreply@collaboranexio.local'",
        "'smtp_username'" => "''",
        "'smtp_password'" => "''",

        // Impostazioni di default
        "'development'" => "'development'",  // Mantieni in development per test
        "'30'" => "'30'",  // Session lifetime
        "'50'" => "'50'",  // Max upload MB
        "'100'" => "'100'", // Rate limit
        "'300'" => "'300'", // Rate limit window
    ];

    $config_content = $template_content;
    $replacements_count = 0;

    foreach ($replacements as $search => $replace) {
        $count = 0;
        $config_content = str_replace($search, $replace, $config_content, $count);
        if ($count > 0) {
            $replacements_count += $count;
        }
    }

    console_log("  ✓ $replacements_count sostituzioni effettuate", ConsoleColor::GREEN);

    // Aggiungi timestamp di generazione
    $generation_comment = "\n// Configurazione generata automaticamente il " . date('Y-m-d H:i:s') . "\n";
    $config_content = str_replace('<?php', '<?php' . $generation_comment, $config_content);

    // 7. Scrittura file di configurazione
    console_log("\n[7/7] Scrittura file di configurazione...", ConsoleColor::YELLOW);

    if (file_put_contents($config_file, $config_content) !== false) {
        console_log("  ✓ config.php creato con successo", ConsoleColor::GREEN);

        // Verifica sintassi PHP
        $syntax_check = shell_exec('php -l "' . $config_file . '" 2>&1');
        if (strpos($syntax_check, 'No syntax errors') !== false) {
            console_log("  ✓ Sintassi PHP verificata", ConsoleColor::GREEN);
        } else {
            console_log("  ! Attenzione: possibili errori di sintassi", ConsoleColor::YELLOW);
        }

        // Creazione directory necessarie
        console_log("\n[Bonus] Creazione directory necessarie...", ConsoleColor::YELLOW);

        $directories = [$upload_dir, $log_dir, $temp_dir];
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (mkdir($dir, 0755, true)) {
                    console_log("  ✓ Creata directory: " . basename($dir), ConsoleColor::GREEN);
                } else {
                    console_log("  ✗ Impossibile creare: " . basename($dir), ConsoleColor::RED);
                }
            } else {
                console_log("  • Directory esistente: " . basename($dir), ConsoleColor::CYAN);
            }
        }

        // Creazione .htaccess per protezione
        $htaccess_content = "# Protezione directory\nOptions -Indexes\nDeny from all";
        $protected_dirs = [$log_dir, $temp_dir];

        foreach ($protected_dirs as $dir) {
            $htaccess_file = $dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                if (file_put_contents($htaccess_file, $htaccess_content) !== false) {
                    console_log("  ✓ Protezione .htaccess per: " . basename($dir), ConsoleColor::GREEN);
                }
            }
        }

        // Riepilogo configurazione
        console_log("\n===========================================", ConsoleColor::CYAN);
        console_log("         CONFIGURAZIONE COMPLETATA", ConsoleColor::GREEN);
        console_log("===========================================", ConsoleColor::CYAN);
        console_log("\nRiepilogo configurazione:", ConsoleColor::WHITE);
        console_log("  • Database: collaboranexio@localhost", ConsoleColor::CYAN);
        console_log("  • Utente DB: root", ConsoleColor::CYAN);
        console_log("  • URL: $base_url", ConsoleColor::CYAN);
        console_log("  • Modalità: development", ConsoleColor::CYAN);
        console_log("  • JWT Secret: " . substr($jwt_secret, 0, 16) . "...", ConsoleColor::CYAN);
        console_log("  • Session: $session_name", ConsoleColor::CYAN);

        if (file_exists($backup_file)) {
            console_log("\nBackup precedente salvato in:", ConsoleColor::YELLOW);
            console_log("  " . basename($backup_file), ConsoleColor::WHITE);
        }

        console_log("\n✓ Configurazione completata con successo!", ConsoleColor::GREEN);

        return true;
    } else {
        console_log("  ✗ Impossibile scrivere config.php", ConsoleColor::RED);
        console_log("  Verificare i permessi di scrittura nella directory", ConsoleColor::YELLOW);
        return false;
    }
}

// Funzione per test post-configurazione
function test_configuration() {
    $config_file = __DIR__ . '/config.php';

    console_log("\n[TEST] Verifica configurazione...", ConsoleColor::YELLOW);

    if (!file_exists($config_file)) {
        console_log("  ✗ File config.php non trovato", ConsoleColor::RED);
        return false;
    }

    // Prova a includere il file
    try {
        require_once $config_file;
        console_log("  ✓ File config.php caricato correttamente", ConsoleColor::GREEN);

        // Verifica costanti critiche
        $required_constants = [
            'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
            'JWT_SECRET', 'ENCRYPTION_KEY', 'APP_SALT',
            'UPLOAD_DIR', 'BASE_URL'
        ];

        $missing = [];
        foreach ($required_constants as $constant) {
            if (!defined($constant)) {
                $missing[] = $constant;
            }
        }

        if (empty($missing)) {
            console_log("  ✓ Tutte le costanti richieste sono definite", ConsoleColor::GREEN);
            return true;
        } else {
            console_log("  ✗ Costanti mancanti: " . implode(', ', $missing), ConsoleColor::RED);
            return false;
        }
    } catch (Exception $e) {
        console_log("  ✗ Errore nel caricamento: " . $e->getMessage(), ConsoleColor::RED);
        return false;
    }
}

// Esecuzione principale
try {
    // Setup configurazione
    if (setup_configuration()) {
        // Test configurazione
        if (test_configuration()) {
            console_log("\n✓ Setup completato con successo!", ConsoleColor::GREEN);
            exit(0);
        } else {
            console_log("\n✗ Test configurazione fallito", ConsoleColor::RED);
            exit(1);
        }
    } else {
        console_log("\n✗ Setup configurazione fallito", ConsoleColor::RED);
        exit(1);
    }
} catch (Exception $e) {
    console_log("\n✗ Errore critico: " . $e->getMessage(), ConsoleColor::RED);
    exit(1);
}