<?php
/**
 * Database Initialization Script for CollaboraNexio
 *
 * This script creates the database, tables, and initial test data
 * Run this from command line or browser to initialize the system
 */

// Error reporting for setup script
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Database configuration
$config = [
    'host' => 'localhost',
    'port' => 3306,
    'username' => 'root',
    'password' => '', // Default XAMPP password
    'database' => 'collaboranexio',
    'charset' => 'utf8mb4'
];

// Color codes for CLI output
$colors = [
    'success' => "\033[32m",
    'error' => "\033[31m",
    'info' => "\033[36m",
    'warning' => "\033[33m",
    'reset' => "\033[0m"
];

// Check if running from CLI
$isCLI = (php_sapi_name() === 'cli');

function output($message, $type = 'info') {
    global $colors, $isCLI;

    if ($isCLI) {
        echo $colors[$type] . $message . $colors['reset'] . PHP_EOL;
    } else {
        $color = match($type) {
            'success' => 'green',
            'error' => 'red',
            'warning' => 'orange',
            default => 'blue'
        };
        echo "<div style='color: $color; font-family: monospace; margin: 5px 0;'>$message</div>";
    }
}

// Start output
if (!$isCLI) {
    echo "<!DOCTYPE html><html><head><title>CollaboraNexio - Database Setup</title></head><body style='background: #f5f5f5; padding: 20px;'>";
    echo "<h1>CollaboraNexio Database Initialization</h1>";
}

output("=========================================", 'info');
output("CollaboraNexio Database Initialization", 'info');
output("=========================================", 'info');

try {
    // Step 1: Connect to MySQL without database
    output("\n1. Connecting to MySQL server...", 'info');
    $dsn = "mysql:host={$config['host']};port={$config['port']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    output("   ✓ Connected to MySQL server", 'success');

    // Step 2: Create database if not exists
    output("\n2. Creating database '{$config['database']}'...", 'info');
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$config['database']}`");
    output("   ✓ Database created/selected", 'success');

    // Step 3: Create tables
    output("\n3. Creating tables...", 'info');

    // Drop existing tables in correct order (foreign key dependencies)
    $dropTables = [
        'audit_logs',
        'user_sessions',
        'password_resets',
        'notifications',
        'settings',
        'users',
        'tenants'
    ];

    foreach ($dropTables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        output("   - Dropped table '$table' if existed", 'warning');
    }

    // Create tenants table
    $pdo->exec("
        CREATE TABLE `tenants` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('active', 'inactive', 'trial', 'suspended') DEFAULT 'trial',
            `plan_type` ENUM('basic', 'professional', 'enterprise') DEFAULT 'basic',
            `max_users` INT DEFAULT 10,
            `settings` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_domain` (`domain`),
            KEY `idx_status` (`status`),
            KEY `idx_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'tenants'", 'success');

    // Create users table
    $pdo->exec("
        CREATE TABLE `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `first_name` VARCHAR(100) DEFAULT NULL,
            `last_name` VARCHAR(100) DEFAULT NULL,
            `display_name` VARCHAR(255) DEFAULT NULL,
            `role` ENUM('admin', 'manager', 'user', 'viewer') DEFAULT 'user',
            `is_active` TINYINT(1) DEFAULT 1,
            `status` ENUM('active', 'inactive', 'pending', 'suspended') DEFAULT 'active',
            `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
            `remember_token` VARCHAR(100) DEFAULT NULL,
            `settings` JSON DEFAULT NULL,
            `avatar_url` VARCHAR(500) DEFAULT NULL,
            `last_login_at` TIMESTAMP NULL DEFAULT NULL,
            `last_login_ip` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_tenant_email` (`tenant_id`, `email`),
            KEY `idx_email` (`email`),
            KEY `idx_role` (`role`),
            KEY `idx_status` (`status`),
            KEY `idx_deleted` (`deleted_at`),
            CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'users'", 'success');

    // Create user_sessions table
    $pdo->exec("
        CREATE TABLE `user_sessions` (
            `id` VARCHAR(128) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT,
            `payload` TEXT NOT NULL,
            `last_activity` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_last_activity` (`last_activity`),
            CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'user_sessions'", 'success');

    // Create password_resets table
    $pdo->exec("
        CREATE TABLE `password_resets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(255) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP NOT NULL,
            `used_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_email` (`email`),
            KEY `idx_token` (`token`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'password_resets'", 'success');

    // Create audit_logs table
    $pdo->exec("
        CREATE TABLE `audit_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED DEFAULT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` INT DEFAULT NULL,
            `old_values` JSON DEFAULT NULL,
            `new_values` JSON DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tenant` (`tenant_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_action` (`action`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `fk_audit_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'audit_logs'", 'success');

    // Create notifications table
    $pdo->exec("
        CREATE TABLE `notifications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(100) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT,
            `data` JSON DEFAULT NULL,
            `read_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_read` (`read_at`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `fk_notifications_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'notifications'", 'success');

    // Create settings table
    $pdo->exec("
        CREATE TABLE `settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED DEFAULT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `category` VARCHAR(100) NOT NULL,
            `key` VARCHAR(100) NOT NULL,
            `value` TEXT,
            `type` ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_setting_key` (`tenant_id`, `user_id`, `category`, `key`),
            KEY `idx_category` (`category`),
            CONSTRAINT `fk_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    output("   ✓ Created table 'settings'", 'success');

    // Step 4: Insert test data
    output("\n4. Inserting test data...", 'info');

    // Insert test tenants
    $stmt = $pdo->prepare("
        INSERT INTO `tenants` (`name`, `domain`, `status`, `plan_type`, `max_users`, `settings`)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $tenants = [
        ['Azienda Demo', 'demo.collaboranexio.local', 'active', 'enterprise', 100, json_encode(['theme' => 'default', 'language' => 'it'])],
        ['Test Company', 'test.collaboranexio.local', 'trial', 'professional', 50, json_encode(['theme' => 'dark', 'language' => 'en'])],
        ['Startup Innovativa', 'startup.collaboranexio.local', 'active', 'basic', 10, json_encode(['theme' => 'default', 'language' => 'it'])]
    ];

    foreach ($tenants as $tenant) {
        $stmt->execute($tenant);
    }
    output("   ✓ Inserted " . count($tenants) . " test tenants", 'success');

    // Insert test users
    $stmt = $pdo->prepare("
        INSERT INTO `users` (`tenant_id`, `email`, `password_hash`, `name`, `first_name`, `last_name`, `display_name`, `role`, `is_active`, `status`, `email_verified_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Password hash for 'Admin123!'
    $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);

    $users = [
        // Tenant 1 users
        [1, 'admin@demo.local', $passwordHash, 'Mario Rossi', 'Mario', 'Rossi', 'Mario Rossi', 'admin', 1, 'active'],
        [1, 'manager@demo.local', $passwordHash, 'Luigi Bianchi', 'Luigi', 'Bianchi', 'Luigi Bianchi', 'manager', 1, 'active'],
        [1, 'user@demo.local', $passwordHash, 'Giulia Verdi', 'Giulia', 'Verdi', 'Giulia Verdi', 'user', 1, 'active'],
        [1, 'viewer@demo.local', $passwordHash, 'Anna Neri', 'Anna', 'Neri', 'Anna Neri', 'viewer', 1, 'active'],

        // Tenant 2 users
        [2, 'admin@test.local', $passwordHash, 'John Smith', 'John', 'Smith', 'John Smith', 'admin', 1, 'active'],
        [2, 'manager@test.local', $passwordHash, 'Jane Doe', 'Jane', 'Doe', 'Jane Doe', 'manager', 1, 'active'],
        [2, 'user@test.local', $passwordHash, 'Bob Johnson', 'Bob', 'Johnson', 'Bob Johnson', 'user', 0, 'inactive'],

        // Tenant 3 users
        [3, 'admin@startup.local', $passwordHash, 'Marco Polo', 'Marco', 'Polo', 'Marco Polo', 'admin', 1, 'active'],
        [3, 'user@startup.local', $passwordHash, 'Chiara Ferragni', 'Chiara', 'Ferragni', 'Chiara Ferragni', 'user', 0, 'pending']
    ];

    foreach ($users as $user) {
        $stmt->execute($user);
    }
    output("   ✓ Inserted " . count($users) . " test users", 'success');

    // Insert some audit logs
    $stmt = $pdo->prepare("
        INSERT INTO `audit_logs` (`tenant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `ip_address`)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $logs = [
        [1, 1, 'user.login', 'user', 1, '127.0.0.1'],
        [1, 1, 'user.create', 'user', 2, '127.0.0.1'],
        [1, 2, 'user.update', 'user', 3, '192.168.1.1'],
        [2, 5, 'user.login', 'user', 5, '10.0.0.1']
    ];

    foreach ($logs as $log) {
        $stmt->execute($log);
    }
    output("   ✓ Inserted " . count($logs) . " audit log entries", 'success');

    // Step 5: Create indexes for performance
    output("\n5. Creating additional indexes...", 'info');

    $indexes = [
        "ALTER TABLE `users` ADD INDEX `idx_last_login` (`last_login_at`)",
        "ALTER TABLE `audit_logs` ADD INDEX `idx_tenant_action` (`tenant_id`, `action`)"
    ];

    foreach ($indexes as $index) {
        try {
            $pdo->exec($index);
            output("   ✓ Index created", 'success');
        } catch (Exception $e) {
            // Index might already exist
            output("   - Index may already exist", 'warning');
        }
    }

    // Step 6: Display summary
    output("\n=========================================", 'info');
    output("DATABASE INITIALIZATION COMPLETE!", 'success');
    output("=========================================", 'info');

    output("\nDatabase Details:", 'info');
    output("  Database: {$config['database']}", 'info');
    output("  Tables created: 7", 'info');
    output("  Test tenants: " . count($tenants), 'info');
    output("  Test users: " . count($users), 'info');

    output("\nTest Credentials:", 'info');
    output("  Email: admin@demo.local", 'info');
    output("  Password: Admin123!", 'info');
    output("  (All test users use the same password)", 'info');

    output("\nNext Steps:", 'warning');
    output("  1. Update /config.php with your database settings", 'warning');
    output("  2. Test the login at /login.php", 'warning');
    output("  3. Access user management at /pages/users.php", 'warning');

} catch (PDOException $e) {
    output("\n✗ DATABASE ERROR: " . $e->getMessage(), 'error');
    output("\nPlease check:", 'error');
    output("  1. MySQL is running", 'error');
    output("  2. Username and password are correct", 'error');
    output("  3. User has CREATE DATABASE privileges", 'error');

    if (!$isCLI) {
        echo "<pre style='background: #ffe0e0; padding: 10px; margin-top: 10px;'>";
        echo "Stack trace:\n" . $e->getTraceAsString();
        echo "</pre>";
    }
} catch (Exception $e) {
    output("\n✗ ERROR: " . $e->getMessage(), 'error');
}

if (!$isCLI) {
    echo "</body></html>";
}