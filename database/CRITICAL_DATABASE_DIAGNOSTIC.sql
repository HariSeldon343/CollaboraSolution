-- ============================================================================
-- CRITICAL DATABASE DIAGNOSTIC FOR LOGIN FAILURE
-- CollaboraNexio - Emergency Verification Script
-- Date: 2025-10-16
-- ============================================================================
-- Purpose: Complete verification of users table integrity and login capability
-- Run this in phpMyAdmin SQL tab to diagnose "User not found" errors
-- ============================================================================

USE collaboranexio;

SELECT '============================================================================' as '';
SELECT 'CRITICAL DATABASE DIAGNOSTIC REPORT - LOGIN FAILURE ANALYSIS' as 'REPORT';
SELECT '============================================================================' as '';
SELECT NOW() as 'Execution Time';

-- ============================================================================
-- SECTION 1: DATABASE CONNECTION TEST
-- ============================================================================
SELECT '' as '';
SELECT '1. DATABASE CONNECTION TEST' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    DATABASE() as 'Current Database',
    VERSION() as 'MySQL Version',
    @@version_comment as 'Server Type';

-- ============================================================================
-- SECTION 2: USERS TABLE STRUCTURE VERIFICATION
-- ============================================================================
SELECT '' as '';
SELECT '2. USERS TABLE STRUCTURE' as 'SECTION';
SELECT '============================================================================' as '';

-- Check if users table exists
SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_ROWS as 'Estimated Rows',
    CREATE_TIME,
    UPDATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users';

-- Get complete column structure
SELECT '' as '';
SELECT 'Column Structure:' as '';
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_KEY,
    COLUMN_DEFAULT,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;

-- Check for critical columns
SELECT '' as '';
SELECT 'Critical Column Verification:' as '';
SELECT
    CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END as 'id',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email') as 'email',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash') as 'password_hash',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'name') as 'name',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role') as 'role',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active') as 'is_active',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id') as 'tenant_id',
    (SELECT CASE WHEN COUNT(*) = 1 THEN '✓ FOUND' ELSE '✗ MISSING' END
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') as 'deleted_at'
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'id';

-- ============================================================================
-- SECTION 3: USER DATA VERIFICATION
-- ============================================================================
SELECT '' as '';
SELECT '3. USER DATA VERIFICATION' as 'SECTION';
SELECT '============================================================================' as '';

-- Total user count
SELECT
    COUNT(*) as 'Total Users (All)',
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as 'Active Users (Not Deleted)',
    COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END) as 'Deleted Users',
    COUNT(CASE WHEN is_active = 1 AND deleted_at IS NULL THEN 1 END) as 'Active & Enabled Users',
    COUNT(CASE WHEN password_hash IS NULL THEN 1 END) as 'Users Without Password',
    COUNT(CASE WHEN email IS NULL OR email = '' THEN 1 END) as 'Users Without Email'
FROM users;

-- User status breakdown
SELECT '' as '';
SELECT 'User Status Breakdown:' as '';
SELECT
    role,
    COUNT(*) as total,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) as not_deleted,
    SUM(CASE WHEN tenant_id IS NOT NULL THEN 1 ELSE 0 END) as with_tenant,
    SUM(CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END) as with_password
FROM users
GROUP BY role
ORDER BY
    CASE role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'manager' THEN 3
        WHEN 'user' THEN 4
        ELSE 5
    END;

-- ============================================================================
-- SECTION 4: ACTUAL USERS LIST (SAFE FOR LOGIN)
-- ============================================================================
SELECT '' as '';
SELECT '4. USERS READY FOR LOGIN' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    id,
    email,
    name,
    role,
    tenant_id,
    CASE
        WHEN is_active = 1 THEN '✓ Active'
        ELSE '✗ Inactive'
    END as status,
    CASE
        WHEN password_hash IS NOT NULL AND LENGTH(password_hash) > 0 THEN '✓ Has Password'
        ELSE '✗ No Password'
    END as password_status,
    CASE
        WHEN deleted_at IS NULL THEN '✓ Not Deleted'
        ELSE '✗ Deleted'
    END as deleted_status,
    last_login,
    created_at
FROM users
WHERE deleted_at IS NULL
  AND is_active = 1
  AND password_hash IS NOT NULL
  AND email IS NOT NULL
ORDER BY
    CASE role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'manager' THEN 3
        WHEN 'user' THEN 4
        ELSE 5
    END,
    created_at DESC;

-- ============================================================================
-- SECTION 5: PROBLEM USERS (CANNOT LOGIN)
-- ============================================================================
SELECT '' as '';
SELECT '5. PROBLEM USERS - CANNOT LOGIN' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    id,
    email,
    name,
    role,
    CASE
        WHEN deleted_at IS NOT NULL THEN '✗ Deleted'
        WHEN is_active = 0 THEN '✗ Inactive'
        WHEN password_hash IS NULL THEN '✗ No Password'
        WHEN email IS NULL OR email = '' THEN '✗ No Email'
        ELSE 'Other Issue'
    END as problem,
    deleted_at,
    created_at
FROM users
WHERE deleted_at IS NOT NULL
   OR is_active = 0
   OR password_hash IS NULL
   OR email IS NULL
   OR email = ''
ORDER BY
    CASE
        WHEN deleted_at IS NOT NULL THEN 1
        WHEN is_active = 0 THEN 2
        WHEN password_hash IS NULL THEN 3
        ELSE 4
    END,
    created_at DESC
LIMIT 20;

-- ============================================================================
-- SECTION 6: FOREIGN KEY CONSTRAINTS VERIFICATION
-- ============================================================================
SELECT '' as '';
SELECT '6. FOREIGN KEY CONSTRAINTS' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    kcu.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
  AND kcu.TABLE_NAME = 'users'
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.CONSTRAINT_NAME;

-- ============================================================================
-- SECTION 7: TENANTS TABLE VERIFICATION
-- ============================================================================
SELECT '' as '';
SELECT '7. TENANTS TABLE STATUS' as 'SECTION';
SELECT '============================================================================' as '';

-- Check if tenants table exists
SELECT
    TABLE_NAME,
    TABLE_ROWS as 'Estimated Rows'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tenants';

-- Tenants summary
SELECT
    COUNT(*) as 'Total Tenants',
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as 'Active Tenants',
    COUNT(CASE WHEN status = 'active' THEN 1 END) as 'Status Active'
FROM tenants;

-- List active tenants
SELECT '' as '';
SELECT 'Active Tenants:' as '';
SELECT
    id,
    name,
    denominazione,
    status,
    partita_iva,
    manager_id,
    created_at
FROM tenants
WHERE deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 10;

-- ============================================================================
-- SECTION 8: USER-TENANT RELATIONSHIPS
-- ============================================================================
SELECT '' as '';
SELECT '8. USER-TENANT RELATIONSHIPS' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    u.id as user_id,
    u.email,
    u.name as user_name,
    u.role,
    u.tenant_id,
    t.name as tenant_name,
    t.denominazione as tenant_company,
    CASE
        WHEN u.tenant_id IS NULL AND u.role NOT IN ('super_admin', 'admin') THEN '⚠ No Tenant (Required)'
        WHEN u.tenant_id IS NOT NULL AND t.id IS NULL THEN '✗ Invalid Tenant (Missing)'
        WHEN u.tenant_id IS NOT NULL AND t.deleted_at IS NOT NULL THEN '✗ Tenant Deleted'
        WHEN u.tenant_id IS NOT NULL AND t.status != 'active' THEN '⚠ Tenant Inactive'
        WHEN u.tenant_id IS NULL AND u.role IN ('super_admin', 'admin') THEN '✓ OK (No Tenant Needed)'
        ELSE '✓ OK'
    END as relationship_status
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
ORDER BY
    CASE
        WHEN u.tenant_id IS NULL AND u.role NOT IN ('super_admin', 'admin') THEN 1
        WHEN u.tenant_id IS NOT NULL AND t.id IS NULL THEN 2
        WHEN u.tenant_id IS NOT NULL AND t.deleted_at IS NOT NULL THEN 3
        ELSE 4
    END,
    u.role,
    u.created_at DESC;

-- ============================================================================
-- SECTION 9: INDEXES VERIFICATION
-- ============================================================================
SELECT '' as '';
SELECT '9. INDEX VERIFICATION' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    INDEX_NAME,
    NON_UNIQUE,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- ============================================================================
-- SECTION 10: NORMALIZATION CHECK (1NF, 2NF, 3NF)
-- ============================================================================
SELECT '' as '';
SELECT '10. NORMALIZATION VERIFICATION' as 'SECTION';
SELECT '============================================================================' as '';

-- Check for duplicate emails (violates unique constraint)
SELECT 'Duplicate Email Check:' as check_type;
SELECT
    email,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id) as user_ids
FROM users
WHERE deleted_at IS NULL
GROUP BY email
HAVING COUNT(*) > 1;

-- Check for NULL in NOT NULL columns
SELECT '' as '';
SELECT 'NULL Value Violations:' as '';
SELECT
    SUM(CASE WHEN id IS NULL THEN 1 ELSE 0 END) as null_id_count,
    SUM(CASE WHEN email IS NULL THEN 1 ELSE 0 END) as null_email_count,
    SUM(CASE WHEN password_hash IS NULL THEN 1 ELSE 0 END) as null_password_count,
    SUM(CASE WHEN role IS NULL THEN 1 ELSE 0 END) as null_role_count
FROM users
WHERE deleted_at IS NULL;

-- ============================================================================
-- SECTION 11: RECENT DATABASE ERRORS (if error log exists)
-- ============================================================================
SELECT '' as '';
SELECT '11. RECENT ACTIVITY' as 'SECTION';
SELECT '============================================================================' as '';

SELECT
    id,
    email,
    name,
    last_login,
    CASE
        WHEN last_login IS NULL THEN 'Never logged in'
        WHEN last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'Inactive > 30 days'
        WHEN last_login < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'Inactive > 7 days'
        ELSE 'Recently active'
    END as activity_status,
    created_at
FROM users
WHERE deleted_at IS NULL
  AND is_active = 1
ORDER BY last_login DESC
LIMIT 15;

-- ============================================================================
-- SECTION 12: RECOMMENDED FIXES
-- ============================================================================
SELECT '' as '';
SELECT '12. DIAGNOSTIC SUMMARY & RECOMMENDATIONS' as 'SECTION';
SELECT '============================================================================' as '';

-- Count issues
SET @missing_password = (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 1 AND password_hash IS NULL);
SET @missing_email = (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 1 AND (email IS NULL OR email = ''));
SET @missing_tenant = (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 1 AND tenant_id IS NULL AND role NOT IN ('super_admin', 'admin'));
SET @inactive_users = (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 0);
SET @total_active_users = (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 1 AND password_hash IS NOT NULL);

SELECT 'ISSUE SUMMARY' as '';
SELECT
    @total_active_users as 'Users Ready for Login',
    @missing_password as 'Users Missing Password',
    @missing_email as 'Users Missing Email',
    @missing_tenant as 'Users Missing Tenant (Non-Admin)',
    @inactive_users as 'Inactive Users';

-- Provide actionable recommendations
SELECT '' as '';
SELECT 'RECOMMENDATIONS' as '';

SELECT
    CASE
        WHEN @total_active_users = 0 THEN '🔴 CRITICAL: No users can login! Create emergency admin.'
        WHEN @total_active_users < 3 THEN '🟡 WARNING: Very few users available. Verify user creation process.'
        ELSE '🟢 OK: Users exist and can login.'
    END as user_count_status;

SELECT
    CASE
        WHEN @missing_password > 0 THEN CONCAT('⚠ ', @missing_password, ' users need password reset')
        ELSE '✓ All active users have passwords'
    END as password_status;

SELECT
    CASE
        WHEN @missing_tenant > 0 THEN CONCAT('⚠ ', @missing_tenant, ' non-admin users missing tenant assignment')
        ELSE '✓ All non-admin users have tenant assignments'
    END as tenant_status;

-- ============================================================================
-- SECTION 13: QUICK FIX SQL (if needed)
-- ============================================================================
SELECT '' as '';
SELECT '13. EMERGENCY FIX COMMANDS (DO NOT RUN YET - FOR REFERENCE)' as 'SECTION';
SELECT '============================================================================' as '';

SELECT '-- If you need to create an emergency admin, run this:' as command
UNION ALL
SELECT '-- INSERT INTO users (email, password_hash, name, role, is_active, created_at, updated_at)'
UNION ALL
SELECT '-- VALUES (''emergency@admin.local'', ''$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'', ''Emergency Admin'', ''super_admin'', 1, NOW(), NOW());'
UNION ALL
SELECT '-- Password for above user is: secret'
UNION ALL
SELECT ''
UNION ALL
SELECT '-- To activate all inactive users:'
UNION ALL
SELECT '-- UPDATE users SET is_active = 1 WHERE deleted_at IS NULL AND password_hash IS NOT NULL;'
UNION ALL
SELECT ''
UNION ALL
SELECT '-- To verify a specific user by email:'
UNION ALL
SELECT '-- SELECT * FROM users WHERE email = ''your@email.com'' AND deleted_at IS NULL;';

-- ============================================================================
-- END OF DIAGNOSTIC REPORT
-- ============================================================================
SELECT '' as '';
SELECT '============================================================================' as '';
SELECT 'END OF DIAGNOSTIC REPORT' as '';
SELECT '============================================================================' as '';
SELECT 'Next Steps:' as '';
SELECT '1. Review all sections above' as step
UNION ALL
SELECT '2. Check if any users appear in "Users Ready for Login" section'
UNION ALL
SELECT '3. If no users, run emergency admin creation SQL'
UNION ALL
SELECT '4. Verify tenant assignments for non-admin users'
UNION ALL
SELECT '5. Test login with verified credentials'
UNION ALL
SELECT '6. Check application error logs at: logs/database_errors.log';
