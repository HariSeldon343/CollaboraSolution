<?php
/**
 * Test script to verify auth_api.php always returns JSON
 * Run this from browser: http://localhost:8888/CollaboraNexio/test_auth_api.php
 */

// Disable error display for this test
error_reporting(0);
ini_set('display_errors', '0');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auth API Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
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
        h1 {
            color: #333;
            margin-bottom: 30px;
        }
        h2 {
            color: #555;
            margin-top: 0;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        button:hover {
            background: #0056b3;
        }
        .response {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .success {
            background: #d4edda;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .test-input {
            display: block;
            width: 100%;
            padding: 8px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <h1>🔧 Auth API Test Suite</h1>

    <div class="test-container">
        <h2>1. Test Invalid JSON</h2>
        <button onclick="testInvalidJSON()">Send Invalid JSON</button>
        <div id="invalid-json-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>2. Test Missing Fields</h2>
        <button onclick="testMissingFields()">Send Empty Request</button>
        <div id="missing-fields-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>3. Test Invalid Email Format</h2>
        <button onclick="testInvalidEmail()">Send Invalid Email</button>
        <div id="invalid-email-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>4. Test Wrong Credentials</h2>
        <button onclick="testWrongCredentials()">Send Wrong Credentials</button>
        <div id="wrong-credentials-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>5. Test Valid Login</h2>
        <input type="email" id="valid-email" class="test-input" placeholder="Email (e.g., admin@demo.local)" value="admin@demo.local">
        <input type="password" id="valid-password" class="test-input" placeholder="Password" value="Admin123!">
        <button onclick="testValidLogin()">Test Login</button>
        <div id="valid-login-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>6. Test Session Check</h2>
        <button onclick="testSessionCheck()">Check Session</button>
        <div id="session-check-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>7. Test Logout</h2>
        <button onclick="testLogout()">Test Logout</button>
        <div id="logout-response" class="response" style="display:none;"></div>
    </div>

    <div class="test-container">
        <h2>8. Test CORS Options</h2>
        <button onclick="testCORS()">Test CORS Preflight</button>
        <div id="cors-response" class="response" style="display:none;"></div>
    </div>

    <script>
        const API_URL = 'auth_api.php';

        function showResponse(elementId, response, isSuccess) {
            const element = document.getElementById(elementId);
            element.style.display = 'block';
            element.className = 'response ' + (isSuccess ? 'success' : 'error');

            // Check if response is JSON
            let display = '';
            if (response.headers && response.headers['content-type'] &&
                response.headers['content-type'].includes('application/json')) {
                display += '✅ Content-Type: application/json\n\n';
            } else {
                display += '❌ Content-Type is not JSON!\n\n';
            }

            display += 'Status: ' + (response.status || 'Unknown') + '\n\n';

            if (typeof response.data === 'object') {
                display += JSON.stringify(response.data, null, 2);
            } else {
                display += response.data || response;
            }

            element.textContent = display;
        }

        async function makeRequest(method, url, data = null) {
            try {
                const options = {
                    method: method,
                    headers: {}
                };

                if (data !== null) {
                    if (data === 'INVALID_JSON') {
                        options.body = '{invalid json}';
                        options.headers['Content-Type'] = 'application/json';
                    } else {
                        options.body = JSON.stringify(data);
                        options.headers['Content-Type'] = 'application/json';
                    }
                }

                const response = await fetch(url, options);
                const contentType = response.headers.get('content-type');

                let responseData;
                const responseText = await response.text();

                try {
                    responseData = JSON.parse(responseText);
                } catch (e) {
                    // If not JSON, return raw text
                    responseData = responseText;
                }

                return {
                    status: response.status,
                    headers: {
                        'content-type': contentType
                    },
                    data: responseData
                };
            } catch (error) {
                return {
                    status: 'Network Error',
                    data: error.message
                };
            }
        }

        async function testInvalidJSON() {
            const response = await makeRequest('POST', API_URL, 'INVALID_JSON');
            showResponse('invalid-json-response', response, response.status === 400);
        }

        async function testMissingFields() {
            const response = await makeRequest('POST', API_URL, {});
            showResponse('missing-fields-response', response, response.status === 400);
        }

        async function testInvalidEmail() {
            const response = await makeRequest('POST', API_URL, {
                email: 'not-an-email',
                password: 'password123'
            });
            showResponse('invalid-email-response', response, response.status === 400);
        }

        async function testWrongCredentials() {
            const response = await makeRequest('POST', API_URL, {
                email: 'wrong@example.com',
                password: 'wrongpassword'
            });
            showResponse('wrong-credentials-response', response, response.status === 401);
        }

        async function testValidLogin() {
            const email = document.getElementById('valid-email').value;
            const password = document.getElementById('valid-password').value;

            const response = await makeRequest('POST', API_URL, {
                email: email,
                password: password
            });
            showResponse('valid-login-response', response, response.status === 200);
        }

        async function testSessionCheck() {
            const response = await makeRequest('GET', API_URL + '?action=check');
            showResponse('session-check-response', response, response.status === 200);
        }

        async function testLogout() {
            const response = await makeRequest('GET', API_URL + '?action=logout');
            showResponse('logout-response', response, response.status === 200);
        }

        async function testCORS() {
            const response = await makeRequest('OPTIONS', API_URL);
            showResponse('cors-response', response, response.status === 200);
        }
    </script>
</body>
</html>