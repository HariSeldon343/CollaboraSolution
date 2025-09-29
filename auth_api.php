<?php
/**
 * CollaboraNexio - Authentication API
 * Handles user authentication with proper error handling and JSON responses
 */

// Suppress all PHP warnings/notices that could break JSON output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// Set JSON header immediately to ensure all output is treated as JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Enable output buffering to catch any unexpected output
ob_start();

try {
    // Define missing constants that db.php expects
    if (!defined('DB_PERSISTENT')) {
        define('DB_PERSISTENT', false);
    }
    if (!defined('LOG_LEVEL')) {
        define('LOG_LEVEL', 'ERROR');
    }

    // Include configuration
    require_once __DIR__ . '/config.php';

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        // Simple session start without strict settings for compatibility
        session_start();
    }

    // CORS headers for development
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

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

        // IMPORTANTE: Forza il salvataggio della sessione immediatamente
        session_write_close();

        // Riapri la sessione per verificare che sia stata salvata
        session_start();

        // Log session info for debugging
        error_log('Session saved with ID: ' . session_id());
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

                // Destroy the session cookie
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
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
    // Ensure any buffered output is cleared
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}
?>