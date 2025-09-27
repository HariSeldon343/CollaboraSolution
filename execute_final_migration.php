<?php
/**
 * Execute Final Migration for 5 Missing Tables
 * Run this script to create the missing tables
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'collaboranexio';

echo "<h1>Creating 5 Missing Tables</h1>";
echo "<pre>";
echo date('[Y-m-d H:i:s]') . " Starting migration...\n";

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to database\n\n";

    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✓ Foreign key checks disabled\n\n";

    // Create tables
    $tables = [
        'project_milestones' => "
            CREATE TABLE IF NOT EXISTS project_milestones (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT 1,
                project_id INT UNSIGNED DEFAULT 1,
                name VARCHAR(200) NOT NULL,
                description TEXT,
                due_date DATE,
                status ENUM('pending','in_progress','completed','delayed') DEFAULT 'pending',
                assigned_to INT UNSIGNED DEFAULT NULL,
                completion_percentage INT DEFAULT 0,
                deliverables JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tenant (tenant_id),
                INDEX idx_project (project_id),
                INDEX idx_status (status),
                INDEX idx_due_date (due_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'event_attendees' => "
            CREATE TABLE IF NOT EXISTS event_attendees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT 1,
                event_id INT UNSIGNED DEFAULT 1,
                user_id INT UNSIGNED DEFAULT 1,
                response_status ENUM('pending','accepted','declined','tentative') DEFAULT 'pending',
                attendance_confirmed BOOLEAN DEFAULT FALSE,
                notes TEXT,
                invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_event_user (event_id, user_id),
                INDEX idx_tenant (tenant_id),
                INDEX idx_user (user_id),
                INDEX idx_response (response_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sessions' => "
            CREATE TABLE IF NOT EXISTS sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT 1,
                user_id INT UNSIGNED DEFAULT 1,
                session_token VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                payload TEXT,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_token (session_token),
                INDEX idx_tenant_user (tenant_id, user_id),
                INDEX idx_expires (expires_at),
                INDEX idx_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'rate_limits' => "
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT 1,
                identifier VARCHAR(255) NOT NULL,
                endpoint VARCHAR(200) NOT NULL,
                ip_address VARCHAR(45),
                attempts INT DEFAULT 1,
                reset_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tenant (tenant_id),
                INDEX idx_identifier (identifier),
                INDEX idx_reset (reset_at),
                INDEX idx_endpoint (endpoint)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'system_settings' => "
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'general',
                setting_key VARCHAR(200) NOT NULL,
                setting_value TEXT,
                value_type ENUM('string','integer','boolean','json','array') DEFAULT 'string',
                description TEXT,
                is_public BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_setting (tenant_id, setting_key),
                INDEX idx_category (category),
                INDEX idx_public (is_public)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    // Create each table
    foreach ($tables as $tableName => $sql) {
        echo "Creating table: $tableName... ";
        try {
            $pdo->exec("DROP TABLE IF EXISTS $tableName");
            $pdo->exec($sql);
            echo "✓ Created\n";
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }

    echo "\n";

    // Insert demo data
    echo "Inserting demo data...\n";

    // Project milestones
    $pdo->exec("
        INSERT INTO project_milestones (tenant_id, project_id, name, description, due_date, status, completion_percentage)
        VALUES
        (1, 1, 'Phase 1 - Planning', 'Initial project planning and requirements', DATE_ADD(NOW(), INTERVAL 30 DAY), 'completed', 100),
        (1, 1, 'Phase 2 - Development', 'Core development phase', DATE_ADD(NOW(), INTERVAL 60 DAY), 'in_progress', 45),
        (1, 2, 'Design Review', 'UI/UX design review and approval', DATE_ADD(NOW(), INTERVAL 15 DAY), 'pending', 0)
    ");
    echo "✓ Added project milestones\n";

    // Event attendees
    $pdo->exec("
        INSERT INTO event_attendees (tenant_id, event_id, user_id, response_status, attendance_confirmed)
        VALUES
        (1, 1, 1, 'accepted', true),
        (1, 1, 2, 'pending', false),
        (1, 2, 1, 'accepted', true)
    ");
    echo "✓ Added event attendees\n";

    // Sessions
    $token1 = 'token_' . uniqid();
    $token2 = 'token_' . uniqid();
    $pdo->exec("
        INSERT INTO sessions (tenant_id, user_id, session_token, ip_address, expires_at)
        VALUES
        (1, 1, '$token1', '127.0.0.1', DATE_ADD(NOW(), INTERVAL 24 HOUR)),
        (1, 2, '$token2', '127.0.0.1', DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ");
    echo "✓ Added sessions\n";

    // Rate limits
    $pdo->exec("
        INSERT INTO rate_limits (tenant_id, identifier, endpoint, ip_address, attempts, reset_at)
        VALUES
        (1, 'api_login', '/api/auth.php', '127.0.0.1', 1, DATE_ADD(NOW(), INTERVAL 1 HOUR))
    ");
    echo "✓ Added rate limits\n";

    // System settings
    $pdo->exec("
        INSERT INTO system_settings (tenant_id, category, setting_key, setting_value, value_type, description, is_public)
        VALUES
        (NULL, 'general', 'app_name', 'CollaboraNexio', 'string', 'Application name', true),
        (NULL, 'general', 'app_version', '1.0.0', 'string', 'Application version', true),
        (NULL, 'security', 'max_login_attempts', '5', 'integer', 'Maximum login attempts', false),
        (NULL, 'security', 'session_lifetime', '7200', 'integer', 'Session lifetime in seconds', false),
        (1, 'tenant', 'max_users', '50', 'integer', 'Maximum users for tenant', false)
    ");
    echo "✓ Added system settings\n";

    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\n✓ Foreign key checks re-enabled\n";

    // Verify creation
    echo "\nVerifying tables:\n";
    echo "================\n";
    $verifyTables = ['project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings'];

    foreach ($verifyTables as $table) {
        $result = $pdo->query("SELECT COUNT(*) as count FROM $table")->fetch();
        echo sprintf("%-20s: %d records\n", $table, $result['count']);
    }

    echo "\n<strong style='color: green;'>✅ SUCCESS! All 5 tables created successfully!</strong>\n";
    echo "\nYou can now check the system status at:\n";
    echo "<a href='system_check.php'>system_check.php</a>\n";

} catch (Exception $e) {
    echo "\n<strong style='color: red;'>❌ ERROR: " . $e->getMessage() . "</strong>\n";
}

echo "</pre>";
?>