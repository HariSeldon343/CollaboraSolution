<?php
/**
 * Simple test for auth_api.php login
 */

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

echo "\n=== Testing Login API ===\n\n";

try {
    // Test direct database connection first
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

    echo "✓ Database connection successful\n\n";

    // Test login query
    $email = 'admin@demo.com';
    $password = 'Admin123!';

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

    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "User found:\n";
        echo "  - Name: {$user['name']}\n";
        echo "  - Email: {$user['email']}\n";
        echo "  - Role: {$user['role']}\n";
        echo "  - Active: " . ($user['is_active'] ? 'Yes' : 'No') . "\n";
        echo "  - Tenant: {$user['tenant_name']}\n\n";

        if (password_verify($password, $user['password_hash'])) {
            echo "✓ Password verification: PASSED\n";
            echo "✓ Login would be successful\n\n";

            // Simulate what auth_api.php would return
            $response = [
                'success' => true,
                'message' => 'Login effettuato con successo',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'tenant_id' => $user['tenant_id'],
                    'tenant_name' => $user['tenant_name'],
                    'tenant_code' => $user['tenant_code']
                ],
                'redirect' => 'dashboard.php'
            ];

            echo "Expected JSON response:\n";
            echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

        } else {
            echo "✗ Password verification: FAILED\n";
        }
    } else {
        echo "✗ User not found in database\n";
    }

    echo "=== Testing auth_api.php directly ===\n\n";

    // Now test the actual API endpoint
    $apiUrl = 'http://localhost/CollaboraNexio/auth_api.php';
    $postData = json_encode([
        'email' => 'admin@demo.com',
        'password' => 'Admin123!'
    ]);

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $postData,
            'timeout' => 5
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);

    if ($response !== false) {
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "✓ API returned valid JSON\n";
            echo "Response:\n";
            echo json_encode($json, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "✗ API returned invalid JSON\n";
            echo "Raw response: " . substr($response, 0, 200) . "\n";
        }
    } else {
        echo "✗ Could not connect to API\n";
        echo "Make sure XAMPP Apache is running on port 80\n";
    }

} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";
?>