<?php
// Phase 5 Installation Script - Safe Version
// This script handles foreign key constraints properly

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

    echo "<h2>CollaboraNexio Phase 5 - External Collaboration Module</h2>";
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
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>";

    echo "Connected to database: <b>$database</b><br><br>";

    // CRITICAL: Disable foreign key checks
    echo "<div class='box'>";
    echo "<b>Step 1: Preparing Database</b><br>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    $mysqli->query("SET time_zone = '+00:00'");
    echo "<span class='success'>✓ Foreign key checks disabled</span><br>";
    echo "</div>";

    // Drop existing Phase 5 tables
    echo "<div class='box'>";
    echo "<b>Step 2: Cleaning Existing Tables</b><br>";

    $tables_to_drop = [
        'collaboration_notifications',
        'collaborative_editing_locks',
        'approval_step_delegates',
        'approval_steps',
        'approval_requests',
        'approval_workflows',
        'file_comment_resolutions',
        'file_comments',
        'file_version_comparisons',
        'file_versions',
        'share_access_logs',
        'share_link_permissions',
        'share_links'
    ];

    foreach ($tables_to_drop as $table) {
        $mysqli->query("DROP TABLE IF EXISTS $table");
        echo "Dropped: $table<br>";
    }
    echo "<span class='success'>✓ Old tables cleaned</span><br>";
    echo "</div>";

    // Create new tables
    echo "<div class='box'>";
    echo "<b>Step 3: Creating Collaboration Tables</b><br>";

    // 1. Share Links
    $sql = "CREATE TABLE share_links (
        tenant_id INT NOT NULL,
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id INT UNSIGNED NOT NULL,
        unique_token VARCHAR(64) NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        password_hash VARCHAR(255) NULL,
        requires_authentication BOOLEAN DEFAULT FALSE,
        expiration_date DATETIME NULL,
        max_downloads INT UNSIGNED NULL,
        current_downloads INT UNSIGNED DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        allow_download BOOLEAN DEFAULT TRUE,
        allow_view BOOLEAN DEFAULT TRUE,
        allow_comment BOOLEAN DEFAULT FALSE,
        allow_upload_version BOOLEAN DEFAULT FALSE,
        title VARCHAR(255) NULL,
        description TEXT NULL,
        custom_message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_accessed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_share_token (unique_token),
        INDEX idx_share_tenant_active (tenant_id, is_active),
        INDEX idx_share_token_lookup (unique_token, is_active),
        INDEX idx_share_file (file_id, tenant_id),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ share_links</span> ";
    } else {
        echo "<span class='error'>✗ share_links: " . $mysqli->error . "</span><br>";
    }

    // 2. Share Access Logs
    $sql = "CREATE TABLE share_access_logs (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        share_link_id INT UNSIGNED NOT NULL,
        access_type ENUM('view', 'download', 'comment', 'upload', 'denied') NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        referer_url TEXT NULL,
        authenticated_user_id INT UNSIGNED NULL,
        authentication_method ENUM('password', 'oauth', 'link_only', 'failed') NULL,
        session_id VARCHAR(64) NULL,
        country_code CHAR(2) NULL,
        city VARCHAR(100) NULL,
        success BOOLEAN DEFAULT TRUE,
        failure_reason VARCHAR(255) NULL,
        bytes_transferred BIGINT UNSIGNED NULL,
        duration_ms INT UNSIGNED NULL,
        accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_access_log_share (share_link_id, accessed_at),
        INDEX idx_access_log_tenant_date (tenant_id, accessed_at),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE,
        FOREIGN KEY (authenticated_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ share_access_logs</span> ";
    } else {
        echo "<span class='error'>✗ share_access_logs: " . $mysqli->error . "</span><br>";
    }

    // 3. File Versions
    $sql = "CREATE TABLE file_versions (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id INT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        mime_type VARCHAR(127) NOT NULL,
        file_hash VARCHAR(64) NOT NULL,
        storage_path VARCHAR(500) NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        modification_type ENUM('create', 'update', 'restore', 'merge', 'auto_save') DEFAULT 'update',
        change_summary TEXT NULL,
        parent_version_id BIGINT UNSIGNED NULL,
        is_current BOOLEAN DEFAULT FALSE,
        is_archived BOOLEAN DEFAULT FALSE,
        checksum_md5 CHAR(32) NULL,
        checksum_sha256 CHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_file_version (file_id, version_number),
        INDEX idx_version_current (file_id, is_current),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ file_versions</span><br>";
    } else {
        echo "<span class='error'>✗ file_versions: " . $mysqli->error . "</span><br>";
    }

    // 4. File Comments
    $sql = "CREATE TABLE file_comments (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id INT UNSIGNED NOT NULL,
        file_version_id BIGINT UNSIGNED NULL,
        parent_comment_id BIGINT UNSIGNED NULL,
        comment_text TEXT NOT NULL,
        comment_type ENUM('general', 'annotation', 'suggestion', 'question', 'approval', 'rejection') DEFAULT 'general',
        author_id INT UNSIGNED NOT NULL,
        author_name VARCHAR(100) NULL,
        author_email VARCHAR(255) NULL,
        is_external BOOLEAN DEFAULT FALSE,
        annotation_type ENUM('text', 'highlight', 'box', 'arrow', 'stamp') NULL,
        position_x DECIMAL(10,4) NULL,
        position_y DECIMAL(10,4) NULL,
        position_width DECIMAL(10,4) NULL,
        position_height DECIMAL(10,4) NULL,
        page_number INT UNSIGNED NULL,
        status ENUM('active', 'resolved', 'archived', 'deleted') DEFAULT 'active',
        priority ENUM('low', 'normal', 'high', 'critical') DEFAULT 'normal',
        thread_id VARCHAR(36) NULL,
        thread_position INT UNSIGNED DEFAULT 0,
        mentioned_users JSON NULL,
        requires_response BOOLEAN DEFAULT FALSE,
        edited_at DATETIME NULL,
        edit_count INT UNSIGNED DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        PRIMARY KEY (id),
        INDEX idx_comment_file (file_id, tenant_id, status),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (file_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ file_comments</span> ";
    } else {
        echo "<span class='error'>✗ file_comments: " . $mysqli->error . "</span><br>";
    }

    // Add self-referencing foreign key for parent_comment_id
    $mysqli->query("ALTER TABLE file_comments
        ADD CONSTRAINT fk_comment_parent
        FOREIGN KEY (parent_comment_id) REFERENCES file_comments(id) ON DELETE CASCADE");

    // 5. Approval Workflows
    $sql = "CREATE TABLE approval_workflows (
        tenant_id INT NOT NULL,
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        workflow_type ENUM('sequential', 'parallel', 'custom') DEFAULT 'sequential',
        auto_approve_on_timeout BOOLEAN DEFAULT FALSE,
        timeout_hours INT UNSIGNED NULL,
        applies_to_file_types JSON NULL,
        applies_to_folders JSON NULL,
        min_file_size BIGINT UNSIGNED NULL,
        max_file_size BIGINT UNSIGNED NULL,
        is_active BOOLEAN DEFAULT TRUE,
        is_default BOOLEAN DEFAULT FALSE,
        notify_on_submission BOOLEAN DEFAULT TRUE,
        notify_on_approval BOOLEAN DEFAULT TRUE,
        notify_on_rejection BOOLEAN DEFAULT TRUE,
        notify_on_completion BOOLEAN DEFAULT TRUE,
        reminder_frequency_hours INT UNSIGNED DEFAULT 24,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_workflow_tenant_active (tenant_id, is_active),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ approval_workflows</span> ";
    } else {
        echo "<span class='error'>✗ approval_workflows: " . $mysqli->error . "</span><br>";
    }

    // 6. Approval Requests
    $sql = "CREATE TABLE approval_requests (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        workflow_id INT UNSIGNED NOT NULL,
        file_id INT UNSIGNED NOT NULL,
        file_version_id BIGINT UNSIGNED NULL,
        request_number VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
        requested_by INT UNSIGNED NOT NULL,
        requested_for INT UNSIGNED NULL,
        department VARCHAR(100) NULL,
        status ENUM('draft', 'pending', 'in_review', 'approved', 'rejected', 'cancelled', 'expired') DEFAULT 'draft',
        current_step_number INT UNSIGNED DEFAULT 1,
        submitted_at DATETIME NULL,
        due_date DATETIME NULL,
        completed_at DATETIME NULL,
        final_decision ENUM('approved', 'rejected', 'cancelled') NULL,
        final_comments TEXT NULL,
        total_duration_hours INT UNSIGNED NULL,
        approval_score DECIMAL(5,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_request_number (tenant_id, request_number),
        INDEX idx_request_workflow (workflow_id, status),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (workflow_id) REFERENCES approval_workflows(id) ON DELETE RESTRICT,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (file_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (requested_for) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ approval_requests</span><br>";
    } else {
        echo "<span class='error'>✗ approval_requests: " . $mysqli->error . "</span><br>";
    }

    // 7. Approval Steps
    $sql = "CREATE TABLE approval_steps (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        approval_request_id BIGINT UNSIGNED NOT NULL,
        step_number INT UNSIGNED NOT NULL,
        step_name VARCHAR(100) NOT NULL,
        step_type ENUM('approval', 'review', 'notification', 'conditional') DEFAULT 'approval',
        required_approvals INT UNSIGNED DEFAULT 1,
        received_approvals INT UNSIGNED DEFAULT 0,
        assigned_to INT UNSIGNED NULL,
        assigned_role VARCHAR(100) NULL,
        assigned_group VARCHAR(100) NULL,
        status ENUM('pending', 'in_progress', 'approved', 'rejected', 'skipped', 'delegated') DEFAULT 'pending',
        decision ENUM('approve', 'reject', 'conditionally_approve') NULL,
        comments TEXT NULL,
        conditions_met JSON NULL,
        attachments JSON NULL,
        assigned_at DATETIME NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        reminder_sent_at DATETIME NULL,
        escalated_at DATETIME NULL,
        delegated_to INT UNSIGNED NULL,
        delegation_reason TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_request_step (approval_request_id, step_number),
        INDEX idx_step_assignee (assigned_to, status),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (delegated_to) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ approval_steps</span> ";
    } else {
        echo "<span class='error'>✗ approval_steps: " . $mysqli->error . "</span><br>";
    }

    // 8. Collaborative Editing Locks
    $sql = "CREATE TABLE collaborative_editing_locks (
        tenant_id INT NOT NULL,
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id INT UNSIGNED NOT NULL,
        locked_by INT UNSIGNED NOT NULL,
        lock_token VARCHAR(64) NOT NULL,
        lock_type ENUM('exclusive', 'shared', 'read', 'write') DEFAULT 'exclusive',
        lock_scope ENUM('file', 'section', 'page') DEFAULT 'file',
        section_id VARCHAR(100) NULL,
        page_number INT UNSIGNED NULL,
        start_position INT UNSIGNED NULL,
        end_position INT UNSIGNED NULL,
        session_id VARCHAR(64) NOT NULL,
        client_info JSON NULL,
        acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        renewed_at DATETIME NULL,
        renewal_count INT UNSIGNED DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        force_released BOOLEAN DEFAULT FALSE,
        release_reason VARCHAR(255) NULL,
        released_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_lock_token (lock_token),
        INDEX idx_lock_file (file_id, is_active),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ collaborative_editing_locks</span> ";
    } else {
        echo "<span class='error'>✗ collaborative_editing_locks: " . $mysqli->error . "</span><br>";
    }

    // 9. Collaboration Notifications
    $sql = "CREATE TABLE collaboration_notifications (
        tenant_id INT NOT NULL,
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        type ENUM('comment', 'mention', 'approval_request', 'approval_decision', 'share', 'version_update', 'lock_released') NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        file_id INT UNSIGNED NULL,
        comment_id BIGINT UNSIGNED NULL,
        approval_request_id BIGINT UNSIGNED NULL,
        share_link_id INT UNSIGNED NULL,
        is_read BOOLEAN DEFAULT FALSE,
        is_archived BOOLEAN DEFAULT FALSE,
        priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
        action_url VARCHAR(500) NULL,
        action_label VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        expires_at DATETIME NULL,
        PRIMARY KEY (id),
        INDEX idx_notification_user (user_id, is_read, created_at DESC),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        FOREIGN KEY (comment_id) REFERENCES file_comments(id) ON DELETE CASCADE,
        FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
        FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($mysqli->query($sql)) {
        echo "<span class='success'>✓ collaboration_notifications</span><br>";
    } else {
        echo "<span class='error'>✗ collaboration_notifications: " . $mysqli->error . "</span><br>";
    }

    // Add remaining tables
    $remaining_tables = [
        'share_link_permissions' => "CREATE TABLE share_link_permissions (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            share_link_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            can_access BOOLEAN DEFAULT TRUE,
            notification_sent BOOLEAN DEFAULT FALSE,
            first_accessed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_share_email (share_link_id, email),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'file_version_comparisons' => "CREATE TABLE file_version_comparisons (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_from_id BIGINT UNSIGNED NOT NULL,
            version_to_id BIGINT UNSIGNED NOT NULL,
            compared_by INT UNSIGNED NOT NULL,
            additions_count INT UNSIGNED DEFAULT 0,
            deletions_count INT UNSIGNED DEFAULT 0,
            modifications_count INT UNSIGNED DEFAULT 0,
            diff_data JSON NULL,
            compared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (version_from_id) REFERENCES file_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (version_to_id) REFERENCES file_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (compared_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'file_comment_resolutions' => "CREATE TABLE file_comment_resolutions (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT UNSIGNED NOT NULL,
            resolved_by INT UNSIGNED NOT NULL,
            resolution_type ENUM('fixed', 'wont_fix', 'duplicate', 'invalid', 'completed') NOT NULL,
            resolution_note TEXT NULL,
            related_version_id BIGINT UNSIGNED NULL,
            resolved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_comment_resolution (comment_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (comment_id) REFERENCES file_comments(id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (related_version_id) REFERENCES file_versions(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'approval_step_delegates' => "CREATE TABLE approval_step_delegates (
            tenant_id INT NOT NULL,
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            approval_step_id BIGINT UNSIGNED NOT NULL,
            delegated_from INT UNSIGNED NOT NULL,
            delegated_to INT UNSIGNED NOT NULL,
            delegation_reason TEXT NOT NULL,
            delegation_type ENUM('temporary', 'permanent', 'auto') DEFAULT 'temporary',
            auto_delegated BOOLEAN DEFAULT FALSE,
            delegated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (approval_step_id) REFERENCES approval_steps(id) ON DELETE CASCADE,
            FOREIGN KEY (delegated_from) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (delegated_to) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($remaining_tables as $name => $sql) {
        if ($mysqli->query($sql)) {
            echo "<span class='success'>✓ $name</span> ";
        } else {
            echo "<span class='error'>✗ $name: " . $mysqli->error . "</span><br>";
        }
    }
    echo "</div>";

    // Insert sample data
    echo "<div class='box'>";
    echo "<b>Step 4: Inserting Sample Data</b><br>";

    // Sample share links
    $sql = "INSERT INTO share_links (tenant_id, file_id, unique_token, created_by, expiration_date, max_downloads, title, description) VALUES
        (1, 1, MD5(CONCAT('SHR_token1_', UNIX_TIMESTAMP())), 1, DATE_ADD(NOW(), INTERVAL 7 DAY), 10, 'Project Proposal Review', 'Please review and provide feedback'),
        (1, 2, MD5(CONCAT('SHR_token2_', UNIX_TIMESTAMP())), 2, DATE_ADD(NOW(), INTERVAL 30 DAY), NULL, 'Design Mockup Share', 'Latest design iteration')";
    $mysqli->query($sql);
    echo "<span class='success'>✓ Share links created</span><br>";

    // Sample file versions
    $sql = "INSERT INTO file_versions (tenant_id, file_id, version_number, file_name, file_size, mime_type, file_hash, storage_path, created_by, modification_type, change_summary, is_current) VALUES
        (1, 1, 1, 'document_v1.pdf', 1024000, 'application/pdf', MD5('file1_v1'), '/versions/1/1/v1.pdf', 1, 'create', 'Initial version', FALSE),
        (1, 1, 2, 'document_v2.pdf', 1048576, 'application/pdf', MD5('file1_v2'), '/versions/1/1/v2.pdf', 1, 'update', 'Updated content', TRUE)";
    $mysqli->query($sql);
    echo "<span class='success'>✓ File versions created</span><br>";

    // Sample approval workflows
    $sql = "INSERT INTO approval_workflows (tenant_id, name, description, workflow_type, timeout_hours, is_active, is_default, created_by) VALUES
        (1, 'Standard Document Approval', 'Default approval process', 'sequential', 48, TRUE, TRUE, 1),
        (1, 'Fast Track Review', 'Expedited review', 'parallel', 24, TRUE, FALSE, 1)";
    $mysqli->query($sql);
    echo "<span class='success'>✓ Workflows created</span><br>";

    echo "</div>";

    // Re-enable foreign key checks
    echo "<div class='box'>";
    echo "<b>Step 5: Finalizing Installation</b><br>";
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "<span class='success'>✓ Foreign key checks re-enabled</span><br>";
    echo "</div>";

    // Verification
    echo "<div class='box'>";
    echo "<b>Step 6: Verification</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as count FROM information_schema.tables
                              WHERE table_schema = '$database'
                              AND table_name IN ('share_links', 'file_versions', 'file_comments',
                                                 'approval_workflows', 'approval_requests',
                                                 'collaborative_editing_locks')");
    $row = $result->fetch_assoc();
    echo "Core tables created: <b>{$row['count']}</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as c FROM share_links");
    $row = $result->fetch_assoc();
    echo "Share links: <b>{$row['c']}</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as c FROM file_versions");
    $row = $result->fetch_assoc();
    echo "File versions: <b>{$row['c']}</b><br>";

    $result = $mysqli->query("SELECT COUNT(*) as c FROM approval_workflows");
    $row = $result->fetch_assoc();
    echo "Workflows: <b>{$row['c']}</b><br>";
    echo "</div>";

    // Success message
    echo "<div class='box success-box'>";
    echo "<h3 class='success'>✅ Phase 5 Installation Complete!</h3>";
    echo "The External Collaboration module has been successfully installed.<br><br>";
    echo "<b>Features now available:</b><br>";
    echo "• Secure file sharing with unique links<br>";
    echo "• File version control system<br>";
    echo "• Comments and annotations on files<br>";
    echo "• Multi-step approval workflows<br>";
    echo "• Collaborative editing locks<br>";
    echo "</div>";

    $mysqli->close();

} catch (Exception $e) {
    echo "<div class='box' style='border-left-color: #f44336;'>";
    echo "<span class='error'>Fatal Error: " . $e->getMessage() . "</span>";
    echo "</div>";
}
?>