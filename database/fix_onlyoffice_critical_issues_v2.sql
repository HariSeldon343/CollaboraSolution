-- ============================================
-- Fix OnlyOffice Integration Critical Issues (v2)
-- ============================================
-- Version: 1.0.1
-- Date: 2025-10-12
-- Author: Database Architect
--
-- Purpose: Fix critical issues found in integrity verification:
--   1. Missing document_editor_callbacks table
--   2. Missing last_modified_by column in files table
--   3. Missing deleted_at column in document_editor_config
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

SELECT 'Checking last_modified_by column in files table...' as status;

-- Check if column already exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND COLUMN_NAME = 'last_modified_by'
);

SELECT IF(@column_exists > 0, 'Column last_modified_by already exists', 'Adding last_modified_by column...') as status;

-- Add column if it doesn't exist
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE files ADD COLUMN last_modified_by INT UNSIGNED NULL AFTER uploaded_by',
    'SELECT "Column last_modified_by already exists - skipping" as message'
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
    @fk_exists = 0,
    'ALTER TABLE files ADD CONSTRAINT fk_files_last_modified_by
     FOREIGN KEY (last_modified_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Foreign key for last_modified_by already exists - skipping" as message'
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
    'SELECT "Index idx_files_last_modified_by already exists - skipping" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Files table updated successfully' as status;

-- ============================================
-- ISSUE 3: Add deleted_at column to document_editor_config
-- ============================================

SELECT 'Checking deleted_at column in document_editor_config...' as status;

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'document_editor_config'
    AND COLUMN_NAME = 'deleted_at'
);

SELECT IF(@column_exists > 0, 'Column deleted_at already exists', 'Adding deleted_at column...') as status;

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE document_editor_config
     ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
    'SELECT "Column deleted_at already exists - skipping" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add deleted_at index
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
    'SELECT "Index idx_config_tenant_deleted already exists - skipping" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'document_editor_config table updated successfully' as status;

SET FOREIGN_KEY_CHECKS = 1;

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

SELECT CONCAT('Updated ', ROW_COUNT(), ' files with last_modified_by') as result;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '
==========================================
VERIFICATION
==========================================
' as section;

-- Verify document_editor_callbacks table
SELECT 'Checking document_editor_callbacks table...' as status;
SELECT COUNT(*) as callback_count,
       'document_editor_callbacks' as table_name
FROM document_editor_callbacks;

-- Verify files table columns
SELECT 'Checking files table for last_modified_by column...' as status;
SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio'
     AND TABLE_NAME = 'files'
     AND COLUMN_NAME = 'last_modified_by') > 0,
    'PASS',
    'FAIL'
) as last_modified_by_check;

-- Verify document_editor_config deleted_at
SELECT 'Checking document_editor_config for deleted_at column...' as status;
SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio'
     AND TABLE_NAME = 'document_editor_config'
     AND COLUMN_NAME = 'deleted_at') > 0,
    'PASS',
    'FAIL'
) as deleted_at_check;

-- Count indexes
SELECT 'Verifying indexes...' as status;
SELECT TABLE_NAME, INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME IN ('document_editor_callbacks', 'files', 'document_editor_config')
AND INDEX_NAME IN (
    'idx_callback_tenant_created',
    'idx_callback_tenant_deleted',
    'idx_files_last_modified_by',
    'idx_config_tenant_deleted'
)
GROUP BY TABLE_NAME, INDEX_NAME;

SELECT '
==========================================
MIGRATION COMPLETED SUCCESSFULLY
==========================================

Fixed Issues:
  1. document_editor_callbacks table created
  2. last_modified_by column added to files table
  3. deleted_at column added to document_editor_config
  4. Foreign key constraints added
  5. Performance indexes optimized
  6. Existing data updated

Next Steps:
  1. Re-run comprehensive_database_integrity_verification.php
  2. Verify all checks pass (should be 100%)
  3. Proceed with frontend testing

==========================================
' as summary;
