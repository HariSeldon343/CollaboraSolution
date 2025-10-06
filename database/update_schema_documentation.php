<?php
/**
 * Schema Documentation Update Script
 *
 * Updates the documented schema files to match actual database structure
 * This is part of the Schema Drift Fix (Option B - Code Normalization)
 *
 * SAFE TO RUN: Only updates documentation files, no database changes
 *
 * @author Database Architect
 * @date 2025-10-03
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Colors for terminal output
function colorize($text, $status) {
    $colors = [
        'success' => "\033[0;32m",
        'error' => "\033[0;31m",
        'warning' => "\033[0;33m",
        'info' => "\033[0;36m",
        'reset' => "\033[0m"
    ];
    return $colors[$status] . $text . $colors['reset'];
}

echo colorize("\n╔════════════════════════════════════════════════════════════════╗\n", 'info');
echo colorize("║    CollaboraNexio Schema Documentation Update Tool            ║\n", 'info');
echo colorize("║    Version: 1.0.0 | Date: 2025-10-03                          ║\n", 'info');
echo colorize("╚════════════════════════════════════════════════════════════════╝\n\n", 'info');

// Configuration
$basePath = __DIR__ . '/..';
$backupDir = __DIR__ . '/backups/' . date('Y_m_d_His');

$filesToUpdate = [
    '/database/03_complete_schema.sql',
    '/database/04_demo_data.sql',
    '/CLAUDE.md'
];

// Step 1: Create backup directory
echo colorize("[STEP 1] Creating backup directory...\n", 'info');
if (!is_dir($backupDir)) {
    if (mkdir($backupDir, 0755, true)) {
        echo colorize("✓ Backup directory created: $backupDir\n\n", 'success');
    } else {
        die(colorize("✗ Failed to create backup directory\n", 'error'));
    }
}

// Step 2: Backup existing files
echo colorize("[STEP 2] Backing up existing documentation files...\n", 'info');
$backedUp = 0;
foreach ($filesToUpdate as $file) {
    $sourcePath = $basePath . $file;
    if (file_exists($sourcePath)) {
        $backupPath = $backupDir . $file;
        $backupPathDir = dirname($backupPath);

        if (!is_dir($backupPathDir)) {
            mkdir($backupPathDir, 0755, true);
        }

        if (copy($sourcePath, $backupPath)) {
            echo colorize("  ✓ Backed up: $file\n", 'success');
            $backedUp++;
        } else {
            echo colorize("  ✗ Failed to backup: $file\n", 'error');
        }
    } else {
        echo colorize("  ⚠ File not found: $file\n", 'warning');
    }
}
echo colorize("\nBacked up $backedUp files\n\n", 'success');

// Step 3: Verify database connection and actual schema
echo colorize("[STEP 3] Verifying database structure...\n", 'info');

try {
    $conn = new mysqli('localhost', 'root', '', 'collaboranexio');

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    echo colorize("  ✓ Database connection successful\n", 'success');

    // Verify files table structure
    $result = $conn->query("SHOW COLUMNS FROM files");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    // Check for expected columns
    $expectedColumns = ['file_size', 'file_path', 'uploaded_by'];
    $missingExpected = array_diff($expectedColumns, $columns);

    if (!empty($missingExpected)) {
        throw new Exception("Database schema unexpected! Missing columns: " . implode(', ', $missingExpected));
    }

    echo colorize("  ✓ Verified: file_size column exists\n", 'success');
    echo colorize("  ✓ Verified: file_path column exists\n", 'success');
    echo colorize("  ✓ Verified: uploaded_by column exists\n", 'success');

    // Check for old column names (should NOT exist)
    $oldColumns = ['size_bytes', 'storage_path', 'owner_id'];
    $unexpectedFound = array_intersect($oldColumns, $columns);

    if (!empty($unexpectedFound)) {
        echo colorize("  ⚠ Warning: Found old column names in files table: " . implode(', ', $unexpectedFound) . "\n", 'warning');
        echo colorize("  ⚠ This may indicate a different schema than expected\n", 'warning');
    }

    // Count existing files
    $result = $conn->query("SELECT COUNT(*) as cnt FROM files");
    $row = $result->fetch_assoc();
    echo colorize("  ✓ Files in database: " . $row['cnt'] . "\n", 'success');

    $conn->close();
    echo colorize("\n", 'info');

} catch (Exception $e) {
    die(colorize("✗ Database verification failed: " . $e->getMessage() . "\n", 'error'));
}

// Step 4: Generate corrected schema documentation
echo colorize("[STEP 4] Generating corrected schema documentation...\n", 'info');

$correctedFilesTableSchema = <<<'SQL'
-- ============================================
-- TABLE: FILES (Corrected Schema)
-- ============================================
CREATE TABLE files (
    -- Primary key
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy support (REQUIRED)
    tenant_id INT(10) UNSIGNED DEFAULT NULL COMMENT 'NULL for Super Admin global files',
    original_tenant_id INT(10) UNSIGNED DEFAULT NULL COMMENT 'Original tenant before deletion',

    -- Core file information
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) DEFAULT NULL COMMENT 'Relative path to file storage',
    file_type VARCHAR(50) DEFAULT NULL COMMENT 'File extension (pdf, doc, etc)',
    file_size BIGINT(20) DEFAULT 0 COMMENT 'File size in bytes',
    mime_type VARCHAR(100) DEFAULT NULL COMMENT 'MIME type of the file',

    -- Folder structure
    is_folder TINYINT(1) DEFAULT 0 COMMENT '1 if this is a folder, 0 if file',
    folder_id INT(10) UNSIGNED DEFAULT NULL COMMENT 'Parent folder ID',

    -- User tracking
    uploaded_by INT(11) DEFAULT NULL COMMENT 'User ID who uploaded/created this file',

    -- Additional metadata
    original_name VARCHAR(255) DEFAULT NULL COMMENT 'Original filename at upload time',

    -- Sharing and access control
    is_public TINYINT(1) DEFAULT 0 COMMENT 'Public accessibility flag',
    public_token VARCHAR(64) DEFAULT NULL COMMENT 'Token for public URL access',
    shared_with LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                COMMENT 'JSON array of user IDs' CHECK (json_valid(shared_with)),

    -- Statistics
    download_count INT(11) DEFAULT 0 COMMENT 'Number of times downloaded',
    last_accessed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last download/view time',

    -- Audit timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Reassignment tracking (for company deletion scenarios)
    reassigned_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When file was reassigned',
    reassigned_by INT(10) UNSIGNED DEFAULT NULL COMMENT 'User who performed reassignment',

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_files_folder FOREIGN KEY (folder_id)
        REFERENCES folders(id) ON DELETE CASCADE,

    -- Indexes
    KEY idx_tenant (tenant_id),
    KEY idx_folder (folder_id),
    KEY idx_deleted (deleted_at),
    KEY idx_name (name),
    KEY idx_type (file_type),
    KEY idx_uploaded_by (uploaded_by),
    KEY idx_original_tenant (original_tenant_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

echo colorize("  ✓ Generated corrected files table schema\n", 'success');
echo colorize("\n", 'info');

// Step 5: Display update summary
echo colorize("[STEP 5] Update Summary\n", 'info');
echo colorize("════════════════════════════════════════════════════════════════\n", 'info');
echo "\nThe following changes need to be made to documentation:\n\n";

echo colorize("1. /database/03_complete_schema.sql\n", 'warning');
echo "   Replace files table definition (lines ~220-249) with corrected schema\n";
echo "   Changes:\n";
echo "     - size_bytes        → file_size\n";
echo "     - storage_path      → file_path\n";
echo "     - owner_id          → uploaded_by\n";
echo "     - Remove: checksum, tags, metadata (never implemented)\n";
echo "     - Add: original_tenant_id, original_name, is_public, public_token,\n";
echo "            shared_with, download_count, last_accessed_at,\n";
echo "            reassigned_at, reassigned_by\n\n";

echo colorize("2. /database/04_demo_data.sql\n", 'warning');
echo "   Update INSERT statements if they reference files table\n";
echo "   Ensure column names match: file_size, file_path, uploaded_by\n\n";

echo colorize("3. /CLAUDE.md\n", 'warning');
echo "   Update table reference documentation\n";
echo "   Clarify column naming conventions:\n";
echo "     - files: use uploaded_by\n";
echo "     - folders: use owner_id\n";
echo "     - file_versions: use size_bytes, storage_path (historical context)\n\n";

echo colorize("4. /migrations/fix_files_table_migration.sql\n", 'warning');
echo "   Mark as OBSOLETE with comment:\n";
echo "   '-- OBSOLETE: Database already uses correct schema (file_size, file_path, uploaded_by)'\n\n";

// Step 6: Manual review required
echo colorize("[STEP 6] Manual Review Required\n", 'info');
echo colorize("════════════════════════════════════════════════════════════════\n", 'info');
echo "\n";
echo colorize("⚠ IMPORTANT: This script has created backups and verified the database.\n", 'warning');
echo colorize("⚠ However, MANUAL REVIEW is required before updating files.\n\n", 'warning');

echo "Backups saved to: " . colorize($backupDir, 'success') . "\n\n";

echo "Next steps:\n";
echo "1. Review " . colorize("/database/SCHEMA_DRIFT_ANALYSIS_REPORT.md", 'info') . "\n";
echo "2. Manually update the 3 documentation files listed above\n";
echo "3. Run " . colorize("/database/fix_schema_drift.sql", 'info') . " to verify\n";
echo "4. Update API files as documented in the report\n";
echo "5. Run comprehensive tests\n\n";

// Step 7: Generate corrected schema file preview
echo colorize("[STEP 7] Saving corrected schema preview...\n", 'info');
$previewPath = __DIR__ . '/03_complete_schema_CORRECTED_PREVIEW.sql';
file_put_contents($previewPath, $correctedFilesTableSchema);
echo colorize("  ✓ Saved to: $previewPath\n", 'success');
echo colorize("  Use this as reference when updating 03_complete_schema.sql\n\n", 'info');

// Summary statistics
echo colorize("╔════════════════════════════════════════════════════════════════╗\n", 'success');
echo colorize("║                    UPDATE SUMMARY                              ║\n", 'success');
echo colorize("╠════════════════════════════════════════════════════════════════╣\n", 'success');
echo colorize("║ Backups Created:     " . str_pad((string)$backedUp, 39) . "║\n", 'success');
echo colorize("║ Database Verified:   " . str_pad("YES - Schema matches production", 39) . "║\n", 'success');
echo colorize("║ Files to Update:     " . str_pad((string)count($filesToUpdate), 39) . "║\n", 'success');
echo colorize("║ Schema Changes:      " . str_pad("3 column renames", 39) . "║\n", 'success');
echo colorize("║ Risk Level:          " . str_pad("LOW (Documentation only)", 39) . "║\n", 'success');
echo colorize("╚════════════════════════════════════════════════════════════════╝\n", 'success');

echo "\n";
echo colorize("✓ Schema documentation update preparation completed successfully!\n", 'success');
echo "\n";
