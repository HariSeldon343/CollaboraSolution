<?php
// Migration Script for Missing Tables
// Version: 2025-01-25
// Author: Database Architect
// Description: Creates missing tables with proper foreign key handling and correct column references

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$username = 'root';
$password = ''; // Empty password for XAMPP default
$database = 'collabora';

try {
    // Connect to MySQL
    $mysqli = new mysqli($host, $username, $password, $database);

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    echo "<h2>CollaboraNexio - Missing Tables Migration</h2>";
    echo "<style>
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        .info { color: blue; }
        .box {
            background: #f0f0f0;
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #2196F3;
        }
        .success-box {
            background: #e8f5e9;
            border-left-color: #4CAF50;
        }
        .error-box {
            background: #ffebee;
            border-left-color: #f44336;
        }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>";

    echo "Connected to database: <b>$database</b><br><br>";

    // ============================================
    // STEP 1: DISABLE FOREIGN KEY CHECKS
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 1: Preparing Database</b><br>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    $mysqli->query("SET time_zone = '+00:00'");
    echo "<span class='success'>✓ Foreign key checks disabled</span><br>";
    echo "<span class='success'>✓ SQL mode configured</span><br>";
    echo "</div>";

    // ============================================
    // STEP 2: CHECK EXISTING TABLES
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 2: Checking Existing Tables</b><br>";

    // Get list of existing tables
    $result = $mysqli->query("SHOW TABLES");
    $existing_tables = [];
    while ($row = $result->fetch_array()) {
        $existing_tables[] = $row[0];
    }

    // Check if required referenced tables exist
    $required_refs = ['tenants', 'users', 'projects', 'events'];
    $missing_refs = [];

    foreach ($required_refs as $ref) {
        if (!in_array($ref, $existing_tables)) {
            $missing_refs[] = $ref;
            echo "<span class='error'>✗ Required table '$ref' is missing!</span><br>";
        } else {
            echo "<span class='success'>✓ Found required table '$ref'</span><br>";
        }
    }

    if (!empty($missing_refs)) {
        die("<div class='error-box'>Cannot proceed: Required reference tables are missing. Please ensure base tables exist first.</div>");
    }
    echo "</div>";

    // ============================================
    // STEP 3: CREATE MISSING TABLES
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 3: Creating Missing Tables</b><br>";

    // 1. PROJECT_MILESTONES TABLE
    if (!in_array('project_milestones', $existing_tables)) {
        $sql = "CREATE TABLE IF NOT EXISTS project_milestones (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            due_date DATE NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'delayed', 'cancelled') DEFAULT 'pending',
            priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            completion_percentage DECIMAL(5,2) DEFAULT 0.00,
            deliverables JSON NULL,
            responsible_user_id INT UNSIGNED NULL,
            dependencies JSON NULL,
            completed_at DATETIME NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_milestone_project (project_id, tenant_id),
            INDEX idx_milestone_due_date (due_date, status),
            INDEX idx_milestone_responsible (responsible_user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created table: project_milestones</span><br>";
        } else {
            echo "<span class='error'>✗ Failed to create project_milestones: " . $mysqli->error . "</span><br>";
        }
    } else {
        echo "<span class='info'>⚠ Table project_milestones already exists</span><br>";
    }

    // 2. EVENT_ATTENDEES TABLE
    if (!in_array('event_attendees', $existing_tables)) {
        $sql = "CREATE TABLE IF NOT EXISTS event_attendees (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            attendance_status ENUM('invited', 'accepted', 'declined', 'tentative', 'attended', 'no_show') DEFAULT 'invited',
            rsvp_response ENUM('yes', 'no', 'maybe', 'pending') DEFAULT 'pending',
            is_organizer BOOLEAN DEFAULT FALSE,
            is_optional BOOLEAN DEFAULT FALSE,
            response_message TEXT NULL,
            responded_at DATETIME NULL,
            reminder_sent BOOLEAN DEFAULT FALSE,
            reminder_sent_at DATETIME NULL,
            check_in_time DATETIME NULL,
            check_out_time DATETIME NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_event_attendee (event_id, user_id),
            INDEX idx_attendee_user (user_id, attendance_status),
            INDEX idx_attendee_event (event_id, attendance_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created table: event_attendees</span><br>";
        } else {
            echo "<span class='error'>✗ Failed to create event_attendees: " . $mysqli->error . "</span><br>";
        }
    } else {
        echo "<span class='info'>⚠ Table event_attendees already exists</span><br>";
    }

    // 3. SESSIONS TABLE
    if (!in_array('sessions', $existing_tables)) {
        $sql = "CREATE TABLE IF NOT EXISTS sessions (
            tenant_id INT NOT NULL,
            id VARCHAR(128) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
            browser VARCHAR(50) NULL,
            platform VARCHAR(50) NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            logout_time DATETIME NULL,
            is_active BOOLEAN DEFAULT TRUE,
            remember_token VARCHAR(100) NULL,
            csrf_token VARCHAR(64) NULL,
            location_country VARCHAR(2) NULL,
            location_city VARCHAR(100) NULL,
            data JSON NULL,
            PRIMARY KEY (id),
            INDEX idx_session_user (user_id, is_active),
            INDEX idx_session_tenant (tenant_id, is_active),
            INDEX idx_session_activity (last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created table: sessions</span><br>";
        } else {
            echo "<span class='error'>✗ Failed to create sessions: " . $mysqli->error . "</span><br>";
        }
    } else {
        echo "<span class='info'>⚠ Table sessions already exists</span><br>";
    }

    // 4. RATE_LIMITS TABLE
    if (!in_array('rate_limits', $existing_tables)) {
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
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
            blocked_until DATETIME NULL,
            is_blocked BOOLEAN DEFAULT FALSE,
            block_reason TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_rate_limit (identifier, endpoint),
            INDEX idx_rate_limit_blocked (is_blocked, blocked_until),
            INDEX idx_rate_limit_tenant (tenant_id, identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created table: rate_limits</span><br>";
        } else {
            echo "<span class='error'>✗ Failed to create rate_limits: " . $mysqli->error . "</span><br>";
        }
    } else {
        echo "<span class='info'>⚠ Table rate_limits already exists</span><br>";
    }

    // 5. SYSTEM_SETTINGS TABLE
    if (!in_array('system_settings', $existing_tables)) {
        $sql = "CREATE TABLE IF NOT EXISTS system_settings (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_group VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            value_type ENUM('string', 'integer', 'boolean', 'json', 'datetime', 'decimal') DEFAULT 'string',
            display_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            is_public BOOLEAN DEFAULT FALSE,
            is_encrypted BOOLEAN DEFAULT FALSE,
            can_override BOOLEAN DEFAULT TRUE,
            default_value TEXT NULL,
            validation_rules JSON NULL,
            last_modified_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_setting (tenant_id, setting_group, setting_key),
            INDEX idx_setting_group (setting_group, is_public),
            INDEX idx_setting_tenant (tenant_id, setting_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created table: system_settings</span><br>";
        } else {
            echo "<span class='error'>✗ Failed to create system_settings: " . $mysqli->error . "</span><br>";
        }
    } else {
        echo "<span class='info'>⚠ Table system_settings already exists</span><br>";
    }

    echo "</div>";

    // ============================================
    // STEP 4: ADD FOREIGN KEY CONSTRAINTS
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 4: Adding Foreign Key Constraints</b><br>";

    // Add foreign keys for project_milestones
    if (in_array('project_milestones', $existing_tables)) {
        $constraints = [
            "ALTER TABLE project_milestones ADD CONSTRAINT fk_milestone_tenant
             FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE",

            "ALTER TABLE project_milestones ADD CONSTRAINT fk_milestone_project
             FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE",

            "ALTER TABLE project_milestones ADD CONSTRAINT fk_milestone_responsible
             FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL"
        ];

        foreach ($constraints as $sql) {
            if ($mysqli->query($sql)) {
                echo "<span class='success'>✓ Added foreign key for project_milestones</span><br>";
            } else {
                if (strpos($mysqli->error, 'Duplicate') === false) {
                    echo "<span class='warning'>⚠ Foreign key issue: " . $mysqli->error . "</span><br>";
                }
            }
        }
    }

    // Add foreign keys for event_attendees
    if (in_array('event_attendees', $existing_tables)) {
        $constraints = [
            "ALTER TABLE event_attendees ADD CONSTRAINT fk_attendee_tenant
             FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE",

            "ALTER TABLE event_attendees ADD CONSTRAINT fk_attendee_event
             FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE",

            "ALTER TABLE event_attendees ADD CONSTRAINT fk_attendee_user
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"
        ];

        foreach ($constraints as $sql) {
            if ($mysqli->query($sql)) {
                echo "<span class='success'>✓ Added foreign key for event_attendees</span><br>";
            } else {
                if (strpos($mysqli->error, 'Duplicate') === false) {
                    echo "<span class='warning'>⚠ Foreign key issue: " . $mysqli->error . "</span><br>";
                }
            }
        }
    }

    // Add foreign keys for sessions
    if (in_array('sessions', $existing_tables)) {
        $constraints = [
            "ALTER TABLE sessions ADD CONSTRAINT fk_session_tenant
             FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE",

            "ALTER TABLE sessions ADD CONSTRAINT fk_session_user
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"
        ];

        foreach ($constraints as $sql) {
            if ($mysqli->query($sql)) {
                echo "<span class='success'>✓ Added foreign key for sessions</span><br>";
            } else {
                if (strpos($mysqli->error, 'Duplicate') === false) {
                    echo "<span class='warning'>⚠ Foreign key issue: " . $mysqli->error . "</span><br>";
                }
            }
        }
    }

    // Add foreign keys for rate_limits
    if (in_array('rate_limits', $existing_tables)) {
        $sql = "ALTER TABLE rate_limits ADD CONSTRAINT fk_rate_limit_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE";

        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Added foreign key for rate_limits</span><br>";
        } else {
            if (strpos($mysqli->error, 'Duplicate') === false) {
                echo "<span class='warning'>⚠ Foreign key issue: " . $mysqli->error . "</span><br>";
            }
        }
    }

    // Add foreign keys for system_settings
    if (in_array('system_settings', $existing_tables)) {
        $constraints = [
            "ALTER TABLE system_settings ADD CONSTRAINT fk_settings_tenant
             FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE",

            "ALTER TABLE system_settings ADD CONSTRAINT fk_settings_modified_by
             FOREIGN KEY (last_modified_by) REFERENCES users(id) ON DELETE SET NULL"
        ];

        foreach ($constraints as $sql) {
            if ($mysqli->query($sql)) {
                echo "<span class='success'>✓ Added foreign key for system_settings</span><br>";
            } else {
                if (strpos($mysqli->error, 'Duplicate') === false) {
                    echo "<span class='warning'>⚠ Foreign key issue: " . $mysqli->error . "</span><br>";
                }
            }
        }
    }

    echo "</div>";

    // ============================================
    // STEP 5: INSERT DEMO DATA
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 5: Inserting Demo Data</b><br>";

    // Check if tenants exist, if not create demo tenants
    $result = $mysqli->query("SELECT COUNT(*) as count FROM tenants");
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        $sql = "INSERT INTO tenants (id, name, created_at) VALUES
                (1, 'Demo Company A', NOW()),
                (2, 'Demo Company B', NOW())";
        $mysqli->query($sql);
        echo "<span class='success'>✓ Created demo tenants</span><br>";
    }

    // Check if users exist, if not create demo users
    $result = $mysqli->query("SELECT COUNT(*) as count FROM users");
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        $sql = "INSERT INTO users (id, tenant_id, username, email, password_hash, created_at) VALUES
                (1, 1, 'admin', 'admin@demo.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', NOW()),
                (2, 1, 'user1', 'user1@demo.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', NOW()),
                (3, 1, 'user2', 'user2@demo.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', NOW())";
        $mysqli->query($sql);
        echo "<span class='success'>✓ Created demo users</span><br>";
    }

    // Check if projects exist, if not create demo projects
    $result = $mysqli->query("SELECT COUNT(*) as count FROM projects");
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        $sql = "INSERT INTO projects (id, tenant_id, name, description, status, created_at) VALUES
                (1, 1, 'Website Redesign', 'Complete overhaul of company website', 'active', NOW()),
                (2, 1, 'Mobile App Development', 'Native mobile app for customers', 'planning', NOW())";
        $mysqli->query($sql);
        echo "<span class='success'>✓ Created demo projects</span><br>";
    }

    // Check if events exist, if not create demo events
    $result = $mysqli->query("SELECT COUNT(*) as count FROM events");
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        $sql = "INSERT INTO events (id, tenant_id, title, description, start_date, end_date, created_by, created_at) VALUES
                (1, 1, 'Project Kickoff', 'Initial project meeting', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW()),
                (2, 1, 'Sprint Review', 'Review sprint progress', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, NOW())";
        $mysqli->query($sql);
        echo "<span class='success'>✓ Created demo events</span><br>";
    }

    // Insert demo data for new tables

    // Project Milestones
    $sql = "INSERT IGNORE INTO project_milestones (tenant_id, project_id, name, description, due_date, status, priority, responsible_user_id) VALUES
            (1, 1, 'Design Mockups Complete', 'Finalize all design mockups', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_progress', 'high', 2),
            (1, 1, 'Frontend Development', 'Complete frontend implementation', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pending', 'high', 2),
            (1, 2, 'Requirements Gathering', 'Complete requirements document', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_progress', 'critical', 1)";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Inserted project milestones</span><br>";
    }

    // Event Attendees
    $sql = "INSERT IGNORE INTO event_attendees (tenant_id, event_id, user_id, attendance_status, rsvp_response, is_organizer) VALUES
            (1, 1, 1, 'accepted', 'yes', TRUE),
            (1, 1, 2, 'invited', 'pending', FALSE),
            (1, 1, 3, 'accepted', 'yes', FALSE),
            (1, 2, 1, 'accepted', 'yes', TRUE),
            (1, 2, 2, 'tentative', 'maybe', FALSE)";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Inserted event attendees</span><br>";
    }

    // System Settings
    $sql = "INSERT IGNORE INTO system_settings (tenant_id, setting_group, setting_key, setting_value, value_type, display_name, description, is_public) VALUES
            (1, 'general', 'app_name', 'CollaboraNexio', 'string', 'Application Name', 'The name of the application', TRUE),
            (1, 'general', 'timezone', 'UTC', 'string', 'Time Zone', 'Default timezone for the application', TRUE),
            (1, 'security', 'session_timeout', '3600', 'integer', 'Session Timeout', 'Session timeout in seconds', FALSE),
            (1, 'security', 'max_login_attempts', '5', 'integer', 'Max Login Attempts', 'Maximum login attempts before lockout', FALSE),
            (1, 'email', 'smtp_host', 'localhost', 'string', 'SMTP Host', 'SMTP server hostname', FALSE),
            (1, 'email', 'smtp_port', '25', 'integer', 'SMTP Port', 'SMTP server port', FALSE)";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Inserted system settings</span><br>";
    }

    echo "</div>";

    // ============================================
    // STEP 6: CREATE INDEXES
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 6: Creating Additional Indexes</b><br>";

    // Create composite indexes for better query performance
    $indexes = [
        "CREATE INDEX idx_milestone_status_date ON project_milestones(status, due_date)",
        "CREATE INDEX idx_attendee_response ON event_attendees(rsvp_response, attendance_status)",
        "CREATE INDEX idx_session_remember ON sessions(remember_token, is_active)",
        "CREATE INDEX idx_rate_window ON rate_limits(first_attempt_at, window_minutes)",
        "CREATE INDEX idx_settings_public ON system_settings(is_public, setting_group)"
    ];

    foreach ($indexes as $sql) {
        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ Created index</span><br>";
        } else {
            if (strpos($mysqli->error, 'Duplicate') === false && strpos($mysqli->error, 'already exists') === false) {
                echo "<span class='warning'>⚠ Index issue: " . $mysqli->error . "</span><br>";
            }
        }
    }

    echo "</div>";

    // ============================================
    // STEP 7: RE-ENABLE FOREIGN KEY CHECKS
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 7: Finalizing Installation</b><br>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "<span class='success'>✓ Foreign key checks re-enabled</span><br>";
    echo "</div>";

    // ============================================
    // STEP 8: VERIFICATION
    // ============================================
    echo "<div class='box'>";
    echo "<b>Step 8: Verification</b><br>";

    $tables_to_check = ['project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings'];
    $all_success = true;

    foreach ($tables_to_check as $table) {
        $result = $mysqli->query("SELECT COUNT(*) as count FROM $table");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "<span class='success'>✓ $table: {$row['count']} records</span><br>";
        } else {
            echo "<span class='error'>✗ $table: Not accessible</span><br>";
            $all_success = false;
        }
    }

    echo "</div>";

    // ============================================
    // FINAL STATUS
    // ============================================
    if ($all_success) {
        echo "<div class='box success-box'>";
        echo "<h3 class='success'>✅ Migration Complete!</h3>";
        echo "All 5 missing tables have been successfully created with demo data:<br>";
        echo "• project_milestones - Project milestone tracking<br>";
        echo "• event_attendees - Event attendance management<br>";
        echo "• sessions - User session management<br>";
        echo "• rate_limits - API rate limiting<br>";
        echo "• system_settings - Application configuration<br><br>";
        echo "<b>Next Steps:</b><br>";
        echo "1. Test the application functionality<br>";
        echo "2. Review and adjust system settings<br>";
        echo "3. Configure rate limiting rules<br>";
        echo "</div>";
    } else {
        echo "<div class='box error-box'>";
        echo "<h3 class='error'>⚠ Migration Partially Complete</h3>";
        echo "Some tables may not have been created successfully. Please check the error messages above.<br>";
        echo "</div>";
    }

    $mysqli->close();

} catch (Exception $e) {
    echo "<div class='box error-box'>";
    echo "<span class='error'>Fatal Error: " . $e->getMessage() . "</span>";
    echo "</div>";
}
?>