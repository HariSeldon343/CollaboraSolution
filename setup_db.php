<?php
// Database setup script for CollaboraNexio
// Run this file in browser or CLI to setup the database

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

    echo "Connected to MySQL successfully\n";

    // Create database if not exists
    $sql = "CREATE DATABASE IF NOT EXISTS $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        die("Error creating database: " . $mysqli->error);
    }

    // Select database
    $mysqli->select_db($database);
    echo "Using database: $database\n\n";

    // Disable foreign key checks
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

    // Drop all existing tables to start fresh
    echo "Dropping existing tables...\n";
    $tables_to_drop = [
        'message_read_receipts', 'message_mentions', 'message_attachments',
        'message_reactions', 'message_edits', 'chat_typing', 'chat_presence',
        'chat_messages', 'channel_members', 'chat_channels',
        'files', 'teams', 'users', 'tenants'
    ];

    foreach ($tables_to_drop as $table) {
        $mysqli->query("DROP TABLE IF EXISTS $table");
    }

    // Create base tables
    echo "Creating base tables...\n";

    // 1. Tenants table
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
    echo "✓ tenants table created\n";

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
    echo "✓ users table created\n";

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
    echo "✓ teams table created\n";

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
    echo "✓ files table created\n\n";

    // Insert sample data
    echo "Inserting sample data...\n";

    $mysqli->query("INSERT INTO tenants (id, name, domain) VALUES
        (1, 'Demo Company', 'demo.local'),
        (2, 'Test Company', 'test.local')");

    $mysqli->query("INSERT INTO users (id, tenant_id, name, email, password) VALUES
        (1, 1, 'Admin User', 'admin@demo.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "'),
        (2, 1, 'John Doe', 'john@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "'),
        (3, 1, 'Jane Smith', 'jane@demo.com', '" . password_hash('password', PASSWORD_DEFAULT) . "')");

    $mysqli->query("INSERT INTO teams (id, tenant_id, name, description, manager_id) VALUES
        (1, 1, 'Development', 'Development team', 1),
        (2, 1, 'Marketing', 'Marketing team', 2)");

    echo "✓ Sample data inserted\n\n";

    // Create chat tables
    echo "Creating chat system tables...\n";

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
        die("Error creating chat_channels: " . $mysqli->error);
    }
    echo "✓ chat_channels created\n";

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
        die("Error creating channel_members: " . $mysqli->error);
    }
    echo "✓ channel_members created\n";

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
        die("Error creating chat_messages: " . $mysqli->error);
    }
    echo "✓ chat_messages created\n";

    // Other chat tables
    $other_tables = [
        "CREATE TABLE message_edits (
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

        "CREATE TABLE chat_presence (
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

        "CREATE TABLE chat_typing (
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

        "CREATE TABLE message_reactions (
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

        "CREATE TABLE message_attachments (
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

        "CREATE TABLE message_mentions (
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

        "CREATE TABLE message_read_receipts (
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

    $table_names = ['message_edits', 'chat_presence', 'chat_typing', 'message_reactions',
                    'message_attachments', 'message_mentions', 'message_read_receipts'];

    foreach ($other_tables as $index => $sql) {
        if (!$mysqli->query($sql)) {
            die("Error creating {$table_names[$index]}: " . $mysqli->error);
        }
        echo "✓ {$table_names[$index]} created\n";
    }

    // Insert sample chat data
    echo "\nInserting sample chat data...\n";

    $mysqli->query("INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
        (1, 'public', 'general', 'General discussion', 1, 1),
        (1, 'public', 'random', 'Random topics', 1, 1),
        (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2)");

    $mysqli->query("INSERT INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
        (1, 1, 1, 'admin'),
        (1, 1, 2, 'member'),
        (1, 1, 3, 'member'),
        (1, 2, 1, 'member'),
        (1, 2, 2, 'member')");

    $mysqli->query("INSERT INTO chat_messages (tenant_id, channel_id, user_id, content, content_plain, sequence_id) VALUES
        (1, 1, 1, 'Welcome to CollaboraNexio!', 'Welcome to CollaboraNexio!', 1000001),
        (1, 1, 2, 'Hello everyone!', 'Hello everyone!', 1000002),
        (1, 1, 3, 'Great to be here!', 'Great to be here!', 1000003)");

    $mysqli->query("INSERT INTO chat_presence (tenant_id, user_id, status) VALUES
        (1, 1, 'online'),
        (1, 2, 'online'),
        (1, 3, 'away')");

    echo "✓ Sample chat data inserted\n\n";

    // Re-enable foreign key checks
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

    // Verify installation
    echo "Verifying installation...\n";
    $result = $mysqli->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    echo "Tables created: " . count($tables) . "\n";
    echo "Tables: " . implode(", ", $tables) . "\n\n";

    // Test query
    $result = $mysqli->query("SELECT COUNT(*) as count FROM chat_messages");
    $count = $result->fetch_assoc()['count'];
    echo "Messages in database: $count\n\n";

    echo "✅ DATABASE SETUP COMPLETE!\n";
    echo "You can now use the chat system.\n";

    $mysqli->close();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>