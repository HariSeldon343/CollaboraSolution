<?php
/**
 * Fix User Management APIs
 * This script tests and fixes the 401 errors in user management APIs
 */

session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix User APIs - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        h1 { color: #333; text-align: center; }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #28a745;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #dc3545;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #17a2b8;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
            font-size: 16px;
        }
        button:hover {
            background: #5a67d8;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
        }
        .fix-button {
            background: #28a745;
        }
        .fix-button:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Fix User Management APIs</h1>

        <?php
        // Check current session status
        $hasSession = !empty($_SESSION);
        $hasUserId = isset($_SESSION['user_id']);
        $hasUserRole = isset($_SESSION['user_role']);
        $hasRole = isset($_SESSION['role']);
        $hasCsrf = isset($_SESSION['csrf_token']);
        ?>

        <div class="info">
            <h3>Current Session Status:</h3>
            <table>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Session Active</td>
                    <td><?php echo $hasSession ? '✅' : '❌'; ?></td>
                    <td><?php echo session_id() ? substr(session_id(), 0, 20) . '...' : 'No session'; ?></td>
                </tr>
                <tr>
                    <td>user_id</td>
                    <td><?php echo $hasUserId ? '✅' : '❌'; ?></td>
                    <td><?php echo $_SESSION['user_id'] ?? 'Not set'; ?></td>
                </tr>
                <tr>
                    <td>user_role (new)</td>
                    <td><?php echo $hasUserRole ? '✅' : '❌'; ?></td>
                    <td><?php echo $_SESSION['user_role'] ?? 'Not set'; ?></td>
                </tr>
                <tr>
                    <td>role (old)</td>
                    <td><?php echo $hasRole ? '✅' : '❌'; ?></td>
                    <td><?php echo $_SESSION['role'] ?? 'Not set'; ?></td>
                </tr>
                <tr>
                    <td>csrf_token</td>
                    <td><?php echo $hasCsrf ? '✅' : '❌'; ?></td>
                    <td><?php echo $hasCsrf ? substr($_SESSION['csrf_token'], 0, 20) . '...' : 'Not set'; ?></td>
                </tr>
            </table>
        </div>

        <?php if (!$hasUserId): ?>
            <div class="warning">
                <h3>⚠️ You are not logged in!</h3>
                <p>Please login first, then come back to this page.</p>
                <button onclick="window.location.href='index.php'">Go to Login</button>
            </div>
        <?php else: ?>
            <?php if (!$hasUserRole && $hasRole): ?>
                <div class="warning">
                    <h3>⚠️ Session Issue Detected!</h3>
                    <p>Your session has 'role' but not 'user_role'. This needs to be fixed.</p>
                    <button class="fix-button" onclick="fixSession()">Fix Session Now</button>
                </div>
            <?php endif; ?>

            <div class="info">
                <h3>Test APIs:</h3>
                <button onclick="testAPI('check_session_api.php')">Test Session API</button>
                <button onclick="testAPI('api/users/list.php?page=1&search=')">Test Users List</button>
                <button onclick="testAPI('api/users/tenants.php')">Test Tenants API</button>
            </div>

            <div id="results"></div>
        <?php endif; ?>
    </div>

    <script>
        const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

        async function testAPI(endpoint) {
            const results = document.getElementById('results');
            results.innerHTML = '<div class="info">Testing ' + endpoint + '...</div>';

            try {
                const headers = {};
                if (csrfToken) {
                    headers['X-CSRF-Token'] = csrfToken;
                }

                const response = await fetch(endpoint, {
                    headers: headers,
                    credentials: 'same-origin'
                });

                const text = await response.text();
                console.log('Response:', text);

                try {
                    const data = JSON.parse(text);

                    if (response.ok) {
                        results.innerHTML = '<div class="success">✅ API Working! Status: ' + response.status + '</div>' +
                                          '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                    } else {
                        results.innerHTML = '<div class="error">❌ API Error! Status: ' + response.status + '</div>' +
                                          '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                    }
                } catch (e) {
                    results.innerHTML = '<div class="error">❌ Invalid JSON Response! Status: ' + response.status + '</div>' +
                                      '<pre>' + text.substring(0, 1000) + '</pre>';
                }
            } catch (error) {
                results.innerHTML = '<div class="error">❌ Network Error: ' + error.message + '</div>';
            }
        }

        async function fixSession() {
            const results = document.getElementById('results');
            results.innerHTML = '<div class="info">Fixing session...</div>';

            try {
                const response = await fetch('fix_session.php', {
                    method: 'POST',
                    credentials: 'same-origin'
                });

                const data = await response.json();

                if (data.success) {
                    results.innerHTML = '<div class="success">✅ Session fixed! Reloading page...</div>';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    results.innerHTML = '<div class="error">❌ Failed to fix session: ' + data.message + '</div>';
                }
            } catch (error) {
                results.innerHTML = '<div class="error">❌ Error: ' + error.message + '</div>';
            }
        }

        <?php if ($hasUserId): ?>
        // Auto-test session API on load
        window.onload = function() {
            testAPI('check_session_api.php');
        }
        <?php endif; ?>
    </script>
</body>
</html>