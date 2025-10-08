-- ============================================
-- Module: Tenants Table Schema Drift Fix
-- Version: 2025-10-07
-- Author: Database Architect
-- Description: Fixes critical schema issues in tenants table
--              - Adds missing deleted_at column for soft-delete support
--              - Creates strategic indexes for performance
--              - Fixes data integrity issues
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP INFORMATION
-- ============================================
-- Before running this script, create a backup:
-- mysqldump -u root collaboranexio tenants > tenants_backup_20251007.sql

-- ============================================
-- SCHEMA FIXES
-- ============================================

-- 1. Add missing deleted_at column for soft-delete support
ALTER TABLE tenants
ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'Soft delete timestamp - NULL means active, NOT NULL means deleted';

-- ============================================
-- INDEX OPTIMIZATION
-- ============================================

-- 2. Index for soft-delete filtering (critical for performance)
CREATE INDEX idx_tenants_deleted_at ON tenants(deleted_at)
COMMENT 'Optimize queries filtering by soft-delete status';

-- 3. Composite index for status + deleted_at (common query pattern)
CREATE INDEX idx_tenants_status_deleted ON tenants(status, deleted_at)
COMMENT 'Optimize queries filtering by status and soft-delete';

-- 4. Index for manager lookups
CREATE INDEX idx_tenants_manager ON tenants(manager_id)
COMMENT 'Optimize manager relationship queries';

-- ============================================
-- DATA INTEGRITY FIXES
-- ============================================

-- 5. Assign a manager to tenant 1 (Demo Company)
-- Using super_admin user (id=1) as default manager
UPDATE tenants
SET manager_id = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND manager_id IS NULL;

-- 6. Add codice_fiscale to Demo Company (Italian tax code format)
-- Using a valid format placeholder
UPDATE tenants
SET codice_fiscale = 'DMOCMP00A01H501X',
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND codice_fiscale IS NULL;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check that deleted_at column was added
SELECT
    'deleted_at column check' as test,
    CASE
        WHEN COUNT(*) > 0 THEN 'PASS'
        ELSE 'FAIL'
    END as status
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tenants'
  AND COLUMN_NAME = 'deleted_at';

-- Check that indexes were created
SELECT
    'indexes check' as test,
    COUNT(*) as indexes_created,
    CASE
        WHEN COUNT(*) >= 3 THEN 'PASS'
        ELSE 'FAIL'
    END as status
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tenants'
  AND INDEX_NAME IN ('idx_tenants_deleted_at', 'idx_tenants_status_deleted', 'idx_tenants_manager');

-- Check data integrity after updates
SELECT
    'data integrity check' as test,
    COUNT(*) as tenants_with_manager_and_cf,
    CASE
        WHEN COUNT(*) >= 1 THEN 'PASS'
        ELSE 'FAIL'
    END as status
FROM tenants
WHERE manager_id IS NOT NULL
  AND codice_fiscale IS NOT NULL
  AND deleted_at IS NULL;

-- Display final tenant status
SELECT
    id,
    denominazione,
    codice_fiscale,
    partita_iva,
    status,
    manager_id,
    deleted_at,
    created_at,
    updated_at
FROM tenants
WHERE deleted_at IS NULL
ORDER BY id;

-- ============================================
-- MIGRATION COMPLETE
-- ============================================
SELECT
    'Migration completed successfully' as status,
    NOW() as execution_time,
    'Please update /api/tenants/list.php to use u.name instead of CONCAT(u.first_name, u.last_name)' as next_action;
