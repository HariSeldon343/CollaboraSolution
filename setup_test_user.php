<?php
/**
 * Setup test user for CollaboraNexio
 * Creates the admin@demo.com user with Admin123! password
 */

// Suppress warnings for clean output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// Define missing constants
if (!defined('DB_PERSISTENT')) {
    define('DB_PERSISTENT', false);
}
if (!defined('LOG_LEVEL')) {
    define('LOG_LEVEL', 'ERROR');
}

require_once __DIR__ . '/config.php';

echo "\n=== CollaboraNexio Test User Setup ===\n\n";

try {
    // Create database connection
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "✓ Connected to database\n";

    // First check if tenants table exists and has data
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $result = $stmt->fetch();
    $tenantCount = $result['count'];

    if ($tenantCount == 0) {
        echo "Creating default tenant...\n";
        $stmt = $pdo->prepare("
            INSERT INTO tenants (name, code, is_active, created_at)
            VALUES (:name, :code, 1, NOW())
        ");
        $stmt->execute([
            ':name' => 'Demo Company',
            ':code' => 'DEMO'
        ]);
        $tenantId = $pdo->lastInsertId();
        echo "✓ Created tenant: Demo Company (ID: $tenantId)\n";
    } else {
        // Get first tenant
        $stmt = $pdo->query("SELECT id, name FROM tenants LIMIT 1");
        $tenant = $stmt->fetch();
        $tenantId = $tenant['id'];
        echo "✓ Using existing tenant: {$tenant['name']} (ID: $tenantId)\n";
    }

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE email = :email");
    $stmt->execute([':email' => 'admin@demo.com']);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        echo "\nUser admin@demo.com already exists (ID: {$existingUser['id']})\n";
        echo "Updating password to Admin123!\n";

        // Update the user's password
        $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password_hash,
                is_active = 1,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':id' => $existingUser['id']
        ]);
        echo "✓ Password updated successfully\n";

    } else {
        echo "\nCreating new user admin@demo.com...\n";

        // Create the user
        $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (
                tenant_id,
                name,
                email,
                password_hash,
                role,
                is_active,
                created_at
            ) VALUES (
                :tenant_id,
                :name,
                :email,
                :password_hash,
                :role,
                1,
                NOW()
            )
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name' => 'Admin User',
            ':email' => 'admin@demo.com',
            ':password_hash' => $passwordHash,
            ':role' => 'admin'
        ]);
        $userId = $pdo->lastInsertId();
        echo "✓ Created user admin@demo.com (ID: $userId)\n";
    }

    // Verify the user can be queried correctly
    echo "\nVerifying user setup...\n";
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
    $stmt->execute([':email' => 'admin@demo.com']);
    $user = $stmt->fetch();

    if ($user) {
        echo "✓ User found in database\n";
        echo "  - Name: {$user['name']}\n";
        echo "  - Email: {$user['email']}\n";
        echo "  - Role: {$user['role']}\n";
        echo "  - Tenant: {$user['tenant_name']} ({$user['tenant_code']})\n";
        echo "  - Active: " . ($user['is_active'] ? 'Yes' : 'No') . "\n";

        // Test password verification
        if (password_verify('Admin123!', $user['password_hash'])) {
            echo "✓ Password verification successful\n";
        } else {
            echo "✗ Password verification failed\n";
        }
    } else {
        echo "✗ User not found in database\n";
    }

    echo "\n=== Setup Complete ===\n";
    echo "\nYou can now login with:\n";
    echo "  Email: admin@demo.com\n";
    echo "  Password: Admin123!\n\n";

} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nPlease check:\n";
    echo "1. XAMPP MySQL is running\n";
    echo "2. Database 'collaboranexio' exists\n";
    echo "3. Tables 'users' and 'tenants' exist\n\n";
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
}
?>