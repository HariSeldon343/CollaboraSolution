<?php
/**
 * Test script for aziende.php API endpoints
 * Tests authentication and CSRF token handling
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth_simple.php';

// Initialize authentication
$auth = new Auth();

// Test different user scenarios
$testScenarios = [
    'super_admin_test' => [
        'user_id' => 1,
        'role' => 'super_admin',
        'user_role' => 'super_admin',
        'tenant_id' => 1,
        'user_name' => 'Test Super Admin',
        'user_email' => 'superadmin@test.com'
    ],
    'admin_test' => [
        'user_id' => 2,
        'role' => 'admin',
        'user_role' => 'admin',
        'tenant_id' => 1,
        'user_name' => 'Test Admin',
        'user_email' => 'admin@test.com'
    ]
];

// Choose test scenario
$scenario = $testScenarios['super_admin_test'];

// Set up test session
foreach ($scenario as $key => $value) {
    $_SESSION[$key] = $value;
}

// Generate CSRF token
$csrfToken = $auth->generateCSRFToken();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test Aziende APIs</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .test { margin: 20px 0; padding: 15px; background: white; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        h2 { color: #333; }
        pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Test Aziende API Endpoints</h1>

    <div class='test'>
        <h2>Session Information</h2>
        <pre>" . json_encode([
            'user_id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'user_role' => $_SESSION['user_role'] ?? null,
            'tenant_id' => $_SESSION['tenant_id'] ?? null,
            'csrf_token' => substr($csrfToken, 0, 20) . '...'
        ], JSON_PRETTY_PRINT) . "</pre>
    </div>

    <div class='test'>
        <h2>Test API Endpoints</h2>
        <button onclick='testUsersList()'>Test Users List API</button>
        <button onclick='testCompaniesList()'>Test Companies List API</button>
        <button onclick='testCompaniesDelete()'>Test Companies Delete API (Mock)</button>
        <div id='results'></div>
    </div>

    <script>
    const csrfToken = '" . $csrfToken . "';

    async function testUsersList() {
        const resultsDiv = document.getElementById('results');
        resultsDiv.innerHTML = '<p class=\"info\">Testing Users List API...</p>';

        try {
            const response = await fetch('api/users/list.php?role=manager,admin', {
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });

            const data = await response.json();

            resultsDiv.innerHTML = '<h3>Users List API Response:</h3>' +
                '<p class=\"' + (response.ok ? 'success' : 'error') + '\">Status: ' + response.status + '</p>' +
                '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

        } catch (error) {
            resultsDiv.innerHTML = '<p class=\"error\">Error: ' + error.message + '</p>';
        }
    }

    async function testCompaniesList() {
        const resultsDiv = document.getElementById('results');
        resultsDiv.innerHTML = '<p class=\"info\">Testing Companies List API...</p>';

        try {
            const response = await fetch('api/companies/list.php', {
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });

            const data = await response.json();

            resultsDiv.innerHTML = '<h3>Companies List API Response:</h3>' +
                '<p class=\"' + (response.ok ? 'success' : 'error') + '\">Status: ' + response.status + '</p>' +
                '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

        } catch (error) {
            resultsDiv.innerHTML = '<p class=\"error\">Error: ' + error.message + '</p>';
        }
    }

    async function testCompaniesDelete() {
        const resultsDiv = document.getElementById('results');
        resultsDiv.innerHTML = '<p class=\"info\">Testing Companies Delete API (with mock ID)...</p>';

        const formData = new FormData();
        formData.append('company_id', '999'); // Non-existent ID for safe testing
        formData.append('csrf_token', csrfToken);

        try {
            const response = await fetch('api/companies/delete.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            resultsDiv.innerHTML = '<h3>Companies Delete API Response:</h3>' +
                '<p class=\"' + (response.ok ? 'success' : 'error') + '\">Status: ' + response.status + '</p>' +
                '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

        } catch (error) {
            resultsDiv.innerHTML = '<p class=\"error\">Error: ' + error.message + '</p>';
        }
    }
    </script>
</body>
</html>";
?>