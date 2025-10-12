-- ============================================
-- Fix OnlyOffice Integration Critical Issues
-- ============================================
-- Version: 1.0.0
-- Date: 2025-10-12
-- Author: Database Architect
--
-- Purpose: Fix 2 critical issues found in integrity verification:
--   1. Missing document_editor_callbacks table
--   2. Missing last_modified_by column in files table
-- ============================================

USE collaboranexio;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- ISSUE 1: Create document_editor_callbacks table
-- ============================================

SELECT 'Creating document_editor_callbacks table...' as status;

CREATE TABLE IF NOT EXISTS document_editor_callbacks (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Session reference
    session_id INT UNSIGNED NOT NULL,

    -- Callback data
    callback_type ENUM(
        'status_changed',
        'force_save',
        'save',
        'editing',
        'co_editing',
        'error'
    ) NOT NULL,

    callback_url VARCHAR(500) NULL,
    callback_data JSON NULL,

    -- Status tracking
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    http_status_code INT NULL,
    response_data JSON NULL,
    error_message TEXT NULL,

    -- Processing timestamps
    processed_at TIMESTAMP NULL DEFAULT NULL,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_callback_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_callback_session FOREIGN KEY (session_id)
        REFERENCES document_editor_sessions(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_callback_tenant_created (tenant_id, created_at),
    INDEX idx_callback_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_callback_tenant_status (tenant_id, status, deleted_at),
    INDEX idx_callback_session (session_id, status),
    INDEX idx_callback_type (callback_type),
    INDEX idx_callback_pending (status, retry_count, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks OnlyOffice callback events for document editing';

SELECT 'Table document_editor_callbacks created successfully' as status;

-- ============================================
-- ISSUE 2: Add last_modified_by column to files table
-- ============================================

SELECT 'Adding last_modified_by column to files table...' as status;

-- Check if column already exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND COLUMN_NAME = 'last_modified_by'
);

-- Add column if it doesn't exist
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE files ADD COLUMN last_modified_by INT UNSIGNED NULL AFTER uploaded_by',
    'SELECT "Column last_modified_by already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint if column was just added
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND COLUMN_NAME = 'last_modified_by'
    AND REFERENCED_TABLE_NAME = 'users'
);

SET @sql = IF(
    @fk_exists = 0 AND @column_exists = 0,
    'ALTER TABLE files ADD CONSTRAINT fk_files_last_modified_by
     FOREIGN KEY (last_modified_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists or column was not added" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for performance
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND INDEX_NAME = 'idx_files_last_modified_by'
);

SET @sql = IF(
    @idx_exists = 0,
    'CREATE INDEX idx_files_last_modified_by ON files(last_modified_by, updated_at)',
    'SELECT "Index idx_files_last_modified_by already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Column last_modified_by added successfully with foreign key and index' as status;

-- ============================================
-- BONUS: Add deleted_at index to document_editor_config
-- ============================================

SELECT 'Adding performance index to document_editor_config...' as status;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'document_editor_config'
    AND INDEX_NAME = 'idx_config_tenant_deleted'
);

SET @sql = IF(
    @idx_exists = 0,
    'CREATE INDEX idx_config_tenant_deleted ON document_editor_config(tenant_id, deleted_at, created_at)',
    'SELECT "Index idx_config_tenant_deleted already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Performance index added successfully' as status;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration completed successfully' as status,
       NOW() as executed_at;

-- Verify document_editor_callbacks table
SELECT 'Verifying document_editor_callbacks table...' as status;
SELECT COUNT(*) as callback_count FROM document_editor_callbacks;
SHOW CREATE TABLE document_editor_callbacks\G

-- Verify files table columns
SELECT 'Verifying files table columns...' as status;
DESCRIBE files;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME IN ('document_editor_callbacks', 'files', 'document_editor_config')
AND INDEX_NAME IN ('idx_callback_tenant_created', 'idx_files_last_modified_by', 'idx_config_tenant_deleted')
GROUP BY TABLE_NAME, INDEX_NAME;

-- ============================================
-- POST-MIGRATION DATA UPDATE
-- ============================================

-- Update last_modified_by for existing files (set to uploaded_by initially)
SELECT 'Updating last_modified_by for existing files...' as status;

UPDATE files
SET last_modified_by = uploaded_by
WHERE last_modified_by IS NULL
AND uploaded_by IS NOT NULL
AND deleted_at IS NULL;

SELECT 'Files updated:', ROW_COUNT() as updated_count;

SELECT '
==========================================
MIGRATION COMPLETED SUCCESSFULLY
==========================================

Summary:
  1. document_editor_callbacks table created
  2. last_modified_by column added to files table
  3. Foreign key constraints added
  4. Performance indexes optimized
  5. Existing data updated

Next Steps:
  1. Re-run comprehensive_database_integrity_verification.php
  2. Verify all checks pass
  3. Proceed with frontend testing

==========================================
' as summary;
