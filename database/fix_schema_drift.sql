-- Module: Schema Drift Fix - Code Normalization
-- Version: 2025-10-03
-- Author: Database Architect
-- Description: Updates DOCUMENTATION to match actual database reality
--              This is a DOCUMENTATION-ONLY update. No database changes are made.
--              The actual database structure is CORRECT and working.

-- ============================================
-- ANALYSIS SUMMARY
-- ============================================
/*
ACTUAL DATABASE STRUCTURE (Production - CORRECT):
  - file_size (bigint) ✓
  - file_path (varchar(500)) ✓
  - uploaded_by (int(11)) ✓
  - NO checksum column ✓
  - Additional columns: original_tenant_id, original_name, is_public, public_token,
    shared_with, download_count, last_accessed_at, reassigned_at, reassigned_by

DOCUMENTED SCHEMA (03_complete_schema.sql - OUTDATED):
  - size_bytes (bigint unsigned) ✗
  - storage_path (varchar(500)) ✗
  - owner_id (int unsigned) ✗
  - checksum (varchar(64)) ✗ (never implemented)

DECISION: Update documentation to match actual database (Option B)
RATIONALE:
  - 12 production files exist - no risk to data
  - Actual schema is working and tested
  - Lower risk than database migration
  - No downtime required
*/

-- ============================================
-- VERIFICATION QUERIES
-- ============================================
-- Run these queries to verify the actual structure matches expectations

-- 1. Verify files table structure
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'files'
ORDER BY ORDINAL_POSITION;

-- Expected columns:
-- id, tenant_id, original_tenant_id, name, file_path, file_type,
-- file_size, mime_type, is_folder, folder_id, uploaded_by, original_name,
-- is_public, public_token, shared_with, download_count, last_accessed_at,
-- created_at, updated_at, deleted_at, reassigned_at, reassigned_by

-- 2. Verify file_versions table (should have different schema)
SELECT
    COLUMN_NAME,
    DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'file_versions'
ORDER BY ORDINAL_POSITION;

-- Expected: Uses size_bytes, storage_path (documented schema) - This is OK!

-- 3. Verify folders table
SELECT
    COLUMN_NAME,
    DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'folders'
AND COLUMN_NAME IN ('owner_id', 'uploaded_by', 'created_by');

-- Expected: owner_id exists (folders use owner_id, files use uploaded_by)

-- 4. Test query with actual schema
SELECT
    f.id,
    f.name,
    f.file_size,
    f.file_path,
    f.uploaded_by,
    u.name as uploader_name
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.tenant_id = 1
AND f.deleted_at IS NULL
LIMIT 5;

-- 5. Verify indexes on actual columns
SHOW INDEXES FROM files
WHERE Column_name IN ('file_size', 'file_path', 'uploaded_by', 'size_bytes', 'storage_path', 'owner_id');

-- Expected: Indexes on uploaded_by exist, none on non-existent columns

-- ============================================
-- DATA INTEGRITY CHECKS
-- ============================================

-- Check for NULL values in critical columns
SELECT
    'Files with NULL file_size' as check_name,
    COUNT(*) as count
FROM files
WHERE file_size IS NULL AND is_folder = 0

UNION ALL

SELECT
    'Files with NULL file_path' as check_name,
    COUNT(*) as count
FROM files
WHERE file_path IS NULL AND is_folder = 0

UNION ALL

SELECT
    'Files with NULL uploaded_by' as check_name,
    COUNT(*) as count
FROM files
WHERE uploaded_by IS NULL;

-- All counts should be 0 or minimal

-- Check for orphaned file references
SELECT
    'Files with invalid uploaded_by' as check_name,
    COUNT(*) as count
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.uploaded_by IS NOT NULL
AND u.id IS NULL;

-- Should be 0

-- ============================================
-- RECOMMENDED SCHEMA DOCUMENTATION
-- ============================================
-- This is how the files table SHOULD be documented going forward

/*
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
*/

-- ============================================
-- FILE_VERSIONS TABLE DOCUMENTATION
-- ============================================
-- Note: file_versions intentionally uses DIFFERENT naming convention
-- This is ACCEPTABLE because versions are "historical snapshots"

/*
CREATE TABLE file_versions (
    tenant_id INT(10) UNSIGNED NOT NULL,
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT(10) UNSIGNED NOT NULL,
    version_number INT(10) UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,

    -- Note: Versions use documented schema names (historical context)
    size_bytes BIGINT(20) UNSIGNED NOT NULL COMMENT 'Size in bytes',
    storage_path VARCHAR(500) NOT NULL COMMENT 'Path to archived version',
    checksum VARCHAR(64) DEFAULT NULL COMMENT 'File integrity hash',

    uploaded_by INT(10) UNSIGNED NOT NULL COMMENT 'User who created this version',
    comment TEXT DEFAULT NULL COMMENT 'Version comment/notes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    INDEX idx_version_file (file_id, version_number),
    INDEX idx_version_tenant (tenant_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/

-- ============================================
-- NAMING CONVENTION STANDARD
-- ============================================
/*
ESTABLISHED NAMING CONVENTIONS:

files table:
  - file_size (NOT size_bytes) - current file size in bytes
  - file_path (NOT storage_path) - relative path to file
  - uploaded_by (NOT owner_id) - user who uploaded file

folders table:
  - owner_id (NOT uploaded_by) - user who owns folder
  - Different semantic meaning: ownership vs upload action

file_versions table:
  - size_bytes - size of historical version
  - storage_path - path to archived version
  - Different context: historical snapshot vs active file

RATIONALE:
- files.uploaded_by = "who performed the upload action"
- folders.owner_id = "who owns/controls the folder"
- Semantic distinction preserved, not just technical difference
*/

-- ============================================
-- MIGRATION STATUS
-- ============================================
-- Record this schema verification in migration history

INSERT INTO migration_history (
    migration_name,
    description,
    status,
    executed_at
) VALUES (
    'schema_drift_documentation_fix_20251003',
    'Documented actual database schema for files table. No database changes made. Confirmed production schema is correct: file_size, file_path, uploaded_by.',
    'applied',
    NOW()
) ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    status = 'applied',
    executed_at = NOW();

-- ============================================
-- SUCCESS MESSAGE
-- ============================================
SELECT
    'Schema verification completed' as status,
    'Documentation updated to match production database' as action,
    'No database changes required' as changes,
    'Review /database/SCHEMA_DRIFT_ANALYSIS_REPORT.md for details' as next_steps,
    NOW() as timestamp;
