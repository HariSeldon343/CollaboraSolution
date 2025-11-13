-- ============================================
-- FIX: Add missing parent_id column to tasks table
-- Date: 2025-10-25
-- Author: Claude Code - Database Fix
-- Description: Adds parent_id column for hierarchical tasks
--              (subtask support)
-- ============================================

USE collaboranexio;

-- Check if column already exists before adding
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'parent_id'
);

-- Add parent_id column if it doesn't exist
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tasks ADD COLUMN parent_id INT UNSIGNED NULL COMMENT ''Parent task ID for subtasks/checklist items'' AFTER description',
    'SELECT ''Column parent_id already exists'' AS Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint for parent_id if not exists
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'tasks'
      AND CONSTRAINT_NAME = 'fk_tasks_parent'
);

SET @sql_fk = IF(
    @fk_exists = 0 AND @column_exists = 0,
    'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE COMMENT ''Parent task for hierarchy''',
    'SELECT ''Foreign key fk_tasks_parent already exists or column not added'' AS Status'
);

PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- Add index for parent_id for performance
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'tasks'
      AND INDEX_NAME = 'idx_tasks_parent'
);

SET @sql_idx = IF(
    @idx_exists = 0 AND @column_exists = 0,
    'CREATE INDEX idx_tasks_parent ON tasks(parent_id)',
    'SELECT ''Index idx_tasks_parent already exists or column not added'' AS Status'
);

PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- Verification
SELECT
    'Fix completed successfully!' AS Status,
    COUNT(*) AS parent_id_column_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tasks'
  AND COLUMN_NAME = 'parent_id';

SELECT
    'Foreign key check' AS Status,
    COUNT(*) AS fk_tasks_parent_exists
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tasks'
  AND CONSTRAINT_NAME = 'fk_tasks_parent';
