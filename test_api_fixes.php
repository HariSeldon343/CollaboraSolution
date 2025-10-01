<?php
/**
 * Test script to verify API fixes
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>CollaboraNexio API Test</h1>\n";
echo "<pre>\n";

// Test 1: Check files_tenant_fixed.php structure
echo "1. Testing files_tenant_fixed.php structure:\n";
echo "   - Checking file exists: ";
$file_path = __DIR__ . '/api/files_tenant_fixed.php';
if (file_exists($file_path)) {
    echo "✓ OK\n";

    // Check for the fixed getTenantList function
    $content = file_get_contents($file_path);
    echo "   - Checking getTenantList has global \$tenant_id: ";
    if (preg_match('/function getTenantList\(\).*?global.*?\$tenant_id/s', $content)) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing global \$tenant_id\n";
    }

    echo "   - Checking ob_clean() before response: ";
    if (preg_match('/function getTenantList\(\).*?ob_clean\(\)/s', $content)) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing ob_clean()\n";
    }
} else {
    echo "✗ FAILED - File not found\n";
}

echo "\n";

// Test 2: Check companies/delete.php structure
echo "2. Testing companies/delete.php structure:\n";
echo "   - Checking file exists: ";
$delete_path = __DIR__ . '/api/companies/delete.php';
if (file_exists($delete_path)) {
    echo "✓ OK\n";

    $content = file_get_contents($delete_path);

    echo "   - Checking for centralized auth include: ";
    if (strpos($content, "require_once '../../includes/api_auth.php'") !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing api_auth.php include\n";
    }

    echo "   - Checking for initializeApiEnvironment(): ";
    if (strpos($content, 'initializeApiEnvironment()') !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing initializeApiEnvironment()\n";
    }

    echo "   - Checking for verifyApiAuthentication(): ";
    if (strpos($content, 'verifyApiAuthentication()') !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing verifyApiAuthentication()\n";
    }

    echo "   - Checking for requireApiRole('super_admin'): ";
    if (strpos($content, "requireApiRole('super_admin')") !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing requireApiRole('super_admin')\n";
    }

    echo "   - Checking for verifyApiCsrfToken(): ";
    if (strpos($content, 'verifyApiCsrfToken(') !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing verifyApiCsrfToken()\n";
    }

    echo "   - Checking for apiSuccess() usage: ";
    if (strpos($content, 'apiSuccess(') !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing apiSuccess() usage\n";
    }

    echo "   - Checking for apiError() usage: ";
    if (strpos($content, 'apiError(') !== false) {
        echo "✓ OK\n";
    } else {
        echo "✗ FAILED - Missing apiError() usage\n";
    }
} else {
    echo "✗ FAILED - File not found\n";
}

echo "\n";

// Test 3: Check api_auth.php exists
echo "3. Checking centralized auth file:\n";
echo "   - Checking includes/api_auth.php exists: ";
$auth_path = __DIR__ . '/includes/api_auth.php';
if (file_exists($auth_path)) {
    echo "✓ OK\n";

    $content = file_get_contents($auth_path);
    $functions = [
        'initializeApiEnvironment',
        'verifyApiAuthentication',
        'getCsrfTokenFromRequest',
        'verifyApiCsrfToken',
        'getApiUserInfo',
        'hasApiRole',
        'requireApiRole',
        'apiSuccess',
        'apiError',
        'normalizeSessionData'
    ];

    foreach ($functions as $func) {
        echo "   - Function $func(): ";
        if (strpos($content, "function $func") !== false) {
            echo "✓ OK\n";
        } else {
            echo "✗ FAILED - Missing\n";
        }
    }
} else {
    echo "✗ FAILED - File not found\n";
}

echo "\n";

// Test 4: Check session_init.php exists
echo "4. Checking session initialization:\n";
echo "   - Checking includes/session_init.php exists: ";
$session_path = __DIR__ . '/includes/session_init.php';
if (file_exists($session_path)) {
    echo "✓ OK\n";
} else {
    echo "✗ FAILED - File not found\n";
}

echo "\n</pre>\n";

// Summary
echo "<h2>Summary</h2>\n";
echo "<ul>\n";
echo "<li><strong>files_tenant_fixed.php</strong>: Fixed missing global \$tenant_id in getTenantList() function - Should resolve 500 error</li>\n";
echo "<li><strong>companies/delete.php</strong>: Now uses centralized API authentication - Should resolve 403 Forbidden error</li>\n";
echo "<li>Both APIs now use proper error handling with ob_clean() to ensure clean JSON responses</li>\n";
echo "<li>Both APIs now use centralized authentication from includes/api_auth.php</li>\n";
echo "</ul>\n";

echo "<h3>Next Steps:</h3>\n";
echo "<ol>\n";
echo "<li>Access this test page via: <a href='http://localhost:8888/CollaboraNexio/test_api_fixes.php'>http://localhost:8888/CollaboraNexio/test_api_fixes.php</a></li>\n";
echo "<li>Login as an Admin or Super Admin user</li>\n";
echo "<li>Test creating a folder in files.php - the tenant list should now load correctly</li>\n";
echo "<li>Test deleting a company in aziende.php - the delete should work for Super Admin users</li>\n";
echo "</ol>\n";
?>