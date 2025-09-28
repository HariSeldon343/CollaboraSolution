<?php
/**
 * Test script for files_tenant.php API
 * This script simulates an authenticated session and tests the list action
 */

session_start();

// Simulate an authenticated session
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = 'test_token_' . uniqid();

// Set the action in GET
$_GET['action'] = 'list';
$_GET['folder_id'] = '';  // Empty for root
$_GET['search'] = '';

// Capture output
ob_start();

// Include the API file
require_once __DIR__ . '/api/files_tenant.php';

// Get the output
$output = ob_get_clean();

// Parse JSON response
$response = json_decode($output, true);

// Display results
header('Content-Type: text/plain');

echo "=== FILES_TENANT.PHP API TEST ===\n\n";

if (json_last_error() === JSON_ERROR_NONE) {
    echo "✓ Valid JSON response received\n\n";

    if (isset($response['success']) && $response['success']) {
        echo "✓ API call successful\n\n";

        echo "Response structure:\n";
        echo "- Items count: " . count($response['data']['items']) . "\n";
        echo "- Breadcrumb count: " . count($response['data']['breadcrumb']) . "\n";
        echo "- User role: " . $response['data']['user_role'] . "\n";
        echo "- Can create root: " . ($response['data']['can_create_root'] ? 'Yes' : 'No') . "\n";

        if (!empty($response['data']['items'])) {
            echo "\nFirst few items:\n";
            foreach (array_slice($response['data']['items'], 0, 3) as $item) {
                echo sprintf("  - %s: %s (type: %s, tenant: %s)\n",
                    $item['id'],
                    $item['name'],
                    $item['type'],
                    $item['tenant_name'] ?? 'N/A'
                );
            }
        } else {
            echo "\nNo items found (this is normal if no files/folders exist)\n";
        }
    } elseif (isset($response['error'])) {
        echo "✗ API returned error: " . $response['error'] . "\n";
        if (isset($response['debug'])) {
            echo "Debug info: " . $response['debug'] . "\n";
        }
    } else {
        echo "✗ Unexpected response structure\n";
    }
} else {
    echo "✗ Invalid JSON response\n";
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "\nRaw output:\n" . $output . "\n";

    // Check for PHP errors
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'Parse error') !== false) {
        echo "\nPHP Error detected in output\n";
    }
}

echo "\n=== TEST COMPLETE ===\n";
?>