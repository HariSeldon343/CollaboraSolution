<?php
/**
 * Direct Authentication Test
 * Tests the authentication flow step by step
 */

// Start with clean error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Start output buffering to catch any errors
ob_start();

$response = [];

try {
    // Step 1: Load configuration
    $response['step1_config'] = 'Loading configuration...';

    // Define missing constants
    if (!defined('DB_PERSISTENT')) {
        define('DB_PERSISTENT', false);
    }
    if (!defined('LOG_LEVEL')) {
        define('LOG_LEVEL', 'ERROR');
    }

    require_once __DIR__ . '/config.php';
    $response['step1_config'] = 'OK - Config loaded';

    // Step 2: Test database connection
    $response['step2_db'] = 'Testing database connection...';

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

    $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
    $response['step2_db'] = 'OK - Database connected';

    // Step 3: Check users table
    $response['step3_table'] = 'Checking users table...';

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    $response['step3_table'] = 'OK - Users table has ' . $result['count'] . ' records';

    // Step 4: Find admin user
    $response['step4_admin'] = 'Finding admin user...';

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
        LIMIT 1
    ");

    $stmt->execute([':email' => 'admin@demo.local']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $response['step4_admin'] = 'OK - Admin user found';
        $response['admin_details'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_active' => $user['is_active'],
            'tenant_id' => $user['tenant_id'],
            'tenant_name' => $user['tenant_name'],
            'has_password_hash' => !empty($user['password_hash'])
        ];

        // Step 5: Test password verification
        $response['step5_password'] = 'Testing password verification...';

        $testPassword = 'Admin123!';
        if (password_verify($testPassword, $user['password_hash'])) {
            $response['step5_password'] = 'OK - Password "Admin123!" is valid';
        } else {
            $response['step5_password'] = 'FAILED - Password "Admin123!" is invalid';

            // Try to create a new hash for comparison
            $newHash = password_hash($testPassword, PASSWORD_DEFAULT);
            $response['debug_hash'] = [
                'stored_hash_length' => strlen($user['password_hash']),
                'stored_hash_sample' => substr($user['password_hash'], 0, 20) . '...',
                'new_hash_sample' => substr($newHash, 0, 20) . '...',
                'hash_algorithm' => PASSWORD_DEFAULT
            ];
        }

        // Step 6: Test session
        $response['step6_session'] = 'Testing session...';

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['test_value'] = 'test_' . time();
        $response['step6_session'] = 'OK - Session started, ID: ' . session_id();

    } else {
        $response['step4_admin'] = 'FAILED - Admin user not found';

        // List available users
        $stmt = $pdo->query("SELECT email, role FROM users LIMIT 5");
        $users = $stmt->fetchAll();
        $response['available_users'] = $users;
    }

    // Success
    $response['success'] = true;
    $response['message'] = 'Test completed successfully';

} catch (PDOException $e) {
    $response['success'] = false;
    $response['error'] = 'Database error: ' . $e->getMessage();
    $response['error_code'] = $e->getCode();

} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'General error: ' . $e->getMessage();

} finally {
    // Clear output buffer
    ob_end_clean();

    // Output JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>