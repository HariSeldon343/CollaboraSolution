<?php
// Simple API test
header('Content-Type: application/json');

// Test direct login
require_once 'config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);

    // Test query
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute(['admin@demo.com']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Test password
        $passwordOk = password_verify('Admin123!', $user['password_hash']);

        echo json_encode([
            'success' => true,
            'database' => 'connected',
            'user_found' => true,
            'password_check' => $passwordOk,
            'user_data' => [
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>