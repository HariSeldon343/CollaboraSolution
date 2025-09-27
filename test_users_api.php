<?php
/**
 * Test User Management APIs
 * Quick test to verify all user management APIs are working
 */

session_start();

// Simulate logged in user
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['user_name'] = 'Admin User';
    $_SESSION['user_email'] = 'admin@demo.local';
    $_SESSION['role'] = 'admin';
    $_SESSION['tenant_id'] = 1;
    $_SESSION['logged_in'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test User Management APIs</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
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
        h1 { color: #333; }
        h2 { color: #667eea; margin-top: 30px; }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #5a67d8;
        }
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
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 13px;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #17a2b8;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Test User Management APIs</h1>

        <div class="info">
            <strong>Session Info:</strong><br>
            User: <?php echo $_SESSION['user_name']; ?><br>
            Role: <?php echo $_SESSION['role']; ?><br>
            Tenant ID: <?php echo $_SESSION['tenant_id']; ?><br>
            CSRF Token: <?php echo substr($_SESSION['csrf_token'], 0, 20); ?>...
        </div>

        <h2>1. Test Tenants API</h2>
        <div class="test-section">
            <button onclick="testTenants()">Test api/users/tenants.php</button>
            <div id="tenants-result"></div>
        </div>

        <h2>2. Test Users List API</h2>
        <div class="test-section">
            <button onclick="testUsersList()">Test api/users/list.php</button>
            <div id="users-result"></div>
        </div>

        <h2>3. Test Create User API</h2>
        <div class="test-section">
            <button onclick="testCreateUser()">Test api/users/create.php</button>
            <div id="create-result"></div>
        </div>

        <h2>4. Test Toggle Status API</h2>
        <div class="test-section">
            <input type="number" id="toggle-user-id" placeholder="User ID" value="2">
            <button onclick="testToggleStatus()">Test api/users/toggle-status.php</button>
            <div id="toggle-result"></div>
        </div>

        <h2>5. Quick Database Check</h2>
        <div class="test-section">
            <button onclick="checkDatabase()">Check Database Tables</button>
            <div id="db-result"></div>
        </div>
    </div>

    <script>
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

        async function testTenants() {
            const resultDiv = document.getElementById('tenants-result');
            resultDiv.innerHTML = '<div class="info">Testing tenants API...</div>';

            try {
                const response = await fetch('api/users/tenants.php', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const responseText = await response.text();
                console.log('Tenants response:', responseText);

                try {
                    const data = JSON.parse(responseText);

                    if (data.success) {
                        const tenants = data.data || data.tenants || [];
                        let html = '<div class="success">✅ API Working! Found ' + tenants.length + ' tenant(s)</div>';

                        if (tenants.length > 0) {
                            html += '<table><tr><th>ID</th><th>Name</th><th>Code</th><th>Status</th><th>Users</th></tr>';
                            tenants.forEach(t => {
                                html += `<tr>
                                    <td>${t.id}</td>
                                    <td>${t.name}</td>
                                    <td>${t.code || '-'}</td>
                                    <td>${t.status}</td>
                                    <td>${t.current_users}/${t.max_users}</td>
                                </tr>`;
                            });
                            html += '</table>';
                        }

                        html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                        resultDiv.innerHTML = html;
                    } else {
                        resultDiv.innerHTML = '<div class="error">❌ Error: ' + (data.error || data.message || 'Unknown error') + '</div>';
                    }
                } catch (e) {
                    resultDiv.innerHTML = '<div class="error">❌ Invalid JSON response</div><pre>' + responseText + '</pre>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Network error: ' + error.message + '</div>';
            }
        }

        async function testUsersList() {
            const resultDiv = document.getElementById('users-result');
            resultDiv.innerHTML = '<div class="info">Testing users list API...</div>';

            try {
                const response = await fetch('api/users/list.php?page=1&search=', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const responseText = await response.text();
                console.log('Users response:', responseText);

                try {
                    const data = JSON.parse(responseText);

                    if (data.success) {
                        let html = '<div class="success">✅ API Working! Found ' + data.users.length + ' user(s)</div>';

                        if (data.users.length > 0) {
                            html += '<table><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Active</th></tr>';
                            data.users.forEach(u => {
                                const name = u.name || `${u.first_name || ''} ${u.last_name || ''}`.trim();
                                html += `<tr>
                                    <td>${u.id}</td>
                                    <td>${name}</td>
                                    <td>${u.email}</td>
                                    <td>${u.role}</td>
                                    <td>${u.is_active ? '✅' : '❌'}</td>
                                </tr>`;
                            });
                            html += '</table>';
                        }

                        html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                        resultDiv.innerHTML = html;
                    } else {
                        resultDiv.innerHTML = '<div class="error">❌ Error: ' + (data.error || data.message || 'Unknown error') + '</div>';
                    }
                } catch (e) {
                    resultDiv.innerHTML = '<div class="error">❌ Invalid JSON response</div><pre>' + responseText + '</pre>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Network error: ' + error.message + '</div>';
            }
        }

        async function testCreateUser() {
            const resultDiv = document.getElementById('create-result');
            resultDiv.innerHTML = '<div class="info">Creating test user...</div>';

            const formData = new FormData();
            formData.append('first_name', 'Test');
            formData.append('last_name', 'User');
            formData.append('email', 'test' + Date.now() + '@demo.local');
            formData.append('password', 'Test123!');
            formData.append('role', 'user');
            formData.append('tenant_id', '1');
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('api/users/create.php', {
                    method: 'POST',
                    body: formData
                });

                const responseText = await response.text();
                console.log('Create response:', responseText);

                try {
                    const data = JSON.parse(responseText);

                    if (data.success) {
                        resultDiv.innerHTML = '<div class="success">✅ User created successfully!</div><pre>' +
                            JSON.stringify(data, null, 2) + '</pre>';
                    } else {
                        resultDiv.innerHTML = '<div class="error">❌ Error: ' +
                            (data.error || data.message || 'Unknown error') + '</div>';
                    }
                } catch (e) {
                    resultDiv.innerHTML = '<div class="error">❌ Invalid JSON response</div><pre>' + responseText + '</pre>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Network error: ' + error.message + '</div>';
            }
        }

        async function testToggleStatus() {
            const userId = document.getElementById('toggle-user-id').value;
            const resultDiv = document.getElementById('toggle-result');
            resultDiv.innerHTML = '<div class="info">Toggling user status...</div>';

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('api/users/toggle-status.php', {
                    method: 'POST',
                    body: formData
                });

                const responseText = await response.text();
                console.log('Toggle response:', responseText);

                try {
                    const data = JSON.parse(responseText);

                    if (data.success) {
                        resultDiv.innerHTML = '<div class="success">✅ Status toggled successfully!</div><pre>' +
                            JSON.stringify(data, null, 2) + '</pre>';
                    } else {
                        resultDiv.innerHTML = '<div class="error">❌ Error: ' +
                            (data.error || data.message || 'Unknown error') + '</div>';
                    }
                } catch (e) {
                    resultDiv.innerHTML = '<div class="error">❌ Invalid JSON response</div><pre>' + responseText + '</pre>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Network error: ' + error.message + '</div>';
            }
        }

        async function checkDatabase() {
            const resultDiv = document.getElementById('db-result');
            resultDiv.innerHTML = '<div class="info">Checking database...</div>';

            try {
                const response = await fetch('system_check.php');
                const text = await response.text();

                // Extract relevant info from system check
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                const tables = doc.querySelectorAll('table');

                if (tables.length > 0) {
                    resultDiv.innerHTML = '<div class="success">✅ Database is accessible</div>' +
                        '<p>Check <a href="system_check.php" target="_blank">system_check.php</a> for full details</p>';
                } else {
                    resultDiv.innerHTML = '<div class="error">Could not retrieve database info</div>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Error: ' + error.message + '</div>';
            }
        }
    </script>
</body>
</html>