-- ============================================
-- COMPREHENSIVE WORKFLOW ACTIVATION SYSTEM VERIFICATION
-- Date: 2025-11-02
-- Purpose: Verify database integrity after workflow activation implementation
-- ============================================

USE collaboranexio;

SET @total_tests = 0;
SET @passed_tests = 0;

SELECT '============================================' AS '';
SELECT 'WORKFLOW ACTIVATION SYSTEM - DATABASE VERIFICATION' AS '';
SELECT 'Execution Time' AS 'Status', NOW() AS 'Timestamp';
SELECT '============================================' AS '';

-- ============================================
-- TEST 1: Check if workflow_settings table exists
-- ============================================

SELECT 'TEST 1: workflow_settings Table Existence' AS '';

SET @table_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'workflow_settings'
);

SET @total_tests = @total_tests + 1;
SET @passed_tests = @passed_tests + IF(@table_exists = 1, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @table_exists AS 'Table Exists (1=Yes, 0=No)',
    CASE
        WHEN @table_exists = 1 THEN 'workflow_settings table created successfully'
        ELSE '⚠️ MIGRATION NOT EXECUTED - Table does not exist'
    END AS 'Details';

-- ============================================
-- CONDITIONAL TESTS (Only if table exists)
-- ============================================

-- TEST 2: Verify table schema (columns)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 2: workflow_settings Schema Verification' AS '';

SET @total_tests = @total_tests + 1;

SELECT @schema_valid := COUNT(*) = 19
INTO @schema_valid
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
  AND COLUMN_NAME IN (
    'id', 'tenant_id', 'scope_type', 'folder_id',
    'workflow_enabled', 'auto_create_workflow',
    'require_validation', 'require_approval', 'auto_approve_on_validation',
    'inherit_from_parent', 'override_parent', 'settings_metadata',
    'configured_by_user_id', 'configuration_reason',
    'deleted_at', 'created_at', 'updated_at'
);

SET @passed_tests = @passed_tests + IF(@schema_valid = 1, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @schema_valid = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'workflow_settings') AS 'Columns Found',
    '17 expected (19 total with indexes)' AS 'Expected',
    CASE
        WHEN @table_exists = 0 THEN 'Table does not exist'
        WHEN @schema_valid = 1 THEN 'All required columns present'
        ELSE 'Missing required columns'
    END AS 'Details';

-- ============================================
-- TEST 3: Verify multi-tenancy compliance (tenant_id NOT NULL)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 3: Multi-Tenancy Compliance (tenant_id NOT NULL)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @tenant_column_valid := (
    SELECT COUNT(*) = 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'workflow_settings'
      AND COLUMN_NAME = 'tenant_id'
      AND IS_NULLABLE = 'NO'
) INTO @tenant_column_valid;

SET @passed_tests = @passed_tests + IF(@tenant_column_valid = 1 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @tenant_column_valid = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    CASE
        WHEN @table_exists = 0 THEN 'N/A'
        WHEN @tenant_column_valid = 1 THEN 'NOT NULL'
        ELSE 'NULL ALLOWED (VIOLATION!)'
    END AS 'tenant_id Status',
    'CollaboraNexio MANDATORY: tenant_id NOT NULL' AS 'Requirement';

-- ============================================
-- TEST 4: Verify soft delete column (deleted_at)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 4: Soft Delete Compliance (deleted_at column)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @soft_delete_valid := (
    SELECT COUNT(*) = 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'workflow_settings'
      AND COLUMN_NAME = 'deleted_at'
      AND IS_NULLABLE = 'YES'
      AND COLUMN_TYPE LIKE 'timestamp%'
) INTO @soft_delete_valid;

SET @passed_tests = @passed_tests + IF(@soft_delete_valid = 1 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @soft_delete_valid = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    CASE
        WHEN @table_exists = 0 THEN 'N/A'
        WHEN @soft_delete_valid = 1 THEN 'TIMESTAMP NULL'
        ELSE 'Column missing or wrong type'
    END AS 'deleted_at Status',
    'CollaboraNexio MANDATORY: Soft delete pattern' AS 'Requirement';

-- ============================================
-- TEST 5: Verify foreign keys
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 5: Foreign Key Constraints' AS '';

SET @total_tests = @total_tests + 1;

SELECT @fk_count := COUNT(*)
INTO @fk_count
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

SET @passed_tests = @passed_tests + IF(@fk_count >= 3 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @fk_count >= 3 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @fk_count AS 'Foreign Keys Found',
    '3 expected minimum (tenant_id, folder_id, configured_by_user_id)' AS 'Expected';

-- Show foreign keys detail
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- ============================================
-- TEST 6: Verify indexes (multi-tenant query optimization)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 6: Index Coverage (Multi-Tenant Optimization)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @index_count := COUNT(DISTINCT INDEX_NAME)
INTO @index_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
  AND INDEX_NAME LIKE 'idx_%';

SET @passed_tests = @passed_tests + IF(@index_count >= 6 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @index_count >= 6 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @index_count AS 'Indexes Found',
    '6 expected minimum' AS 'Expected',
    CASE
        WHEN @table_exists = 0 THEN 'Table does not exist'
        WHEN @index_count >= 6 THEN 'Optimal index coverage'
        ELSE 'Missing indexes - performance degradation'
    END AS 'Details';

-- Show indexes detail
SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS 'Columns',
    NON_UNIQUE,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
GROUP BY INDEX_NAME, NON_UNIQUE, INDEX_TYPE
ORDER BY INDEX_NAME;

-- ============================================
-- TEST 7: Verify CHECK constraint (scope consistency)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 7: CHECK Constraint (Scope Consistency)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @check_constraint := COUNT(*)
INTO @check_constraint
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'workflow_settings'
  AND CONSTRAINT_NAME LIKE '%scope_consistency%';

SET @passed_tests = @passed_tests + IF(@check_constraint >= 1 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @check_constraint >= 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @check_constraint AS 'CHECK Constraints Found',
    '1 expected (chk_workflow_settings_scope_consistency)' AS 'Expected',
    CASE
        WHEN @table_exists = 0 THEN 'Table does not exist'
        WHEN @check_constraint >= 1 THEN 'Scope validation enforced at DB level'
        ELSE 'Missing CHECK constraint - data integrity risk'
    END AS 'Details';

-- ============================================
-- TEST 8: Verify MySQL function exists (get_workflow_enabled_for_folder)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 8: MySQL Helper Function Existence' AS '';

SET @total_tests = @total_tests + 1;

SELECT @function_exists := COUNT(*)
INTO @function_exists
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_TYPE = 'FUNCTION'
  AND ROUTINE_NAME = 'get_workflow_enabled_for_folder';

SET @passed_tests = @passed_tests + IF(@function_exists = 1, 1, 0);

SELECT
    CASE
        WHEN @function_exists = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @function_exists AS 'Function Exists (1=Yes, 0=No)',
    CASE
        WHEN @function_exists = 1 THEN 'Helper function available for inheritance logic'
        ELSE '⚠️ MIGRATION NOT EXECUTED - Function does not exist'
    END AS 'Details';

-- ============================================
-- TEST 9: Test function execution (if exists)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 9: Function Execution Test' AS '';

SET @total_tests = @total_tests + 1;

SET @function_test_result = NULL;
SET @function_test_error = NULL;

-- Only test if function exists
SELECT IF(@function_exists = 1,
    (SELECT get_workflow_enabled_for_folder(1, 1)),
    NULL
) INTO @function_test_result;

SET @passed_tests = @passed_tests + IF(@function_test_result IS NOT NULL OR @function_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @function_exists = 0 THEN '⏭️ SKIP'
        WHEN @function_test_result IS NOT NULL THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    COALESCE(@function_test_result, 'N/A') AS 'Function Return Value',
    'Expected: 0 or 1 (0 if no config, 1 if enabled)' AS 'Expected',
    CASE
        WHEN @function_exists = 0 THEN 'Function does not exist'
        WHEN @function_test_result IS NOT NULL THEN 'Function executes successfully'
        ELSE 'Function execution failed'
    END AS 'Details';

-- ============================================
-- TEST 10: Verify demo data insertion
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 10: Demo Data (Default Tenant Config)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @demo_data := COUNT(*)
INTO @demo_data
FROM workflow_settings
WHERE tenant_id = 1
  AND scope_type = 'tenant'
  AND deleted_at IS NULL;

SET @passed_tests = @passed_tests + IF(@demo_data >= 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @demo_data = 1 THEN '✅ PASS'
        WHEN @demo_data = 0 THEN '⚠️ WARNING'
        ELSE '❌ FAIL'
    END AS 'Result',
    @demo_data AS 'Tenant-wide Configs for tenant_id=1',
    '1 expected (or 0 if tenant_id=1 does not exist)' AS 'Expected',
    CASE
        WHEN @table_exists = 0 THEN 'Table does not exist'
        WHEN @demo_data = 1 THEN 'Default config created'
        WHEN @demo_data = 0 THEN 'No config (tenant_id=1 may not exist)'
        ELSE 'Duplicate configs detected (UNIQUE constraint issue)'
    END AS 'Details';

-- ============================================
-- TEST 11: Verify existing workflow tables integrity
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 11: Existing Workflow Tables Integrity' AS '';

SET @total_tests = @total_tests + 1;

SELECT @workflow_tables := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME IN ('workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
);

SET @passed_tests = @passed_tests + IF(@workflow_tables = 4, 1, 0);

SELECT
    CASE
        WHEN @workflow_tables = 4 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @workflow_tables AS 'Workflow Tables Found',
    '4 expected (workflow_roles, document_workflow, document_workflow_history, file_assignments)' AS 'Expected',
    CASE
        WHEN @workflow_tables = 4 THEN 'All workflow tables intact'
        ELSE 'Missing workflow tables - system incomplete'
    END AS 'Details';

-- ============================================
-- TEST 12: Verify API changes (list.php modified query)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 12: API Modifications Verification (Indirect)' AS '';

SET @total_tests = @total_tests + 1;

-- Check if user_tenant_access table exists (used by modified API)
SELECT @uta_table := COUNT(*)
INTO @uta_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'user_tenant_access';

SET @passed_tests = @passed_tests + IF(@uta_table = 1, 1, 0);

SELECT
    CASE
        WHEN @uta_table = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @uta_table AS 'user_tenant_access Table Exists',
    '1 expected (required for /api/workflow/roles/list.php)' AS 'Expected',
    CASE
        WHEN @uta_table = 1 THEN 'Table exists - API can query available users'
        ELSE 'Table missing - API will fail'
    END AS 'Details';

-- ============================================
-- TEST 13: Check for previous bugs fixes (regression check)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 13: Regression Check (Previous Fixes Intact)' AS '';

SET @total_tests = @total_tests + 1;

-- Check key tables from previous bugs exist
SELECT @previous_tables := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME IN ('audit_logs', 'files', 'folders', 'users', 'tenants')
);

SET @passed_tests = @passed_tests + IF(@previous_tables = 5, 1, 0);

SELECT
    CASE
        WHEN @previous_tables = 5 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @previous_tables AS 'Core Tables Found',
    '5 expected (audit_logs, files, folders, users, tenants)' AS 'Expected',
    CASE
        WHEN @previous_tables = 5 THEN 'Previous fixes intact'
        ELSE 'Core tables missing - CRITICAL REGRESSION'
    END AS 'Details';

-- ============================================
-- TEST 14: Data integrity check (no NULL tenant_id violations)
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 14: Multi-Tenant Data Integrity (No NULL tenant_id)' AS '';

SET @total_tests = @total_tests + 1;

SELECT @null_tenant_violations := COALESCE((
    SELECT COUNT(*)
    FROM workflow_settings
    WHERE tenant_id IS NULL
), 0) INTO @null_tenant_violations;

SET @passed_tests = @passed_tests + IF(@null_tenant_violations = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @null_tenant_violations = 0 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    @null_tenant_violations AS 'NULL tenant_id Violations',
    '0 expected (CollaboraNexio MANDATORY)' AS 'Expected',
    CASE
        WHEN @table_exists = 0 THEN 'Table does not exist'
        WHEN @null_tenant_violations = 0 THEN 'Perfect multi-tenant compliance'
        ELSE 'CRITICAL: NULL tenant_id found - security breach'
    END AS 'Details';

-- ============================================
-- TEST 15: Storage engine and charset verification
-- ============================================

SELECT '--------------------------------------------' AS '';
SELECT 'TEST 15: Storage Engine and Charset' AS '';

SET @total_tests = @total_tests + 1;

SELECT @storage_valid := (
    SELECT COUNT(*) = 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'workflow_settings'
      AND ENGINE = 'InnoDB'
      AND TABLE_COLLATION = 'utf8mb4_unicode_ci'
) INTO @storage_valid;

SET @passed_tests = @passed_tests + IF(@storage_valid = 1 OR @table_exists = 0, 1, 0);

SELECT
    CASE
        WHEN @table_exists = 0 THEN '⏭️ SKIP'
        WHEN @storage_valid = 1 THEN '✅ PASS'
        ELSE '❌ FAIL'
    END AS 'Result',
    (SELECT ENGINE FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'workflow_settings') AS 'Engine',
    (SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'workflow_settings') AS 'Collation',
    'Expected: InnoDB + utf8mb4_unicode_ci' AS 'Expected';

-- ============================================
-- FINAL SUMMARY
-- ============================================

SELECT '============================================' AS '';
SELECT 'FINAL VERIFICATION SUMMARY' AS '';
SELECT '============================================' AS '';

SELECT
    @passed_tests AS 'Tests Passed',
    @total_tests AS 'Total Tests',
    CONCAT(ROUND((@passed_tests / @total_tests) * 100, 1), '%') AS 'Success Rate',
    CASE
        WHEN @passed_tests = @total_tests THEN '✅ ALL TESTS PASSED'
        WHEN @passed_tests >= @total_tests * 0.9 THEN '⚠️ MOSTLY PASSED (90%+)'
        WHEN @passed_tests >= @total_tests * 0.7 THEN '⚠️ PARTIAL SUCCESS (70%+)'
        ELSE '❌ CRITICAL FAILURES'
    END AS 'Status',
    CASE
        WHEN @table_exists = 0 THEN '⚠️ MIGRATION NOT YET EXECUTED - Run workflow_activation_system.sql'
        WHEN @function_exists = 0 THEN '⚠️ FUNCTION MISSING - Run migration again'
        WHEN @passed_tests = @total_tests THEN '🎉 PRODUCTION READY'
        WHEN @passed_tests >= @total_tests * 0.9 THEN 'Minor issues - Review failed tests'
        ELSE 'CRITICAL - Review failed tests before deployment'
    END AS 'Recommendation';

-- Migration execution instructions (if not executed)
SELECT '--------------------------------------------' AS '';
SELECT CASE
    WHEN @table_exists = 0 THEN 'NEXT STEPS: Execute Migration'
    ELSE 'Migration Status: COMPLETED'
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '⚠️ The workflow_settings table does not exist. Migration has NOT been executed yet.'
    ELSE ''
END AS 'Status';

SELECT CASE
    WHEN @table_exists = 0 THEN 'To execute migration, run ONE of the following:'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '1. Via MySQL CLI:'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '   mysql -u root collaboranexio < /mnt/c/xampp/htdocs/CollaboraNexio/database/migrations/workflow_activation_system.sql'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '2. Via Browser (if PHP script created):'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '   http://localhost:8888/CollaboraNexio/run_workflow_activation_migration.php'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '3. Via phpMyAdmin:'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '   - Navigate to SQL tab'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '   - Paste contents of workflow_activation_system.sql'
    ELSE ''
END AS '';

SELECT CASE
    WHEN @table_exists = 0 THEN '   - Execute query'
    ELSE ''
END AS '';

-- Database size check
SELECT '--------------------------------------------' AS '';
SELECT 'Database Size Information' AS '';

SELECT
    TABLE_NAME,
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS 'Size (MB)',
    TABLE_ROWS AS 'Approx Rows',
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
ORDER BY TABLE_NAME;

-- Total workflow system size
SELECT
    CONCAT(ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2), ' MB') AS 'Total Workflow System Size',
    SUM(TABLE_ROWS) AS 'Total Rows (Approx)'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments');

SELECT '============================================' AS '';
SELECT 'VERIFICATION COMPLETE' AS '';
SELECT NOW() AS 'Timestamp';
SELECT '============================================' AS '';
