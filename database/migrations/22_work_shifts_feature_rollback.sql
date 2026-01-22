-- ============================================
-- ROLLBACK: MIGRATION 22 - WORK SHIFTS MANAGEMENT SYSTEM
-- Version: 1.0.0
-- Date: 2025-12-19
-- Author: Database Architect (CollaboraNexio)
-- ============================================
--
-- WARNING: This rollback will:
-- 1. DROP shift_change_requests table (data loss)
-- 2. DROP work_shifts table (data loss)
-- 3. DROP shift_types table (data loss)
-- 4. Remove tenants.has_shift_management column
--
-- RECOMMENDATION: Create backups before executing!
-- ============================================

USE collaboranexio;

SELECT '=== Starting Rollback of Migration 22 ===' AS status;
SELECT CONCAT('Executed at: ', NOW()) AS execution_time;

-- ============================================
-- STEP 1: Backup tables (optional safety measure)
-- ============================================

-- Uncomment to create backups before rollback:
/*
CREATE TABLE IF NOT EXISTS shift_change_requests_backup_rollback22 AS
SELECT * FROM shift_change_requests;

CREATE TABLE IF NOT EXISTS work_shifts_backup_rollback22 AS
SELECT * FROM work_shifts;

CREATE TABLE IF NOT EXISTS shift_types_backup_rollback22 AS
SELECT * FROM shift_types;
*/

-- ============================================
-- STEP 2: Drop tables in correct order (respecting FK constraints)
-- ============================================

SELECT 'Step 1: Dropping shift_change_requests table...' AS status;

-- Check if table exists before dropping
SET @tbl_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'shift_change_requests'
);

SET @sql = IF(@tbl_exists > 0,
    'DROP TABLE shift_change_requests',
    'SELECT ''Table shift_change_requests does not exist'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SELECT 'Step 2: Dropping work_shifts table...' AS status;

SET @tbl_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'work_shifts'
);

SET @sql = IF(@tbl_exists > 0,
    'DROP TABLE work_shifts',
    'SELECT ''Table work_shifts does not exist'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SELECT 'Step 3: Dropping shift_types table...' AS status;

SET @tbl_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'shift_types'
);

SET @sql = IF(@tbl_exists > 0,
    'DROP TABLE shift_types',
    'SELECT ''Table shift_types does not exist'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================
-- STEP 3: Remove tenants.has_shift_management column
-- ============================================

SELECT 'Step 4: Removing tenants.has_shift_management column...' AS status;

-- Remove index first
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND INDEX_NAME = 'idx_tenants_shift_management'
);

SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE tenants DROP INDEX idx_tenants_shift_management',
    'SELECT ''Index idx_tenants_shift_management does not exist'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove column
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'has_shift_management'
);

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE tenants DROP COLUMN has_shift_management',
    'SELECT ''Column has_shift_management does not exist'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================
-- VERIFICATION
-- ============================================

SELECT '=== Rollback Verification ===' AS section;

-- Verify tables are dropped
SELECT
    'shift_types' AS table_name,
    CASE WHEN COUNT(*) = 0 THEN 'DROPPED' ELSE 'STILL EXISTS' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_types';

SELECT
    'work_shifts' AS table_name,
    CASE WHEN COUNT(*) = 0 THEN 'DROPPED' ELSE 'STILL EXISTS' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'work_shifts';

SELECT
    'shift_change_requests' AS table_name,
    CASE WHEN COUNT(*) = 0 THEN 'DROPPED' ELSE 'STILL EXISTS' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_change_requests';

-- Verify column is dropped
SELECT
    'tenants.has_shift_management' AS column_name,
    CASE WHEN COUNT(*) = 0 THEN 'DROPPED' ELSE 'STILL EXISTS' END AS status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'tenants'
AND COLUMN_NAME = 'has_shift_management';

SELECT '=== Rollback 22 Completed ===' AS final_status;
SELECT CONCAT('Completed at: ', NOW()) AS completion_time;

