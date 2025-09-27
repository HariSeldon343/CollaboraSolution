<?php
/**
 * API Response Handler
 *
 * Provides standardized JSON response handling for all API endpoints
 * with proper error suppression and consistent response format
 */

// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// Allow CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Send a JSON response and terminate execution
 *
 * @param bool $success Success status
 * @param mixed $data Response data
 * @param string $message Optional message
 * @param int $code HTTP status code
 */
function api_response($success = true, $data = null, $message = '', $code = 200) {
    // Clear any previous output
    if (ob_get_length()) {
        ob_clean();
    }

    // Set HTTP status code
    http_response_code($code);

    // Build response array
    $response = [
        'success' => $success,
        'timestamp' => time(),
        'message' => $message
    ];

    // Add data if provided
    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }
    }

    // Output JSON
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Send an error response
 *
 * @param string $message Error message
 * @param int $code HTTP status code (default 400)
 * @param array $errors Optional array of detailed errors
 */
function api_error($message, $code = 400, $errors = []) {
    $data = empty($errors) ? null : ['errors' => $errors];
    api_response(false, $data, $message, $code);
}

/**
 * Send a success response
 *
 * @param mixed $data Response data
 * @param string $message Success message
 */
function api_success($data = null, $message = 'Operation successful') {
    api_response(true, $data, $message, 200);
}

/**
 * Validate required parameters
 *
 * @param array $params Parameters to check
 * @param array $required Required parameter names
 * @return array|null Validation errors or null if valid
 */
function validate_required($params, $required) {
    $errors = [];
    foreach ($required as $field) {
        if (!isset($params[$field]) || empty(trim($params[$field]))) {
            $errors[] = "Field '$field' is required";
        }
    }
    return empty($errors) ? null : $errors;
}

/**
 * Get JSON input from request body
 *
 * @return array Decoded JSON data
 */
function get_json_input() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON input', 400);
    }

    return $data ?: [];
}

/**
 * Database connection wrapper with error handling
 *
 * @return Database|null Database instance or null on failure
 */
function get_db_connection() {
    try {
        // Check if database class exists
        if (!class_exists('Database')) {
            require_once __DIR__ . '/db.php';
        }

        return Database::getInstance();
    } catch (Exception $e) {
        // Log the actual error for debugging
        error_log('Database connection failed: ' . $e->getMessage());

        // Return user-friendly error
        api_error('Database connection failed. Please check your configuration.', 500);
        return null;
    }
}

/**
 * Verify CSRF token
 *
 * @param string $token Token to verify
 * @return bool True if valid
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Start session if not already started
 */
function ensure_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Custom error handler to prevent PHP errors from breaking JSON output
 */
function api_error_handler($errno, $errstr, $errfile, $errline) {
    // Log the error
    error_log("API Error [$errno]: $errstr in $errfile on line $errline");

    // Don't output anything - let the script continue
    return true;
}

/**
 * Custom exception handler for uncaught exceptions
 */
function api_exception_handler($exception) {
    // Log the exception
    error_log("API Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());

    // Send JSON error response
    api_error('An unexpected error occurred', 500);
}

// Set custom error and exception handlers
set_error_handler('api_error_handler');
set_exception_handler('api_exception_handler');

// Start output buffering to catch any unexpected output
ob_start();
?>