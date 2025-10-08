<?php
/**
 * COMPREHENSIVE SYSTEM-WIDE VERIFICATION SCRIPT
 * Tests all critical components of CollaboraNexio after recent fixes
 *
 * Run from command line: php comprehensive_system_verification.php
 * Or via browser: http://localhost:8888/CollaboraNexio/comprehensive_system_verification.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=" . str_repeat("=", 78) . "\n";
echo "  COLLABORANEXIO - COMPREHENSIVE SYSTEM VERIFICATION REPORT\n";
echo "=" . str_repeat("=", 78) . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Environment: " . (PRODUCTION_MODE ? 'PRODUCTION' : 'DEVELOPMENT') . "\n";
echo "Base URL: " . BASE_URL . "\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// Test tracking
$testResults = [];
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$warningTests = 0;

/**
 * Helper function to run a test
 */
function runTest($name, $callable, &$testResults, &$totalTests, &$passedTests, &$failedTests, &$warningTests) {
    echo "\n" . str_repeat("-", 80) . "\n";
    echo "TEST: {$name}\n";
    echo str_repeat("-", 80) . "\n";

    $totalTests++;

    try {
        $result = $callable();

        if ($result['status'] === 'PASS') {
            echo "✅ PASSED\n";
            $passedTests++;
        } elseif ($result['status'] === 'WARNING') {
            echo "⚠️  WARNING\n";
            $warningTests++;
        } else {
            echo "❌ FAILED\n";
            $failedTests++;
        }

        if (!empty($result['details'])) {
            foreach ($result['details'] as $detail) {
                echo "  - {$detail}\n";
            }
        }

        if (!empty($result['error'])) {
            echo "  ERROR: {$result['error']}\n";
        }

        $testResults[$name] = $result;

    } catch (Exception $e) {
        echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
        $failedTests++;
        $testResults[$name] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
}

// TEST 1: Configuration Files
runTest("Configuration Files Integrity", function() {
    $details = [];
    $issues = [];

    // Check config.php
    if (!file_exists(__DIR__ . '/config.php')) {
        $issues[] = "config.php missing";
    } else {
        $details[] = "config.php exists";
    }

    // Check includes directory
    $requiredIncludes = ['db.php', 'auth_simple.php', 'api_auth.php', 'api_response.php', 'session_init.php', 'favicon.php'];
    foreach ($requiredIncludes as $file) {
        if (!file_exists(__DIR__ . "/includes/{$file}")) {
            $issues[] = "includes/{$file} missing";
        } else {
            $details[] = "includes/{$file} exists";
        }
    }

    // Check critical constants
    $requiredConstants = ['DB_NAME', 'DB_HOST', 'DB_USER', 'BASE_URL', 'SESSION_LIFETIME'];
    foreach ($requiredConstants as $const) {
        if (!defined($const)) {
            $issues[] = "Constant {$const} not defined";
        }
    }

    return [
        'status' => empty($issues) ? 'PASS' : 'FAIL',
        'details' => $details,
        'issues' => $issues
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 2: Database Connection
runTest("Database Connection", function() use ($conn) {
    if (!$conn) {
        return ['status' => 'FAIL', 'error' => 'No database connection'];
    }

    $stmt = $conn->query("SELECT DATABASE() as db_name");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'status' => 'PASS',
        'details' => ["Connected to database: {$result['db_name']}"]
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 3: Tenants Table Schema
runTest("Tenants Table Schema", function() use ($conn) {
    $stmt = $conn->query("DESCRIBE tenants");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');

    $requiredColumns = ['id', 'denominazione', 'partita_iva', 'codice_fiscale', 'status', 'manager_id', 'deleted_at'];
    $missing = array_diff($requiredColumns, $columnNames);

    if (!empty($missing)) {
        return ['status' => 'FAIL', 'error' => 'Missing columns: ' . implode(', ', $missing)];
    }

    return [
        'status' => 'PASS',
        'details' => ["All required columns present (" . count($columnNames) . " total)"]
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 4: Users Table Schema
runTest("Users Table Schema", function() use ($conn) {
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');

    $requiredColumns = ['id', 'name', 'email', 'password_hash', 'role', 'tenant_id', 'deleted_at'];
    $missing = array_diff($requiredColumns, $columnNames);

    $legacyColumns = ['first_name', 'last_name'];
    $foundLegacy = array_intersect($legacyColumns, $columnNames);

    $details = ["All required columns present"];

    if (!empty($foundLegacy)) {
        $details[] = "WARNING: Legacy columns found: " . implode(', ', $foundLegacy);
    }

    if (!empty($missing)) {
        return ['status' => 'FAIL', 'error' => 'Missing columns: ' . implode(', ', $missing)];
    }

    return [
        'status' => empty($foundLegacy) ? 'PASS' : 'WARNING',
        'details' => $details
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 5: Files Table Schema (Schema Drift Check)
runTest("Files Table Schema (Schema Drift)", function() use ($conn) {
    $stmt = $conn->query("DESCRIBE files");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');

    $correctColumns = ['file_size', 'file_path', 'uploaded_by'];
    $wrongColumns = ['size_bytes', 'storage_path', 'owner_id'];

    $details = [];
    $issues = [];

    foreach ($correctColumns as $col) {
        if (in_array($col, $columnNames)) {
            $details[] = "✓ Correct column: {$col}";
        } else {
            $issues[] = "✗ Missing correct column: {$col}";
        }
    }

    foreach ($wrongColumns as $col) {
        if (in_array($col, $columnNames)) {
            $issues[] = "✗ Found legacy column: {$col}";
        }
    }

    return [
        'status' => empty($issues) ? 'PASS' : 'FAIL',
        'details' => $details,
        'issues' => $issues
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 6: Demo Company Data
runTest("Demo Company Data Integrity", function() use ($db) {
    $tenant = $db->fetchOne(
        "SELECT id, denominazione, partita_iva, codice_fiscale, status, manager_id, deleted_at FROM tenants WHERE id = 1"
    );

    if (!$tenant) {
        return ['status' => 'FAIL', 'error' => 'Demo Company (ID=1) not found'];
    }

    $issues = [];
    $details = [];

    $details[] = "Denominazione: {$tenant['denominazione']}";
    $details[] = "P.IVA: {$tenant['partita_iva']}";
    $details[] = "C.F.: {$tenant['codice_fiscale']}";
    $details[] = "Manager ID: {$tenant['manager_id']}";

    if (empty($tenant['denominazione'])) $issues[] = "Missing denominazione";
    if (empty($tenant['partita_iva'])) $issues[] = "Missing partita_iva";
    if (empty($tenant['codice_fiscale'])) $issues[] = "Missing codice_fiscale";
    if (empty($tenant['manager_id'])) $issues[] = "Missing manager_id";
    if ($tenant['deleted_at']) $issues[] = "Tenant is soft-deleted";

    return [
        'status' => empty($issues) ? 'PASS' : 'FAIL',
        'details' => $details,
        'issues' => $issues
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 7: API Endpoints Existence
runTest("Tenant API Endpoints", function() {
    $baseDir = __DIR__ . '/api/tenants/';
    $requiredApis = ['list.php', 'create.php', 'update.php', 'delete.php', 'get.php'];

    $details = [];
    $missing = [];

    foreach ($requiredApis as $api) {
        if (file_exists($baseDir . $api)) {
            $details[] = "✓ {$api} exists";
        } else {
            $missing[] = "✗ {$api} missing";
        }
    }

    return [
        'status' => empty($missing) ? 'PASS' : 'FAIL',
        'details' => $details,
        'missing' => $missing
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 8: User API Endpoints
runTest("User API Endpoints", function() {
    $baseDir = __DIR__ . '/api/users/';
    $requiredApis = ['list.php', 'create_simple.php', 'update_v2.php', 'delete.php'];

    $details = [];
    $missing = [];

    foreach ($requiredApis as $api) {
        if (file_exists($baseDir . $api)) {
            $details[] = "✓ {$api} exists";
        } else {
            $missing[] = "✗ {$api} missing";
        }
    }

    return [
        'status' => empty($missing) ? 'PASS' : 'FAIL',
        'details' => $details,
        'missing' => $missing
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 9: API Authentication Pattern
runTest("API Authentication Pattern Check", function() {
    $testFile = __DIR__ . '/api/tenants/list.php';
    $content = file_get_contents($testFile);

    $patterns = [
        'api_auth.php inclusion' => "require_once '../../includes/api_auth.php'",
        'initializeApiEnvironment' => 'initializeApiEnvironment()',
        'verifyApiAuthentication' => 'verifyApiAuthentication()',
        'getApiUserInfo' => 'getApiUserInfo()',
        'Soft-delete filter' => 'deleted_at IS NULL'
    ];

    $details = [];
    $missing = [];

    foreach ($patterns as $name => $pattern) {
        if (strpos($content, $pattern) !== false) {
            $details[] = "✓ Uses {$name}";
        } else {
            $missing[] = "✗ Missing {$name}";
        }
    }

    return [
        'status' => empty($missing) ? 'PASS' : 'WARNING',
        'details' => $details,
        'missing' => $missing
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 10: Logo and Branding Files
runTest("Logo and Branding Files", function() {
    $baseDir = __DIR__ . '/assets/images/';
    $requiredFiles = [
        'logo.png',
        'logo.svg',
        'favicon.svg',
        'favicon-16x16.png',
        'favicon-32x32.png',
        'apple-touch-icon.png'
    ];

    $details = [];
    $missing = [];
    $today = date('Y-m-d');

    foreach ($requiredFiles as $file) {
        $fullPath = $baseDir . $file;
        if (file_exists($fullPath)) {
            $size = filesize($fullPath);
            $mtime = date('Y-m-d', filemtime($fullPath));
            $isRecent = ($mtime === $today);

            $details[] = "✓ {$file} ({$size} bytes, modified: {$mtime}" . ($isRecent ? " ✓ TODAY" : "") . ")";
        } else {
            $missing[] = "✗ {$file} missing";
        }
    }

    return [
        'status' => empty($missing) ? 'PASS' : 'FAIL',
        'details' => $details,
        'missing' => $missing
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 11: Frontend Pages Existence
runTest("Frontend Pages Existence", function() {
    $requiredPages = [
        'index.php' => 'Login page',
        'dashboard.php' => 'Dashboard',
        'aziende.php' => 'Companies management',
        'utenti.php' => 'Users management',
        'files.php' => 'Files management',
        'progetti.php' => 'Projects management',
        'tasks.php' => 'Tasks management',
        'calendar.php' => 'Calendar',
        'chat.php' => 'Chat'
    ];

    $details = [];
    $missing = [];

    foreach ($requiredPages as $file => $desc) {
        if (file_exists(__DIR__ . '/' . $file)) {
            $details[] = "✓ {$file} ({$desc})";
        } else {
            $missing[] = "✗ {$file} ({$desc}) missing";
        }
    }

    return [
        'status' => empty($missing) ? 'PASS' : 'WARNING',
        'details' => $details,
        'missing' => $missing
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 12: Soft-Delete Implementation
runTest("Soft-Delete Implementation Check", function() use ($db) {
    // Check that deleted_at column exists in key tables
    $tables = ['users', 'tenants', 'files', 'projects'];
    $details = [];
    $issues = [];

    foreach ($tables as $table) {
        try {
            $columns = $db->fetchAll("DESCRIBE {$table}");
            $columnNames = array_column($columns, 'Field');

            if (in_array('deleted_at', $columnNames)) {
                $details[] = "✓ {$table} has deleted_at column";

                // Check if there are any soft-deleted records
                $deletedCount = $db->count($table, ['deleted_at' => ['!=', null]]);
                if ($deletedCount > 0) {
                    $details[] = "  → {$deletedCount} soft-deleted records in {$table}";
                }
            } else {
                $issues[] = "✗ {$table} missing deleted_at column";
            }
        } catch (Exception $e) {
            $issues[] = "✗ Could not check {$table}: " . $e->getMessage();
        }
    }

    return [
        'status' => empty($issues) ? 'PASS' : 'FAIL',
        'details' => $details,
        'issues' => $issues
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 13: User Data Integrity
runTest("User Data Integrity", function() use ($db) {
    $users = $db->fetchAll("SELECT id, name, email, role, tenant_id, deleted_at FROM users WHERE deleted_at IS NULL");

    $details = [];
    $details[] = "Total active users: " . count($users);

    // Count by role
    $roleCounts = [];
    foreach ($users as $user) {
        $role = $user['role'];
        $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
    }

    foreach ($roleCounts as $role => $count) {
        $details[] = "  {$role}: {$count}";
    }

    // Check for users without email or name
    $invalidUsers = array_filter($users, function($u) {
        return empty($u['email']) || empty($u['name']);
    });

    if (!empty($invalidUsers)) {
        return [
            'status' => 'WARNING',
            'details' => $details,
            'warning' => count($invalidUsers) . " users have missing email or name"
        ];
    }

    return [
        'status' => 'PASS',
        'details' => $details
    ];
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// TEST 14: Database Indexes
runTest("Database Indexes Check", function() use ($conn) {
    $stmt = $conn->query("SHOW INDEXES FROM tenants WHERE Key_name LIKE 'idx_%'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $details = [];

    if (count($indexes) > 0) {
        $details[] = "Found " . count($indexes) . " custom indexes on tenants table";

        $indexNames = [];
        foreach ($indexes as $idx) {
            $indexNames[$idx['Key_name']] = true;
        }

        foreach (array_keys($indexNames) as $name) {
            $details[] = "  ✓ {$name}";
        }

        return ['status' => 'PASS', 'details' => $details];
    } else {
        return [
            'status' => 'WARNING',
            'details' => ['No custom indexes found on tenants table'],
            'warning' => 'Consider adding indexes for performance'
        ];
    }
}, $testResults, $totalTests, $passedTests, $failedTests, $warningTests);

// SUMMARY REPORT
echo "\n\n";
echo "=" . str_repeat("=", 78) . "\n";
echo "  VERIFICATION SUMMARY\n";
echo "=" . str_repeat("=", 78) . "\n\n";

echo "Total Tests Run: {$totalTests}\n";
echo "Passed:          {$passedTests} ✅\n";
echo "Failed:          {$failedTests} ❌\n";
echo "Warnings:        {$warningTests} ⚠️\n";
echo "Success Rate:    " . round(($passedTests / $totalTests) * 100, 2) . "%\n\n";

// Overall health status
$healthScore = ($passedTests + ($warningTests * 0.5)) / $totalTests * 100;

if ($healthScore >= 95) {
    $healthStatus = "EXCELLENT ✅✅✅";
    $healthColor = "\033[32m"; // Green
} elseif ($healthScore >= 85) {
    $healthStatus = "GOOD ✅";
    $healthColor = "\033[32m"; // Green
} elseif ($healthScore >= 70) {
    $healthStatus = "FAIR ⚠️";
    $healthColor = "\033[33m"; // Yellow
} else {
    $healthStatus = "POOR ❌";
    $healthColor = "\033[31m"; // Red
}

echo "System Health:   {$healthStatus} (" . round($healthScore, 2) . "%)\n";

// List failed tests
if ($failedTests > 0) {
    echo "\n" . str_repeat("-", 80) . "\n";
    echo "FAILED TESTS:\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($testResults as $name => $result) {
        if ($result['status'] === 'FAIL') {
            echo "❌ {$name}\n";
            if (!empty($result['error'])) {
                echo "   Error: {$result['error']}\n";
            }
            if (!empty($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    echo "   - {$issue}\n";
                }
            }
        }
    }
}

// List warnings
if ($warningTests > 0) {
    echo "\n" . str_repeat("-", 80) . "\n";
    echo "WARNINGS:\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($testResults as $name => $result) {
        if ($result['status'] === 'WARNING') {
            echo "⚠️  {$name}\n";
            if (!empty($result['warning'])) {
                echo "   Warning: {$result['warning']}\n";
            }
            if (!empty($result['missing'])) {
                foreach ($result['missing'] as $item) {
                    echo "   - {$item}\n";
                }
            }
        }
    }
}

echo "\n" . str_repeat("=", 78) . "\n";
echo "  END OF VERIFICATION REPORT\n";
echo "=" . str_repeat("=", 78) . "\n";

// Exit with appropriate code
exit($failedTests === 0 ? 0 : 1);
