<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Authentication API</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
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
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #007bff;
        }
        .button {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        .button:hover {
            background: #0056b3;
        }
        .button.success {
            background: #28a745;
        }
        .button.danger {
            background: #dc3545;
        }
        .result {
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .result.success {
            border: 2px solid #28a745;
            background: #d4edda;
        }
        .result.error {
            border: 2px solid #dc3545;
            background: #f8d7da;
        }
        .credentials {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            margin-left: 10px;
            font-size: 12px;
        }
        .status.ok {
            background: #28a745;
            color: white;
        }
        .status.error {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Authentication API Test</h1>

        <div class="credentials">
            <h3>Test Credentials:</h3>
            <strong>Email:</strong> admin@demo.local<br>
            <strong>Password:</strong> Admin123!
        </div>

        <!-- Database Test -->
        <div class="test-section">
            <h3>1. Database Connection Test</h3>
            <button class="button" onclick="testDatabase()">Test Database</button>
            <div id="dbResult"></div>
        </div>

        <!-- API Test -->
        <div class="test-section">
            <h3>2. API Endpoint Test</h3>
            <button class="button" onclick="testAPI()">Test API Endpoint</button>
            <div id="apiResult"></div>
        </div>

        <!-- Login Test -->
        <div class="test-section">
            <h3>3. Login Test</h3>
            <button class="button success" onclick="testLogin()">Test Login</button>
            <div id="loginResult"></div>
        </div>

        <!-- Session Test -->
        <div class="test-section">
            <h3>4. Session Check Test</h3>
            <button class="button" onclick="checkSession()">Check Session</button>
            <div id="sessionResult"></div>
        </div>

        <!-- Logout Test -->
        <div class="test-section">
            <h3>5. Logout Test</h3>
            <button class="button danger" onclick="testLogout()">Test Logout</button>
            <div id="logoutResult"></div>
        </div>

        <!-- Fix Issues -->
        <div class="test-section" style="background: #fff3cd; border-color: #ffc107;">
            <h3>Quick Fix</h3>
            <p>If tests are failing, run the fix script:</p>
            <a href="fix_auth_immediately.php" class="button" style="background: #ffc107; color: #000;">
                Run Fix Script
            </a>
        </div>
    </div>

    <script>
        // Test database connection
        function testDatabase() {
            const resultDiv = document.getElementById('dbResult');
            resultDiv.innerHTML = 'Testing database connection...';

            <?php
            try {
                $pdo = new PDO('mysql:host=localhost;dbname=collaboranexio;charset=utf8mb4',
                               'root', '',
                               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                echo "resultDiv.className = 'result success';";
                echo "resultDiv.innerHTML = 'Database connected!\\nUsers in database: " . $result['count'] . "';";
            } catch (Exception $e) {
                echo "resultDiv.className = 'result error';";
                echo "resultDiv.innerHTML = 'Database error: " . addslashes($e->getMessage()) . "';";
            }
            ?>
        }

        // Test API endpoint
        async function testAPI() {
            const resultDiv = document.getElementById('apiResult');
            resultDiv.innerHTML = 'Testing API endpoint...';

            try {
                const response = await fetch('auth_api.php?action=check');
                const text = await response.text();

                try {
                    const data = JSON.parse(text);
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = 'API is responding with JSON!\n' + JSON.stringify(data, null, 2);
                } catch (e) {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = 'API returned invalid JSON:\n' + text;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = 'API request failed: ' + error.message;
            }
        }

        // Test login
        async function testLogin() {
            const resultDiv = document.getElementById('loginResult');
            resultDiv.innerHTML = 'Testing login...';

            try {
                const response = await fetch('auth_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        email: 'admin@demo.local',
                        password: 'Admin123!'
                    })
                });

                const text = await response.text();

                try {
                    const data = JSON.parse(text);

                    if (data.success) {
                        resultDiv.className = 'result success';
                        resultDiv.innerHTML = 'Login successful!\n' + JSON.stringify(data, null, 2);
                    } else {
                        resultDiv.className = 'result error';
                        resultDiv.innerHTML = 'Login failed:\n' + JSON.stringify(data, null, 2);
                    }
                } catch (e) {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = 'Invalid JSON response:\n' + text;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = 'Login request failed: ' + error.message;
            }
        }

        // Check session
        async function checkSession() {
            const resultDiv = document.getElementById('sessionResult');
            resultDiv.innerHTML = 'Checking session...';

            try {
                const response = await fetch('auth_api.php?action=check');
                const data = await response.json();

                if (data.authenticated) {
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = 'Session active!\n' + JSON.stringify(data, null, 2);
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = 'No active session\n' + JSON.stringify(data, null, 2);
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = 'Session check failed: ' + error.message;
            }
        }

        // Test logout
        async function testLogout() {
            const resultDiv = document.getElementById('logoutResult');
            resultDiv.innerHTML = 'Testing logout...';

            try {
                const response = await fetch('auth_api.php?action=logout');
                const data = await response.json();

                resultDiv.className = 'result success';
                resultDiv.innerHTML = 'Logout successful!\n' + JSON.stringify(data, null, 2);
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = 'Logout failed: ' + error.message;
            }
        }

        // Run initial database test
        window.onload = function() {
            testDatabase();
        };
    </script>
</body>
</html>