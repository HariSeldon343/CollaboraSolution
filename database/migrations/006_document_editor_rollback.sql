-- ============================================
-- ROLLBACK: Document Editor Integration
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Rollback script for document editor migration
-- WARNING: This will remove all document editor functionality!
-- ============================================

USE collaboranexio;

-- ============================================
-- STEP 1: BACKUP DATA (Optional but recommended)
-- ============================================

-- Create backup tables before rollback
CREATE TABLE IF NOT EXISTS document_editor_sessions_backup AS
SELECT * FROM document_editor_sessions;

CREATE TABLE IF NOT EXISTS document_editor_changes_backup AS
SELECT * FROM document_editor_changes;

CREATE TABLE IF NOT EXISTS document_editor_locks_backup AS
SELECT * FROM document_editor_locks;

-- Backup files table columns
CREATE TABLE IF NOT EXISTS files_editor_columns_backup AS
SELECT
    id,
    is_editable,
    editor_format,
    last_edited_by,
    last_edited_at,
    editor_version,
    is_locked,
    checksum
FROM files
WHERE last_edited_by IS NOT NULL
   OR editor_version > 0;

SELECT 'Backup tables created' as status,
       (SELECT COUNT(*) FROM document_editor_sessions) as sessions_backed_up,
       (SELECT COUNT(*) FROM document_editor_changes) as changes_backed_up,
       NOW() as backup_time;

-- ============================================
-- STEP 2: DROP TRIGGERS AND VIEWS
-- ============================================

DROP TRIGGER IF EXISTS update_file_editor_version;
DROP VIEW IF EXISTS v_editor_statistics;

SELECT 'Triggers and views dropped' as status;

-- ============================================
-- STEP 3: DROP STORED PROCEDURES AND FUNCTIONS
-- ============================================

DROP PROCEDURE IF EXISTS get_active_editor_sessions;
DROP PROCEDURE IF EXISTS cleanup_expired_editor_sessions;
DROP FUNCTION IF EXISTS generate_document_key;

SELECT 'Stored procedures and functions dropped' as status;

-- ============================================
-- STEP 4: REMOVE FOREIGN KEY CONSTRAINTS
-- ============================================

-- Remove foreign key from files table
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND CONSTRAINT_NAME = 'fk_files_last_edited_by'
);

SET @sql = IF(@fk_exists > 0,
    'ALTER TABLE files DROP FOREIGN KEY fk_files_last_edited_by',
    'SELECT "Foreign key fk_files_last_edited_by does not exist" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 5: DROP INDEXES
-- ============================================

-- Drop indexes from files table
DROP INDEX IF EXISTS idx_files_editable ON files;
DROP INDEX IF EXISTS idx_files_locked ON files;

SELECT 'Indexes dropped from files table' as status;

-- ============================================
-- STEP 6: REMOVE COLUMNS FROM FILES TABLE
-- ============================================

ALTER TABLE files
    DROP COLUMN IF EXISTS is_editable,
    DROP COLUMN IF EXISTS editor_format,
    DROP COLUMN IF EXISTS last_edited_by,
    DROP COLUMN IF EXISTS last_edited_at,
    DROP COLUMN IF EXISTS editor_version,
    DROP COLUMN IF EXISTS is_locked,
    DROP COLUMN IF EXISTS checksum;

SELECT 'Columns removed from files table' as status;

-- ============================================
-- STEP 7: DROP DOCUMENT EDITOR TABLES
-- ============================================

-- Drop in reverse order of dependencies
DROP TABLE IF EXISTS document_editor_changes;
DROP TABLE IF EXISTS document_editor_locks;
DROP TABLE IF EXISTS document_editor_sessions;

SELECT 'Document editor tables dropped' as status;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Rollback completed' as status,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME LIKE 'document_editor%'
        AND TABLE_NAME NOT LIKE '%_backup') as remaining_editor_tables,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME = 'files'
        AND COLUMN_NAME IN ('is_editable', 'editor_format', 'last_edited_by',
                           'last_edited_at', 'editor_version', 'is_locked', 'checksum'))
        as remaining_editor_columns,
       NOW() as rollback_completed_at;

-- ============================================
-- RESTORE DATA (Optional)
-- ============================================

/*
-- To restore backed up data after re-running migration:

-- Restore sessions
INSERT INTO document_editor_sessions
SELECT * FROM document_editor_sessions_backup;

-- Restore changes
INSERT INTO document_editor_changes
SELECT * FROM document_editor_changes_backup;

-- Restore locks (if still relevant)
INSERT INTO document_editor_locks
SELECT * FROM document_editor_locks_backup
WHERE expires_at > NOW();

-- Update files table with backed up editor data
UPDATE files f
INNER JOIN files_editor_columns_backup b ON f.id = b.id
SET f.is_editable = b.is_editable,
    f.editor_format = b.editor_format,
    f.last_edited_by = b.last_edited_by,
    f.last_edited_at = b.last_edited_at,
    f.editor_version = b.editor_version,
    f.is_locked = b.is_locked,
    f.checksum = b.checksum;

*/

-- ============================================
-- CLEANUP BACKUP TABLES (Optional)
-- ============================================

/*
-- Run this after confirming rollback is successful:

DROP TABLE IF EXISTS document_editor_sessions_backup;
DROP TABLE IF EXISTS document_editor_changes_backup;
DROP TABLE IF EXISTS document_editor_locks_backup;
DROP TABLE IF EXISTS files_editor_columns_backup;

*/

-- ============================================
-- END OF ROLLBACK
-- ============================================