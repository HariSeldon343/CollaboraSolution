<?php
/**
 * Task Management Schema Integrity Check
 * Verifica completa dello schema database per identificare la causa dell'errore 500
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance();

echo "========================================\n";
echo "TASK MANAGEMENT SCHEMA INTEGRITY CHECK\n";
echo "========================================\n\n";

// 1. Check if tables exist
echo "1. CHECKING TABLE EXISTENCE\n";
echo "----------------------------\n";

$tables = ['tasks', 'task_assignments', 'task_comments', 'task_history'];
$existingTables = [];

foreach ($tables as $table) {
    try {
        $result = $db->fetchOne("SHOW TABLES LIKE '$table'");
        if ($result) {
            $existingTables[] = $table;
            echo "✓ Table '$table' EXISTS\n";
        } else {
            echo "✗ Table '$table' MISSING\n";
        }
    } catch (Exception $e) {
        echo "✗ Table '$table' ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: " . count($existingTables) . "/4 tables exist\n\n";

if (count($existingTables) === 0) {
    echo "ERROR: No task tables found. Migration not executed.\n";
    exit(1);
}

// 2. Check table structure for each existing table
echo "\n2. CHECKING TABLE STRUCTURE\n";
echo "----------------------------\n";

foreach ($existingTables as $table) {
    echo "\nTable: $table\n";
    echo str_repeat("-", 50) . "\n";

    try {
        $columns = $db->fetchAll("DESCRIBE $table");

        // Check for critical columns
        $criticalColumns = [
            'id' => false,
            'tenant_id' => false,
            'deleted_at' => false,
            'created_at' => false,
            'updated_at' => false
        ];

        $allColumns = [];
        foreach ($columns as $col) {
            $colName = $col['Field'];
            $allColumns[] = $colName;

            if (isset($criticalColumns[$colName])) {
                $criticalColumns[$colName] = true;
            }
        }

        // Report critical columns
        foreach ($criticalColumns as $colName => $exists) {
            if ($exists) {
                echo "  ✓ $colName\n";
            } else {
                echo "  ✗ $colName MISSING\n";
            }
        }

        echo "\n  Total columns: " . count($allColumns) . "\n";
        echo "  Columns: " . implode(', ', $allColumns) . "\n";

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 3. Check indexes
echo "\n\n3. CHECKING INDEXES\n";
echo "-------------------\n";

foreach ($existingTables as $table) {
    echo "\nTable: $table\n";

    try {
        $indexes = $db->fetchAll("SHOW INDEX FROM $table");

        $indexNames = [];
        foreach ($indexes as $idx) {
            $indexNames[] = $idx['Key_name'];
        }

        $uniqueIndexes = array_unique($indexNames);
        echo "  Total indexes: " . count($uniqueIndexes) . "\n";

        // Check for critical composite indexes
        $hasCompositeIndex = false;
        foreach ($uniqueIndexes as $idxName) {
            if (strpos($idxName, 'tenant') !== false) {
                echo "  ✓ Tenant index found: $idxName\n";
                $hasCompositeIndex = true;
            }
        }

        if (!$hasCompositeIndex) {
            echo "  ⚠ No tenant composite index found\n";
        }

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 4. Check foreign keys
echo "\n\n4. CHECKING FOREIGN KEYS\n";
echo "------------------------\n";

foreach ($existingTables as $table) {
    echo "\nTable: $table\n";

    try {
        $fks = $db->fetchAll("
            SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = '$table'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        if (count($fks) > 0) {
            foreach ($fks as $fk) {
                echo "  ✓ {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']})\n";
            }
        } else {
            echo "  ⚠ No foreign keys found\n";
        }

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 5. Check data integrity
echo "\n\n5. CHECKING DATA INTEGRITY\n";
echo "--------------------------\n";

if (in_array('tasks', $existingTables)) {
    try {
        $totalTasks = $db->fetchOne("SELECT COUNT(*) as cnt FROM tasks")['cnt'] ?? 0;
        $activeTasks = $db->fetchOne("SELECT COUNT(*) as cnt FROM tasks WHERE deleted_at IS NULL")['cnt'] ?? 0;
        $deletedTasks = $db->fetchOne("SELECT COUNT(*) as cnt FROM tasks WHERE deleted_at IS NOT NULL")['cnt'] ?? 0;

        echo "tasks:\n";
        echo "  Total: $totalTasks\n";
        echo "  Active: $activeTasks\n";
        echo "  Deleted: $deletedTasks\n";

        // Check for orphaned records (missing tenant_id)
        $orphanedRecords = $db->fetchAll("
            SELECT t.id, t.title, t.tenant_id
            FROM tasks t
            LEFT JOIN tenants tn ON t.tenant_id = tn.id
            WHERE tn.id IS NULL
            LIMIT 5
        ");

        if (count($orphanedRecords) > 0) {
            echo "\n  ✗ ORPHANED RECORDS FOUND (missing tenant):\n";
            foreach ($orphanedRecords as $rec) {
                echo "    - Task ID {$rec['id']}: tenant_id={$rec['tenant_id']} (tenant doesn't exist)\n";
            }
        } else {
            echo "  ✓ No orphaned records\n";
        }

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 6. Test a simple query (like the API would do)
echo "\n\n6. SIMULATING API QUERY\n";
echo "-----------------------\n";

if (in_array('tasks', $existingTables)) {
    try {
        // Simulate what list.php would do
        $testTenantId = 1;

        $tasks = $db->fetchAll("
            SELECT
                t.*,
                u.name as creator_name
            FROM tasks t
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.tenant_id = ?
              AND t.deleted_at IS NULL
            ORDER BY t.created_at DESC
            LIMIT 10
        ", [$testTenantId]);

        echo "✓ Query executed successfully\n";
        echo "  Result count: " . count($tasks) . " tasks\n";

        if (count($tasks) > 0) {
            echo "\n  Sample task:\n";
            $sample = $tasks[0];
            echo "    ID: {$sample['id']}\n";
            echo "    Title: {$sample['title']}\n";
            echo "    Status: {$sample['status']}\n";
            echo "    Created by: {$sample['creator_name']}\n";
        }

    } catch (Exception $e) {
        echo "✗ QUERY FAILED: " . $e->getMessage() . "\n";
        echo "\nThis is likely the cause of the 500 error!\n";
    }
}

// 7. Check for schema inconsistencies
echo "\n\n7. SCHEMA INCONSISTENCIES CHECK\n";
echo "-------------------------------\n";

$issues = [];

// Check if tasks table has all expected columns
if (in_array('tasks', $existingTables)) {
    try {
        $columns = $db->fetchAll("DESCRIBE tasks");
        $columnNames = array_column($columns, 'Field');

        $expectedColumns = [
            'id', 'tenant_id', 'title', 'description', 'parent_id',
            'created_by', 'assigned_to', 'project_id', 'status', 'priority',
            'due_date', 'start_date', 'estimated_hours', 'actual_hours',
            'progress_percentage', 'tags', 'attachments', 'completed_at',
            'completed_by', 'deleted_at', 'created_at', 'updated_at'
        ];

        $missingColumns = array_diff($expectedColumns, $columnNames);

        if (count($missingColumns) > 0) {
            $issues[] = "tasks table missing columns: " . implode(', ', $missingColumns);
            echo "✗ Missing columns in tasks: " . implode(', ', $missingColumns) . "\n";
        } else {
            echo "✓ All expected columns present in tasks table\n";
        }

    } catch (Exception $e) {
        $issues[] = "Could not verify tasks columns: " . $e->getMessage();
    }
}

// Final summary
echo "\n\n========================================\n";
echo "SUMMARY\n";
echo "========================================\n";

if (count($existingTables) < 4) {
    echo "✗ CRITICAL: Not all tables created (" . count($existingTables) . "/4)\n";
    echo "  Missing tables: " . implode(', ', array_diff($tables, $existingTables)) . "\n";
    echo "\n  ACTION REQUIRED: Run migration script:\n";
    echo "  php run_simple_task_migration.php\n";
}

if (count($issues) > 0) {
    echo "\n✗ ISSUES FOUND:\n";
    foreach ($issues as $issue) {
        echo "  - $issue\n";
    }
} else {
    echo "\n✓ No critical issues found in schema\n";
}

echo "\n";
echo "If API is still returning 500 error, check:\n";
echo "1. PHP error logs: /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log\n";
echo "2. Apache error logs: C:\\xampp\\apache\\logs\\error.log\n";
echo "3. Database error logs: C:\\xampp\\mysql\\data\\[hostname].err\n";

echo "\nCheck completed at " . date('Y-m-d H:i:s') . "\n";
