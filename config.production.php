<?php
/**
 * CollaboraNexio - Production Configuration
 * PHP 8.3 Optimized Settings
 *
 * This file contains all production-specific configurations
 * with security hardening and performance optimizations
 */

declare(strict_types=1);

// ==============================================================
// ENVIRONMENT SETTINGS
// ==============================================================

// Production mode flag
define('PRODUCTION_MODE', true);
define('ENVIRONMENT', 'production');

// Disable all error display (log only)
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// PHP 8.3 specific optimizations
ini_set('opcache.enable', '1');
ini_set('opcache.enable_cli', '0');
ini_set('opcache.memory_consumption', '256');
ini_set('opcache.interned_strings_buffer', '16');
ini_set('opcache.max_accelerated_files', '20000');
ini_set('opcache.validate_timestamps', '0');
ini_set('opcache.save_comments', '0');
ini_set('opcache.jit', 'tracing');
ini_set('opcache.jit_buffer_size', '100M');

// ==============================================================
// DATABASE CONFIGURATION
// ==============================================================

// Production database settings with connection pooling
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'collaboranexio');
define('DB_USER', 'collaboranexio_prod');
define('DB_PASS', 'SecureProductionPassword#2024!'); // Change this!
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// Database connection options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => true,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    PDO::ATTR_STRINGIFY_FETCHES => false
]);

// Connection pool settings
define('DB_MAX_CONNECTIONS', 100);
define('DB_CONNECTION_TIMEOUT', 10);
define('DB_RETRY_ATTEMPTS', 3);
define('DB_RETRY_DELAY', 1000); // milliseconds

// ==============================================================
// SESSION CONFIGURATION
// ==============================================================

// Secure session settings
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
ini_set('session.hash_function', 'sha256');
ini_set('session.gc_maxlifetime', '1440');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
ini_set('session.save_path', __DIR__ . '/sessions');
ini_set('session.cookie_lifetime', '0');
ini_set('session.cookie_path', '/');
ini_set('session.name', 'CNXSESSID');

// Session encryption key (change this!)
define('SESSION_ENCRYPTION_KEY', bin2hex(random_bytes(32)));

// ==============================================================
// SECURITY CONFIGURATION
// ==============================================================

// Security headers and settings
define('SECURITY_HEADERS', [
    'X-Frame-Options' => 'SAMEORIGIN',
    'X-Content-Type-Options' => 'nosniff',
    'X-XSS-Protection' => '1; mode=block',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none';"
]);

// CSRF protection
define('CSRF_TOKEN_NAME', '_csrf_token');
define('CSRF_TOKEN_LENGTH', 32);
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour
define('CSRF_REGENERATE_INTERVAL', 900); // 15 minutes

// Password policy
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBERS', true);
define('PASSWORD_REQUIRE_SPECIAL', true);
define('PASSWORD_BCRYPT_COST', 12);
define('PASSWORD_ARGON2_MEMORY_COST', 65536);
define('PASSWORD_ARGON2_TIME_COST', 4);
define('PASSWORD_ARGON2_THREADS', 3);

// Login security
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('LOGIN_ATTEMPT_WINDOW', 300); // 5 minutes
define('REQUIRE_2FA', false); // Set to true for two-factor authentication
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('SESSION_RENEWAL_TIME', 300); // 5 minutes before timeout

// ==============================================================
// API RATE LIMITING
// ==============================================================

// Rate limiting configuration
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds
define('RATE_LIMIT_MAX_REQUESTS', 1000); // Max requests per window
define('RATE_LIMIT_BURST_SIZE', 50); // Max burst requests
define('RATE_LIMIT_STORAGE', 'redis'); // 'redis', 'file', or 'database'

// Per-endpoint rate limits
define('RATE_LIMITS', [
    'login' => ['window' => 300, 'max' => 5],
    'register' => ['window' => 3600, 'max' => 3],
    'api/*' => ['window' => 60, 'max' => 100],
    'upload' => ['window' => 3600, 'max' => 20],
    'export' => ['window' => 3600, 'max' => 10],
    'email' => ['window' => 3600, 'max' => 50]
]);

// IP whitelist (no rate limiting for these)
define('RATE_LIMIT_WHITELIST', [
    '127.0.0.1',
    '::1'
]);

// ==============================================================
// CACHE CONFIGURATION
// ==============================================================

// Cache settings
define('CACHE_ENABLED', true);
define('CACHE_DRIVER', 'redis'); // 'redis', 'memcached', 'file', 'apcu'
define('CACHE_PREFIX', 'cnx_');
define('CACHE_TTL', 3600); // Default TTL in seconds

// Redis cache configuration
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', null);
define('REDIS_DATABASE', 0);
define('REDIS_PREFIX', 'collaboranexio:');

// Cache TTLs for different data types
define('CACHE_TTL_SESSION', 1800);
define('CACHE_TTL_USER', 3600);
define('CACHE_TTL_CONFIG', 86400);
define('CACHE_TTL_QUERY', 300);
define('CACHE_TTL_PAGE', 600);

// ==============================================================
// FILE UPLOAD CONFIGURATION
// ==============================================================

// File upload settings
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_MAX_SIZE', 10485760); // 10MB in bytes
define('UPLOAD_ALLOWED_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv'
]);
define('UPLOAD_ALLOWED_EXTENSIONS', [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'pdf', 'doc', 'docx', 'xls', 'xlsx',
    'txt', 'csv'
]);
define('UPLOAD_SANITIZE_FILENAME', true);
define('UPLOAD_GENERATE_THUMBNAILS', true);
define('UPLOAD_THUMBNAIL_WIDTH', 200);
define('UPLOAD_THUMBNAIL_HEIGHT', 200);
define('UPLOAD_VIRUS_SCAN', false); // Enable if ClamAV is installed

// ==============================================================
// EMAIL CONFIGURATION
// ==============================================================

// Email settings
define('MAIL_ENABLED', true);
define('MAIL_DRIVER', 'smtp'); // 'smtp', 'sendmail', 'mail'
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls'); // 'tls' or 'ssl'
define('MAIL_USERNAME', 'noreply@collaboranexio.com');
define('MAIL_PASSWORD', 'your-email-password'); // Use environment variable in production
define('MAIL_FROM_ADDRESS', 'noreply@collaboranexio.com');
define('MAIL_FROM_NAME', 'CollaboraNexio');
define('MAIL_REPLY_TO', 'support@collaboranexio.com');

// Email rate limiting
define('MAIL_THROTTLE_ENABLED', true);
define('MAIL_THROTTLE_LIMIT', 100); // Max emails per hour
define('MAIL_QUEUE_ENABLED', true);

// ==============================================================
// LOGGING CONFIGURATION
// ==============================================================

// Logging settings
define('LOG_LEVEL', 'error'); // 'debug', 'info', 'warning', 'error', 'critical'
define('LOG_DIR', __DIR__ . '/logs/');
define('LOG_FILE_PERMISSION', 0664);
define('LOG_DATE_FORMAT', 'Y-m-d H:i:s');
define('LOG_MAX_FILE_SIZE', 10485760); // 10MB
define('LOG_MAX_FILES', 30); // Keep 30 days of logs
define('LOG_ROTATE_DAILY', true);

// Separate log files for different components
define('LOG_FILES', [
    'error' => 'error.log',
    'access' => 'access.log',
    'security' => 'security.log',
    'api' => 'api.log',
    'database' => 'database.log',
    'email' => 'email.log',
    'performance' => 'performance.log'
]);

// ==============================================================
// PERFORMANCE MONITORING
// ==============================================================

// Performance settings
define('PERFORMANCE_MONITORING', true);
define('PERFORMANCE_SLOW_QUERY_TIME', 1000); // milliseconds
define('PERFORMANCE_SLOW_REQUEST_TIME', 3000); // milliseconds
define('PERFORMANCE_MEMORY_LIMIT_WARNING', 0.8); // 80% of memory_limit
define('PERFORMANCE_LOG_QUERIES', false); // Set to true for debugging only

// ==============================================================
// BACKUP CONFIGURATION
// ==============================================================

// Backup settings
define('BACKUP_ENABLED', true);
define('BACKUP_DIR', __DIR__ . '/backups/');
define('BACKUP_RETENTION_DAYS', 30);
define('BACKUP_COMPRESS', true);
define('BACKUP_ENCRYPT', true);
define('BACKUP_ENCRYPTION_KEY', bin2hex(random_bytes(32))); // Store securely!
define('BACKUP_SCHEDULE', '0 2 * * *'); // Cron format: 2 AM daily

// ==============================================================
// MAINTENANCE MODE
// ==============================================================

// Maintenance mode settings
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'Il sistema è in manutenzione. Torneremo presto online.');
define('MAINTENANCE_ALLOWED_IPS', ['127.0.0.1', '::1']);
define('MAINTENANCE_BYPASS_KEY', bin2hex(random_bytes(16)));

// ==============================================================
// MULTI-TENANT CONFIGURATION
// ==============================================================

// Multi-tenant settings
define('MULTI_TENANT_ENABLED', true);
define('TENANT_ISOLATION_MODE', 'strict'); // 'strict' or 'shared'
define('DEFAULT_TENANT_ID', 1);
define('TENANT_CACHE_TTL', 3600);
define('TENANT_SUBDOMAIN_ENABLED', false);

// ==============================================================
// MISCELLANEOUS
// ==============================================================

// Application settings
define('APP_NAME', 'CollaboraNexio');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://collaboranexio.com');
define('APP_TIMEZONE', 'Europe/Rome');
define('APP_LOCALE', 'it_IT');
define('APP_CHARSET', 'UTF-8');

// Debug mode (always false in production)
define('DEBUG_MODE', false);
define('DEBUG_SQL', false);
define('DEBUG_PERFORMANCE', false);

// API settings
define('API_VERSION', 'v1');
define('API_TIMEOUT', 30); // seconds
define('API_KEY_REQUIRED', true);
define('API_CORS_ENABLED', true);
define('API_CORS_ORIGINS', ['https://collaboranexio.com']);

// Pagination defaults
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Search settings
define('SEARCH_MIN_LENGTH', 3);
define('SEARCH_MAX_RESULTS', 100);
define('SEARCH_FUZZY_ENABLED', true);

// ==============================================================
// FUNCTION TO APPLY SECURITY HEADERS
// ==============================================================

/**
 * Apply security headers to response
 */
function applySecurityHeaders(): void {
    foreach (SECURITY_HEADERS as $header => $value) {
        header("$header: $value");
    }

    // Remove sensitive headers
    header_remove('X-Powered-By');
    header_remove('Server');
}

// Apply headers immediately if not in CLI mode
if (PHP_SAPI !== 'cli') {
    applySecurityHeaders();
}

// ==============================================================
// AUTO-LOAD PRODUCTION OPTIMIZATIONS
// ==============================================================

// Set locale
setlocale(LC_ALL, APP_LOCALE . '.' . APP_CHARSET);

// Set default timezone
date_default_timezone_set(APP_TIMEZONE);

// Set internal encoding
mb_internal_encoding(APP_CHARSET);

// Create necessary directories
$directories = [LOG_DIR, UPLOAD_DIR, BACKUP_DIR, ini_get('session.save_path')];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}