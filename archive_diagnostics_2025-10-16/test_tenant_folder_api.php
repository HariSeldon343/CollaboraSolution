<?php
/**
 * Tenant Folder Creation API Diagnostic Test
 *
 * Tests the backend API endpoints required for tenant folder creation
 *
 * Usage: Access via browser at /CollaboraNexio/test_tenant_folder_api.php
 */

header('Content-Type: application/json');

// Initialize session
session_start();

// Configuration
$basePath = __DIR__;
$tests = [];
$overallStatus = 'PASS';

/**
 * Test 1: Check session and authentication
 */
function test_session() {
    $result = [
        'name' => 'Session & Authentication',
        'status' => 'PASS',
        'details' => []
    ];

    if (!isset($_SESSION['user_id'])) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'No active user session - user not logged in';
        $result['fix'] = 'Login to the system before testing';
    } else {
        $result['details'][] = 'User ID: ' . $_SESSION['user_id'];
        $result['details'][] = 'Tenant ID: ' . ($_SESSION['tenant_id'] ?? 'Not set');
        $result['details'][] = 'Session active: Yes';
    }

    return $result;
}

/**
 * Test 2: Check required files exist
 */
function test_files($basePath) {
    $result = [
        'name' => 'Required Files Exist',
        'status' => 'PASS',
        'details' => []
    ];

    $requiredFiles = [
        'api/files_tenant.php' => 'Main API endpoint',
        'api/tenants/list.php' => 'Tenant list API',
        'assets/js/filemanager_enhanced.js' => 'Frontend JavaScript',
        'files.php' => 'Main file manager page'
    ];

    $missingFiles = [];

    foreach ($requiredFiles as $file => $description) {
        $fullPath = $basePath . '/' . $file;
        if (!file_exists($fullPath)) {
            $missingFiles[] = "$file ($description)";
            $result['status'] = 'FAIL';
        } else {
            $result['details'][] = "✓ $file - Found";
        }
    }

    if (!empty($missingFiles)) {
        $result['missing_files'] = $missingFiles;
        $result['fix'] = 'Ensure all required files are present in the project';
    }

    return $result;
}

/**
 * Test 3: Check database connection and tables
 */
function test_database($basePath) {
    $result = [
        'name' => 'Database Connection & Schema',
        'status' => 'PASS',
        'details' => []
    ];

    try {
        require_once $basePath . '/includes/db.php';
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $result['details'][] = 'Database connection: OK';

        // Check required tables
        $requiredTables = ['files', 'tenants', 'users', 'user_tenant_access'];

        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $result['status'] = 'FAIL';
                $result['details'][] = "✗ Table '$table' NOT FOUND";
            } else {
                $result['details'][] = "✓ Table '$table' exists";
            }
        }

        // Check files table schema
        $stmt = $pdo->query("SHOW COLUMNS FROM files");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredColumns = ['id', 'name', 'is_folder', 'folder_id', 'tenant_id', 'uploaded_by', 'file_path'];
        $missingColumns = array_diff($requiredColumns, $columns);

        if (!empty($missingColumns)) {
            $result['status'] = 'FAIL';
            $result['details'][] = '✗ Missing columns in files table: ' . implode(', ', $missingColumns);
            $result['fix'] = 'Run database migration scripts to update files table schema';
        } else {
            $result['details'][] = '✓ Files table has all required columns';
        }

    } catch (Exception $e) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'Database error: ' . $e->getMessage();
        $result['fix'] = 'Check database configuration in config.php';
    }

    return $result;
}

/**
 * Test 4: Check user role and permissions
 */
function test_permissions($basePath) {
    $result = [
        'name' => 'User Role & Permissions',
        'status' => 'PASS',
        'details' => []
    ];

    if (!isset($_SESSION['user_id'])) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'No active session';
        return $result;
    }

    try {
        require_once $basePath . '/includes/db.php';
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $result['status'] = 'FAIL';
            $result['details'][] = 'User not found in database';
            return $result;
        }

        $result['details'][] = 'User role: ' . $user['role'];

        $allowedRoles = ['admin', 'super_admin'];
        if (in_array($user['role'], $allowedRoles)) {
            $result['details'][] = '✓ User has permission to create tenant folders';
        } else {
            $result['status'] = 'WARNING';
            $result['details'][] = '⚠ User role "' . $user['role'] . '" may not have permission';
            $result['details'][] = 'Only admin and super_admin can create tenant folders';
        }

        // Check tenant access
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_tenant_access WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $accessCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $result['details'][] = "User has access to $accessCount tenant(s)";

    } catch (Exception $e) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'Error checking permissions: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Test 5: Test tenant list API
 */
function test_tenant_list_api($basePath) {
    $result = [
        'name' => 'Tenant List API (/api/tenants/list.php)',
        'status' => 'PASS',
        'details' => []
    ];

    if (!isset($_SESSION['user_id'])) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'No active session - cannot test API';
        return $result;
    }

    try {
        // Simulate API call
        ob_start();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        include $basePath . '/api/tenants/list.php';
        $output = ob_get_clean();

        $apiResult = json_decode($output, true);

        if (!$apiResult) {
            $result['status'] = 'FAIL';
            $result['details'][] = 'API returned invalid JSON';
            $result['raw_output'] = substr($output, 0, 500);
            return $result;
        }

        if (!isset($apiResult['success'])) {
            $result['status'] = 'FAIL';
            $result['details'][] = 'API response missing "success" field';
            return $result;
        }

        if ($apiResult['success']) {
            $tenants = $apiResult['data']['tenants'] ?? $apiResult['data'] ?? [];
            $result['details'][] = '✓ API call successful';
            $result['details'][] = 'Tenants returned: ' . count($tenants);

            if (count($tenants) > 0) {
                $result['details'][] = 'Sample tenant: ' . ($tenants[0]['denominazione'] ?? $tenants[0]['name'] ?? 'N/A');
            } else {
                $result['status'] = 'WARNING';
                $result['details'][] = '⚠ No tenants found in database';
            }
        } else {
            $result['status'] = 'FAIL';
            $result['details'][] = 'API returned error: ' . ($apiResult['error'] ?? 'Unknown');
        }

    } catch (Exception $e) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'Error testing API: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Test 6: Check create_root_folder endpoint configuration
 */
function test_create_root_folder_endpoint($basePath) {
    $result = [
        'name' => 'Create Root Folder Endpoint Configuration',
        'status' = 'PASS',
        'details' => []
    ];

    $apiFile = $basePath . '/api/files_tenant.php';

    if (!file_exists($apiFile)) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'API file not found: ' . $apiFile;
        return $result;
    }

    $content = file_get_contents($apiFile);

    // Check for create_root_folder action
    if (strpos($content, "case 'create_root_folder':") === false) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'create_root_folder action not found in switch statement';
        $result['fix'] = 'Add create_root_folder case to files_tenant.php';
        return $result;
    }

    $result['details'][] = '✓ create_root_folder action found in API';

    // Check for function definition
    if (strpos($content, 'function createRootFolder()') === false) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'createRootFolder() function not defined';
        $result['fix'] = 'Add createRootFolder() function to files_tenant.php';
        return $result;
    }

    $result['details'][] = '✓ createRootFolder() function defined';

    // Check for CSRF validation
    if (strpos($content, 'verifyApiCsrfToken') === false && strpos($content, 'csrf_token') === false) {
        $result['status'] = 'WARNING';
        $result['details'][] = '⚠ CSRF validation not found';
    } else {
        $result['details'][] = '✓ CSRF validation present';
    }

    // Check for tenant_id parameter
    if (strpos($content, '$target_tenant_id') === false && strpos($content, 'tenant_id') === false) {
        $result['status'] = 'WARNING';
        $result['details'][] = '⚠ tenant_id parameter handling not clearly visible';
    } else {
        $result['details'][] = '✓ tenant_id parameter handling present';
    }

    return $result;
}

/**
 * Test 7: Check JavaScript configuration
 */
function test_javascript_config($basePath) {
    $result = [
        'name' => 'JavaScript Configuration',
        'status' => 'PASS',
        'details' => []
    ];

    $jsFile = $basePath . '/assets/js/filemanager_enhanced.js';

    if (!file_exists($jsFile)) {
        $result['status'] = 'FAIL';
        $result['details'][] = 'JavaScript file not found';
        return $result;
    }

    $content = file_get_contents($jsFile);

    // Check for required methods
    $requiredMethods = [
        'showCreateTenantFolderModal' => 'Modal display method',
        'loadTenantOptions' => 'Load tenant list',
        'createRootFolder' => 'Create folder API call'
    ];

    foreach ($requiredMethods as $method => $description) {
        if (strpos($content, $method) === false) {
            $result['status'] = 'FAIL';
            $result['details'][] = "✗ Method '$method' not found ($description)";
        } else {
            $result['details'][] = "✓ Method '$method' found";
        }
    }

    // Check for event listener
    if (strpos($content, "getElementById('createRootFolderBtn')") === false) {
        $result['status'] = 'WARNING';
        $result['details'][] = '⚠ Event listener for createRootFolderBtn not clearly visible';
    } else {
        $result['details'][] = '✓ Event listener setup found';
    }

    return $result;
}

// Run all tests
$tests[] = test_session();
$tests[] = test_files($basePath);
$tests[] = test_database($basePath);
$tests[] = test_permissions($basePath);
$tests[] = test_tenant_list_api($basePath);
$tests[] = test_create_root_folder_endpoint($basePath);
$tests[] = test_javascript_config($basePath);

// Calculate overall status
foreach ($tests as $test) {
    if ($test['status'] === 'FAIL') {
        $overallStatus = 'FAIL';
        break;
    }
}

// Output results
echo json_encode([
    'overall_status' => $overallStatus,
    'timestamp' => date('Y-m-d H:i:s'),
    'total_tests' => count($tests),
    'passed' => count(array_filter($tests, fn($t) => $t['status'] === 'PASS')),
    'failed' => count(array_filter($tests, fn($t) => $t['status'] === 'FAIL')),
    'warnings' => count(array_filter($tests, fn($t) => $t['status'] === 'WARNING')),
    'tests' => $tests
], JSON_PRETTY_PRINT);
