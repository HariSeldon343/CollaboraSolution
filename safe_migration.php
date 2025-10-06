<?php
/**
 * Safe Database Migration Script
 * Version: 2025-01-25
 * Description: Creates missing tables with comprehensive error handling
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'collabora';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Migration</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { color: #333; border-bottom: 3px solid #2196F3; padding-bottom: 10px; }
        .box { background: #fafafa; padding: 15px; margin: 15px 0; border-radius: 5px; border-left: 4px solid #2196F3; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        .info { color: blue; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .success-box { background: #e8f5e9; border-left-color: #4CAF50; }
        .error-box { background: #ffebee; border-left-color: #f44336; }
    </style>
</head>
<body>
<div class='container'>
<h1>CollaboraNexio - Safe Database Migration</h1>";

try {
    // Connect to database
    $mysqli = new mysqli($host, $username, $password, $database);

    if ($mysqli->connect_error) {
        die("<div class='error-box box'>Connection failed: " . $mysqli->connect_error . "</div></body></html>");
    }

    $mysqli->set_charset("utf8mb4");

    // Step 1: Disable foreign key checks
    echo "<div class='box'><h3>Step 1: Preparing Database</h3>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    echo "<p class='success'>✓ Foreign key checks disabled</p></div>";

    // Step 2: Check and create base tables if needed
    echo "<div class='box'><h3>Step 2: Checking Base Tables</h3>";

    // Check if base tables exist
    $base_tables = ['tenants', 'users', 'projects', 'events'];
    $existing = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $existing[] = $row[0];
    }

    // Create tenants if missing
    if (!in_array('tenants', $existing)) {
        $sql = "CREATE TABLE tenants (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $mysqli->query($sql);
        echo "<p class='warning'>Created base table: tenants</p>";

        // Insert demo tenants
        $mysqli->query("INSERT INTO tenants (id, name) VALUES (1, 'Demo Company A'), (2, 'Demo Company B')");
    } else {
        echo "<p class='info'>✓ Table 'tenants' exists</p>";
    }

    // Create users if missing
    if (!in_array('users', $existing)) {
        $sql = "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $mysqli->query($sql);
        echo "<p class='warning'>Created base table: users</p>";

        // Insert demo users
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $mysqli->query("INSERT INTO users (id, tenant_id, username, email, password_hash) VALUES
            (1, 1, 'admin', 'admin@demo.com', '$hash'),
            (2, 1, 'user1', 'user1@demo.com', '$hash'),
            (3, 1, 'user2', 'user2@demo.com', '$hash')");
    } else {
        echo "<p class='info'>✓ Table 'users' exists</p>";
    }

    // Create projects if missing
    if (!in_array('projects', $existing)) {
        $sql = "CREATE TABLE projects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(50) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $mysqli->query($sql);
        echo "<p class='warning'>Created base table: projects</p>";

        // Insert demo projects
        $mysqli->query("INSERT INTO projects (id, tenant_id, name, description, status) VALUES
            (1, 1, 'Website Redesign', 'Complete overhaul', 'active'),
            (2, 1, 'Mobile App', 'Native app development', 'planning')");
    } else {
        echo "<p class='info'>✓ Table 'projects' exists</p>";
    }

    // Create events if missing
    if (!in_array('events', $existing)) {
        $sql = "CREATE TABLE events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $mysqli->query($sql);
        echo "<p class='warning'>Created base table: events</p>";

        // Insert demo events
        $mysqli->query("INSERT INTO events (id, tenant_id, title, description, start_date, end_date, created_by) VALUES
            (1, 1, 'Project Kickoff', 'Initial meeting', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 1),
            (2, 1, 'Sprint Review', 'Review progress', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 7 DAY), 1)");
    } else {
        echo "<p class='info'>✓ Table 'events' exists</p>";
    }

    echo "</div>";

    // Step 3: Create missing tables
    echo "<div class='box'><h3>Step 3: Creating Missing Tables</h3>";

    $tables_created = 0;
    $tables_failed = 0;

    // 1. project_milestones
    if (!in_array('project_milestones', $existing)) {
        $sql = "CREATE TABLE project_milestones (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            due_date DATE NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'delayed', 'cancelled') DEFAULT 'pending',
            priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            completion_percentage DECIMAL(5,2) DEFAULT 0.00,
            responsible_user_id INT UNSIGNED NULL,
            completed_at DATETIME NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_milestone_project (project_id, tenant_id),
            INDEX idx_milestone_due_date (due_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($mysqli->query($sql)) {
            echo "<p class='success'>✓ Created table: project_milestones</p>";
            $tables_created++;

            // Insert demo data
            $mysqli->query("INSERT INTO project_milestones (tenant_id, project_id, name, due_date, status, priority, responsible_user_id) VALUES
                (1, 1, 'Design Mockups', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_progress', 'high', 2),
                (1, 1, 'Frontend Dev', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pending', 'high', 2),
                (1, 2, 'Requirements', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_progress', 'critical', 1)");
        } else {
            echo "<p class='error'>✗ Failed: project_milestones - " . $mysqli->error . "</p>";
            $tables_failed++;
        }
    }

    // 2. event_attendees
    if (!in_array('event_attendees', $existing)) {
        $sql = "CREATE TABLE event_attendees (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            attendance_status ENUM('invited', 'accepted', 'declined', 'tentative', 'attended', 'no_show') DEFAULT 'invited',
            rsvp_response ENUM('yes', 'no', 'maybe', 'pending') DEFAULT 'pending',
            is_organizer BOOLEAN DEFAULT FALSE,
            is_optional BOOLEAN DEFAULT FALSE,
            responded_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_event_attendee (event_id, user_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_attendee_user (user_id, attendance_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($mysqli->query($sql)) {
            echo "<p class='success'>✓ Created table: event_attendees</p>";
            $tables_created++;

            // Insert demo data
            $mysqli->query("INSERT INTO event_attendees (tenant_id, event_id, user_id, attendance_status, rsvp_response, is_organizer) VALUES
                (1, 1, 1, 'accepted', 'yes', TRUE),
                (1, 1, 2, 'invited', 'pending', FALSE),
                (1, 1, 3, 'accepted', 'yes', FALSE)");
        } else {
            echo "<p class='error'>✗ Failed: event_attendees - " . $mysqli->error . "</p>";
            $tables_failed++;
        }
    }

    // 3. sessions
    if (!in_array('sessions', $existing)) {
        $sql = "CREATE TABLE sessions (
            tenant_id INT NOT NULL,
            id VARCHAR(128) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            data JSON NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_session_user (user_id, is_active),
            INDEX idx_session_activity (last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($mysqli->query($sql)) {
            echo "<p class='success'>✓ Created table: sessions</p>";
            $tables_created++;
        } else {
            echo "<p class='error'>✗ Failed: sessions - " . $mysqli->error . "</p>";
            $tables_failed++;
        }
    }

    // 4. rate_limits
    if (!in_array('rate_limits', $existing)) {
        $sql = "CREATE TABLE rate_limits (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL,
            identifier_type ENUM('ip', 'user', 'api_key', 'endpoint') NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            attempts INT UNSIGNED DEFAULT 1,
            max_attempts INT UNSIGNED DEFAULT 60,
            window_minutes INT UNSIGNED DEFAULT 1,
            first_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_blocked BOOLEAN DEFAULT FALSE,
            PRIMARY KEY (id),
            UNIQUE KEY uk_rate_limit (identifier, endpoint),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            INDEX idx_rate_limit_blocked (is_blocked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($mysqli->query($sql)) {
            echo "<p class='success'>✓ Created table: rate_limits</p>";
            $tables_created++;
        } else {
            echo "<p class='error'>✗ Failed: rate_limits - " . $mysqli->error . "</p>";
            $tables_failed++;
        }
    }

    // 5. system_settings
    if (!in_array('system_settings', $existing)) {
        $sql = "CREATE TABLE system_settings (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_group VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            value_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
            display_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            is_public BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_setting (tenant_id, setting_group, setting_key),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            INDEX idx_setting_group (setting_group, is_public)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($mysqli->query($sql)) {
            echo "<p class='success'>✓ Created table: system_settings</p>";
            $tables_created++;

            // Insert demo settings
            $mysqli->query("INSERT INTO system_settings (tenant_id, setting_group, setting_key, setting_value, value_type, display_name, description, is_public) VALUES
                (1, 'general', 'app_name', 'CollaboraNexio', 'string', 'App Name', 'Application name', TRUE),
                (1, 'general', 'timezone', 'UTC', 'string', 'Timezone', 'Default timezone', TRUE),
                (1, 'security', 'session_timeout', '3600', 'integer', 'Session Timeout', 'Timeout in seconds', FALSE),
                (1, 'security', 'max_login_attempts', '5', 'integer', 'Max Login Attempts', 'Maximum attempts', FALSE)");
        } else {
            echo "<p class='error'>✗ Failed: system_settings - " . $mysqli->error . "</p>";
            $tables_failed++;
        }
    }

    echo "</div>";

    // Step 4: Re-enable foreign key checks
    echo "<div class='box'><h3>Step 4: Finalizing</h3>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "<p class='success'>✓ Foreign key checks re-enabled</p>";
    echo "</div>";

    // Step 5: Verification
    echo "<div class='box'><h3>Step 5: Verification</h3>";
    echo "<table><tr><th>Table</th><th>Status</th><th>Records</th></tr>";

    $check_tables = ['project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings'];
    foreach ($check_tables as $table) {
        $result = $mysqli->query("SELECT COUNT(*) as count FROM $table");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "<tr><td>$table</td><td class='success'>✓ Active</td><td>{$row['count']}</td></tr>";
        } else {
            echo "<tr><td>$table</td><td class='error'>✗ Error</td><td>N/A</td></tr>";
        }
    }
    echo "</table></div>";

    // Final status
    if ($tables_failed == 0) {
        echo "<div class='box success-box'>";
        echo "<h2 class='success'>✅ Migration Completed Successfully!</h2>";
        echo "<p>All missing tables have been created with demo data.</p>";
        echo "<p><strong>Tables created:</strong> $tables_created</p>";
        echo "<p><strong>Next steps:</strong></p>";
        echo "<ul>
                <li>Test the application functionality</li>
                <li>Review the created tables and data</li>
                <li>Configure system settings as needed</li>
              </ul>";
        echo "</div>";
    } else {
        echo "<div class='box error-box'>";
        echo "<h2 class='warning'>⚠ Migration Partially Complete</h2>";
        echo "<p>Some tables could not be created. Please review the errors above.</p>";
        echo "<p><strong>Tables created:</strong> $tables_created</p>";
        echo "<p><strong>Tables failed:</strong> $tables_failed</p>";
        echo "</div>";
    }

    $mysqli->close();

} catch (Exception $e) {
    echo "<div class='box error-box'>";
    echo "<h2 class='error'>Fatal Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "</div></body></html>";
?>