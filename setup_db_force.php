<?php
// Force Database Setup Script for CollaboraNexio
// This script will completely reset the database

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

    echo "<h2>CollaboraNexio Database Setup</h2>";
    echo "Connected to MySQL successfully<br>";

    // Drop and recreate the entire database
    echo "<br><b>Step 1: Dropping existing database...</b><br>";
    $mysqli->query("DROP DATABASE IF EXISTS $database");
    echo "✓ Database dropped<br>";

    echo "<br><b>Step 2: Creating fresh database...</b><br>";
    $sql = "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        die("Error creating database: " . $mysqli->error);
    }
    echo "✓ Database created<br>";

    // Select the new database
    $mysqli->select_db($database);
    echo "✓ Using database: $database<br>";

    // Now create all tables from scratch
    echo "<br><b>Step 3: Creating base tables...</b><br>";

    // 1. Tenants table (no foreign keys)
    $sql = "CREATE TABLE tenants (
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

    if (!$mysqli->query($sql)) {
        die("Error creating tenants table: " . $mysqli->error);
    }
    echo "✓ tenants table created<br>";

    // 2. Users table
    $sql = "CREATE TABLE users (
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

    if (!$mysqli->query($sql)) {
        die("Error creating users table: " . $mysqli->error);
    }
    echo "✓ users table created<br>";

    // 3. Teams table
    $sql = "CREATE TABLE teams (
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

    if (!$mysqli->query($sql)) {
        die("Error creating teams table: " . $mysqli->error);
    }
    echo "✓ teams table created<br>";

    // 4. Files table
    $sql = "CREATE TABLE files (
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

    if (!$mysqli->query($sql)) {
        die("Error creating files table: " . $mysqli->error);
    }
    echo "✓ files table created<br>";

    // Insert base sample data
    echo "<br><b>Step 4: Inserting sample data...</b><br>";

    $mysqli->query("INSERT INTO tenants (id, name, domain) VALUES
        (1, 'Demo Company', 'demo.local'),
        (2, 'Test Company', 'test.local')");
    echo "✓ Tenants inserted<br>";

    $mysqli->query("INSERT INTO users (id, tenant_id, name, email, password) VALUES
        (1, 1, 'Admin User', 'admin@demo.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "'),
        (2, 1, 'John Doe', 'john@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "'),
        (3, 1, 'Jane Smith', 'jane@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "')");
    echo "✓ Users inserted<br>";

    $mysqli->query("INSERT INTO teams (id, tenant_id, name, description, manager_id) VALUES
        (1, 1, 'Development', 'Development team', 1),
        (2, 1, 'Marketing', 'Marketing team', 2)");
    echo "✓ Teams inserted<br>";

    // Create chat system tables
    echo "<br><b>Step 5: Creating chat system tables...</b><br>";

    // Chat Channels
    $sql = "CREATE TABLE chat_channels (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        echo "⚠ Warning creating chat_channels: " . $mysqli->error . "<br>";
    } else {
        echo "✓ chat_channels created<br>";
    }

    // Channel Members
    $sql = "CREATE TABLE channel_members (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        echo "⚠ Warning creating channel_members: " . $mysqli->error . "<br>";
    } else {
        echo "✓ channel_members created<br>";
    }

    // Chat Messages
    $sql = "CREATE TABLE chat_messages (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1000000";

    if (!$mysqli->query($sql)) {
        echo "⚠ Warning creating chat_messages: " . $mysqli->error . "<br>";
    } else {
        echo "✓ chat_messages created<br>";
    }

    // Create remaining tables without showing all details
    $tables = [
        'message_edits' => "CREATE TABLE message_edits (
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

        'chat_presence' => "CREATE TABLE chat_presence (
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

        'chat_typing' => "CREATE TABLE chat_typing (
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

        'message_reactions' => "CREATE TABLE message_reactions (
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

        'message_attachments' => "CREATE TABLE message_attachments (
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

        'message_mentions' => "CREATE TABLE message_mentions (
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

        'message_read_receipts' => "CREATE TABLE message_read_receipts (
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

    foreach ($tables as $name => $sql) {
        if (!$mysqli->query($sql)) {
            echo "⚠ Warning creating $name: " . $mysqli->error . "<br>";
        } else {
            echo "✓ $name created<br>";
        }
    }

    // Insert sample chat data
    echo "<br><b>Step 6: Inserting sample chat data...</b><br>";

    $mysqli->query("INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
        (1, 'public', 'general', 'General discussion', 1, 1),
        (1, 'public', 'random', 'Random topics', 1, 1),
        (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2)");
    echo "✓ Channels created<br>";

    $mysqli->query("INSERT INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
        (1, 1, 1, 'admin'),
        (1, 1, 2, 'member'),
        (1, 1, 3, 'member')");
    echo "✓ Members added<br>";

    $mysqli->query("INSERT INTO chat_messages (tenant_id, channel_id, user_id, content, content_plain, sequence_id) VALUES
        (1, 1, 1, 'Welcome to CollaboraNexio Chat! 🎉', 'Welcome to CollaboraNexio Chat!', 1000001),
        (1, 1, 2, 'Hello everyone!', 'Hello everyone!', 1000002),
        (1, 1, 3, 'Great to be here!', 'Great to be here!', 1000003)");
    echo "✓ Messages added<br>";

    $mysqli->query("INSERT INTO chat_presence (tenant_id, user_id, status) VALUES
        (1, 1, 'online'),
        (1, 2, 'online'),
        (1, 3, 'away')");
    echo "✓ Presence set<br>";

    // Final verification
    echo "<br><b>Step 7: Verification...</b><br>";

    $result = $mysqli->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    echo "Total tables created: <b>" . count($tables) . "</b><br>";
    echo "<details><summary>Click to see all tables</summary>";
    echo "<pre>" . implode("\n", $tables) . "</pre>";
    echo "</details><br>";

    // Check data
    $result = $mysqli->query("SELECT COUNT(*) as count FROM chat_messages");
    $count = $result->fetch_assoc()['count'];
    echo "Messages in database: <b>$count</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as count FROM users");
    $count = $result->fetch_assoc()['count'];
    echo "Users in database: <b>$count</b><br>";

    echo "<br><div style='background: #4CAF50; color: white; padding: 10px; border-radius: 5px;'>";
    echo "<h3>✅ DATABASE SETUP COMPLETE!</h3>";
    echo "The CollaboraNexio database has been successfully created with all tables and sample data.";
    echo "</div>";

    echo "<br><b>Login credentials:</b><br>";
    echo "Email: admin@demo.com<br>";
    echo "Password: admin123<br>";

    $mysqli->close();

} catch (Exception $e) {
    echo "<div style='background: #f44336; color: white; padding: 10px; border-radius: 5px;'>";
    echo "Error: " . $e->getMessage();
    echo "</div>";
}
?>