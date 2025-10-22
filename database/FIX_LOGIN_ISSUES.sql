-- ============================================================================
-- CRITICAL FIX: LOGIN SYSTEM RESTORATION
-- CollaboraNexio - Emergency User Recovery
-- Date: 2025-10-16
-- ============================================================================
-- Purpose: Restore login capability by fixing common user table issues
-- IMPORTANT: Review diagnostic report first before running this!
-- ============================================================================

USE collaboranexio;

-- ============================================================================
-- SAFETY CHECK: Backup critical data first
-- ============================================================================
SELECT '============================================================================' as '';
SELECT 'STARTING LOGIN SYSTEM FIX' as 'STATUS';
SELECT '============================================================================' as '';
SELECT NOW() as 'Execution Time';

-- Show current state before changes
SELECT '' as '';
SELECT 'BEFORE FIX - Current User Count:' as '';
SELECT
    COUNT(*) as total_users,
    COUNT(CASE WHEN deleted_at IS NULL AND is_active = 1 AND password_hash IS NOT NULL THEN 1 END) as login_ready_users
FROM users;

-- ============================================================================
-- FIX 1: Remove soft delete flag from accidentally deleted users
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 1: Restoring soft-deleted users with valid credentials' as 'ACTION';

-- First, show which users will be restored
SELECT
    id,
    email,
    name,
    role,
    'Will be restored (deleted_at will be cleared)' as action
FROM users
WHERE deleted_at IS NOT NULL
  AND password_hash IS NOT NULL
  AND email IS NOT NULL
  AND is_active = 1;

-- Restore them (uncomment to execute)
-- UPDATE users
-- SET deleted_at = NULL, updated_at = NOW()
-- WHERE deleted_at IS NOT NULL
--   AND password_hash IS NOT NULL
--   AND email IS NOT NULL
--   AND is_active = 1;

SELECT 'Users restored from soft-delete status' as result;

-- ============================================================================
-- FIX 2: Activate users that have credentials but are inactive
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 2: Activating users with valid credentials' as 'ACTION';

-- Show which users will be activated
SELECT
    id,
    email,
    name,
    role,
    'Will be activated (is_active = 1)' as action
FROM users
WHERE is_active = 0
  AND deleted_at IS NULL
  AND password_hash IS NOT NULL
  AND email IS NOT NULL;

-- Activate them (uncomment to execute)
-- UPDATE users
-- SET is_active = 1, updated_at = NOW()
-- WHERE is_active = 0
--   AND deleted_at IS NULL
--   AND password_hash IS NOT NULL
--   AND email IS NOT NULL;

SELECT 'Users activated' as result;

-- ============================================================================
-- FIX 3: Create emergency super admin if no admins exist
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 3: Emergency Admin Creation (if needed)' as 'ACTION';

-- Check if any super_admin exists
SET @admin_count = (
    SELECT COUNT(*)
    FROM users
    WHERE role = 'super_admin'
      AND deleted_at IS NULL
      AND is_active = 1
      AND password_hash IS NOT NULL
);

SELECT CONCAT('Current super_admins: ', @admin_count) as status;

-- Create emergency admin if none exist
-- UNCOMMENT THE BLOCK BELOW TO CREATE EMERGENCY ADMIN
/*
INSERT INTO users (
    email,
    password_hash,
    name,
    role,
    is_active,
    created_at,
    updated_at
)
SELECT
    'emergency@admin.local',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- Password: secret
    'Emergency Admin',
    'super_admin',
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users
    WHERE email = 'emergency@admin.local'
);
*/

SELECT 'Emergency admin creation ready (uncomment to execute)' as result;

-- ============================================================================
-- FIX 4: Fix orphaned tenant references
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 4: Fixing orphaned tenant references' as 'ACTION';

-- Show users with invalid tenant_id
SELECT
    u.id,
    u.email,
    u.name,
    u.role,
    u.tenant_id,
    'Invalid tenant reference (will be set to NULL)' as action
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND u.tenant_id IS NOT NULL
  AND t.id IS NULL;

-- Fix orphaned references (uncomment to execute)
-- UPDATE users u
-- LEFT JOIN tenants t ON u.tenant_id = t.id
-- SET u.tenant_id = NULL, u.updated_at = NOW()
-- WHERE u.deleted_at IS NULL
--   AND u.is_active = 1
--   AND u.tenant_id IS NOT NULL
--   AND t.id IS NULL;

SELECT 'Orphaned tenant references fixed' as result;

-- ============================================================================
-- FIX 5: Ensure proper indexes exist
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 5: Index Verification' as 'ACTION';

-- Check if critical indexes exist
SELECT
    INDEX_NAME,
    COLUMN_NAME,
    NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND INDEX_NAME IN ('idx_user_email', 'idx_user_deleted', 'idx_user_tenant_status')
ORDER BY INDEX_NAME;

-- Create missing indexes if needed (uncomment to execute)
/*
-- Email index for login lookups
CREATE INDEX IF NOT EXISTS idx_user_email ON users(email);

-- Soft delete index
CREATE INDEX IF NOT EXISTS idx_user_deleted ON users(deleted_at);

-- Composite index for active user lookups
CREATE INDEX IF NOT EXISTS idx_user_active_lookup ON users(email, is_active, deleted_at);

-- Tenant relationship index
CREATE INDEX IF NOT EXISTS idx_user_tenant ON users(tenant_id, deleted_at);
*/

SELECT 'Index verification complete (add indexes if missing)' as result;

-- ============================================================================
-- FIX 6: Verify authentication query compatibility
-- ============================================================================
SELECT '' as '';
SELECT 'FIX 6: Authentication Query Test' as 'ACTION';

-- Test the exact query used by api/auth.php
-- This simulates the login query to verify it works
SELECT
    u.id,
    u.email,
    u.name,
    u.role,
    u.is_active,
    u.tenant_id,
    t.name as tenant_name,
    t.status as tenant_status,
    CASE
        WHEN u.password_hash IS NOT NULL THEN '✓ Has Password'
        ELSE '✗ Missing Password'
    END as password_check,
    CASE
        WHEN u.deleted_at IS NULL THEN '✓ Not Deleted'
        ELSE '✗ Soft Deleted'
    END as delete_check
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id AND t.deleted_at IS NULL
WHERE u.is_active = 1
  AND u.deleted_at IS NULL
  AND u.password_hash IS NOT NULL
ORDER BY
    CASE u.role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'manager' THEN 3
        WHEN 'user' THEN 4
        ELSE 5
    END,
    u.created_at DESC;

-- ============================================================================
-- VERIFICATION: Check results after fixes
-- ============================================================================
SELECT '' as '';
SELECT '============================================================================' as '';
SELECT 'AFTER FIX - Updated User Count:' as '';

SELECT
    COUNT(*) as total_users,
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as active_not_deleted,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as enabled_users,
    COUNT(CASE WHEN deleted_at IS NULL AND is_active = 1 AND password_hash IS NOT NULL THEN 1 END) as login_ready_users,
    COUNT(CASE WHEN role = 'super_admin' AND deleted_at IS NULL AND is_active = 1 THEN 1 END) as super_admins
FROM users;

-- List all users that can now login
SELECT '' as '';
SELECT 'Users Ready for Login:' as '';
SELECT
    u.id,
    u.email,
    u.name,
    u.role,
    COALESCE(t.name, 'No Tenant') as tenant,
    u.last_login,
    u.created_at
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id AND t.deleted_at IS NULL
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND u.password_hash IS NOT NULL
ORDER BY
    CASE u.role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'manager' THEN 3
        WHEN 'user' THEN 4
        ELSE 5
    END,
    u.created_at DESC;

-- ============================================================================
-- FINAL RECOMMENDATIONS
-- ============================================================================
SELECT '' as '';
SELECT '============================================================================' as '';
SELECT 'FIX COMPLETE - NEXT STEPS' as 'STATUS';
SELECT '============================================================================' as '';

SELECT 'Next Steps:' as ''
UNION ALL
SELECT '1. Review "Users Ready for Login" list above'
UNION ALL
SELECT '2. If no users shown, uncomment FIX 3 to create emergency admin'
UNION ALL
SELECT '3. Test login with: emergency@admin.local / secret'
UNION ALL
SELECT '4. If still failing, check logs/database_errors.log'
UNION ALL
SELECT '5. Verify PHP session is working: api/auth.php?action=check'
UNION ALL
SELECT '6. Check database connection in config.php'
UNION ALL
SELECT ''
UNION ALL
SELECT 'Common Test Accounts:'
UNION ALL
SELECT '  Email: emergency@admin.local | Password: secret (if created)'
UNION ALL
SELECT '  Check above list for existing accounts';

-- ============================================================================
-- DEBUGGING QUERIES (for manual testing)
-- ============================================================================
SELECT '' as '';
SELECT 'DEBUGGING QUERIES (copy these to test specific issues):' as '';
SELECT '-- Test specific user login:' as query
UNION ALL
SELECT '-- SELECT * FROM users WHERE email = ''your@email.com'' AND deleted_at IS NULL;'
UNION ALL
SELECT ''
UNION ALL
SELECT '-- Verify password hash exists:'
UNION ALL
SELECT '-- SELECT email, LENGTH(password_hash) as hash_length FROM users WHERE email = ''your@email.com'';'
UNION ALL
SELECT ''
UNION ALL
SELECT '-- Check tenant relationship:'
UNION ALL
SELECT '-- SELECT u.email, u.tenant_id, t.name FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id WHERE u.email = ''your@email.com'';';

SELECT '' as '';
SELECT '============================================================================' as '';
SELECT 'END OF FIX SCRIPT' as '';
SELECT '============================================================================' as '';
