<?php
/**
 * CollaboraNexio - Authentication API
 * Handles user authentication with proper error handling and JSON responses
 */

// CRITICAL: Suppress ALL error display to prevent HTML in JSON responses
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start output buffering IMMEDIATELY to catch any output
ob_start();

// Set error handler to log errors instead of displaying them
set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error [$severity]: $message in $file on line $line");
    return true; // Prevent default error handler
});

// Set exception handler for uncaught exceptions
set_exception_handler(function($exception) {
    error_log('Uncaught Exception: ' . $exception->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore interno del server',
        'error' => defined('DEBUG_MODE') && DEBUG_MODE ? $exception->getMessage() : null
    ]);
    exit;
});

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        error_log("Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}");
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Errore interno del server',
            'error' => defined('DEBUG_MODE') && DEBUG_MODE ? $error['message'] : null
        ]);
    }
});

// Set JSON header immediately to ensure all output is treated as JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Define missing constants that db.php expects
    if (!defined('DB_PERSISTENT')) {
        define('DB_PERSISTENT', false);
    }
    if (!defined('LOG_LEVEL')) {
        define('LOG_LEVEL', 'ERROR');
    }

    // Include configuration
    $config_file = __DIR__ . '/config.php';
    if (!file_exists($config_file)) {
        throw new Exception('Configuration file not found');
    }
    require_once $config_file;

    // Initialize session with centralized configuration (includes session_start)
    $session_file = __DIR__ . '/includes/session_init.php';
    if (!file_exists($session_file)) {
        // Fallback to basic session start if session_init.php doesn't exist
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    } else {
        require_once $session_file;
    }

    // CORS headers for development and Cloudflare tunnel
    $allowedOrigin = '*'; // You can specify specific origins if needed
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true'); // Important for session cookies

    // Handle OPTIONS request for CORS preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        ob_end_clean();
        http_response_code(200);
        exit();
    }

    // Function to create a fallback PDO connection if Database class fails
    function getFallbackConnection() {
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
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception('Database connection failed');
        }
    }

    // Try to get database connection
    $pdo = null;

    // First try to use the Database class if it exists
    if (file_exists(__DIR__ . '/includes/db.php')) {
        try {
            require_once __DIR__ . '/includes/db.php';
            if (class_exists('Database')) {
                $db = Database::getInstance();
                $pdo = $db->getConnection();
            }
        } catch (Exception $e) {
            // Database class failed, will try fallback
            error_log('Database class failed: ' . $e->getMessage());
        }
    }

    // If Database class failed or doesn't exist, use fallback
    if (!$pdo) {
        $pdo = getFallbackConnection();
    }

    // Process the request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get and validate input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input');
        }

        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        // Validate required fields
        if (empty($email) || empty($password)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email e password sono richiesti']);
            exit;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Formato email non valido'
            ]);
            exit;
        }

        // Query user with tenant information
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.name,
                u.email,
                u.password_hash,
                u.role,
                u.tenant_id,
                u.is_active,
                t.name as tenant_name
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE u.email = :email
            LIMIT 1
        ");

        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify user exists and is active
        if (!$user) {
            ob_end_clean();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Credenziali non valide'
            ]);
            exit;
        }

        // Check if user is active
        if ($user['is_active'] != 1) {
            ob_end_clean();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Account non attivo. Contattare l\'amministratore.'
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

        // Authentication successful - set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['role'] = $user['role'];  // Use 'role' as primary
        $_SESSION['user_role'] = $user['role'];  // Keep for backward compatibility
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['tenant_name'] = $user['tenant_name'] ?? 'Default';
        $_SESSION['tenant_code'] = 'default'; // Default code since column doesn't exist yet
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        // Genera token CSRF per la sessione
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // IMPORTANTE: Forza il salvataggio della sessione immediatamente
        session_write_close();

        // Riapri la sessione per verificare che sia stata salvata
        session_start();

        // Log session info for debugging (con nome cookie corretto)
        error_log('Session saved with cookie name: ' . session_name() . ', ID: ' . session_id());
        error_log('Session data after save: ' . json_encode($_SESSION));

        // Update last login timestamp
        try {
            $updateStmt = $pdo->prepare("
                UPDATE users
                SET last_login = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([':id' => $user['id']]);
        } catch (Exception $e) {
            // Log error but don't fail the login
            error_log('Failed to update last_login: ' . $e->getMessage());
        }

        // Return success response - redirect to login_success.php instead
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
                'tenant_id' => $user['tenant_id'],
                'tenant_name' => $user['tenant_name'] ?? 'Default',
                'tenant_code' => 'default' // Default code since column doesn't exist yet
            ],
            'redirect' => 'dashboard.php',  // Direct redirect to dashboard
            'session_id' => session_id(),  // Include session ID for debugging
            'session_saved' => true
        ]);
        exit;

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = $_GET['action'];

        switch ($action) {
            case 'check':
                // Check if user is authenticated
                $authenticated = isset($_SESSION['user_id']) && isset($_SESSION['logged_in']);

                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'authenticated' => $authenticated,
                    'user' => $authenticated ? [
                        'id' => $_SESSION['user_id'],
                        'name' => $_SESSION['user_name'] ?? '',
                        'email' => $_SESSION['user_email'] ?? '',
                        'role' => $_SESSION['user_role'] ?? '',
                        'tenant_id' => $_SESSION['tenant_id'] ?? null,
                        'tenant_name' => $_SESSION['tenant_name'] ?? 'Default'
                    ] : null
                ]);
                break;

            case 'logout':
                // Clear session data
                $_SESSION = [];

                // Destroy the session cookie with proper parameters
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();

                    // Use the same cookie configuration as session_init.php
                    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $cookieDomain = '';
                    if (strpos($currentHost, 'nexiosolution.it') !== false) {
                        $cookieDomain = '.nexiosolution.it';
                    }

                    // Destroy COLLAB_SID cookie
                    setcookie('COLLAB_SID', '', time() - 42000,
                        '/CollaboraNexio/', $cookieDomain,
                        $params["secure"], $params["httponly"]
                    );
                }

                // Destroy the session
                session_destroy();

                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'message' => 'Logout effettuato con successo'
                ]);
                break;

            case 'session':
                // Get session info
                ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'session_id' => session_id(),
                    'session_status' => session_status(),
                    'session_data' => $_SESSION
                ]);
                break;

            default:
                ob_end_clean();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Azione non valida'
                ]);
        }

    } else {
        // Method not allowed
        ob_end_clean();
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Metodo HTTP non permesso'
        ]);
    }

} catch (PDOException $e) {
    // Database errors
    error_log('Database error in auth_api.php: ' . $e->getMessage());

    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore di connessione al database. Verificare la configurazione.',
        'error' => DEBUG_MODE ? $e->getMessage() : null
    ]);

} catch (Exception $e) {
    // General errors
    error_log('Error in auth_api.php: ' . $e->getMessage());

    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => DEBUG_MODE ? $e->getMessage() : null
    ]);

} catch (Error $e) {
    // PHP errors
    error_log('PHP Error in auth_api.php: ' . $e->getMessage());

    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore interno del server',
        'error' => DEBUG_MODE ? $e->getMessage() : null
    ]);

} finally {
    // Nothing to do here - output is already sent
    // The ob_end_clean() is handled in the catch blocks before sending JSON
}
?>