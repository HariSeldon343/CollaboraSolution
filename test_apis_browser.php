<?php
/**
 * Browser-based test for User Management APIs
 * Access this file through your browser while logged in
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Please login first to test the APIs');
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API User Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .test-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-header {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }
        .test-result {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #0056b3;
        }
        .session-info {
            background: #e9ecef;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h1>Test API User Management</h1>

    <div class="session-info">
        <strong>Sessione corrente:</strong><br>
        User ID: <?php echo $_SESSION['user_id']; ?><br>
        Tenant ID: <?php echo $_SESSION['tenant_id']; ?><br>
        Role: <?php echo $_SESSION['role'] ?? 'N/A'; ?><br>
        CSRF Token: <?php echo substr($_SESSION['csrf_token'] ?? 'N/A', 0, 20); ?>...
    </div>

    <div class="test-container">
        <div class="test-header">Test 1: Get Tenants API</div>
        <button onclick="testGetTenants()">Esegui Test</button>
        <div id="test1-result"></div>
    </div>

    <div class="test-container">
        <div class="test-header">Test 2: Delete User API (Invalid User)</div>
        <button onclick="testDeleteInvalidUser()">Esegui Test</button>
        <div id="test2-result"></div>
    </div>

    <div class="test-container">
        <div class="test-header">Test 3: Delete User API (No CSRF Token)</div>
        <button onclick="testDeleteNoCsrf()">Esegui Test</button>
        <div id="test3-result"></div>
    </div>

    <div class="test-container">
        <div class="test-header">Test 4: Test Error Handling</div>
        <button onclick="testErrorHandling()">Esegui Test</button>
        <div id="test4-result"></div>
    </div>

    <script>
        // Get CSRF token from session
        const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

        function displayResult(elementId, success, data) {
            const element = document.getElementById(elementId);
            let html = '';

            if (success) {
                html += '<div class="test-result success">✓ Test superato</div>';
            } else {
                html += '<div class="test-result error">✗ Test fallito</div>';
            }

            if (data.status) {
                html += '<div class="test-result info">HTTP Status: ' + data.status + '</div>';
            }

            if (data.response) {
                html += '<div class="test-result info">Response:<pre>' +
                    JSON.stringify(data.response, null, 2) + '</pre></div>';
            }

            if (data.error) {
                html += '<div class="test-result error">Error: ' + data.error + '</div>';
            }

            element.innerHTML = html;
        }

        async function testGetTenants() {
            const resultDiv = document.getElementById('test1-result');
            resultDiv.innerHTML = '<div class="test-result info">Testing...</div>';

            try {
                const response = await fetch('/CollaboraNexio/api/users/tenants.php', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const contentType = response.headers.get('content-type');
                let data;

                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    data = { error: 'Non-JSON response', body: text.substring(0, 200) };
                }

                const success = response.ok && data && !data.error;
                displayResult('test1-result', success, {
                    status: response.status,
                    response: data
                });

            } catch (error) {
                displayResult('test1-result', false, {
                    error: error.message
                });
            }
        }

        async function testDeleteInvalidUser() {
            const resultDiv = document.getElementById('test2-result');
            resultDiv.innerHTML = '<div class="test-result info">Testing...</div>';

            try {
                const response = await fetch('/CollaboraNexio/api/users/delete.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: 99999,
                        csrf_token: csrfToken
                    }),
                    credentials: 'same-origin'
                });

                const contentType = response.headers.get('content-type');
                let data;

                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    data = { error: 'Non-JSON response', body: text.substring(0, 200) };
                }

                // This should fail with proper error message
                const success = response.status === 500 && data && data.error &&
                    !data.body; // Ensure it's JSON error, not HTML

                displayResult('test2-result', success, {
                    status: response.status,
                    response: data
                });

            } catch (error) {
                displayResult('test2-result', false, {
                    error: error.message
                });
            }
        }

        async function testDeleteNoCsrf() {
            const resultDiv = document.getElementById('test3-result');
            resultDiv.innerHTML = '<div class="test-result info">Testing...</div>';

            try {
                const response = await fetch('/CollaboraNexio/api/users/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: 1
                    }),
                    credentials: 'same-origin'
                });

                const contentType = response.headers.get('content-type');
                let data;

                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    data = { error: 'Non-JSON response', body: text.substring(0, 200) };
                }

                // Should fail with CSRF error
                const success = response.status === 403 && data && data.error &&
                    data.error.includes('CSRF') && !data.body;

                displayResult('test3-result', success, {
                    status: response.status,
                    response: data
                });

            } catch (error) {
                displayResult('test3-result', false, {
                    error: error.message
                });
            }
        }

        async function testErrorHandling() {
            const resultDiv = document.getElementById('test4-result');
            resultDiv.innerHTML = '<div class="test-result info">Testing multiple scenarios...</div>';

            let allTests = [];

            // Test 1: Wrong HTTP method
            try {
                const response1 = await fetch('/CollaboraNexio/api/users/delete.php', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const data1 = await response1.json().catch(() => ({ error: 'Invalid JSON' }));
                allTests.push({
                    test: 'Wrong HTTP Method',
                    passed: response1.status === 405 && data1.error,
                    details: `Status: ${response1.status}, Error: ${data1.error || 'None'}`
                });
            } catch (e) {
                allTests.push({
                    test: 'Wrong HTTP Method',
                    passed: false,
                    details: e.message
                });
            }

            // Display all test results
            let html = '';
            let allPassed = true;

            for (const test of allTests) {
                html += `<div class="test-result ${test.passed ? 'success' : 'error'}">
                    ${test.test}: ${test.passed ? '✓' : '✗'} - ${test.details}
                </div>`;
                if (!test.passed) allPassed = false;
            }

            displayResult('test4-result', allPassed, {
                response: allTests
            });
        }

        // Auto-run first test on page load
        window.addEventListener('load', () => {
            console.log('Test page loaded. Click buttons to run tests.');
        });
    </script>
</body>
</html>