<?php
/**
 * Quick Test - Verifica immediata del login
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>Quick Login Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 100px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
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
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin: 10px 0;
        }
        button:hover {
            background: #5a67d8;
        }
        .links {
            text-align: center;
            margin-top: 20px;
        }
        .links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Quick Login Test</h1>

        <div id="status"></div>

        <button onclick="runFix()">1. Run Database Fix First</button>
        <button onclick="testLogin()">2. Test Login</button>
        <button onclick="window.location.href='index.php'">3. Go to Login Page</button>

        <div id="result"></div>

        <div class="links">
            <a href="fix_auth_immediately.php" target="_blank">Fix Script</a> |
            <a href="test_auth.php" target="_blank">Full Test</a> |
            <a href="dashboard.php" target="_blank">Dashboard</a>
        </div>
    </div>

    <script>
    async function runFix() {
        const result = document.getElementById('result');
        result.innerHTML = '<div class="error">Loading fix script...</div>';

        try {
            const response = await fetch('fix_auth_immediately.php');
            const text = await response.text();

            if (text.includes('✅')) {
                result.innerHTML = '<div class="success">✅ Database fixed! Now try login.</div>';
            } else {
                result.innerHTML = '<div class="error">Check fix script output in new window</div>';
            }

            window.open('fix_auth_immediately.php', '_blank');
        } catch (error) {
            result.innerHTML = '<div class="error">Error: ' + error.message + '</div>';
        }
    }

    async function testLogin() {
        const result = document.getElementById('result');
        const status = document.getElementById('status');

        status.innerHTML = '<div class="success">Testing with admin@demo.local / Admin123!</div>';
        result.innerHTML = '<div class="error">Testing...</div>';

        try {
            const response = await fetch('auth_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    email: 'admin@demo.local',
                    password: 'Admin123!'
                })
            });

            const responseText = await response.text();
            console.log('Raw response:', responseText);

            try {
                const data = JSON.parse(responseText);

                if (data.success) {
                    result.innerHTML = `
                        <div class="success">
                            <h2>✅ LOGIN SUCCESSFUL!</h2>
                            <p><strong>User:</strong> ${data.user.name}</p>
                            <p><strong>Email:</strong> ${data.user.email}</p>
                            <p><strong>Role:</strong> ${data.user.role}</p>
                            <p><strong>Redirect:</strong> ${data.redirect}</p>
                            <p>You can now go to the login page!</p>
                        </div>
                    `;
                } else {
                    result.innerHTML = `
                        <div class="error">
                            ❌ Login failed: ${data.message}
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    `;
                }
            } catch (parseError) {
                result.innerHTML = `
                    <div class="error">
                        ❌ Invalid JSON response. This usually means PHP error.
                        <p>Response status: ${response.status}</p>
                        <p>Raw response:</p>
                        <pre>${responseText.substring(0, 500)}</pre>
                        <p><strong>Run the fix script first!</strong></p>
                    </div>
                `;
            }
        } catch (error) {
            result.innerHTML = `
                <div class="error">
                    ❌ Network error: ${error.message}
                </div>
            `;
        }
    }

    // Auto-check on load
    window.onload = function() {
        document.getElementById('status').innerHTML = `
            <div class="success">
                <strong>Instructions:</strong><br>
                1. Click "Run Database Fix First" to fix the database<br>
                2. Click "Test Login" to verify it works<br>
                3. Click "Go to Login Page" to use the system<br><br>
                <strong>Credentials:</strong> admin@demo.local / Admin123!
            </div>
        `;
    }
    </script>
</body>
</html>