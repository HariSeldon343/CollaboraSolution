-- ============================================
-- DEFINITIVE TENANT CLEANUP FIX SCRIPT
-- ============================================
-- Module: Tenant Soft Delete Fix
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Soft-delete all tenants except ID 11
--
-- IMPORTANT: This script uses SOFT DELETE (deleted_at)
-- For HARD DELETE, use fix_tenant_cleanup_hard.sql instead
-- ============================================

USE collaboranexio;

-- Show current state BEFORE changes
SELECT '=== CURRENT TENANT STATE (BEFORE) ===' as status;
SELECT
    id,
    name,
    status,
    deleted_at,
    created_at
FROM tenants
ORDER BY id;

-- Count active tenants
SELECT
    COUNT(*) as active_tenants,
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NOT NULL) as deleted_tenants,
    (SELECT COUNT(*) FROM tenants) as total_tenants
FROM tenants
WHERE deleted_at IS NULL;

-- ============================================
-- SOFT DELETE ALL TENANTS EXCEPT ID 11
-- ============================================

SELECT '=== EXECUTING SOFT DELETE ===' as status;

-- Update all tenants except ID 11
UPDATE tenants
SET
    deleted_at = NOW(),
    updated_at = NOW()
WHERE id != 11
AND deleted_at IS NULL;

-- Show affected rows
SELECT ROW_COUNT() as tenants_soft_deleted;

-- ============================================
-- VERIFY TENANT ID 11 IS STILL ACTIVE
-- ============================================

SELECT '=== VERIFYING TENANT ID 11 ===' as status;

SELECT
    id,
    name,
    status,
    deleted_at,
    CASE
        WHEN deleted_at IS NULL THEN 'ACTIVE ✓'
        ELSE 'DELETED ✗'
    END as state
FROM tenants
WHERE id = 11;

-- Ensure tenant 11 is active and not suspended
UPDATE tenants
SET
    deleted_at = NULL,
    status = 'active',
    updated_at = NOW()
WHERE id = 11;

-- ============================================
-- FINAL STATE VERIFICATION
-- ============================================

SELECT '=== TENANT STATE AFTER FIX ===' as status;

SELECT
    id,
    name,
    status,
    deleted_at,
    CASE
        WHEN deleted_at IS NULL THEN 'ACTIVE'
        ELSE 'DELETED'
    END as state
FROM tenants
ORDER BY id;

-- Count verification
SELECT
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) as active_tenants,
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NOT NULL) as deleted_tenants,
    (SELECT COUNT(*) FROM tenants) as total_tenants,
    CASE
        WHEN (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) = 1 THEN 'SUCCESS ✓'
        ELSE 'ISSUE DETECTED ✗'
    END as status;

-- ============================================
-- API QUERY SIMULATION
-- ============================================

SELECT '=== API QUERY RESULT (What users will see) ===' as status;

-- This is the EXACT query used by files_tenant_fixed.php
SELECT
    id,
    name,
    CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
    status
FROM tenants
WHERE deleted_at IS NULL
AND status != 'suspended'
ORDER BY name;

-- Expected result: Only 1 row (Tenant ID 11)

-- ============================================
-- RELATED DATA CHECK
-- ============================================

SELECT '=== RELATED DATA IMPACT ===' as status;

-- Check orphaned data (should still be accessible via tenant_id FK)
SELECT
    'Users' as table_name,
    COUNT(*) as total_records,
    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as active_records,
    (SELECT COUNT(*) FROM users WHERE tenant_id NOT IN (SELECT id FROM tenants WHERE deleted_at IS NULL)) as orphaned_records
FROM users
UNION ALL
SELECT
    'Folders',
    COUNT(*),
    (SELECT COUNT(*) FROM folders WHERE deleted_at IS NULL),
    (SELECT COUNT(*) FROM folders WHERE tenant_id NOT IN (SELECT id FROM tenants WHERE deleted_at IS NULL))
FROM folders
UNION ALL
SELECT
    'Files',
    COUNT(*),
    (SELECT COUNT(*) FROM files WHERE deleted_at IS NULL),
    (SELECT COUNT(*) FROM files WHERE tenant_id NOT IN (SELECT id FROM tenants WHERE deleted_at IS NULL))
FROM files;

-- ============================================
-- COMPLETION MESSAGE
-- ============================================

SELECT
    '=== TENANT CLEANUP COMPLETE ===' as status,
    NOW() as completed_at,
    'Run verify_tenant_cleanup.php to confirm changes' as next_step;

-- IMPORTANT: Clear browser cache and reload the application
-- If tenants still appear, check:
-- 1. Browser cache (Ctrl+Shift+Delete)
-- 2. Browser console for errors
-- 3. Session storage/localStorage
-- 4. User role (must be super_admin to see tenant dropdown)
