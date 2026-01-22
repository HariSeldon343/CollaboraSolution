-- ============================================
-- ROLLBACK: Page Visibility Settings
-- Version: 2025-12-12
-- Author: Database Architect
-- Description: Rollback migration 17 - Remove page_visibility_settings table
--
-- WARNING: This will permanently delete all visibility settings!
-- Consider soft-delete approach if you need to preserve data.
-- ============================================

USE collaboranexio;

SELECT 'Starting Rollback of Migration 17: Page Visibility Settings...' AS status;

-- ============================================
-- OPTION 1: SOFT DELETE (RECOMMENDED)
-- Preserves data for potential recovery
-- ============================================

/*
-- Soft delete all records instead of dropping table
UPDATE page_visibility_settings
SET deleted_at = NOW()
WHERE deleted_at IS NULL;

SELECT 'All page_visibility_settings records soft-deleted' AS status,
       (SELECT COUNT(*) FROM page_visibility_settings WHERE deleted_at IS NOT NULL) AS deleted_count;
*/

-- ============================================
-- OPTION 2: BACKUP BEFORE DROP
-- Creates backup table before dropping
-- ============================================

-- Create backup table with timestamp
SET @backup_table = CONCAT('page_visibility_settings_backup_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s'));
SELECT CONCAT('Creating backup table: ', @backup_table) AS status;

SET @backup_sql = CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @backup_table, '` AS SELECT * FROM page_visibility_settings'
);

PREPARE backup_stmt FROM @backup_sql;
EXECUTE backup_stmt;
DEALLOCATE PREPARE backup_stmt;

SELECT CONCAT('Backup created with ',
    (SELECT COUNT(*) FROM page_visibility_settings),
    ' records') AS status;

-- ============================================
-- OPTION 3: DROP TABLE (DESTRUCTIVE)
-- Uncomment to permanently remove
-- ============================================

-- Drop foreign key constraints first (if any dependent tables exist)
-- ALTER TABLE some_table DROP FOREIGN KEY fk_some_table_page_visibility;

-- Drop the table
DROP TABLE IF EXISTS page_visibility_settings;

SELECT 'page_visibility_settings table dropped successfully' AS status;

-- ============================================
-- VERIFICATION
-- ============================================

-- Verify table no longer exists
SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio'
       AND TABLE_NAME = 'page_visibility_settings') = 0,
    'VERIFIED: Table successfully removed',
    'WARNING: Table still exists!'
) AS verification_status;

-- List backup tables (if any)
SELECT TABLE_NAME, TABLE_ROWS, CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME LIKE 'page_visibility_settings_backup_%'
ORDER BY CREATE_TIME DESC;

SELECT '=== ROLLBACK COMPLETE ===' AS status, NOW() AS executed_at;
