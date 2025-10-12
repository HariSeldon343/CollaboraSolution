<?php
/**
 * Test API call to open_document.php simulating browser request
 */

declare(strict_types=1);

// Start session first
session_name('COLLAB_SID');
session_start();

// Simulate authenticated user
$_SESSION['user_id'] = 19;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['user_name'] = 'Test User';
$_SESSION['user_email'] = 'test@example.com';
$_SESSION['csrf_token'] = 'test_token_' . bin2hex(random_bytes(16));

// Set CSRF token in request
$_GET['csrf_token'] = $_SESSION['csrf_token'];
$_GET['file_id'] = 43;
$_GET['mode'] = 'edit';

// Simulate HTTP headers
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Test Script';

echo "=== TESTING API CALL ===\n";
echo "Session ID: " . session_id() . "\n";
echo "User ID: " . $_SESSION['user_id'] . "\n";
echo "File ID: " . $_GET['file_id'] . "\n";
echo "CSRF Token: " . substr($_SESSION['csrf_token'], 0, 20) . "...\n";
echo "\n=== API RESPONSE ===\n";

// Capture output
ob_start();

try {
    // Include the API file
    include __DIR__ . '/api/documents/open_document.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

$output = ob_get_clean();

echo "Output length: " . strlen($output) . " bytes\n";
echo "First 500 chars:\n";
echo substr($output, 0, 500) . "\n";

// Try to parse as JSON
$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "\n=== VALID JSON ===\n";
    echo json_encode($json, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n=== INVALID JSON ===\n";
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "\nFull output:\n";
    echo $output . "\n";
}
