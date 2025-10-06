<?php
/**
 * CRITICAL FIX SCRIPT - Run this immediately to fix authentication
 * This will fix all database and authentication issues
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>CollaboraNexio - Emergency Auth Fix</h1>";
echo "<pre>";

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=collaboranexio;charset=utf8mb4',
                   'root', '',
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ Database connected successfully\n";
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}

// Fix 1: Add missing columns to tenants table
echo "\n=== FIXING DATABASE SCHEMA ===\n";

try {
    // Check if code column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'code'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN code VARCHAR(50) UNIQUE AFTER name");
        echo "✅ Added 'code' column to tenants table\n";
    } else {
        echo "ℹ️ 'code' column already exists in tenants\n";
    }

    // Check if plan_type column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'plan_type'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN plan_type VARCHAR(50) DEFAULT 'basic' AFTER code");
        echo "✅ Added 'plan_type' column to tenants table\n";
    } else {
        echo "ℹ️ 'plan_type' column already exists in tenants\n";
    }

    // Update existing tenants to have a code
    $pdo->exec("UPDATE tenants SET code = LOWER(REPLACE(name, ' ', '_')) WHERE code IS NULL");
    echo "✅ Updated tenant codes\n";

} catch (PDOException $e) {
    echo "⚠️ Schema fix warning: " . $e->getMessage() . "\n";
}

// Fix 2: Create activity_logs table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED,
        user_id INT UNSIGNED,
        action VARCHAR(255),
        entity_type VARCHAR(100),
        entity_id INT,
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id),
        KEY idx_user (user_id),
        KEY idx_entity (entity_type, entity_id),
        KEY idx_created (created_at)
    )");
    echo "✅ Activity logs table ready\n";
} catch (PDOException $e) {
    echo "⚠️ Activity logs table warning: " . $e->getMessage() . "\n";
}

// Fix 3: Check/Create default tenant
echo "\n=== CHECKING DEFAULT TENANT ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM tenants WHERE id = 1");
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        $pdo->exec("INSERT INTO tenants (id, name, code, plan_type, status)
                    VALUES (1, 'Demo Company', 'demo', 'enterprise', 'active')");
        echo "✅ Created default tenant\n";
    } else {
        echo "ℹ️ Default tenant exists: " . $tenant['name'] . "\n";
    }
} catch (PDOException $e) {
    echo "⚠️ Tenant check warning: " . $e->getMessage() . "\n";
}

// Fix 4: Create/Update admin user
echo "\n=== SETTING UP ADMIN USER ===\n";
try {
    $adminEmail = 'admin@demo.local';
    $adminPassword = 'Admin123!';
    $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

    // Check if admin exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $adminEmail]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        // Create admin user
        $stmt = $pdo->prepare("
            INSERT INTO users (tenant_id, name, email, password_hash, role, is_active)
            VALUES (1, 'Admin User', :email, :password, 'super_admin', 1)
        ");
        $stmt->execute([
            ':email' => $adminEmail,
            ':password' => $passwordHash
        ]);
        echo "✅ Created admin user\n";
    } else {
        // Update password
        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password,
                is_active = 1,
                role = 'super_admin'
            WHERE email = :email
        ");
        $stmt->execute([
            ':password' => $passwordHash,
            ':email' => $adminEmail
        ]);
        echo "✅ Updated admin user password\n";
    }

    echo "\nAdmin Credentials:\n";
    echo "  Email: $adminEmail\n";
    echo "  Password: $adminPassword\n";

} catch (PDOException $e) {
    echo "❌ Admin user error: " . $e->getMessage() . "\n";
}

// Fix 5: Test authentication directly
echo "\n=== TESTING AUTHENTICATION ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.password_hash,
            u.role,
            u.tenant_id,
            u.is_active,
            t.name as tenant_name,
            t.code as tenant_code
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        WHERE u.email = :email
    ");
    $stmt->execute([':email' => $adminEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($adminPassword, $user['password_hash'])) {
        echo "✅ Authentication test PASSED!\n";
        echo "  User: " . $user['name'] . "\n";
        echo "  Role: " . $user['role'] . "\n";
        echo "  Tenant: " . $user['tenant_name'] . "\n";
    } else {
        echo "❌ Authentication test FAILED!\n";
    }
} catch (PDOException $e) {
    echo "❌ Auth test error: " . $e->getMessage() . "\n";
}

// Fix 6: Clear any existing sessions
echo "\n=== CLEARING SESSIONS ===\n";
$sessionPath = session_save_path() ?: sys_get_temp_dir();
echo "Session path: $sessionPath\n";
session_start();
session_destroy();
echo "✅ Sessions cleared\n";

echo "\n=== FIX COMPLETE ===\n";
echo "Now try logging in with:\n";
echo "  URL: http://localhost:8888/CollaboraNexio/login.php\n";
echo "  Email: admin@demo.local\n";
echo "  Password: Admin123!\n";

echo "</pre>";

// Show button to test login
?>
<hr>
<h2>Quick Actions:</h2>
<p>
    <a href="login.php" style="padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">
        Go to Login Page
    </a>
    &nbsp;&nbsp;
    <a href="test_auth.php" style="padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;">
        Test Auth API
    </a>
</p>