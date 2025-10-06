-- ============================================
-- Database Integrity Fix Script
-- Fix schema drift issues found in verification
-- Author: Database Architect
-- Date: 2025-10-04
-- ============================================

USE collaboranexio;

-- ============================================
-- FIX 1: Add missing uploaded_by foreign key
-- ============================================

-- First, fix any NULL tenant_id values (assign to default tenant)
UPDATE files
SET tenant_id = 1
WHERE tenant_id IS NULL;

-- Note: Cannot make tenant_id NOT NULL due to FK with SET NULL
-- We'll rely on application logic to prevent NULL tenant_id on INSERT

-- Check if uploaded_by references valid users
SELECT
    f.id,
    f.name,
    f.uploaded_by,
    'INVALID' as status
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.uploaded_by IS NOT NULL AND u.id IS NULL;

-- Fix uploaded_by data type to match users.id
ALTER TABLE files
    MODIFY uploaded_by INT(10) UNSIGNED NULL;

-- Add foreign key for uploaded_by (if not exists)
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND COLUMN_NAME = 'uploaded_by'
    AND REFERENCED_TABLE_NAME = 'users'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE files ADD CONSTRAINT fk_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "FK already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- FIX 2: Add status column for approval workflow
-- ============================================

-- Check if status column exists
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND COLUMN_NAME = 'status'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE files ADD COLUMN status VARCHAR(50) DEFAULT NULL AFTER mime_type',
    'SELECT "Status column already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set default status for existing files
UPDATE files
SET status = 'approvato'
WHERE status IS NULL;

-- ============================================
-- FIX 3: Add composite index for multi-tenant queries
-- ============================================

-- Drop old index if exists and create composite
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND INDEX_NAME = 'idx_tenant_status'
);

SET @sql = IF(
    @idx_exists = 0,
    'CREATE INDEX idx_tenant_status ON files(tenant_id, status)',
    'SELECT "Composite index already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- FIX 4: Add CHECK constraint for status values
-- ============================================

-- Note: MySQL 8.0+ supports CHECK constraints
SET @constraint_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND CONSTRAINT_NAME = 'chk_files_status'
);

SET @sql = IF(
    @constraint_exists = 0,
    'ALTER TABLE files ADD CONSTRAINT chk_files_status CHECK (status IN (''in_approvazione'', ''approvato'', ''rifiutato'', NULL))',
    'SELECT "CHECK constraint already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '=== VERIFICATION RESULTS ===' as '';

-- Show updated structure
SELECT 'Files table structure:' as '';
DESCRIBE files;

-- Show foreign keys
SELECT 'Foreign keys on files table:' as '';
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'files'
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Show indexes
SELECT 'Indexes on files table:' as '';
SHOW INDEX FROM files;

-- Check data integrity
SELECT 'Data integrity check:' as '';
SELECT
    'Total files' as metric,
    COUNT(*) as count
FROM files
UNION ALL
SELECT
    'Files with NULL tenant_id',
    COUNT(*)
FROM files
WHERE tenant_id IS NULL
UNION ALL
SELECT
    'Files with invalid tenant_id',
    COUNT(*)
FROM files f
LEFT JOIN tenants t ON f.tenant_id = t.id
WHERE t.id IS NULL
UNION ALL
SELECT
    'Files with invalid uploaded_by',
    COUNT(*)
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.uploaded_by IS NOT NULL AND u.id IS NULL;

SELECT 'Database integrity fix completed!' as status;
