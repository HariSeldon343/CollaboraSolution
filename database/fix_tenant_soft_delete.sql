-- ============================================
-- FIX: Tenant Soft Delete System
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Ensures tenants table has proper soft delete support
--              and fixes the get_tenant_list API filter issue
-- ============================================

USE collaboranexio;

-- ============================================
-- STEP 1: Verify tenants table structure
-- ============================================

SELECT 'Step 1: Checking tenants table structure...' as status;

-- Check if deleted_at column exists
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tenants'
ORDER BY ORDINAL_POSITION;

-- ============================================
-- STEP 2: Add deleted_at column if missing
-- ============================================

SELECT 'Step 2: Adding deleted_at column if missing...' as status;

-- Add deleted_at column if it doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'tenants';
SET @columnname = 'deleted_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT "Column already exists" as status;',
  'ALTER TABLE tenants ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ============================================
-- STEP 3: Add index for soft delete queries
-- ============================================

SELECT 'Step 3: Adding index for deleted_at...' as status;

-- Drop index if exists (for idempotency)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND INDEX_NAME = 'idx_tenants_deleted'
  ) > 0,
  'DROP INDEX idx_tenants_deleted ON tenants;',
  'SELECT "Index does not exist yet" as status;'
));
PREPARE dropIndexIfExists FROM @preparedStatement;
EXECUTE dropIndexIfExists;
DEALLOCATE PREPARE dropIndexIfExists;

-- Create index
CREATE INDEX idx_tenants_deleted ON tenants(deleted_at);

-- ============================================
-- STEP 4: List current tenant status
-- ============================================

SELECT 'Step 4: Current tenant status...' as status;

SELECT
    id,
    name,
    company_name,
    status,
    deleted_at,
    created_at,
    CASE
        WHEN deleted_at IS NOT NULL THEN 'DELETED'
        WHEN status = 'suspended' THEN 'SUSPENDED'
        ELSE 'ACTIVE'
    END as current_status
FROM tenants
ORDER BY id;

-- ============================================
-- STEP 5: Soft delete all tenants except ID 11
-- ============================================

SELECT 'Step 5: Soft deleting tenants except ID 11...' as status;

-- First, show what will be deleted
SELECT
    id,
    name,
    company_name,
    'WILL BE DELETED' as action
FROM tenants
WHERE id != 11
  AND deleted_at IS NULL;

-- Perform soft delete (uncomment to execute)
-- WARNING: This will mark all tenants except ID 11 as deleted
-- UPDATE tenants
-- SET deleted_at = NOW()
-- WHERE id != 11
--   AND deleted_at IS NULL;

-- ============================================
-- STEP 6: Verify final state
-- ============================================

SELECT 'Step 6: Final verification...' as status;

-- Count tenants by status
SELECT
    CASE
        WHEN deleted_at IS NOT NULL THEN 'Soft Deleted'
        WHEN status = 'suspended' THEN 'Suspended'
        ELSE 'Active'
    END as tenant_status,
    COUNT(*) as count
FROM tenants
GROUP BY tenant_status;

-- Show only active tenants (should only be ID 11)
SELECT
    id,
    name,
    company_name,
    status,
    created_at
FROM tenants
WHERE deleted_at IS NULL
ORDER BY id;

-- ============================================
-- STEP 7: Test query that get_tenant_list should use
-- ============================================

SELECT 'Step 7: Testing get_tenant_list query...' as status;

-- This is what the API should return (super_admin view)
SELECT
    id,
    name,
    CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
    status
FROM tenants
WHERE status != 'suspended'
  AND deleted_at IS NULL  -- CRITICAL: This filter is required
ORDER BY name;

-- ============================================
-- VERIFICATION SUMMARY
-- ============================================

SELECT 'Verification Summary' as status;

SELECT
    (SELECT COUNT(*) FROM tenants) as total_tenants,
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) as active_tenants,
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NOT NULL) as deleted_tenants,
    (SELECT COUNT(*) FROM tenants WHERE id = 11 AND deleted_at IS NULL) as tenant_11_status,
    CASE
        WHEN (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) = 1
         AND (SELECT COUNT(*) FROM tenants WHERE id = 11 AND deleted_at IS NULL) = 1
        THEN '✓ PASS: Only tenant ID 11 is active'
        ELSE '✗ FAIL: Multiple tenants are still active'
    END as final_status;

-- ============================================
-- CLEANUP INSTRUCTIONS
-- ============================================

/*
After running this migration:

1. UNCOMMENT line 85-88 to actually perform the soft delete
2. Restart Apache/PHP to clear any cached queries
3. Clear browser cache and cookies
4. Test the tenant dropdown in files.php
5. Verify only "S.co (ID 11)" appears in the dropdown

If you need to HARD DELETE tenants instead:
    DELETE FROM tenants WHERE id != 11 AND id != 1;

If you need to RESTORE a deleted tenant:
    UPDATE tenants SET deleted_at = NULL WHERE id = <tenant_id>;

To view all deleted tenants:
    SELECT * FROM tenants WHERE deleted_at IS NOT NULL;
*/

-- ============================================
-- END OF MIGRATION
-- ============================================
