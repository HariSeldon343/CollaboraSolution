<?php
/**
 * Files Table Migration Script
 *
 * This script safely migrates the existing files table to the new structure
 * required by the file management system.
 *
 * Author: Database Architect
 * Date: 2025-09-27
 */

require_once 'config.php';

// Initialize PDO connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

// Color codes for terminal output
$colors = [
    'reset'  => "\033[0m",
    'red'    => "\033[31m",
    'green'  => "\033[32m",
    'yellow' => "\033[33m",
    'blue'   => "\033[34m",
    'cyan'   => "\033[36m"
];

function printMessage($message, $color = 'reset') {
    global $colors;
    echo $colors[$color] . $message . $colors['reset'] . "\n";
}

function printHeader($message) {
    printMessage("\n" . str_repeat("=", 60), 'blue');
    printMessage($message, 'cyan');
    printMessage(str_repeat("=", 60) . "\n", 'blue');
}

function executeSQL($pdo, $sql, $description) {
    try {
        printMessage("  → " . $description . "...", 'yellow');
        $result = $pdo->exec($sql);
        printMessage("    ✓ Success", 'green');
        return true;
    } catch (PDOException $e) {
        printMessage("    ✗ Error: " . $e->getMessage(), 'red');
        return false;
    }
}

// Main migration process
try {
    printHeader("FILES TABLE MIGRATION TOOL");
    printMessage("Starting migration process at " . date('Y-m-d H:i:s'), 'cyan');

    // Step 1: Check current table structure
    printHeader("STEP 1: ANALYZING CURRENT STRUCTURE");

    $tableExists = false;
    $hasData = false;
    $currentColumns = [];

    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'files'");
        if ($checkTable->rowCount() > 0) {
            $tableExists = true;
            printMessage("  ✓ Table 'files' exists", 'green');

            // Get column information
            $columns = $pdo->query("DESCRIBE files");
            $currentColumns = $columns->fetchAll(PDO::FETCH_ASSOC);

            printMessage("\n  Current columns:", 'cyan');
            foreach ($currentColumns as $col) {
                printMessage("    - " . $col['Field'] . " (" . $col['Type'] . ")", 'reset');
            }

            // Check for data
            $count = $pdo->query("SELECT COUNT(*) as total FROM files")->fetch();
            $hasData = $count['total'] > 0;

            if ($hasData) {
                printMessage("\n  ⚠ Table contains " . $count['total'] . " records", 'yellow');
            } else {
                printMessage("\n  ✓ Table is empty", 'green');
            }
        } else {
            printMessage("  ✓ Table 'files' does not exist", 'green');
        }
    } catch (PDOException $e) {
        printMessage("  ✗ Error checking table: " . $e->getMessage(), 'red');
    }

    // Step 2: Backup existing data
    if ($tableExists && $hasData) {
        printHeader("STEP 2: BACKING UP EXISTING DATA");

        $backupTableName = 'files_backup_' . date('Ymd_His');

        $sql = "CREATE TABLE `$backupTableName` AS SELECT * FROM files";
        if (executeSQL($pdo, $sql, "Creating backup table '$backupTableName'")) {
            printMessage("  ✓ Backup created successfully", 'green');

            // Verify backup
            $backupCount = $pdo->query("SELECT COUNT(*) as total FROM `$backupTableName`")->fetch();
            printMessage("  ✓ Backed up " . $backupCount['total'] . " records", 'green');
        } else {
            printMessage("\n  ⚠ WARNING: Could not create backup. Continue anyway? (y/n): ", 'yellow');
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            if (trim($line) != 'y') {
                printMessage("  ✗ Migration cancelled", 'red');
                exit(1);
            }
            fclose($handle);
        }
    } else {
        printMessage("\nSkipping backup (no data to backup)", 'cyan');
    }

    // Step 3: Drop dependent objects
    printHeader("STEP 3: DROPPING DEPENDENT OBJECTS");

    $dependentObjects = [
        'VIEW active_files' => 'DROP VIEW IF EXISTS active_files',
        'TABLE file_activity_logs' => 'DROP TABLE IF EXISTS file_activity_logs',
        'TABLE file_permissions' => 'DROP TABLE IF EXISTS file_permissions'
    ];

    foreach ($dependentObjects as $object => $sql) {
        executeSQL($pdo, $sql, "Dropping $object");
    }

    // Step 4: Recreate files table with new structure
    printHeader("STEP 4: RECREATING FILES TABLE");

    // Drop old table
    if ($tableExists) {
        executeSQL($pdo, "DROP TABLE IF EXISTS files", "Dropping old files table");
    }

    // Create new table
    $createTableSQL = "
    CREATE TABLE files (
        -- Multi-tenancy support
        tenant_id INT NOT NULL,

        -- Primary key
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,

        -- Core fields
        name VARCHAR(255) NOT NULL COMMENT 'Name of the file or folder',
        file_path VARCHAR(500) NOT NULL COMMENT 'Full path to the file',
        file_type VARCHAR(50) DEFAULT NULL COMMENT 'File extension (pdf, doc, etc)',
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
        mime_type VARCHAR(100) DEFAULT NULL COMMENT 'MIME type of the file',

        -- Folder structure
        is_folder BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE if this is a folder',
        folder_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent folder ID',

        -- User tracking
        uploaded_by INT NOT NULL COMMENT 'User who uploaded the file',

        -- Additional metadata
        original_name VARCHAR(255) DEFAULT NULL COMMENT 'Original filename when uploaded',
        description TEXT DEFAULT NULL,
        is_public BOOLEAN NOT NULL DEFAULT FALSE,
        public_token VARCHAR(64) DEFAULT NULL,
        shared_with JSON DEFAULT NULL,
        download_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_accessed_at TIMESTAMP NULL DEFAULT NULL,

        -- Audit fields
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL DEFAULT NULL,

        PRIMARY KEY (id),
        CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        CONSTRAINT fk_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_files_parent_folder FOREIGN KEY (folder_id) REFERENCES files(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!executeSQL($pdo, $createTableSQL, "Creating new files table")) {
        printMessage("\n  ✗ Failed to create new table structure", 'red');
        exit(1);
    }

    // Step 5: Create indexes
    printHeader("STEP 5: CREATING INDEXES");

    $indexes = [
        'idx_files_tenant_lookup' => 'CREATE INDEX idx_files_tenant_lookup ON files(tenant_id, deleted_at, is_folder)',
        'idx_files_folder_structure' => 'CREATE INDEX idx_files_folder_structure ON files(tenant_id, folder_id, name)',
        'idx_files_uploaded_by' => 'CREATE INDEX idx_files_uploaded_by ON files(uploaded_by, created_at)',
        'idx_files_public_access' => 'CREATE INDEX idx_files_public_access ON files(is_public, public_token)',
        'idx_files_file_type' => 'CREATE INDEX idx_files_file_type ON files(file_type, tenant_id)',
        'idx_files_deleted' => 'CREATE INDEX idx_files_deleted ON files(deleted_at)',
        'idx_files_folder_contents' => 'CREATE INDEX idx_files_folder_contents ON files(folder_id, deleted_at, name)'
    ];

    foreach ($indexes as $indexName => $sql) {
        executeSQL($pdo, $sql, "Creating index $indexName");
    }

    // Step 6: Restore data if backup exists
    if ($tableExists && $hasData && isset($backupTableName)) {
        printHeader("STEP 6: RESTORING DATA FROM BACKUP");

        // Check which columns exist in backup
        $backupColumns = $pdo->query("DESCRIBE `$backupTableName`")->fetchAll(PDO::FETCH_COLUMN, 0);

        // Build column mapping
        $columnMap = [
            'name' => in_array('file_name', $backupColumns) ? 'file_name' :
                     (in_array('original_name', $backupColumns) ? 'original_name' : "'unnamed_file'"),
            'file_path' => in_array('file_path', $backupColumns) ? 'file_path' : "'/unknown'",
            'file_type' => in_array('file_type', $backupColumns) ? 'file_type' : 'NULL',
            'file_size' => in_array('file_size', $backupColumns) ? 'COALESCE(file_size, 0)' : '0',
            'mime_type' => in_array('mime_type', $backupColumns) ? 'mime_type' : 'NULL',
            'is_folder' => in_array('is_folder', $backupColumns) ? 'COALESCE(is_folder, 0)' : '0',
            'folder_id' => in_array('folder_id', $backupColumns) ? 'folder_id' : 'NULL',
            'uploaded_by' => in_array('uploaded_by', $backupColumns) ? 'uploaded_by' : '1',
            'original_name' => in_array('original_name', $backupColumns) ? 'original_name' : 'NULL',
            'is_public' => in_array('is_public', $backupColumns) ? 'COALESCE(is_public, 0)' : '0',
            'public_token' => in_array('public_token', $backupColumns) ? 'public_token' : 'NULL',
            'shared_with' => in_array('shared_with', $backupColumns) ? 'shared_with' : 'NULL',
            'download_count' => in_array('download_count', $backupColumns) ? 'COALESCE(download_count, 0)' : '0',
            'last_accessed_at' => in_array('last_accessed_at', $backupColumns) ? 'last_accessed_at' : 'NULL',
            'created_at' => in_array('created_at', $backupColumns) ? 'created_at' : 'NOW()',
            'updated_at' => in_array('updated_at', $backupColumns) ? 'updated_at' : 'NOW()',
            'deleted_at' => in_array('deleted_at', $backupColumns) ? 'deleted_at' : 'NULL'
        ];

        // Build restore query
        $restoreSQL = "
        INSERT INTO files (
            tenant_id, id, name, file_path, file_type, file_size, mime_type,
            is_folder, folder_id, uploaded_by, original_name, is_public,
            public_token, shared_with, download_count, last_accessed_at,
            created_at, updated_at, deleted_at
        )
        SELECT
            tenant_id,
            id,
            {$columnMap['name']} as name,
            {$columnMap['file_path']} as file_path,
            {$columnMap['file_type']} as file_type,
            {$columnMap['file_size']} as file_size,
            {$columnMap['mime_type']} as mime_type,
            {$columnMap['is_folder']} as is_folder,
            {$columnMap['folder_id']} as folder_id,
            {$columnMap['uploaded_by']} as uploaded_by,
            {$columnMap['original_name']} as original_name,
            {$columnMap['is_public']} as is_public,
            {$columnMap['public_token']} as public_token,
            {$columnMap['shared_with']} as shared_with,
            {$columnMap['download_count']} as download_count,
            {$columnMap['last_accessed_at']} as last_accessed_at,
            {$columnMap['created_at']} as created_at,
            {$columnMap['updated_at']} as updated_at,
            {$columnMap['deleted_at']} as deleted_at
        FROM `$backupTableName`
        ";

        if (executeSQL($pdo, $restoreSQL, "Restoring data from backup")) {
            $restoredCount = $pdo->query("SELECT COUNT(*) as total FROM files")->fetch();
            printMessage("  ✓ Restored " . $restoredCount['total'] . " records", 'green');
        }
    }

    // Step 7: Create default folders
    printHeader("STEP 7: CREATING DEFAULT FOLDERS");

    $defaultFolders = ['Documents', 'Projects', 'Shared'];

    foreach ($defaultFolders as $folderName) {
        $sql = "
        INSERT INTO files (tenant_id, name, file_path, is_folder, uploaded_by, created_at)
        SELECT
            t.id as tenant_id,
            '$folderName' as name,
            CONCAT('/tenant_', t.id, '/', LOWER('$folderName')) as file_path,
            TRUE as is_folder,
            COALESCE(
                (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1),
                (SELECT id FROM users WHERE tenant_id = t.id ORDER BY id LIMIT 1)
            ) as uploaded_by,
            NOW() as created_at
        FROM tenants t
        WHERE t.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM files f
            WHERE f.tenant_id = t.id
            AND f.name = '$folderName'
            AND f.is_folder = TRUE
            AND f.deleted_at IS NULL
        )
        ";

        executeSQL($pdo, $sql, "Creating '$folderName' folder for active tenants");
    }

    // Step 8: Recreate dependent objects
    printHeader("STEP 8: RECREATING DEPENDENT OBJECTS");

    // Create file_permissions table
    $permissionsTableSQL = "
    CREATE TABLE file_permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id INT UNSIGNED NOT NULL,
        user_id INT DEFAULT NULL,
        permission ENUM('view', 'download', 'edit', 'delete', 'share') NOT NULL DEFAULT 'view',
        granted_by INT NOT NULL,
        granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL DEFAULT NULL,

        PRIMARY KEY (id),
        UNIQUE KEY unique_file_user_permission (file_id, user_id, permission),
        INDEX idx_permission_file (file_id),
        INDEX idx_permission_user (user_id),
        INDEX idx_permission_expires (expires_at),

        CONSTRAINT fk_permissions_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        CONSTRAINT fk_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    executeSQL($pdo, $permissionsTableSQL, "Creating file_permissions table");

    // Create file_activity_logs table
    $activityLogsSQL = "
    CREATE TABLE file_activity_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT NOT NULL,
        file_id INT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        action ENUM('upload', 'download', 'view', 'edit', 'delete', 'restore', 'share', 'move', 'rename', 'create_folder') NOT NULL,
        details JSON DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        INDEX idx_log_file (file_id, created_at),
        INDEX idx_log_user (user_id, created_at),
        INDEX idx_log_tenant (tenant_id, created_at),
        INDEX idx_log_action (action, created_at),

        CONSTRAINT fk_logs_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
        CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    executeSQL($pdo, $activityLogsSQL, "Creating file_activity_logs table");

    // Create active_files view
    $viewSQL = "
    CREATE OR REPLACE VIEW active_files AS
    SELECT * FROM files WHERE deleted_at IS NULL
    ";

    executeSQL($pdo, $viewSQL, "Creating active_files view");

    // Step 9: Final verification
    printHeader("STEP 9: VERIFICATION");

    // Check table structure
    $verifyColumns = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN, 0);
    $requiredColumns = ['id', 'tenant_id', 'name', 'file_path', 'file_type', 'file_size',
                       'mime_type', 'is_folder', 'folder_id', 'uploaded_by', 'created_at',
                       'updated_at', 'deleted_at'];

    $missingColumns = array_diff($requiredColumns, $verifyColumns);

    if (empty($missingColumns)) {
        printMessage("  ✓ All required columns are present", 'green');
    } else {
        printMessage("  ✗ Missing columns: " . implode(', ', $missingColumns), 'red');
    }

    // Check record counts
    $stats = [
        'Total files' => $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn(),
        'Folders' => $pdo->query("SELECT COUNT(*) FROM files WHERE is_folder = TRUE")->fetchColumn(),
        'Documents' => $pdo->query("SELECT COUNT(*) FROM files WHERE is_folder = FALSE")->fetchColumn(),
        'Active files' => $pdo->query("SELECT COUNT(*) FROM files WHERE deleted_at IS NULL")->fetchColumn()
    ];

    printMessage("\n  Statistics:", 'cyan');
    foreach ($stats as $label => $count) {
        printMessage("    - $label: $count", 'reset');
    }

    // Success message
    printHeader("MIGRATION COMPLETED SUCCESSFULLY");
    printMessage("Migration completed at " . date('Y-m-d H:i:s'), 'green');

    if (isset($backupTableName)) {
        printMessage("\n  ℹ Backup table '$backupTableName' has been preserved", 'cyan');
        printMessage("  You can drop it manually after verifying the migration:", 'cyan');
        printMessage("    DROP TABLE `$backupTableName`;", 'yellow');
    }

} catch (Exception $e) {
    printMessage("\n✗ FATAL ERROR: " . $e->getMessage(), 'red');
    printMessage("\nStack trace:", 'red');
    printMessage($e->getTraceAsString(), 'red');
    exit(1);
}

printMessage("\n✓ Migration tool finished successfully\n", 'green');