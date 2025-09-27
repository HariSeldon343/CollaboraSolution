<?php
/**
 * Authentication System Fix Script
 * Diagnoses and fixes authentication issues
 */

// Start with output buffering
ob_start();

// Set headers
header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Authentication System</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .info { color: #17a2b8; }
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
        .test-result {
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid #ccc;
            background: #f8f9fa;
        }
        .test-result.success { border-left-color: #28a745; }
        .test-result.error { border-left-color: #dc3545; }
    </style>
</head>
<body>
    <h1>CollaboraNexio - Authentication System Fix</h1>

    <div class="card">
        <h2>Step 1: Configuration Check</h2>
        <?php
        $issues = [];
        $fixes = [];

        // Check config file
        if (file_exists('config.php')) {
            echo '<p class="success">✅ Config file exists</p>';

            // Define missing constants before including config
            if (!defined('DB_PERSISTENT')) {
                define('DB_PERSISTENT', false);
                echo '<p class="warning">⚠️ Added missing DB_PERSISTENT constant</p>';
            }
            if (!defined('LOG_LEVEL')) {
                define('LOG_LEVEL', 'ERROR');
                echo '<p class="warning">⚠️ Added missing LOG_LEVEL constant</p>';
            }

            require_once 'config.php';

            // Check database constants
            $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
            foreach ($required as $const) {
                if (defined($const)) {
                    echo "<p class='info'>✓ $const = " . constant($const) . "</p>";
                } else {
                    echo "<p class='error'>✗ $const not defined</p>";
                    $issues[] = "$const not defined";
                }
            }
        } else {
            echo '<p class="error">✗ Config file not found</p>';
            $issues[] = 'config.php not found';
        }
        ?>
    </div>

    <div class="card">
        <h2>Step 2: Database Connection</h2>
        <?php
        if (empty($issues)) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );

                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                echo '<p class="success">✅ Database connection successful</p>';

                // Check tables
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                echo '<p class="info">Tables found: ' . implode(', ', $tables) . '</p>';

                if (!in_array('users', $tables)) {
                    echo '<p class="error">✗ Users table not found</p>';
                    $issues[] = 'Users table missing';
                }

            } catch (PDOException $e) {
                echo '<p class="error">✗ Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
                $issues[] = 'Database connection failed';
            }
        }
        ?>
    </div>

    <div class="card">
        <h2>Step 3: Admin User Check</h2>
        <?php
        if (isset($pdo) && in_array('users', $tables ?? [])) {
            try {
                // Check for admin user
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
                $stmt->execute([':email' => 'admin@demo.local']);
                $admin = $stmt->fetch();

                if ($admin) {
                    echo '<p class="success">✅ Admin user exists</p>';
                    echo '<p class="info">ID: ' . $admin['id'] . '</p>';
                    echo '<p class="info">Name: ' . $admin['name'] . '</p>';
                    echo '<p class="info">Email: ' . $admin['email'] . '</p>';
                    echo '<p class="info">Role: ' . $admin['role'] . '</p>';
                    echo '<p class="info">Active: ' . ($admin['is_active'] ? 'Yes' : 'No') . '</p>';

                    // Test password
                    $testPassword = 'Admin123!';
                    if (password_verify($testPassword, $admin['password_hash'])) {
                        echo '<p class="success">✅ Password "Admin123!" is valid</p>';
                    } else {
                        echo '<p class="error">✗ Password "Admin123!" is invalid</p>';
                        $fixes[] = 'reset_password';
                    }
                } else {
                    echo '<p class="error">✗ Admin user not found</p>';
                    $fixes[] = 'create_admin';
                }
            } catch (Exception $e) {
                echo '<p class="error">✗ Error checking admin user: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }
        ?>
    </div>

    <div class="card">
        <h2>Step 4: Test Authentication API</h2>
        <div id="apiTestResult"></div>
        <button onclick="testAPI()">Test API</button>
        <button onclick="testSimpleAPI()">Test Simple API</button>
    </div>

    <?php if (!empty($fixes)): ?>
    <div class="card">
        <h2>Step 5: Apply Fixes</h2>
        <?php
        foreach ($fixes as $fix) {
            if ($fix === 'reset_password' && isset($pdo)) {
                echo '<p>Resetting admin password...</p>';
                try {
                    $newHash = password_hash('Admin123!', PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE email = :email");
                    $stmt->execute([
                        ':hash' => $newHash,
                        ':email' => 'admin@demo.local'
                    ]);
                    echo '<p class="success">✅ Password reset to: Admin123!</p>';
                } catch (Exception $e) {
                    echo '<p class="error">✗ Failed to reset password: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }

            if ($fix === 'create_admin' && isset($pdo)) {
                echo '<p>Creating admin user...</p>';
                try {
                    $newHash = password_hash('Admin123!', PASSWORD_DEFAULT);

                    // Check for tenant
                    $tenantId = null;
                    try {
                        $stmt = $pdo->query("SELECT id FROM tenants LIMIT 1");
                        $tenant = $stmt->fetch();
                        if ($tenant) {
                            $tenantId = $tenant['id'];
                        }
                    } catch (Exception $e) {
                        // Tenants might not exist
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, password_hash, role, is_active, tenant_id, created_at, updated_at)
                        VALUES (:name, :email, :hash, :role, :active, :tenant_id, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':name' => 'Administrator',
                        ':email' => 'admin@demo.local',
                        ':hash' => $newHash,
                        ':role' => 'admin',
                        ':active' => 1,
                        ':tenant_id' => $tenantId
                    ]);
                    echo '<p class="success">✅ Admin user created with password: Admin123!</p>';
                } catch (Exception $e) {
                    echo '<p class="error">✗ Failed to create admin: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }
        }
        ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Quick Links</h2>
        <button onclick="window.location.href='login.php'">Go to Login</button>
        <button onclick="window.location.href='test_auth_direct.php'">Test Auth Direct</button>
        <button onclick="window.location.href='reset_admin_password.php'">Reset Password Script</button>
        <button onclick="window.location.href='test_db.php'">Database Test</button>
    </div>

    <script>
        async function testAPI() {
            const resultDiv = document.getElementById('apiTestResult');
            resultDiv.innerHTML = '<p>Testing auth_api.php...</p>';

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
                let data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    resultDiv.innerHTML = `
                        <div class="test-result error">
                            <p class="error">✗ Invalid JSON response</p>
                            <p>Response:</p>
                            <pre>${text.substring(0, 500)}</pre>
                        </div>
                    `;
                    return;
                }

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="test-result success">
                            <p class="success">✅ API working correctly!</p>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="test-result error">
                            <p class="error">✗ API returned error</p>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="test-result error">
                        <p class="error">✗ Request failed</p>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }

        async function testSimpleAPI() {
            const resultDiv = document.getElementById('apiTestResult');
            resultDiv.innerHTML = '<p>Testing auth_api_simple.php...</p>';

            try {
                const response = await fetch('auth_api_simple.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        email: 'admin@demo.local',
                        password: 'Admin123!'
                    })
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="test-result success">
                            <p class="success">✅ Simple API working!</p>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="test-result error">
                            <p class="error">✗ Simple API error</p>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="test-result error">
                        <p class="error">✗ Request failed</p>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
    </script>
</body>
</html>
<?php
// End output buffering and send
ob_end_flush();
?>