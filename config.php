<?php
/**
 * CollaboraNexio - Development Configuration
 * For XAMPP on Windows with port 8888
 */

declare(strict_types=1);

// ==============================================================
// ENVIRONMENT SETTINGS
// ==============================================================

// Rileva automaticamente l'ambiente basandosi sull'hostname
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Rimuovi la porta da HTTP_HOST per evitare duplicazione in BASE_URL
$currentHost = preg_replace('/:\d+$/', '', $currentHost);
$isProduction = false;

if (strpos($currentHost, 'nexiosolution.it') !== false) {
    // Ambiente di produzione (Cloudflare)
    $isProduction = true;
    define('PRODUCTION_MODE', true);
    define('ENVIRONMENT', 'production');
    define('DEBUG_MODE', false);

    // Disabilita error reporting in produzione
    error_reporting(E_ERROR | E_PARSE);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    // Ambiente di sviluppo locale
    $isProduction = false;
    define('PRODUCTION_MODE', false);
    define('ENVIRONMENT', 'development');
    define('DEBUG_MODE', true);

    // Enable error reporting for development
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Log degli errori per entrambi gli ambienti
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// ==============================================================
// DATABASE CONFIGURATION
// ==============================================================

// XAMPP default MySQL settings
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'collaboranexio');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP default: empty password
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// PDO options
define('DB_PERSISTENT', false); // Add missing constant
define('LOG_LEVEL', 'ERROR'); // Add missing log level constant
define('DB_PDO_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    PDO::ATTR_PERSISTENT => false
]);

// ==============================================================
// SESSION CONFIGURATION
// ==============================================================

// Session settings
define('SESSION_LIFETIME', 7200); // 2 hours

// Nome della sessione comune per entrambi gli ambienti
define('SESSION_NAME', 'COLLAB_SID');  // Common session name for both environments

// Configurazioni basate sull'ambiente
if (PRODUCTION_MODE) {
    define('SESSION_SECURE', true); // HTTPS in produzione
    define('SESSION_DOMAIN', '.nexiosolution.it'); // Dominio con punto iniziale per supportare sottodomini
} else {
    define('SESSION_SECURE', false); // HTTP in sviluppo locale
    define('SESSION_DOMAIN', ''); // Vuoto per localhost
}

define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Lax'); // Lax per permettere navigazione cross-domain

// Session configuration moved to includes/session_init.php
// to be applied BEFORE session_start() is called

// ==============================================================
// APPLICATION SETTINGS
// ==============================================================

// Base URL - Dinamico basato sull'ambiente
if (PRODUCTION_MODE) {
    // Produzione su Cloudflare
    define('BASE_URL', 'https://app.nexiosolution.it/CollaboraNexio');
} else {
    // Sviluppo locale
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    $portStr = '';

    // Aggiungi la porta solo se non è standard
    if (($protocol === 'http' && $port != '80') || ($protocol === 'https' && $port != '443')) {
        $portStr = ':' . $port;
    }

    define('BASE_URL', $protocol . '://' . $currentHost . $portStr . '/CollaboraNexio');
}

define('BASE_PATH', __DIR__);
define('UPLOAD_PATH', __DIR__ . '/uploads');
define('TEMP_PATH', __DIR__ . '/temp');
define('LOG_PATH', __DIR__ . '/logs');

// Create directories if they don't exist
foreach ([UPLOAD_PATH, TEMP_PATH, LOG_PATH] as $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

// File upload settings
define('MAX_FILE_SIZE', 104857600); // 100MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt', 'csv']);

// ==============================================================
// SECURITY SETTINGS
// ==============================================================

// Security keys (change these in production!)
define('CSRF_TOKEN_NAME', '_token');
define('ENCRYPTION_KEY', 'dev_encryption_key_change_in_production');
define('JWT_SECRET', 'dev_jwt_secret_change_in_production');

// Password policy
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBERS', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// Rate limiting
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('API_RATE_LIMIT', 100); // requests per minute

// ==============================================================
// EMAIL CONFIGURATION
// ==============================================================

define('MAIL_FROM_NAME', 'CollaboraNexio');
define('MAIL_FROM_EMAIL', 'noreply@localhost');
define('MAIL_SMTP_HOST', 'localhost');
define('MAIL_SMTP_PORT', 25);
define('MAIL_SMTP_AUTH', false);
define('MAIL_SMTP_USERNAME', '');
define('MAIL_SMTP_PASSWORD', '');
define('MAIL_SMTP_SECURE', ''); // 'tls' or 'ssl'

// ==============================================================
// TIMEZONE
// ==============================================================

date_default_timezone_set('Europe/Rome');

// ==============================================================
// AUTOLOADER
// ==============================================================

// Simple autoloader for classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/includes/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ==============================================================
// HELPER FUNCTIONS
// ==============================================================

/**
 * Get configuration value
 */
function config($key, $default = null) {
    return defined($key) ? constant($key) : $default;
}

/**
 * Check if in debug mode
 */
function isDebugMode(): bool {
    return defined('DEBUG_MODE') && DEBUG_MODE === true;
}

/**
 * Safe redirect function
 */
function redirect($url) {
    header("Location: $url");
    exit();
}