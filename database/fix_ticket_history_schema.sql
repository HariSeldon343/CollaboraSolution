-- ============================================
-- Module: Support Ticket System - Schema Fix
-- Version: 2025-10-26
-- Author: Database Architect
-- Description: Fix ticket_history table schema issues
-- ============================================

USE collaboranexio;

-- ============================================
-- FIX 1: Add missing updated_at column
-- ============================================

-- Check if updated_at column exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ticket_history'
      AND COLUMN_NAME = 'updated_at'
);

-- Add column if missing
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE ticket_history
     ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     COMMENT ''Last update timestamp'' AFTER created_at',
    'SELECT ''Column updated_at already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- FIX 2: Add composite index (tenant_id, created_at)
-- ============================================

-- Check if index exists
SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ticket_history'
      AND INDEX_NAME = 'idx_ticket_history_tenant_created'
);

-- Add index if missing
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_ticket_history_tenant_created
     ON ticket_history(tenant_id, created_at)',
    'SELECT ''Index idx_ticket_history_tenant_created already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Fix completed successfully' as status,
       NOW() as executed_at;

-- Verify updated_at column
SELECT 'Verifying updated_at column...' as status;
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ticket_history'
  AND COLUMN_NAME = 'updated_at';

-- Verify composite index
SELECT 'Verifying composite index...' as status;
SELECT
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ticket_history'
  AND INDEX_NAME = 'idx_ticket_history_tenant_created'
ORDER BY SEQ_IN_INDEX;

-- Final verification: Show complete table structure
SELECT 'Final table structure verification...' as status;
DESCRIBE ticket_history;

SELECT '============================================' as separator;
SELECT 'ticket_history schema fixes completed' as status;
SELECT '============================================' as separator;
