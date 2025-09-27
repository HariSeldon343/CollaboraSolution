<?php
/**
 * Test Login Flow - Verifica il flusso di autenticazione
 * Accesso: http://localhost:8888/CollaboraNexio/test_login_flow.php
 */

session_start();

// Configuration
$baseUrl = 'http://localhost:8888/CollaboraNexio';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Login Flow - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn:hover {
            background: #5a67d8;
        }
        .btn-success {
            background: #10b981;
        }
        .btn-danger {
            background: #ef4444;
        }
        .status {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .logged-in {
            background: #d4edda;
            color: #155724;
        }
        .not-logged-in {
            background: #f8d7da;
            color: #721c24;
        }
        #result {
            margin-top: 20px;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔬 Test Complete Login Flow</h1>

        <!-- Current Status -->
        <?php if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in'])): ?>
            <div class="status logged-in">
                ✅ <strong>Currently Logged In as:</strong> <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Unknown'); ?>
            </div>
        <?php else: ?>
            <div class="status not-logged-in">
                ❌ <strong>Not Logged In</strong>
            </div>
        <?php endif; ?>

        <!-- Test 1: Direct Login -->
        <div class="test-section">
            <h2>Test 1: Direct Login API Call</h2>
            <p>This will call auth_api.php directly and show the response.</p>
            <button class="btn" onclick="testDirectLogin()">Test Direct Login</button>
            <button class="btn btn-danger" onclick="testLogout()">Test Logout</button>
            <div id="login-result"></div>
        </div>

        <!-- Test 2: Session Check -->
        <div class="test-section">
            <h2>Test 2: Check Session Status</h2>
            <p>Check if the session is properly maintained.</p>
            <button class="btn" onclick="checkSession()">Check Session via API</button>
            <button class="btn btn-success" onclick="location.reload()">Refresh Page</button>
            <div id="session-result"></div>
        </div>

        <!-- Test 3: Navigation -->
        <div class="test-section">
            <h2>Test 3: Navigate to Pages</h2>
            <p>Try accessing different pages to see if session persists.</p>
            <a href="index.php" class="btn">Login Page</a>
            <a href="dashboard.php" class="btn btn-success">Dashboard</a>
            <a href="login_success.php" class="btn">Login Success</a>
            <a href="system_check.php" class="btn">System Check</a>
        </div>

        <!-- Test 4: Cookie Check -->
        <div class="test-section">
            <h2>Test 4: Cookie & Session Info</h2>
            <table>
                <tr>
                    <th>Property</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Session ID</td>
                    <td><?php echo session_id(); ?></td>
                </tr>
                <tr>
                    <td>Session Name</td>
                    <td><?php echo session_name(); ?></td>
                </tr>
                <tr>
                    <td>Session Status</td>
                    <td><?php
                        $status = session_status();
                        echo $status == PHP_SESSION_ACTIVE ? 'ACTIVE' :
                             ($status == PHP_SESSION_NONE ? 'NONE' : 'DISABLED');
                    ?></td>
                </tr>
                <tr>
                    <td>PHPSESSID Cookie</td>
                    <td><?php echo $_COOKIE['PHPSESSID'] ?? 'Not Set'; ?></td>
                </tr>
                <tr>
                    <td>Session Save Path</td>
                    <td><?php echo session_save_path() ?: 'Default'; ?></td>
                </tr>
            </table>
        </div>

        <!-- Current Session Data -->
        <div class="test-section">
            <h2>Current Session Data</h2>
            <pre><?php print_r($_SESSION); ?></pre>
        </div>

        <div id="result"></div>
    </div>

    <script>
        function showResult(message, isError = false) {
            const resultDiv = document.getElementById('result');
            resultDiv.style.background = isError ? '#fee' : '#efe';
            resultDiv.style.color = isError ? '#c00' : '#060';
            resultDiv.textContent = typeof message === 'object' ?
                JSON.stringify(message, null, 2) : message;
        }

        async function testDirectLogin() {
            try {
                showResult('Attempting login...');

                const response = await fetch('auth_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin',  // Important for cookies
                    body: JSON.stringify({
                        email: 'admin@demo.local',
                        password: 'Admin123!'
                    })
                });

                const data = await response.json();
                document.getElementById('login-result').innerHTML =
                    '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                if (data.success) {
                    showResult('Login successful! Session ID: ' + (data.session_id || 'unknown'));

                    // Wait a moment then refresh to show session status
                    setTimeout(() => {
                        showResult('Refreshing page to show session status...');
                        location.reload();
                    }, 2000);
                } else {
                    showResult('Login failed: ' + data.message, true);
                }
            } catch (error) {
                showResult('Error: ' + error.message, true);
                document.getElementById('login-result').innerHTML =
                    '<pre style="color: red;">Error: ' + error.message + '</pre>';
            }
        }

        async function testLogout() {
            try {
                const response = await fetch('auth_api.php?action=logout', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                const data = await response.json();
                showResult('Logout response: ' + JSON.stringify(data));

                setTimeout(() => {
                    location.reload();
                }, 1000);
            } catch (error) {
                showResult('Logout error: ' + error.message, true);
            }
        }

        async function checkSession() {
            try {
                const response = await fetch('auth_api.php?action=check', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                const data = await response.json();
                document.getElementById('session-result').innerHTML =
                    '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                if (data.authenticated) {
                    showResult('Session is active for user: ' + data.user.name);
                } else {
                    showResult('Session is not active', true);
                }
            } catch (error) {
                showResult('Session check error: ' + error.message, true);
            }
        }

        // Check session on page load
        window.onload = function() {
            checkSession();
        };
    </script>
</body>
</html>