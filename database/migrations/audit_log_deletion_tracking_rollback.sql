-- ============================================
-- Module: Audit Log Deletion Tracking System - ROLLBACK
-- Version: 2025-10-27
-- Author: Database Architect
-- Description: Rollback script for audit_log_deletion_tracking.sql
--
-- WARNING: This will DROP audit_log_deletions table and remove
--          deleted_at column from audit_logs table
--
-- CRITICAL: Create backups before running this script!
-- ============================================

USE collaboranexio;

-- ============================================
-- SAFETY CHECK
-- ============================================
SELECT 'WARNING: This will REMOVE audit log deletion tracking!' as warning;
SELECT 'Press Ctrl+C to cancel within 5 seconds...' as warning;
SELECT SLEEP(5) as countdown;

-- ============================================
-- BACKUP RECOMMENDATION
-- ============================================
SELECT '
IMPORTANT: Create backup before proceeding!

Backup command:
mysqldump -u root collaboranexio audit_log_deletions > audit_log_deletions_backup_$(date +%Y%m%d_%H%M%S).sql
mysqldump -u root collaboranexio audit_logs > audit_logs_backup_$(date +%Y%m%d_%H%M%S).sql

Restore command (if needed):
mysql -u root collaboranexio < audit_log_deletions_backup_YYYYMMDD_HHMMSS.sql
mysql -u root collaboranexio < audit_logs_backup_YYYYMMDD_HHMMSS.sql
' as backup_instructions;

-- Verify backup exists (informational only)
SELECT
    'Current record counts (BACKUP THESE!):' as info,
    (SELECT COUNT(*) FROM audit_log_deletions) as deletion_records,
    (SELECT COUNT(*) FROM audit_logs WHERE deleted_at IS NOT NULL) as soft_deleted_logs,
    (SELECT COUNT(*) FROM audit_logs WHERE deleted_at IS NULL) as active_logs;

-- ============================================
-- STEP 1: DROP VIEWS
-- ============================================
SELECT 'Dropping views...' as status;

DROP VIEW IF EXISTS v_recent_audit_deletions;
DROP VIEW IF EXISTS v_audit_deletion_summary;

SELECT 'Views dropped successfully' as status;

-- ============================================
-- STEP 2: DROP STORED PROCEDURES AND FUNCTIONS
-- ============================================
SELECT 'Dropping stored procedures and functions...' as status;

DROP PROCEDURE IF EXISTS record_audit_log_deletion;
DROP PROCEDURE IF EXISTS mark_deletion_notification_sent;
DROP FUNCTION IF EXISTS get_deletion_stats;

SELECT 'Procedures and functions dropped successfully' as status;

-- ============================================
-- STEP 3: BACKUP audit_log_deletions DATA (Optional)
-- ============================================
-- Create a temporary backup table before dropping
SELECT 'Creating backup table...' as status;

CREATE TABLE IF NOT EXISTS audit_log_deletions_backup_20251027 AS
SELECT * FROM audit_log_deletions;

SELECT
    'Backup created successfully' as status,
    COUNT(*) as backed_up_records
FROM audit_log_deletions_backup_20251027;

-- ============================================
-- STEP 4: DROP audit_log_deletions TABLE
-- ============================================
SELECT 'Dropping audit_log_deletions table...' as status;

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log_deletions;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'audit_log_deletions table dropped successfully' as status;

-- ============================================
-- STEP 5: REMOVE deleted_at COLUMN FROM audit_logs
-- ============================================
SELECT 'Removing deleted_at column from audit_logs...' as status;

-- Check if column exists before attempting to drop
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'Column exists, proceeding with removal...'
        ELSE 'Column does not exist, skipping...'
    END as column_check
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'deleted_at';

-- Drop deleted_at column if it exists
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 1 THEN
                'ALTER TABLE audit_logs DROP COLUMN deleted_at'
            ELSE
                'SELECT "Column deleted_at does not exist" as info'
        END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'deleted_at'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'deleted_at column removed from audit_logs' as status;

-- ============================================
-- STEP 6: DROP INDEXES RELATED TO deleted_at
-- ============================================
SELECT 'Removing indexes...' as status;

-- Drop idx_audit_deleted index if exists
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 1 THEN
                'DROP INDEX idx_audit_deleted ON audit_logs'
            ELSE
                'SELECT "Index idx_audit_deleted does not exist" as info'
        END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_deleted'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop idx_audit_tenant_deleted index if exists
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 1 THEN
                'DROP INDEX idx_audit_tenant_deleted ON audit_logs'
            ELSE
                'SELECT "Index idx_audit_tenant_deleted does not exist" as info'
        END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_tenant_deleted'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Indexes removed successfully' as status;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Rollback completed!' as status;

-- Verify audit_log_deletions table is gone
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'PASS: audit_log_deletions table dropped'
        ELSE 'FAIL: audit_log_deletions table still exists'
    END as table_check
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name = 'audit_log_deletions';

-- Verify deleted_at column is removed from audit_logs
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'PASS: deleted_at column removed from audit_logs'
        ELSE 'FAIL: deleted_at column still exists in audit_logs'
    END as column_check
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'deleted_at';

-- Verify backup table exists
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'PASS: Backup table audit_log_deletions_backup_20251027 exists'
        ELSE 'WARNING: Backup table not found'
    END as backup_check,
    COALESCE((SELECT COUNT(*) FROM audit_log_deletions_backup_20251027), 0) as backup_record_count
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name = 'audit_log_deletions_backup_20251027';

-- Verify procedures and functions are dropped
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'PASS: All deletion-related procedures/functions removed'
        ELSE CONCAT('WARNING: ', COUNT(*), ' procedures/functions still exist')
    END as procedure_check
FROM information_schema.ROUTINES
WHERE routine_schema = DATABASE()
  AND routine_name LIKE '%deletion%';

-- ============================================
-- POST-ROLLBACK INSTRUCTIONS
-- ============================================
SELECT '
ROLLBACK COMPLETED SUCCESSFULLY!

What was removed:
- audit_log_deletions table (dropped)
- deleted_at column from audit_logs table (removed)
- Related indexes on audit_logs (removed)
- Stored procedures: record_audit_log_deletion, mark_deletion_notification_sent
- Function: get_deletion_stats
- Views: v_recent_audit_deletions, v_audit_deletion_summary

What was preserved:
- Backup table: audit_log_deletions_backup_20251027 (contains all deletion records)
- audit_logs table and all its data (only deleted_at column removed)

To restore if needed:
1. Re-run: database/migrations/audit_log_deletion_tracking.sql

To permanently remove backup table:
DROP TABLE IF EXISTS audit_log_deletions_backup_20251027;

Current audit_logs status:
' as post_rollback_info;

SELECT
    COUNT(*) as total_audit_logs,
    MIN(created_at) as oldest_log,
    MAX(created_at) as newest_log
FROM audit_logs;

-- ============================================
-- FINAL STATUS
-- ============================================
SELECT
    'Audit Log Deletion Tracking System REMOVED' as final_status,
    NOW() as executed_at;
