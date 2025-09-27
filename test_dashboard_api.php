<?php
/**
 * Test script for Dashboard API
 *
 * This script tests the dashboard API endpoints to ensure they work correctly
 */

session_start();

// Simulate logged in user for testing
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['user_name'] = 'Test User';
$_SESSION['user_role'] = 'admin';

// Include required files
require_once 'config.php';

// Function to test API endpoint
function testEndpoint($url, $description) {
    echo "\n=== Testing: $description ===\n";
    echo "URL: $url\n";

    // Set up session cookie for the request
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: ' . session_name() . '=' . session_id() . "\r\n"
        ]
    ];
    $context = stream_context_create($opts);

    // Make the request
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        echo "ERROR: Failed to fetch URL\n";
        return;
    }

    // Parse JSON response
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERROR: Invalid JSON response\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
        return;
    }

    // Display result
    if (isset($data['success']) && $data['success']) {
        echo "SUCCESS: " . ($data['message'] ?? 'OK') . "\n";

        // Show summary of data
        if (isset($data['data'])) {
            if (is_array($data['data'])) {
                echo "Data keys: " . implode(', ', array_keys($data['data'])) . "\n";

                // Show widget count if present
                if (isset($data['data']['widgets'])) {
                    echo "Widgets count: " . count($data['data']['widgets']) . "\n";
                }

                // Show stats if present
                if (isset($data['data']['stats'])) {
                    echo "Stats available: " . implode(', ', array_keys($data['data']['stats'])) . "\n";
                }

                // Show activities count if present
                if (isset($data['data']['activities'])) {
                    echo "Activities count: " . count($data['data']['activities']) . "\n";
                }
            }
        }
    } else {
        echo "FAILED: " . ($data['message'] ?? 'Unknown error') . "\n";
    }
}

// Test various endpoints
echo "Dashboard API Test Script\n";
echo "========================\n";

$baseUrl = 'http://localhost:8888/api/dashboard.php';

// Test main endpoints
testEndpoint($baseUrl . '?action=load', 'Main dashboard load');
testEndpoint($baseUrl . '?action=stats', 'Dashboard statistics');
testEndpoint($baseUrl . '?action=activities', 'Recent activities');
testEndpoint($baseUrl . '?action=notifications', 'User notifications');
testEndpoint($baseUrl . '?action=widgets', 'Widget configuration');

// Test widget data endpoints
echo "\n--- Widget Data Endpoints ---\n";
testEndpoint($baseUrl . '?widget=metric&metric=users', 'Metric widget - Users');
testEndpoint($baseUrl . '?widget=metric&metric=files', 'Metric widget - Files');
testEndpoint($baseUrl . '?widget=metric&metric=tasks', 'Metric widget - Tasks');
testEndpoint($baseUrl . '?widget=chart&type=line', 'Chart widget - Line');
testEndpoint($baseUrl . '?widget=activities', 'Activities widget');
testEndpoint($baseUrl . '?widget=storage', 'Storage widget');
testEndpoint($baseUrl . '?widget=burndown', 'Burndown widget');
testEndpoint($baseUrl . '?widget=calendar', 'Calendar widget');

echo "\n\nTest completed!\n";
echo "Session ID: " . session_id() . "\n";
echo "User ID: " . $_SESSION['user_id'] . "\n";
echo "Tenant ID: " . $_SESSION['tenant_id'] . "\n";