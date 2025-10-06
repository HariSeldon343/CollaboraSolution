<?php
/**
 * Quick Database Fix for Authentication
 * Fixes the users table and creates admin user
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'collaboranexio';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Database Authentication</title>
    <style>
        body { font-family: monospace; max-width: 1000px; margin: 50px auto; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 5px; overflow-x: auto; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🔧 Fix Database Authentication</h1>

    <?php
    try {
        // Connect to MySQL
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<div class='success'>✅ Connected to MySQL</div>";

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS $database");
        $pdo->exec("USE $database");
        echo "<div class='success'>✅ Database '$database' selected</div>";

        // Disable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        echo "<div class='info'>⚠️ Foreign key checks disabled</div>";

        // Drop existing users table
        $pdo->exec("DROP TABLE IF EXISTS users");
        echo "<div class='info'>📦 Dropped existing users table</div>";

        // Create new users table with correct schema
        $sql = "CREATE TABLE users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED DEFAULT 1,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user','manager','admin','super_admin') DEFAULT 'user',
            is_active TINYINT(1) DEFAULT 1,
            avatar VARCHAR(255) DEFAULT NULL,
            last_login TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_tenant (tenant_id),
            INDEX idx_email (email),
            INDEX idx_role (role),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $pdo->exec($sql);
        echo "<div class='success'>✅ Created users table with correct schema</div>";

        // Check if tenants table exists, create if not
        $result = $pdo->query("SHOW TABLES LIKE 'tenants'");
        if ($result->rowCount() == 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE,
                domain VARCHAR(255),
                status ENUM('active','inactive','trial') DEFAULT 'active',
                max_users INT DEFAULT 10,
                plan_type VARCHAR(50) DEFAULT 'basic',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Insert default tenant
            $pdo->exec("INSERT INTO tenants (id, name, code, status) VALUES (1, 'Demo Company', 'demo', 'active')");
            echo "<div class='success'>✅ Created tenants table and demo tenant</div>";
        }

        // Generate password hash for Admin123!
        $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        echo "<div class='info'>🔑 Generated password hash for 'Admin123!'</div>";

        // Insert test users
        $stmt = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password_hash, role, is_active) VALUES
            (1, 'Admin User', 'admin@demo.local', :hash, 'admin', 1),
            (1, 'Manager User', 'manager@demo.local', :hash, 'manager', 1),
            (1, 'Test User 1', 'user1@demo.local', :hash, 'user', 1),
            (1, 'Test User 2', 'user2@demo.local', :hash, 'user', 1)
        ");
        $stmt->execute(['hash' => $passwordHash]);
        echo "<div class='success'>✅ Created 4 test users</div>";

        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        echo "<div class='info'>✅ Foreign key checks re-enabled</div>";

        // Show created users
        $result = $pdo->query("SELECT id, name, email, role, is_active FROM users");
        $users = $result->fetchAll(PDO::FETCH_ASSOC);

        echo "<h2>Created Users:</h2>";
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Password</th><th>Role</th><th>Active</th></tr>";
        foreach ($users as $user) {
            $active = $user['is_active'] ? '✅' : '❌';
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td><strong>{$user['email']}</strong></td>";
            echo "<td>Admin123!</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>{$active}</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<h2 class='success'>✅ Database Fixed Successfully!</h2>";
        echo "<p>You can now login with:</p>";
        echo "<ul>";
        echo "<li><strong>admin@demo.local</strong> / Admin123! (Admin)</li>";
        echo "<li><strong>manager@demo.local</strong> / Admin123! (Manager)</li>";
        echo "<li><strong>user1@demo.local</strong> / Admin123! (User)</li>";
        echo "<li><strong>user2@demo.local</strong> / Admin123! (User)</li>";
        echo "</ul>";

    } catch (PDOException $e) {
        echo "<div class='error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>

    <div style="margin-top: 30px;">
        <h3>Test Login:</h3>
        <button onclick="testLogin()">Test Login API</button>
        <button onclick="window.location.href='index.php'">Go to Login Page</button>
        <button onclick="window.location.href='test_login_flow.php'">Test Login Flow</button>
        <div id="testResult"></div>
    </div>

    <script>
    async function testLogin() {
        const resultDiv = document.getElementById('testResult');
        resultDiv.innerHTML = '<p>Testing login with admin@demo.local...</p>';

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

            const text = await response.text();
            console.log('Response:', text);

            try {
                const data = JSON.parse(text);
                if (data.success) {
                    resultDiv.innerHTML = '<div class="success">✅ Login successful! User: ' + data.user.name + '</div>';
                } else {
                    resultDiv.innerHTML = '<div class="error">❌ Login failed: ' + data.message + '</div>';
                }
            } catch (e) {
                resultDiv.innerHTML = '<div class="error">❌ Invalid JSON response: <pre>' + text + '</pre></div>';
            }
        } catch (error) {
            resultDiv.innerHTML = '<div class="error">❌ Network error: ' + error.message + '</div>';
        }
    }
    </script>
</body>
</html>