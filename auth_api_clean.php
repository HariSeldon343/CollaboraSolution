<?php
// Clean Authentication API - No extra output
error_reporting(0);
ini_set('display_errors', '0');

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

// Start output buffering
ob_start();

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

// Load config
require_once __DIR__ . '/config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
function getConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

// Clean any output and send JSON
function sendJson($data) {
    if (ob_get_level()) ob_end_clean();
    echo json_encode($data);
    exit();
}

// Handle requests
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Login
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            sendJson(['success' => false, 'message' => 'Email and password required']);
        }

        $pdo = getConnection();
        if (!$pdo) {
            sendJson(['success' => false, 'message' => 'Database connection failed']);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            sendJson(['success' => false, 'message' => 'Invalid credentials']);
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

        sendJson([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'redirect' => 'dashboard.php',
            'session_id' => session_id()
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = $_GET['action'];

        switch ($action) {
            case 'check':
                $logged_in = isset($_SESSION['user_id']) && isset($_SESSION['logged_in']);
                sendJson([
                    'success' => true,
                    'authenticated' => $logged_in,
                    'user' => $logged_in ? [
                        'id' => $_SESSION['user_id'],
                        'name' => $_SESSION['user_name'] ?? '',
                        'email' => $_SESSION['user_email'] ?? '',
                        'role' => $_SESSION['user_role'] ?? ''
                    ] : null
                ]);
                break;

            case 'logout':
                $_SESSION = [];
                session_destroy();
                sendJson(['success' => true, 'message' => 'Logged out']);
                break;

            case 'session':
                sendJson([
                    'success' => true,
                    'session_id' => session_id(),
                    'session_data' => $_SESSION
                ]);
                break;

            default:
                sendJson(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        sendJson(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
?>