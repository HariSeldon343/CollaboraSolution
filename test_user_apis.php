<?php
/**
 * Test script for User Management APIs
 * Tests both tenants.php and delete.php endpoints
 */

// Start session to set test data
session_start();

// Test configuration
$baseUrl = 'http://localhost/CollaboraNexio';
$testResults = [];

// Function to make API call
function testApi($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();

    // Basic configuration
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Set method
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    // Set headers
    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $allHeaders = array_merge($defaultHeaders, $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

    // Include cookies
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'code' => $httpCode,
        'response' => $response,
        'error' => $error,
        'data' => json_decode($response, true)
    ];
}

// Function to display test result
function showResult($testName, $result) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "TEST: $testName\n";
    echo str_repeat("-", 60) . "\n";
    echo "HTTP Code: {$result['code']}\n";
    echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";

    if ($result['error']) {
        echo "CURL Error: {$result['error']}\n";
    }

    if ($result['data']) {
        echo "Response: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
    } elseif ($result['response']) {
        echo "Raw Response: " . substr($result['response'], 0, 500) . "\n";
    }

    return $result['success'];
}

// Function to setup test session
function setupTestSession() {
    $_SESSION['user_id'] = 1;
    $_SESSION['tenant_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['user_name'] = 'Test Admin';
    $_SESSION['user_email'] = 'admin@test.com';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['last_activity'] = time();
    $_SESSION['regenerate_time'] = time();
    $_SESSION['user_agent'] = 'Test Script';

    return $_SESSION['csrf_token'];
}

// Function to test with direct PHP include (simulates being logged in)
function testDirectCall($title, $apiFile, $setupCallback = null) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "DIRECT TEST: $title\n";
    echo str_repeat("-", 60) . "\n";

    // Backup superglobals
    $backupServer = $_SERVER;
    $backupPost = $_POST;
    $backupGet = $_GET;

    try {
        // Setup test environment
        if ($setupCallback) {
            $setupCallback();
        }

        // Capture output
        ob_start();
        $errorOccurred = false;

        // Set error handler
        set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errorOccurred) {
            echo "PHP Error [$errno]: $errstr in $errfile on line $errline\n";
            $errorOccurred = true;
            return true;
        });

        // Include the API file
        include $apiFile;

        // Get output
        $output = ob_get_clean();

        // Restore error handler
        restore_error_handler();

        // Try to decode JSON
        $decoded = json_decode($output, true);

        if ($decoded !== null) {
            echo "JSON Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            echo "Status: SUCCESS (Valid JSON)\n";
        } else {
            echo "Raw Output: " . substr($output, 0, 500) . "\n";
            if (strpos($output, '<') !== false) {
                echo "Status: FAILED (HTML/PHP output detected)\n";
            } else {
                echo "Status: UNKNOWN (Not valid JSON)\n";
            }
        }

        if ($errorOccurred) {
            echo "Status: FAILED (PHP errors occurred)\n";
        }

    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "Status: FAILED (Exception thrown)\n";
    } finally {
        // Restore superglobals
        $_SERVER = $backupServer;
        $_POST = $backupPost;
        $_GET = $backupGet;

        // Clean any remaining output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}

// Main test execution
echo "USER MANAGEMENT API TEST SUITE\n";
echo str_repeat("=", 60) . "\n\n";

// Setup test session
$csrfToken = setupTestSession();
echo "Test session initialized:\n";
echo "- User ID: {$_SESSION['user_id']}\n";
echo "- Tenant ID: {$_SESSION['tenant_id']}\n";
echo "- Role: {$_SESSION['role']}\n";
echo "- CSRF Token: $csrfToken\n";

// Test 1: Direct call to tenants.php
testDirectCall('Get Tenants API', __DIR__ . '/api/users/tenants.php', function() use ($csrfToken) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;
});

// Test 2: Direct call to delete.php without proper data (should fail gracefully)
testDirectCall('Delete User API (No Data)', __DIR__ . '/api/users/delete.php', function() use ($csrfToken) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;
    $_POST = [];
});

// Test 3: Direct call to delete.php with test data
testDirectCall('Delete User API (With Data)', __DIR__ . '/api/users/delete.php', function() use ($csrfToken) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;
    $_POST = ['user_id' => 99999]; // Non-existent user
});

// Test 4: Test with insufficient role
testDirectCall('Delete User API (Insufficient Role)', __DIR__ . '/api/users/delete.php', function() use ($csrfToken) {
    $_SESSION['role'] = 'user'; // Change role to regular user
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;
    $_POST = ['user_id' => 2];
});

// Test 5: Test without authentication
testDirectCall('Tenants API (No Auth)', __DIR__ . '/api/users/tenants.php', function() {
    unset($_SESSION['user_id']); // Remove authentication
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

// Summary
echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST SUMMARY\n";
echo str_repeat("-", 60) . "\n";
echo "All tests completed. Check output above for results.\n";
echo "Key points to verify:\n";
echo "1. All responses should be valid JSON\n";
echo "2. No PHP warnings or HTML output should appear\n";
echo "3. Error messages should be in JSON format\n";
echo "4. Proper HTTP status codes should be returned\n";
echo str_repeat("=", 60) . "\n";

// Test with CURL (if running on a web server)
if (php_sapi_name() !== 'cli') {
    echo "\n<pre>\n";
    echo "Note: For full API testing via HTTP, run this script from command line\n";
    echo "or implement session sharing for CURL requests.\n";
    echo "</pre>\n";
}
?>