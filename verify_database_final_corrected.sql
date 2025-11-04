-- ============================================
-- CORRECTED DATABASE VERIFICATION
-- Post BUG-061 - Production Readiness Check
-- Date: 2025-11-04
-- ============================================

USE collaboranexio;

-- ============================================
-- COMPREHENSIVE VERIFICATION
-- ============================================

SELECT '=== TABLE COUNT ===' as '';
SELECT
    COUNT(*) as total_tables,
    CASE
        WHEN COUNT(*) >= 63 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_type = 'BASE TABLE';

SELECT '=== WORKFLOW TABLES ===' as '';
SELECT COUNT(*) as workflow_tables_count,
       CASE WHEN COUNT(*) = 5 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments');

SELECT '=== WORKFLOW_SETTINGS STRUCTURE ===' as '';
SELECT COUNT(*) as column_count,
       CASE WHEN COUNT(*) = 17 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_settings';

SELECT '=== MYSQL FUNCTION ===' as '';
SELECT COUNT(*) as function_exists,
       CASE WHEN COUNT(*) = 1 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM information_schema.routines
WHERE routine_schema = 'collaboranexio'
  AND routine_name = 'get_workflow_enabled_for_folder'
  AND routine_type = 'FUNCTION';

SELECT '=== FUNCTION TEST ===' as '';
SELECT get_workflow_enabled_for_folder(NULL, NULL) as result;

SELECT '=== MULTI-TENANT COMPLIANCE (Active Records) ===' as '';
SELECT
    'workflow_roles' as table_name,
    COUNT(*) as active_records,
    SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) as null_violations,
    CASE WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM workflow_roles
WHERE deleted_at IS NULL
HAVING COUNT(*) > 0
UNION ALL
SELECT 'document_workflow', COUNT(*), SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS' ELSE '❌ FAIL' END
FROM document_workflow WHERE deleted_at IS NULL HAVING COUNT(*) > 0
UNION ALL
SELECT 'file_assignments', COUNT(*), SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END),
       CASE WHEN SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) = 0 THEN '✅ PASS' ELSE '❌ FAIL' END
FROM file_assignments WHERE deleted_at IS NULL HAVING COUNT(*) > 0;

SELECT '=== SOFT DELETE COMPLIANCE ===' as '';
SELECT
    table_name,
    CASE WHEN COUNT(*) = 1 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM information_schema.columns
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'file_assignments')
  AND column_name = 'deleted_at'
GROUP BY table_name;

SELECT '=== USER_TENANT_ACCESS ===' as '';
SELECT
    COUNT(*) as total_records,
    CASE WHEN COUNT(*) >= 2 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM user_tenant_access
WHERE deleted_at IS NULL;

SELECT '=== STORAGE ENGINE ===' as '';
SELECT COUNT(*) as correct_engine_count,
       CASE WHEN COUNT(*) = 5 THEN '✅ PASS' ELSE '❌ FAIL' END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
  AND engine = 'InnoDB'
  AND table_collation = 'utf8mb4_unicode_ci';

SELECT '=== DATABASE SIZE ===' as '';
SELECT
    ROUND(SUM(data_length + index_length)/1024/1024, 2) as size_mb,
    CASE WHEN ROUND(SUM(data_length + index_length)/1024/1024, 2) BETWEEN 8 AND 15 THEN '✅ PASS' ELSE '⚠️  WARNING' END as status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio';

SELECT '=== AUDIT LOGS ACTIVITY ===' as '';
SELECT
    COUNT(*) as total_logs,
    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
    CASE WHEN COUNT(*) > 0 THEN '✅ PASS' ELSE '⚠️  WARNING' END as status
FROM audit_logs
WHERE deleted_at IS NULL;

SELECT '=== CHECK CONSTRAINTS ===' as '';
SELECT COUNT(*) as constraint_count,
       CASE WHEN COUNT(*) >= 5 THEN '✅ PASS' ELSE '⚠️  WARNING' END as status
FROM information_schema.check_constraints
WHERE constraint_schema = 'collaboranexio'
  AND table_name = 'audit_logs';

SELECT '=== REGRESSION CHECK ===' as '';
SELECT
    'record_audit_log_deletion' as procedure_name,
    CASE WHEN COUNT(*) = 1 THEN '✅ INTACT' ELSE '❌ MISSING' END as status
FROM information_schema.routines
WHERE routine_schema = 'collaboranexio'
  AND routine_name = 'record_audit_log_deletion'
  AND routine_type = 'PROCEDURE';

SELECT '=== FOREIGN KEY COUNT ===' as '';
SELECT
    table_name,
    COUNT(*) as fk_count
FROM information_schema.key_column_usage
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
  AND referenced_table_name IS NOT NULL
GROUP BY table_name
ORDER BY table_name;

SELECT '=== INDEX COUNT ===' as '';
SELECT
    table_name,
    COUNT(DISTINCT index_name) as index_count
FROM information_schema.statistics
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
GROUP BY table_name
ORDER BY table_name;

SELECT '=== NORMALIZATION: DUPLICATE CHECK ===' as '';
SELECT COUNT(*) as duplicate_workflow_roles,
       CASE WHEN COUNT(*) = 0 THEN '✅ PASS' ELSE '⚠️  WARNING' END as status
FROM (
    SELECT user_id, tenant_id, workflow_role, COUNT(*) as cnt
    FROM workflow_roles
    WHERE deleted_at IS NULL
    GROUP BY user_id, tenant_id, workflow_role
    HAVING COUNT(*) > 1
) duplicates;

SELECT '============================================' as '';
SELECT 'VERIFICATION COMPLETE' as '';
SELECT NOW() as timestamp;
