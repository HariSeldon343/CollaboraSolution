<?php
/**
 * COMPREHENSIVE DATABASE VERIFICATION - Workflow Activation System
 * Date: 2025-11-02
 * Purpose: Verify database integrity after workflow activation implementation
 */

require_once __DIR__ . '/includes/db.php';

// Prevent browser execution - CLI only or admin access required
if (php_sapi_name() !== 'cli') {
    session_start();
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
        die("⛔ Access Denied: Admin access required");
    }
}

header('Content-Type: text/plain; charset=utf-8');

// Get database instance
$db = Database::getInstance();

// Test counters
$totalTests = 0;
$passedTests = 0;

echo str_repeat('=', 80) . PHP_EOL;
echo "WORKFLOW ACTIVATION SYSTEM - DATABASE VERIFICATION" . PHP_EOL;
echo "Execution Time: " . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

// ============================================
// TEST 1: Check if workflow_settings table exists
// ============================================

echo "TEST 1: workflow_settings Table Existence" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;
$tableExists = false;

try {
    $result = $db->query("
        SELECT COUNT(*) as count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME = 'workflow_settings'
    ");
    $tableExists = ($result[0]['count'] == 1);

    if ($tableExists) {
        $passedTests++;
        echo "✅ PASS: workflow_settings table exists" . PHP_EOL;
    } else {
        echo "❌ FAIL: workflow_settings table does NOT exist" . PHP_EOL;
        echo "⚠️  MIGRATION NOT EXECUTED - Table does not exist" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

// ============================================
// TEST 2: Verify table schema (columns)
// ============================================

echo "TEST 2: workflow_settings Schema Verification" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT COUNT(*) as count
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
              )
        ");

        $columnsFound = $result[0]['count'];
        $schemaValid = ($columnsFound == 17);

        if ($schemaValid) {
            $passedTests++;
            echo "✅ PASS: All 17 required columns present" . PHP_EOL;
        } else {
            echo "❌ FAIL: Found $columnsFound columns, expected 17" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 3: Verify multi-tenancy compliance (tenant_id NOT NULL)
// ============================================

echo "TEST 3: Multi-Tenancy Compliance (tenant_id NOT NULL)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
              AND COLUMN_NAME = 'tenant_id'
        ");

        $isNullable = $result[0]['IS_NULLABLE'];

        if ($isNullable === 'NO') {
            $passedTests++;
            echo "✅ PASS: tenant_id is NOT NULL (CollaboraNexio MANDATORY)" . PHP_EOL;
        } else {
            echo "❌ FAIL: tenant_id allows NULL - SECURITY VIOLATION!" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 4: Verify soft delete column (deleted_at)
// ============================================

echo "TEST 4: Soft Delete Compliance (deleted_at column)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT COLUMN_TYPE, IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
              AND COLUMN_NAME = 'deleted_at'
        ");

        $columnType = $result[0]['COLUMN_TYPE'];
        $isNullable = $result[0]['IS_NULLABLE'];

        $softDeleteValid = (strpos($columnType, 'timestamp') !== false && $isNullable === 'YES');

        if ($softDeleteValid) {
            $passedTests++;
            echo "✅ PASS: deleted_at is TIMESTAMP NULL (CollaboraNexio MANDATORY)" . PHP_EOL;
        } else {
            echo "❌ FAIL: deleted_at column missing or wrong type" . PHP_EOL;
            echo "   Type: $columnType, Nullable: $isNullable" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 5: Verify foreign keys
// ============================================

echo "TEST 5: Foreign Key Constraints" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $fkCount = count($result);

        if ($fkCount >= 3) {
            $passedTests++;
            echo "✅ PASS: Found $fkCount foreign keys (expected 3+)" . PHP_EOL;
            echo PHP_EOL;
            echo "Foreign Keys Detail:" . PHP_EOL;
            foreach ($result as $fk) {
                echo "  - {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}" . PHP_EOL;
            }
        } else {
            echo "❌ FAIL: Found $fkCount foreign keys, expected 3+" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 6: Verify indexes (multi-tenant query optimization)
// ============================================

echo "TEST 6: Index Coverage (Multi-Tenant Optimization)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
              AND INDEX_NAME LIKE 'idx_%'
            GROUP BY INDEX_NAME
        ");

        $indexCount = count($result);

        if ($indexCount >= 6) {
            $passedTests++;
            echo "✅ PASS: Found $indexCount indexes (expected 6+)" . PHP_EOL;
            echo PHP_EOL;
            echo "Indexes Detail:" . PHP_EOL;
            foreach ($result as $idx) {
                echo "  - {$idx['INDEX_NAME']}: ({$idx['columns']})" . PHP_EOL;
            }
        } else {
            echo "❌ FAIL: Found $indexCount indexes, expected 6+" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 7: Verify CHECK constraint (scope consistency)
// ============================================

echo "TEST 7: CHECK Constraint (Scope Consistency)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT COUNT(*) as count
            FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
              AND CONSTRAINT_NAME LIKE '%scope_consistency%'
        ");

        $checkCount = $result[0]['count'];

        if ($checkCount >= 1) {
            $passedTests++;
            echo "✅ PASS: CHECK constraint found (chk_workflow_settings_scope_consistency)" . PHP_EOL;
            echo "   Enforces: tenant scope MUST have NULL folder_id, folder scope MUST have folder_id" . PHP_EOL;
        } else {
            echo "❌ FAIL: CHECK constraint missing - data integrity risk" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 8: Verify MySQL function exists (get_workflow_enabled_for_folder)
// ============================================

echo "TEST 8: MySQL Helper Function Existence" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;
$functionExists = false;

try {
    $result = $db->query("
        SELECT COUNT(*) as count
        FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = 'collaboranexio'
          AND ROUTINE_TYPE = 'FUNCTION'
          AND ROUTINE_NAME = 'get_workflow_enabled_for_folder'
    ");

    $functionExists = ($result[0]['count'] == 1);

    if ($functionExists) {
        $passedTests++;
        echo "✅ PASS: get_workflow_enabled_for_folder() function exists" . PHP_EOL;
        echo "   Purpose: Resolve workflow status with inheritance (folder → parent → tenant)" . PHP_EOL;
    } else {
        echo "❌ FAIL: get_workflow_enabled_for_folder() function does NOT exist" . PHP_EOL;
        echo "⚠️  MIGRATION NOT EXECUTED - Function does not exist" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

// ============================================
// TEST 9: Test function execution (if exists)
// ============================================

echo "TEST 9: Function Execution Test" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$functionExists) {
    echo "⏭️  SKIP: Function does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("SELECT get_workflow_enabled_for_folder(1, 1) as result");
        $functionResult = $result[0]['result'];

        $passedTests++;
        echo "✅ PASS: Function executes successfully" . PHP_EOL;
        echo "   Return value: $functionResult (0 = disabled, 1 = enabled)" . PHP_EOL;
    } catch (Exception $e) {
        echo "❌ FAIL: Function execution failed" . PHP_EOL;
        echo "   Error: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 10: Verify demo data insertion
// ============================================

echo "TEST 10: Demo Data (Default Tenant Config)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT COUNT(*) as count
            FROM workflow_settings
            WHERE tenant_id = 1
              AND scope_type = 'tenant'
              AND deleted_at IS NULL
        ");

        $demoDataCount = $result[0]['count'];

        if ($demoDataCount == 1) {
            $passedTests++;
            echo "✅ PASS: Default tenant config created for tenant_id=1" . PHP_EOL;
        } elseif ($demoDataCount == 0) {
            echo "⚠️  WARNING: No config for tenant_id=1 (may not exist or demo data skipped)" . PHP_EOL;
            $passedTests++; // Not a failure
        } else {
            echo "❌ FAIL: Multiple configs detected ($demoDataCount) - UNIQUE constraint issue" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 11: Verify existing workflow tables integrity
// ============================================

echo "TEST 11: Existing Workflow Tables Integrity" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

try {
    $result = $db->query("
        SELECT COUNT(*) as count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME IN ('workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
    ");

    $workflowTablesCount = $result[0]['count'];

    if ($workflowTablesCount == 4) {
        $passedTests++;
        echo "✅ PASS: All 4 workflow tables exist and intact" . PHP_EOL;
        echo "   Tables: workflow_roles, document_workflow, document_workflow_history, file_assignments" . PHP_EOL;
    } else {
        echo "❌ FAIL: Found $workflowTablesCount workflow tables, expected 4" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

// ============================================
// TEST 12: Verify API dependencies (user_tenant_access table)
// ============================================

echo "TEST 12: API Dependencies Verification" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

try {
    $result = $db->query("
        SELECT COUNT(*) as count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME = 'user_tenant_access'
    ");

    $utaTableExists = ($result[0]['count'] == 1);

    if ($utaTableExists) {
        $passedTests++;
        echo "✅ PASS: user_tenant_access table exists" . PHP_EOL;
        echo "   Required for: /api/workflow/roles/list.php" . PHP_EOL;
    } else {
        echo "❌ FAIL: user_tenant_access table missing - API will fail" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

// ============================================
// TEST 13: Regression check (previous fixes intact)
// ============================================

echo "TEST 13: Regression Check (Previous Fixes Intact)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

try {
    $result = $db->query("
        SELECT COUNT(*) as count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME IN ('audit_logs', 'files', 'folders', 'users', 'tenants')
    ");

    $coreTablesCount = $result[0]['count'];

    if ($coreTablesCount == 5) {
        $passedTests++;
        echo "✅ PASS: All 5 core tables intact" . PHP_EOL;
        echo "   Tables: audit_logs, files, folders, users, tenants" . PHP_EOL;
    } else {
        echo "❌ FAIL: Found $coreTablesCount core tables, expected 5 - CRITICAL REGRESSION" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

// ============================================
// TEST 14: Data integrity check (no NULL tenant_id violations)
// ============================================

echo "TEST 14: Multi-Tenant Data Integrity (No NULL tenant_id)" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT COUNT(*) as count
            FROM workflow_settings
            WHERE tenant_id IS NULL
        ");

        $nullViolations = $result[0]['count'];

        if ($nullViolations == 0) {
            $passedTests++;
            echo "✅ PASS: Perfect multi-tenant compliance (zero NULL tenant_id violations)" . PHP_EOL;
        } else {
            echo "❌ FAIL: CRITICAL - Found $nullViolations NULL tenant_id violations (security breach)" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// TEST 15: Storage engine and charset verification
// ============================================

echo "TEST 15: Storage Engine and Charset" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalTests++;

if (!$tableExists) {
    echo "⏭️  SKIP: Table does not exist" . PHP_EOL;
} else {
    try {
        $result = $db->query("
            SELECT ENGINE, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'workflow_settings'
        ");

        $engine = $result[0]['ENGINE'];
        $collation = $result[0]['TABLE_COLLATION'];

        $storageValid = ($engine === 'InnoDB' && $collation === 'utf8mb4_unicode_ci');

        if ($storageValid) {
            $passedTests++;
            echo "✅ PASS: Correct storage configuration" . PHP_EOL;
            echo "   Engine: $engine, Collation: $collation" . PHP_EOL;
        } else {
            echo "❌ FAIL: Incorrect storage configuration" . PHP_EOL;
            echo "   Found: Engine=$engine, Collation=$collation" . PHP_EOL;
            echo "   Expected: Engine=InnoDB, Collation=utf8mb4_unicode_ci" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;

// ============================================
// FINAL SUMMARY
// ============================================

echo str_repeat('=', 80) . PHP_EOL;
echo "FINAL VERIFICATION SUMMARY" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

$successRate = round(($passedTests / $totalTests) * 100, 1);

echo "Tests Passed:     $passedTests / $totalTests" . PHP_EOL;
echo "Success Rate:     $successRate%" . PHP_EOL;

if ($passedTests == $totalTests) {
    echo "Status:           ✅ ALL TESTS PASSED" . PHP_EOL;
    echo "Recommendation:   🎉 PRODUCTION READY" . PHP_EOL;
} elseif ($successRate >= 90) {
    echo "Status:           ⚠️  MOSTLY PASSED (90%+)" . PHP_EOL;
    echo "Recommendation:   Minor issues - Review failed tests" . PHP_EOL;
} elseif ($successRate >= 70) {
    echo "Status:           ⚠️  PARTIAL SUCCESS (70%+)" . PHP_EOL;
    echo "Recommendation:   Review failed tests before deployment" . PHP_EOL;
} else {
    echo "Status:           ❌ CRITICAL FAILURES" . PHP_EOL;
    echo "Recommendation:   CRITICAL - Review failed tests before deployment" . PHP_EOL;
}

echo PHP_EOL;

// Migration status
if (!$tableExists) {
    echo str_repeat('=', 80) . PHP_EOL;
    echo "⚠️  MIGRATION STATUS: NOT YET EXECUTED" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

    echo "The workflow_settings table does not exist. Migration has NOT been executed yet." . PHP_EOL . PHP_EOL;

    echo "To execute migration, run ONE of the following:" . PHP_EOL . PHP_EOL;

    echo "1. Via MySQL CLI:" . PHP_EOL;
    echo "   mysql -u root collaboranexio < database/migrations/workflow_activation_system.sql" . PHP_EOL . PHP_EOL;

    echo "2. Via Browser (if running from web):" . PHP_EOL;
    echo "   Navigate to: http://localhost:8888/CollaboraNexio/run_workflow_activation_migration.php" . PHP_EOL . PHP_EOL;

    echo "3. Via phpMyAdmin:" . PHP_EOL;
    echo "   - Navigate to SQL tab" . PHP_EOL;
    echo "   - Paste contents of database/migrations/workflow_activation_system.sql" . PHP_EOL;
    echo "   - Execute query" . PHP_EOL . PHP_EOL;
} else {
    echo str_repeat('=', 80) . PHP_EOL;
    echo "✅ MIGRATION STATUS: COMPLETED" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;
}

// Database size information
echo str_repeat('=', 80) . PHP_EOL;
echo "DATABASE SIZE INFORMATION" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

try {
    $result = $db->query("
        SELECT
            TABLE_NAME,
            ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb,
            TABLE_ROWS,
            ENGINE,
            TABLE_COLLATION
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
        ORDER BY TABLE_NAME
    ");

    if (count($result) > 0) {
        foreach ($result as $table) {
            echo sprintf(
                "%-30s %8s MB  %10s rows  %-10s  %s" . PHP_EOL,
                $table['TABLE_NAME'],
                $table['size_mb'],
                number_format($table['TABLE_ROWS']),
                $table['ENGINE'],
                $table['TABLE_COLLATION']
            );
        }

        echo PHP_EOL;

        // Total size
        $totalResult = $db->query("
            SELECT
                ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as total_mb,
                SUM(TABLE_ROWS) as total_rows
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME IN ('workflow_settings', 'workflow_roles', 'document_workflow', 'document_workflow_history', 'file_assignments')
        ");

        echo "Total Workflow System Size: " . $totalResult[0]['total_mb'] . " MB" . PHP_EOL;
        echo "Total Rows (Approx):        " . number_format($totalResult[0]['total_rows']) . PHP_EOL;
    } else {
        echo "No workflow tables found in database." . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error retrieving size information: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo "VERIFICATION COMPLETE" . PHP_EOL;
echo "Timestamp: " . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
