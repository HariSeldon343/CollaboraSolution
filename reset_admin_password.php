<?php
/**
 * Reset Admin Password Script
 * Updates the admin user password to Admin123!
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Output as JSON
header('Content-Type: application/json; charset=utf-8');

// Start output buffering
ob_start();

$response = [];

try {
    // Load configuration
    if (!defined('DB_PERSISTENT')) {
        define('DB_PERSISTENT', false);
    }
    if (!defined('LOG_LEVEL')) {
        define('LOG_LEVEL', 'ERROR');
    }

    require_once __DIR__ . '/config.php';

    // Connect to database
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

    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = :email");
    $stmt->execute([':email' => 'admin@demo.local']);
    $user = $stmt->fetch();

    if ($user) {
        // User exists, update password
        $newPassword = 'Admin123!';
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([
            ':hash' => $newHash,
            ':id' => $user['id']
        ]);

        $response['success'] = true;
        $response['message'] = 'Admin password updated successfully';
        $response['user_id'] = $user['id'];
        $response['email'] = $user['email'];
        $response['new_password'] = $newPassword;

        // Verify the update
        $verifyStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $verifyStmt->execute([':id' => $user['id']]);
        $updatedUser = $verifyStmt->fetch();

        if (password_verify($newPassword, $updatedUser['password_hash'])) {
            $response['verification'] = 'Password verification successful';
        } else {
            $response['verification'] = 'Warning: Password verification failed after update';
        }

    } else {
        // User doesn't exist, create it
        $response['creating_user'] = true;

        // Check if tenants table exists and get first tenant
        $tenantId = null;
        try {
            $tenantStmt = $pdo->query("SELECT id FROM tenants LIMIT 1");
            $tenant = $tenantStmt->fetch();
            if ($tenant) {
                $tenantId = $tenant['id'];
            }
        } catch (Exception $e) {
            // Tenants table might not exist
            $tenantId = null;
        }

        // Create admin user
        $newPassword = 'Admin123!';
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $insertStmt = $pdo->prepare("
            INSERT INTO users (
                name, email, password_hash, role, is_active, tenant_id, created_at, updated_at
            ) VALUES (
                :name, :email, :hash, :role, :active, :tenant_id, NOW(), NOW()
            )
        ");

        $insertStmt->execute([
            ':name' => 'Administrator',
            ':email' => 'admin@demo.local',
            ':hash' => $newHash,
            ':role' => 'admin',
            ':active' => 1,
            ':tenant_id' => $tenantId
        ]);

        $response['success'] = true;
        $response['message'] = 'Admin user created successfully';
        $response['user_id'] = $pdo->lastInsertId();
        $response['email'] = 'admin@demo.local';
        $response['new_password'] = $newPassword;
    }

    // List all users for reference
    $allUsersStmt = $pdo->query("SELECT id, email, role, is_active FROM users");
    $response['all_users'] = $allUsersStmt->fetchAll();

} catch (PDOException $e) {
    $response['success'] = false;
    $response['error'] = 'Database error: ' . $e->getMessage();
    $response['error_code'] = $e->getCode();

    // If it's a table not found error, provide setup instructions
    if (strpos($e->getMessage(), 'users') !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
        $response['help'] = 'The users table does not exist. Please run the database initialization script first.';
        $response['setup_url'] = '/CollaboraNexio/setup/init_database.php';
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'General error: ' . $e->getMessage();

} finally {
    // Clear output buffer and output JSON
    ob_end_clean();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>