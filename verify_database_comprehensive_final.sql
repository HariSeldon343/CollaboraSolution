-- ============================================
-- COMPREHENSIVE DATABASE VERIFICATION SCRIPT
-- Post BUG-061 - Final Production Readiness Check
-- Date: 2025-11-04
-- Author: Database Architect
-- ============================================

USE collaboranexio;

SET @test_number = 0;

SELECT '============================================' as '';
SELECT 'COLLABORANEXIO DATABASE VERIFICATION' as '';
SELECT 'Final Production Readiness Check' as '';
SELECT '============================================' as '';
SELECT '' as '';

-- ============================================
-- TEST 1: TABLE COUNT VERIFICATION
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Table Count Verification') as '';

SELECT
    COUNT(*) as total_tables,
    CASE
        WHEN COUNT(*) = 72 THEN '✅ PASS - Expected 72 tables found'
        WHEN COUNT(*) > 72 THEN CONCAT('⚠️  WARNING - Found ', COUNT(*), ' tables (expected 72)')
        ELSE CONCAT('❌ FAIL - Found only ', COUNT(*), ' tables (expected 72)')
    END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_type = 'BASE TABLE';

SELECT '' as '';

-- ============================================
-- TEST 2: WORKFLOW TABLES PRESENCE
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Workflow Tables Verification') as '';

SELECT
    table_name,
    table_rows,
    ROUND(data_length/1024, 2) as 'size_kb',
    engine,
    table_collation,
    '✅ PRESENT' as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
ORDER BY table_name;

-- Check if all 5 expected
SELECT
    CASE
        WHEN COUNT(*) = 5 THEN '✅ PASS - All 5 workflow tables present'
        ELSE CONCAT('❌ FAIL - Only ', COUNT(*), ' of 5 workflow tables found')
    END as overall_status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  );

SELECT '' as '';

-- ============================================
-- TEST 3: WORKFLOW_SETTINGS TABLE STRUCTURE
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': workflow_settings Structure Verification') as '';

SELECT
    column_name,
    column_type,
    is_nullable,
    column_default,
    column_key
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_settings'
ORDER BY ordinal_position;

-- Verify column count
SELECT
    CASE
        WHEN COUNT(*) >= 17 THEN CONCAT('✅ PASS - Found ', COUNT(*), ' columns (expected ≥17)')
        ELSE CONCAT('❌ FAIL - Only ', COUNT(*), ' columns (expected ≥17)')
    END as column_count_status
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_settings';

SELECT '' as '';

-- ============================================
-- TEST 4: INDEXES VERIFICATION
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Critical Indexes Verification') as '';

SELECT
    table_name,
    index_name,
    GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns,
    non_unique,
    index_type
FROM information_schema.statistics
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
GROUP BY table_name, index_name, non_unique, index_type
ORDER BY table_name, index_name;

-- Count indexes on workflow tables
SELECT
    table_name,
    COUNT(DISTINCT index_name) as index_count
FROM information_schema.statistics
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
GROUP BY table_name
ORDER BY table_name;

SELECT '' as '';

-- ============================================
-- TEST 5: MYSQL FUNCTION VERIFICATION
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': MySQL Function get_workflow_enabled_for_folder() Check') as '';

-- Check if function exists
SELECT
    routine_name,
    routine_type,
    dtd_identifier as return_type,
    routine_definition,
    '✅ EXISTS' as status
FROM information_schema.routines
WHERE routine_schema = 'collaboranexio'
  AND routine_name = 'get_workflow_enabled_for_folder'
  AND routine_type = 'FUNCTION';

-- Verify existence
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN '✅ PASS - Function exists and is callable'
        ELSE '❌ FAIL - Function does not exist'
    END as function_status
FROM information_schema.routines
WHERE routine_schema = 'collaboranexio'
  AND routine_name = 'get_workflow_enabled_for_folder'
  AND routine_type = 'FUNCTION';

-- Test function execution (safe test with NULL values)
SELECT '--- Function Test Execution ---' as '';
SELECT 'Testing: get_workflow_enabled_for_folder(NULL, NULL)' as '';
SELECT get_workflow_enabled_for_folder(NULL, NULL) as function_result;

SELECT '' as '';

-- ============================================
-- TEST 6: FOREIGN KEY CONSTRAINTS
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Foreign Key Constraints Verification') as '';

SELECT
    kcu.table_name,
    kcu.constraint_name,
    kcu.column_name,
    kcu.referenced_table_name,
    kcu.referenced_column_name,
    rc.delete_rule,
    rc.update_rule,
    '✅ VALID' as status
FROM information_schema.key_column_usage kcu
JOIN information_schema.referential_constraints rc
    ON kcu.constraint_name = rc.constraint_name
    AND kcu.constraint_schema = rc.constraint_schema
WHERE kcu.table_schema = 'collaboranexio'
  AND kcu.table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
  AND kcu.referenced_table_name IS NOT NULL
ORDER BY kcu.table_name, kcu.constraint_name;

-- Count foreign keys
SELECT
    table_name,
    COUNT(*) as fk_count
FROM information_schema.key_column_usage
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
  AND referenced_table_name IS NOT NULL
GROUP BY table_name;

SELECT '' as '';

-- ============================================
-- TEST 7: MULTI-TENANT COMPLIANCE
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Multi-Tenant Compliance (NULL tenant_id Check)') as '';

-- Check workflow_settings
SELECT 'workflow_settings' as table_name,
       COUNT(*) as total_records,
       SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) as null_tenant_ids,
       CASE
           WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS'
           ELSE '❌ FAIL'
       END as status
FROM workflow_settings
UNION ALL
SELECT 'workflow_roles',
       COUNT(*),
       SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE
           WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS'
           ELSE '❌ FAIL'
       END
FROM workflow_roles
UNION ALL
SELECT 'document_workflow',
       COUNT(*),
       SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE
           WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS'
           ELSE '❌ FAIL'
       END
FROM document_workflow
UNION ALL
SELECT 'document_workflow_history',
       COUNT(*),
       SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE
           WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS'
           ELSE '❌ FAIL'
       END
FROM document_workflow_history
UNION ALL
SELECT 'file_assignments',
       COUNT(*),
       SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE
           WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS'
           ELSE '❌ FAIL'
       END
FROM file_assignments;

SELECT '' as '';

-- ============================================
-- TEST 8: SOFT DELETE COMPLIANCE
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Soft Delete Pattern Compliance') as '';

-- Check if deleted_at column exists on mutable tables
SELECT
    'workflow_settings' as table_name,
    CASE
        WHEN COUNT(*) = 1 THEN '✅ PASS - deleted_at column present'
        ELSE '❌ FAIL - deleted_at column missing'
    END as status
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_settings'
  AND column_name = 'deleted_at'
UNION ALL
SELECT
    'workflow_roles',
    CASE
        WHEN COUNT(*) = 1 THEN '✅ PASS - deleted_at column present'
        ELSE '❌ FAIL - deleted_at column missing'
    END
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_roles'
  AND column_name = 'deleted_at'
UNION ALL
SELECT
    'document_workflow',
    CASE
        WHEN COUNT(*) = 1 THEN '✅ PASS - deleted_at column present'
        ELSE '❌ FAIL - deleted_at column missing'
    END
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'document_workflow'
  AND column_name = 'deleted_at'
UNION ALL
SELECT
    'file_assignments',
    CASE
        WHEN COUNT(*) = 1 THEN '✅ PASS - deleted_at column present'
        ELSE '❌ FAIL - deleted_at column missing'
    END
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'file_assignments'
  AND column_name = 'deleted_at'
UNION ALL
SELECT
    'document_workflow_history',
    CASE
        WHEN COUNT(*) = 0 THEN '✅ PASS - Immutable (no deleted_at expected)'
        ELSE '⚠️  WARNING - Immutable table has deleted_at'
    END
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'document_workflow_history'
  AND column_name = 'deleted_at';

SELECT '' as '';

-- ============================================
-- TEST 9: user_tenant_access DATA INTEGRITY
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': user_tenant_access Population Check') as '';

SELECT
    COUNT(*) as total_records,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT tenant_id) as unique_tenants,
    CASE
        WHEN COUNT(*) >= 2 THEN '✅ PASS - Table populated (≥2 records)'
        WHEN COUNT(*) = 0 THEN '❌ FAIL - Table EMPTY'
        ELSE '⚠️  WARNING - Only 1 record (expected ≥2)'
    END as status
FROM user_tenant_access
WHERE deleted_at IS NULL;

-- Show sample records
SELECT
    uta.id,
    uta.user_id,
    u.name as user_name,
    u.email,
    uta.tenant_id,
    t.name as tenant_name,
    uta.created_at
FROM user_tenant_access uta
LEFT JOIN users u ON uta.user_id = u.id
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE uta.deleted_at IS NULL
ORDER BY uta.tenant_id, uta.user_id
LIMIT 10;

SELECT '' as '';

-- ============================================
-- TEST 10: STORAGE ENGINE & CHARSET
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Storage Engine & Charset Compliance') as '';

SELECT
    table_name,
    engine,
    table_collation,
    CASE
        WHEN engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci' THEN '✅ PASS'
        WHEN engine = 'InnoDB' THEN '⚠️  WARNING - Wrong collation'
        ELSE '❌ FAIL - Wrong engine'
    END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
ORDER BY table_name;

SELECT '' as '';

-- ============================================
-- TEST 11: DATABASE SIZE & PERFORMANCE
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Database Size Analysis') as '';

SELECT
    ROUND(SUM(data_length + index_length)/1024/1024, 2) as 'total_size_mb',
    ROUND(SUM(data_length)/1024/1024, 2) as 'data_size_mb',
    ROUND(SUM(index_length)/1024/1024, 2) as 'index_size_mb',
    CASE
        WHEN ROUND(SUM(data_length + index_length)/1024/1024, 2) BETWEEN 8 AND 15 THEN '✅ PASS - Size healthy (~10.3 MB expected)'
        WHEN ROUND(SUM(data_length + index_length)/1024/1024, 2) < 8 THEN '⚠️  WARNING - Database smaller than expected'
        ELSE '⚠️  WARNING - Database larger than expected'
    END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio';

-- Size breakdown by workflow tables
SELECT
    table_name,
    table_rows,
    ROUND(data_length/1024, 2) as 'data_kb',
    ROUND(index_length/1024, 2) as 'index_kb',
    ROUND((data_length + index_length)/1024, 2) as 'total_kb'
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
ORDER BY (data_length + index_length) DESC;

SELECT '' as '';

-- ============================================
-- TEST 12: AUDIT LOGS ACTIVITY
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Audit Logs Activity Check') as '';

SELECT
    COUNT(*) as total_audit_logs,
    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7days,
    CASE
        WHEN COUNT(*) > 0 THEN '✅ PASS - Audit system active'
        ELSE '⚠️  WARNING - No audit logs found'
    END as status
FROM audit_logs
WHERE deleted_at IS NULL;

-- Recent audit actions
SELECT
    action,
    COUNT(*) as count
FROM audit_logs
WHERE deleted_at IS NULL
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY action
ORDER BY count DESC
LIMIT 10;

SELECT '' as '';

-- ============================================
-- TEST 13: CHECK CONSTRAINTS VERIFICATION
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': CHECK Constraints on audit_logs') as '';

-- Try to find CHECK constraints (MySQL 8.0.16+)
SELECT
    constraint_name,
    table_name,
    check_clause
FROM information_schema.check_constraints
WHERE constraint_schema = 'collaboranexio'
  AND table_name = 'audit_logs'
ORDER BY constraint_name;

-- Count CHECK constraints
SELECT
    CASE
        WHEN COUNT(*) >= 5 THEN CONCAT('✅ PASS - Found ', COUNT(*), ' CHECK constraints (expected ≥5)')
        WHEN COUNT(*) > 0 THEN CONCAT('⚠️  WARNING - Only ', COUNT(*), ' CHECK constraints (expected ≥5)')
        ELSE '⚠️  WARNING - No CHECK constraints found (may be older MySQL version)'
    END as status
FROM information_schema.check_constraints
WHERE constraint_schema = 'collaboranexio'
  AND table_name = 'audit_logs';

SELECT '' as '';

-- ============================================
-- TEST 14: NORMALIZATION CHECK
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Database Normalization (3NF) Verification') as '';

-- Check for duplicate data in workflow_roles
SELECT 'workflow_roles: Checking for duplicate user-tenant-role combinations' as check_name;
SELECT
    user_id,
    tenant_id,
    workflow_role,
    COUNT(*) as duplicate_count
FROM workflow_roles
WHERE deleted_at IS NULL
GROUP BY user_id, tenant_id, workflow_role
HAVING COUNT(*) > 1;

SELECT
    CASE
        WHEN COUNT(*) = 0 THEN '✅ PASS - No duplicate workflow roles'
        ELSE CONCAT('⚠️  WARNING - Found ', COUNT(*), ' duplicate workflow role combinations')
    END as status
FROM (
    SELECT user_id, tenant_id, workflow_role, COUNT(*) as cnt
    FROM workflow_roles
    WHERE deleted_at IS NULL
    GROUP BY user_id, tenant_id, workflow_role
    HAVING COUNT(*) > 1
) duplicates;

-- Check for orphaned records (referential integrity beyond FK)
SELECT 'file_assignments: Checking for orphaned records' as check_name;
SELECT
    fa.id,
    fa.file_id,
    fa.folder_id,
    fa.assigned_to_user_id,
    CASE
        WHEN fa.file_id IS NOT NULL AND f.id IS NULL THEN 'ORPHANED FILE'
        WHEN fa.folder_id IS NOT NULL AND fd.id IS NULL THEN 'ORPHANED FOLDER'
        WHEN u.id IS NULL THEN 'ORPHANED USER'
        ELSE 'OK'
    END as orphan_type
FROM file_assignments fa
LEFT JOIN files f ON fa.file_id = f.id AND f.deleted_at IS NULL
LEFT JOIN folders fd ON fa.folder_id = fd.id AND fd.deleted_at IS NULL
LEFT JOIN users u ON fa.assigned_to_user_id = u.id AND u.deleted_at IS NULL
WHERE fa.deleted_at IS NULL
  AND (
      (fa.file_id IS NOT NULL AND f.id IS NULL) OR
      (fa.folder_id IS NOT NULL AND fd.id IS NULL) OR
      u.id IS NULL
  )
LIMIT 10;

SELECT '' as '';

-- ============================================
-- TEST 15: REGRESSION CHECK (Previous Bug Fixes)
-- ============================================
SET @test_number = @test_number + 1;
SELECT CONCAT('TEST ', @test_number, ': Regression Check (BUG-046 through BUG-061)') as '';

-- Check if stored procedure from BUG-046 still exists
SELECT
    'BUG-046: record_audit_log_deletion procedure' as bug_fix,
    CASE
        WHEN COUNT(*) = 1 THEN '✅ INTACT'
        ELSE '❌ MISSING'
    END as status
FROM information_schema.routines
WHERE routine_schema = 'collaboranexio'
  AND routine_name = 'record_audit_log_deletion'
  AND routine_type = 'PROCEDURE'
UNION ALL
-- Check if workflow tables from BUG-050/051 exist
SELECT
    'BUG-050/051: Workflow tables existence',
    CASE
        WHEN COUNT(*) = 5 THEN '✅ INTACT'
        ELSE CONCAT('❌ INCOMPLETE (', COUNT(*), '/5)')
    END
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN (
      'workflow_settings',
      'workflow_roles',
      'document_workflow',
      'document_workflow_history',
      'file_assignments'
  )
UNION ALL
-- Check if user_tenant_access is populated (BUG-060)
SELECT
    'BUG-060: user_tenant_access populated',
    CASE
        WHEN (SELECT COUNT(*) FROM user_tenant_access WHERE deleted_at IS NULL) >= 2
        THEN '✅ INTACT'
        ELSE '❌ DEPOPULATED'
    END
UNION ALL
-- Check if audit_logs table has proper structure (BUG-041/047)
SELECT
    'BUG-041/047: audit_logs structure',
    CASE
        WHEN COUNT(*) >= 20 THEN '✅ INTACT'
        ELSE '❌ COLUMNS MISSING'
    END
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'audit_logs';

SELECT '' as '';

-- ============================================
-- FINAL SUMMARY
-- ============================================
SELECT '============================================' as '';
SELECT 'FINAL VERIFICATION SUMMARY' as '';
SELECT '============================================' as '';

-- Count total tests passed/failed
SELECT
    @test_number as total_tests_executed,
    'See individual test results above' as instructions,
    'Expected: All tests PASS or WARNING (no FAIL)' as expected_result,
    NOW() as verification_timestamp;

SELECT '' as '';
SELECT '============================================' as '';
SELECT 'Production Readiness Criteria:' as '';
SELECT '  ✅ All 72 tables present' as '';
SELECT '  ✅ 5 workflow tables operational' as '';
SELECT '  ✅ MySQL function callable' as '';
SELECT '  ✅ 0 NULL tenant_id violations' as '';
SELECT '  ✅ Soft delete compliant' as '';
SELECT '  ✅ InnoDB + utf8mb4_unicode_ci' as '';
SELECT '  ✅ user_tenant_access populated' as '';
SELECT '  ✅ Previous fixes intact' as '';
SELECT '  ✅ Audit logs active' as '';
SELECT '  ✅ No orphaned records' as '';
SELECT '============================================' as '';
SELECT '' as '';
SELECT 'Verification Complete.' as '';
SELECT 'Review results above for PASS/FAIL/WARNING status.' as '';
SELECT '' as '';
