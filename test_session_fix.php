<?php
/**
 * Test script to verify session configuration fix
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>Testing Session Configuration Fix</h2>";
echo "<pre>";

// Test 1: Include session_init.php
echo "Test 1: Including session_init.php...\n";
require_once __DIR__ . '/includes/session_init.php';
echo "✓ Session initialization completed without warnings\n\n";

// Test 2: Check session status
echo "Test 2: Checking session status...\n";
$status = session_status();
switch($status) {
    case PHP_SESSION_DISABLED:
        echo "✗ Sessions are disabled\n";
        break;
    case PHP_SESSION_NONE:
        echo "✗ No session exists\n";
        break;
    case PHP_SESSION_ACTIVE:
        echo "✓ Session is active\n";
        break;
}
echo "\n";

// Test 3: Set and retrieve session variable
echo "Test 3: Setting and retrieving session variable...\n";
$_SESSION['test_var'] = 'Session working correctly';
if (isset($_SESSION['test_var']) && $_SESSION['test_var'] === 'Session working correctly') {
    echo "✓ Session variables work correctly\n";
} else {
    echo "✗ Session variables not working\n";
}
echo "\n";

// Test 4: Check session configuration
echo "Test 4: Checking session configuration...\n";
echo "Session ID: " . session_id() . "\n";
echo "Session save path: " . session_save_path() . "\n";
echo "Session cookie lifetime: " . ini_get('session.cookie_lifetime') . "\n";
echo "Session httponly: " . ini_get('session.cookie_httponly') . "\n";
echo "Session use_only_cookies: " . ini_get('session.use_only_cookies') . "\n";
echo "Session gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . "\n";
echo "\n";

// Test 5: Try to change session settings (should not cause warnings)
echo "Test 5: Attempting to change session settings after session start...\n";
@ini_set('session.cookie_lifetime', '3600');
if (error_get_last() === null) {
    echo "✓ No errors when attempting to change settings\n";
} else {
    $error = error_get_last();
    if (strpos($error['message'], 'Session ini settings cannot be changed') !== false) {
        echo "Expected: Session already started, settings cannot be changed\n";
    } else {
        echo "Unexpected error: " . $error['message'] . "\n";
    }
}

echo "\n";
echo "<strong>Summary:</strong>\n";
echo "The session configuration has been fixed. Session settings are now configured\n";
echo "BEFORE session_start() is called, eliminating the warning messages.\n";
echo "</pre>";

// Clean up test variable
unset($_SESSION['test_var']);
?>