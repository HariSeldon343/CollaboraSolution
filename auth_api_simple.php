<?php
/**
 * Simplified Authentication API - For Testing
 * Minimal implementation to identify issues
 */

// Absolute minimum error handling to prevent output
error_reporting(0);
ini_set('display_errors', '0');

// Set JSON header first
header('Content-Type: application/json; charset=utf-8');

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Define constants if missing
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_PORT')) define('DB_PORT', 3306);
    if (!defined('DB_NAME')) define('DB_NAME', 'collaboranexio');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
    if (!defined('DEBUG_MODE')) define('DEBUG_MODE', true);

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Handle OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        ob_end_clean();
        http_response_code(200);
        exit();
    }

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

    // Handle POST request (login)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get input
        $input = json_decode(file_get_contents('php://input'), true);

        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        // Validate input
        if (empty($email) || empty($password)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email e password sono richiesti']);
            exit;
        }

        // Query user
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.name,
                u.email,
                u.password_hash,
                u.role,
                u.tenant_id,
                u.is_active
            FROM users u
            WHERE u.email = :email
            LIMIT 1
        ");

        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        // Check user exists
        if (!$user) {
            ob_end_clean();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Credenziali non valide'
            ]);
            exit;
        }

        // Check if active
        if ($user['is_active'] != 1) {
            ob_end_clean();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Account non attivo'
            ]);
            exit;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            ob_end_clean();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Credenziali non valide'
            ]);
            exit;
        }

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        // Force session save
        session_write_close();
        session_start();

        // Return success
        ob_end_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login effettuato con successo',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'tenant_id' => $user['tenant_id']
            ],
            'redirect' => 'dashboard.php',
            'session_id' => session_id()
        ]);
        exit;
    }

    // Handle GET request
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = $_GET['action'];

        if ($action === 'check') {
            $authenticated = isset($_SESSION['user_id']);
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'authenticated' => $authenticated,
                'session_id' => session_id()
            ]);
            exit;
        }

        if ($action === 'logout') {
            session_destroy();
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Logout effettuato'
            ]);
            exit;
        }
    }

    // Default response
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Richiesta non valida'
    ]);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore database',
        'error' => DEBUG_MODE ? $e->getMessage() : null
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore server',
        'error' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>