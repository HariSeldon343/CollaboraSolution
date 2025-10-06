<?php
// Clean Installation Script for CollaboraNexio
// This script handles existing database issues

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$username = 'root';
$password = ''; // Empty password for XAMPP default
$database = 'collabora';

try {
    // Connect to MySQL
    $mysqli = new mysqli($host, $username, $password);

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    echo "<h2>CollaboraNexio Database Clean Installation</h2>";
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
    </style>";

    echo "Connected to MySQL successfully<br><br>";

    // Try to use existing database or create new one
    echo "<div class='box'>";
    echo "<b>Step 1: Database Setup</b><br>";

    // Check if database exists
    $db_exists = $mysqli->select_db($database);

    if ($db_exists) {
        echo "<span class='info'>Database '$database' exists. Cleaning existing tables...</span><br>";

        // Disable foreign key checks for cleanup
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

        // Get all tables and drop them
        $result = $mysqli->query("SHOW TABLES");
        if ($result) {
            $tables = [];
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }

            foreach ($tables as $table) {
                $mysqli->query("DROP TABLE IF EXISTS `$table`");
                echo "Dropped table: $table<br>";
            }
        }

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
        echo "<span class='success'>✓ All existing tables dropped</span><br>";

    } else {
        // Create new database
        echo "<span class='info'>Database does not exist. Creating new...</span><br>";
        $sql = "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if (!$mysqli->query($sql)) {
            // If can't create, try to use it anyway
            echo "<span class='warning'>Warning: Could not create database (may already exist)</span><br>";
        }
        $mysqli->select_db($database);
        echo "<span class='success'>✓ Database ready</span><br>";
    }
    echo "</div>";

    // Make sure we're using the right database
    $mysqli->select_db($database);

    // Create base tables
    echo "<div class='box'>";
    echo "<b>Step 2: Creating Base Tables</b><br>";

    // 1. Tenants table
    $sql = "CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NULL,
        settings JSON NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant_name (name),
        INDEX idx_tenant_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ tenants</span> ";
    } else {
        echo "<span class='error'>✗ tenants: " . $mysqli->error . "</span><br>";
    }

    // 2. Users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'manager', 'user') DEFAULT 'user',
        avatar_url TEXT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        last_login_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_email (tenant_id, email),
        INDEX idx_user_tenant (tenant_id),
        INDEX idx_user_active (is_active),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ users</span> ";
    } else {
        echo "<span class='error'>✗ users: " . $mysqli->error . "</span><br>";
    }

    // 3. Teams table
    $sql = "CREATE TABLE IF NOT EXISTS teams (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        manager_id INT UNSIGNED NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_team_tenant (tenant_id),
        INDEX idx_team_active (is_active),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ teams</span> ";
    } else {
        echo "<span class='error'>✗ teams: " . $mysqli->error . "</span><br>";
    }

    // 4. Files table
    $sql = "CREATE TABLE IF NOT EXISTS files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path TEXT NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        mime_type VARCHAR(100) NULL,
        checksum VARCHAR(64) NULL,
        is_public BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_file_tenant (tenant_id),
        INDEX idx_file_user (user_id),
        INDEX idx_file_checksum (checksum),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ files</span><br>";
    } else {
        echo "<span class='error'>✗ files: " . $mysqli->error . "</span><br>";
    }
    echo "</div>";

    // Create chat tables
    echo "<div class='box'>";
    echo "<b>Step 3: Creating Chat Tables</b><br>";

    $chat_tables = [
        'chat_channels' => "CREATE TABLE IF NOT EXISTS chat_channels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            channel_type ENUM('public', 'private', 'direct') DEFAULT 'public',
            name VARCHAR(255) NULL,
            description TEXT NULL,
            team_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NOT NULL,
            is_archived BOOLEAN DEFAULT FALSE,
            allow_threading BOOLEAN DEFAULT TRUE,
            max_members INT UNSIGNED NULL,
            last_message_at TIMESTAMP NULL,
            message_count INT UNSIGNED DEFAULT 0,
            member_count INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_channels_tenant (tenant_id),
            INDEX idx_channels_team (team_id),
            INDEX idx_channels_created_by (created_by),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'channel_members' => "CREATE TABLE IF NOT EXISTS channel_members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            channel_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('owner', 'admin', 'member', 'guest') DEFAULT 'member',
            muted_until TIMESTAMP NULL,
            notification_level ENUM('all', 'mentions', 'none') DEFAULT 'all',
            last_read_message_id INT UNSIGNED NULL,
            last_read_at TIMESTAMP NULL,
            unread_count INT UNSIGNED DEFAULT 0,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_channel_member (channel_id, user_id),
            INDEX idx_members_tenant (tenant_id),
            INDEX idx_members_user (user_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'chat_messages' => "CREATE TABLE IF NOT EXISTS chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            channel_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            parent_message_id INT UNSIGNED NULL,
            message_type ENUM('text', 'file', 'system', 'code', 'poll') DEFAULT 'text',
            content TEXT NOT NULL,
            content_plain TEXT NULL,
            is_edited BOOLEAN DEFAULT FALSE,
            is_deleted BOOLEAN DEFAULT FALSE,
            is_pinned BOOLEAN DEFAULT FALSE,
            edit_count INT UNSIGNED DEFAULT 0,
            reaction_count INT UNSIGNED DEFAULT 0,
            reply_count INT UNSIGNED DEFAULT 0,
            sequence_id BIGINT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            INDEX idx_messages_channel (channel_id),
            INDEX idx_messages_user (user_id),
            INDEX idx_messages_sequence (tenant_id, sequence_id),
            FULLTEXT idx_messages_content (content_plain),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000000",

        'message_edits' => "CREATE TABLE IF NOT EXISTS message_edits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            previous_content TEXT NOT NULL,
            new_content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'chat_presence' => "CREATE TABLE IF NOT EXISTS chat_presence (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
            status_message VARCHAR(255) NULL,
            last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_poll_at TIMESTAMP NULL,
            current_channel_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_presence_user (tenant_id, user_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'chat_typing' => "CREATE TABLE IF NOT EXISTS chat_typing (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            channel_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_typing (channel_id, user_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'message_reactions' => "CREATE TABLE IF NOT EXISTS message_reactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reaction (message_id, user_id, emoji),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'message_attachments' => "CREATE TABLE IF NOT EXISTS message_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            file_id INT UNSIGNED NULL,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            file_url TEXT NOT NULL,
            mime_type VARCHAR(100) NULL,
            thumbnail_url TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'message_mentions' => "CREATE TABLE IF NOT EXISTS message_mentions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            mentioned_user_id INT UNSIGNED NULL,
            mention_type ENUM('user', 'channel', 'everyone') DEFAULT 'user',
            notified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'message_read_receipts' => "CREATE TABLE IF NOT EXISTS message_read_receipts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_read_receipt (message_id, user_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($chat_tables as $name => $sql) {
        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ $name</span> ";
        } else {
            echo "<br><span class='error'>✗ $name: " . $mysqli->error . "</span>";
        }
    }
    echo "</div>";

    // Insert sample data
    echo "<div class='box'>";
    echo "<b>Step 4: Inserting Sample Data</b><br>";

    // Clear existing data first
    $mysqli->query("DELETE FROM tenants WHERE id IN (1,2)");

    // Insert tenants
    $sql = "INSERT INTO tenants (id, name, domain) VALUES
        (1, 'Demo Company', 'demo.local'),
        (2, 'Test Company', 'test.local')
        ON DUPLICATE KEY UPDATE name=VALUES(name)";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Tenants inserted</span><br>";
    }

    // Insert users
    $sql = "INSERT INTO users (id, tenant_id, name, email, password) VALUES
        (1, 1, 'Admin User', 'admin@demo.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "'),
        (2, 1, 'John Doe', 'john@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "'),
        (3, 1, 'Jane Smith', 'jane@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "')
        ON DUPLICATE KEY UPDATE name=VALUES(name)";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Users inserted</span><br>";
    }

    // Insert teams
    $sql = "INSERT INTO teams (tenant_id, name, description, manager_id) VALUES
        (1, 'Development', 'Development team', 1),
        (1, 'Marketing', 'Marketing team', 2)
        ON DUPLICATE KEY UPDATE name=VALUES(name)";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Teams inserted</span><br>";
    }

    // Insert channels
    $sql = "INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
        (1, 'public', 'general', 'General discussion', 1, 1),
        (1, 'public', 'random', 'Random topics', 1, 1),
        (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2)";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Channels created</span><br>";
    }

    // Insert channel members
    $sql = "INSERT INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
        (1, 1, 1, 'admin'),
        (1, 1, 2, 'member'),
        (1, 1, 3, 'member')";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Members added</span><br>";
    }

    // Insert messages
    $sql = "INSERT INTO chat_messages (tenant_id, channel_id, user_id, content, content_plain, sequence_id) VALUES
        (1, 1, 1, 'Welcome to CollaboraNexio Chat! 🎉', 'Welcome to CollaboraNexio Chat!', 1000001),
        (1, 1, 2, 'Hello everyone!', 'Hello everyone!', 1000002),
        (1, 1, 3, 'Great to be here!', 'Great to be here!', 1000003)";
    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ Messages added</span><br>";
    }
    echo "</div>";

    // Verification
    echo "<div class='box'>";
    echo "<b>Step 5: Verification</b><br>";

    $result = $mysqli->query("SHOW TABLES");
    $count = $result->num_rows;
    echo "Total tables: <b>$count</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as c FROM users");
    $row = $result->fetch_assoc();
    echo "Users: <b>{$row['c']}</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as c FROM chat_messages");
    $row = $result->fetch_assoc();
    echo "Messages: <b>{$row['c']}</b><br>";
    echo "</div>";

    // Success message
    echo "<div class='box success-box'>";
    echo "<h3 class='success'>✅ Installation Complete!</h3>";
    echo "The CollaboraNexio database has been successfully configured.<br><br>";
    echo "<b>Test Credentials:</b><br>";
    echo "Email: <code>admin@demo.com</code><br>";
    echo "Password: <code>admin123</code><br>";
    echo "</div>";

    $mysqli->close();

} catch (Exception $e) {
    echo "<div class='box' style='border-left-color: #f44336;'>";
    echo "<span class='error'>Fatal Error: " . $e->getMessage() . "</span>";
    echo "</div>";
}
?>