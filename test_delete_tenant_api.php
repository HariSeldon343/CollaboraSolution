<?php
/**
 * Test script to simulate the exact API call for tenant deletion
 */

declare(strict_types=1);

// Start session and simulate super_admin
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['user_role'] = 'super_admin';
$_SESSION['csrf_token'] = 'test_token';

// Simulate POST request
$_POST['tenant_id'] = 2;
$_POST['csrf_token'] = 'test_token';

// Set request method
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TestScript';

echo "Simulating tenant deletion via API...\n\n";

// Capture output from the API
ob_start();

try {
    include __DIR__ . '/api/tenants/delete.php';
} catch (Exception $e) {
    echo "CAUGHT EXCEPTION: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();

echo "API Response:\n";
echo $output;
echo "\n";
