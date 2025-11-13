-- ============================================
-- Module: user_tenant_access Population
-- Version: 2025-11-05
-- Author: Database Architect
-- Description: Populate user_tenant_access for all active users
--              Creates tenant access records for users missing them
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFICATION BEFORE MIGRATION
-- ============================================

SELECT '=== BEFORE MIGRATION ===' as status;

-- Check current state
SELECT
    'Total active users' as metric,
    COUNT(*) as count
FROM users
WHERE deleted_at IS NULL;

SELECT
    'Total user_tenant_access records' as metric,
    COUNT(*) as count
FROM user_tenant_access
WHERE deleted_at IS NULL;

SELECT
    'Orphaned users (no tenant access)' as metric,
    COUNT(*) as count
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id AND uta.deleted_at IS NULL
WHERE u.deleted_at IS NULL
  AND uta.id IS NULL;

-- ============================================
-- MIGRATION: Populate user_tenant_access
-- ============================================

-- For each active user without tenant access, create record
INSERT INTO user_tenant_access (user_id, tenant_id, granted_at, created_at)
SELECT
    u.id,
    u.tenant_id,
    NOW(),
    NOW()
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id AND uta.tenant_id = u.tenant_id AND uta.deleted_at IS NULL
WHERE u.deleted_at IS NULL
  AND uta.id IS NULL;

-- ============================================
-- VERIFICATION AFTER MIGRATION
-- ============================================

SELECT '=== AFTER MIGRATION ===' as status;

-- Check new state
SELECT
    'Total user_tenant_access records' as metric,
    COUNT(*) as count
FROM user_tenant_access
WHERE deleted_at IS NULL;

SELECT
    'Orphaned users (should be 0)' as metric,
    COUNT(*) as count
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id AND uta.deleted_at IS NULL
WHERE u.deleted_at IS NULL
  AND uta.id IS NULL;

-- List all user_tenant_access records
SELECT
    uta.id,
    uta.user_id,
    u.name as user_name,
    u.email,
    uta.tenant_id,
    t.name as tenant_name,
    uta.created_at
FROM user_tenant_access uta
INNER JOIN users u ON uta.user_id = u.id
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE uta.deleted_at IS NULL
ORDER BY uta.tenant_id, uta.user_id;

SELECT 'Migration completed successfully' as status;
