<?php
/**
 * Database Schema Verification Script
 * Comprehensive check of database structure and data integrity
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';

echo "=== COLLABORANEXIO SYSTEM VERIFICATION ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

$results = [];

// Test 1: Check tenants table structure
echo "1. TENANTS TABLE STRUCTURE\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("DESCRIBE tenants");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requiredColumns = ['id', 'denominazione', 'partita_iva', 'codice_fiscale', 'status', 'manager_id', 'deleted_at', 'created_at', 'updated_at'];
    $foundColumns = array_column($columns, 'Field');

    $results['tenants_structure'] = [
        'status' => 'PASS',
        'columns' => count($foundColumns),
        'details' => []
    ];

    foreach ($requiredColumns as $col) {
        if (in_array($col, $foundColumns)) {
            echo "✅ Column '{$col}' exists\n";
        } else {
            echo "❌ Column '{$col}' MISSING\n";
            $results['tenants_structure']['status'] = 'FAIL';
            $results['tenants_structure']['details'][] = "Missing column: {$col}";
        }
    }

    // Display full structure
    echo "\nFull structure:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']} {$col['Extra']}\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['tenants_structure'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Test 2: Check users table structure
echo "2. USERS TABLE STRUCTURE\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requiredColumns = ['id', 'name', 'email', 'password', 'role', 'tenant_id', 'deleted_at', 'created_at', 'updated_at'];
    $foundColumns = array_column($columns, 'Field');

    $results['users_structure'] = [
        'status' => 'PASS',
        'columns' => count($foundColumns),
        'details' => []
    ];

    foreach ($requiredColumns as $col) {
        if (in_array($col, $foundColumns)) {
            echo "✅ Column '{$col}' exists\n";
        } else {
            echo "❌ Column '{$col}' MISSING\n";
            $results['users_structure']['status'] = 'FAIL';
            $results['users_structure']['details'][] = "Missing column: {$col}";
        }
    }

    // Check for legacy columns
    $legacyColumns = ['first_name', 'last_name'];
    foreach ($legacyColumns as $col) {
        if (in_array($col, $foundColumns)) {
            echo "⚠️  Legacy column '{$col}' still exists (should be removed)\n";
            $results['users_structure']['details'][] = "Legacy column found: {$col}";
        }
    }

    echo "\nFull structure:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']} {$col['Extra']}\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['users_structure'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Test 3: Check Demo Company data
echo "3. DEMO COMPANY DATA INTEGRITY\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("SELECT id, denominazione, partita_iva, codice_fiscale, status, manager_id, deleted_at FROM tenants WHERE id = 1");
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant) {
        echo "✅ Demo Company found (ID: {$tenant['id']})\n";
        echo "  Denominazione: {$tenant['denominazione']}\n";
        echo "  P.IVA: {$tenant['partita_iva']}\n";
        echo "  C.F.: {$tenant['codice_fiscale']}\n";
        echo "  Status: {$tenant['status']}\n";
        echo "  Manager ID: {$tenant['manager_id']}\n";
        echo "  Deleted: " . ($tenant['deleted_at'] ? 'YES ❌' : 'NO ✅') . "\n";

        $isComplete = !empty($tenant['denominazione']) &&
                      !empty($tenant['partita_iva']) &&
                      !empty($tenant['codice_fiscale']) &&
                      !empty($tenant['manager_id']) &&
                      is_null($tenant['deleted_at']);

        $results['demo_company'] = [
            'status' => $isComplete ? 'PASS' : 'FAIL',
            'data' => $tenant
        ];

        if ($isComplete) {
            echo "✅ Demo Company data is COMPLETE\n";
        } else {
            echo "❌ Demo Company data is INCOMPLETE\n";
        }
    } else {
        echo "❌ Demo Company NOT FOUND\n";
        $results['demo_company'] = ['status' => 'FAIL', 'error' => 'Tenant ID 1 not found'];
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['demo_company'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Test 4: Check users data
echo "4. USERS DATA CHECK\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("SELECT id, name, email, role, tenant_id, deleted_at FROM users ORDER BY id LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($users) . " users\n";

    foreach ($users as $user) {
        $deleted = $user['deleted_at'] ? '🗑️  DELETED' : '✅ ACTIVE';
        echo "  [{$user['id']}] {$user['name']} ({$user['email']}) - {$user['role']} - Tenant: {$user['tenant_id']} {$deleted}\n";
    }

    // Count by role
    $stmt = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY role");
    $roleCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\nUser counts by role:\n";
    foreach ($roleCounts as $roleCount) {
        echo "  {$roleCount['role']}: {$roleCount['count']}\n";
    }

    $results['users_data'] = [
        'status' => 'PASS',
        'total_users' => count($users),
        'role_counts' => $roleCounts
    ];

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['users_data'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Test 5: Check indexes
echo "5. DATABASE INDEXES CHECK\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("SHOW INDEXES FROM tenants WHERE Key_name LIKE 'idx_%'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($indexes) > 0) {
        echo "✅ Found " . count($indexes) . " custom indexes on tenants table\n";
        foreach ($indexes as $idx) {
            echo "  - {$idx['Key_name']} on {$idx['Column_name']}\n";
        }
    } else {
        echo "⚠️  No custom indexes found on tenants table\n";
    }

    $results['indexes'] = [
        'status' => count($indexes) > 0 ? 'PASS' : 'WARNING',
        'count' => count($indexes)
    ];

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['indexes'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Test 6: Check files table schema (common drift issue)
echo "6. FILES TABLE SCHEMA CHECK\n";
echo str_repeat("-", 50) . "\n";
try {
    $stmt = $conn->query("DESCRIBE files");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $foundColumns = array_column($columns, 'Field');

    $criticalColumns = [
        'file_size' => 'Should be file_size (NOT size_bytes)',
        'file_path' => 'Should be file_path (NOT storage_path)',
        'uploaded_by' => 'Should be uploaded_by (NOT owner_id)'
    ];

    $results['files_schema'] = [
        'status' => 'PASS',
        'details' => []
    ];

    foreach ($criticalColumns as $col => $note) {
        if (in_array($col, $foundColumns)) {
            echo "✅ Column '{$col}' exists - {$note}\n";
        } else {
            echo "❌ Column '{$col}' MISSING - {$note}\n";
            $results['files_schema']['status'] = 'FAIL';
            $results['files_schema']['details'][] = "Missing: {$col}";
        }
    }

    // Check for wrong column names
    $wrongColumns = ['size_bytes', 'storage_path', 'owner_id'];
    foreach ($wrongColumns as $col) {
        if (in_array($col, $foundColumns)) {
            echo "⚠️  Found '{$col}' - This might indicate schema drift\n";
            $results['files_schema']['details'][] = "Found legacy column: {$col}";
        }
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    $results['files_schema'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

echo "\n";

// Summary
echo "=== SUMMARY ===\n";
echo str_repeat("=", 50) . "\n";

$totalTests = count($results);
$passedTests = count(array_filter($results, function($r) { return $r['status'] === 'PASS'; }));
$failedTests = count(array_filter($results, function($r) { return $r['status'] === 'FAIL'; }));
$warningTests = count(array_filter($results, function($r) { return $r['status'] === 'WARNING'; }));

echo "Total Tests: {$totalTests}\n";
echo "Passed: {$passedTests} ✅\n";
echo "Failed: {$failedTests} ❌\n";
echo "Warnings: {$warningTests} ⚠️\n";
echo "Success Rate: " . round(($passedTests / $totalTests) * 100, 2) . "%\n";

echo "\nDetailed Results:\n";
foreach ($results as $test => $result) {
    $icon = $result['status'] === 'PASS' ? '✅' : ($result['status'] === 'WARNING' ? '⚠️' : '❌');
    echo "  {$icon} {$test}: {$result['status']}\n";
    if (!empty($result['details'])) {
        foreach ($result['details'] as $detail) {
            echo "     - {$detail}\n";
        }
    }
    if (!empty($result['error'])) {
        echo "     Error: {$result['error']}\n";
    }
}

echo "\n=== END OF VERIFICATION ===\n";
