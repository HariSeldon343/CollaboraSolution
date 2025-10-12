-- ========================================
-- FIX TENANT DATA - Keep only tenant ID 11 (S.co)
-- ========================================

USE collaboranexio;

-- Show current state
SELECT 'BEFORE CLEANUP:' as status;
SELECT id, name, denominazione, deleted_at, status FROM tenants ORDER BY id;

-- Start transaction
START TRANSACTION;

-- Soft delete all tenants except ID 11
UPDATE tenants
SET deleted_at = NOW(),
    status = 'inactive',
    updated_at = NOW()
WHERE id != 11
  AND deleted_at IS NULL;

-- Ensure tenant 11 is active and not deleted
UPDATE tenants
SET deleted_at = NULL,
    status = 'active',
    updated_at = NOW()
WHERE id = 11;

-- Show final state
SELECT 'AFTER CLEANUP:' as status;
SELECT id, name, denominazione, deleted_at, status FROM tenants ORDER BY id;

-- Count active tenants (should be 1)
SELECT 'ACTIVE TENANTS COUNT:' as status;
SELECT COUNT(*) as active_count FROM tenants WHERE deleted_at IS NULL;

-- Commit changes
COMMIT;

SELECT 'Database cleaned successfully. Only tenant ID 11 (S.co) is active.' as result;
