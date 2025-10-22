<?php
/**
 * Download File for Editor
 *
 * Secure endpoint per OnlyOffice per scaricare i file da editare
 * Valida JWT token e verifica permessi prima di servire il file
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// No standard session for OnlyOffice requests
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Include required files
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/document_editor_helper.php';

// CORS headers for OnlyOffice Document Server access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set headers for security
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Send error response and exit
 */
function sendError(string $message, int $code = 403): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit();
}

try {
    // ENHANCED LOGGING FOR DEBUGGING
    if (DEBUG_MODE) {
        error_log('=== OnlyOffice Download Request ===');
        error_log('Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log('Remote Address: ' . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
        error_log('User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
        error_log('PRODUCTION_MODE: ' . (defined('PRODUCTION_MODE') ? (PRODUCTION_MODE ? 'TRUE' : 'FALSE') : 'UNDEFINED'));
        error_log('ONLYOFFICE_DOWNLOAD_URL constant: ' . (defined('ONLYOFFICE_DOWNLOAD_URL') ? ONLYOFFICE_DOWNLOAD_URL : 'UNDEFINED'));
    }

    // Get parameters
    $file_id = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;

    // Get token from query parameter OR Authorization header
    $token = $_GET['token'] ?? '';

    // If no token in query, check Authorization header (OnlyOffice sends token here when using document.token)
    if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        // Extract token from "Bearer TOKEN" format
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    if (DEBUG_MODE) {
        error_log('File ID: ' . $file_id);
        error_log('Token source: ' . (!empty($_GET['token']) ? 'QUERY_PARAM' : (!empty($_SERVER['HTTP_AUTHORIZATION']) ? 'AUTH_HEADER' : 'NONE')));
        error_log('Token present: ' . (!empty($token) ? 'YES' : 'NO'));
        if (!empty($token)) {
            error_log('Token preview: ' . substr($token, 0, 50) . '...');
        }
    }

    if ($file_id <= 0) {
        if (DEBUG_MODE) {
            error_log('ERROR: Invalid file ID');
        }
        sendError('ID file non valido', 400);
    }

    if (empty($token)) {
        if (DEBUG_MODE) {
            error_log('ERROR: Token missing');
        }
        sendError('Token mancante', 401);
    }

    // Verify JWT token
    $payload = verifyOnlyOfficeJWT($token);

    if ($payload === false) {
        error_log('Invalid JWT token for file download: ' . $file_id);
        sendError('Token non valido', 403);
    }

    // Extract data from token
    $token_file_id = $payload['file_id'] ?? 0;
    $token_user_id = $payload['user_id'] ?? 0;
    $token_tenant_id = $payload['tenant_id'] ?? 0;
    $session_token = $payload['session_token'] ?? '';
    $permissions = $payload['permissions'] ?? [];

    // Verify file ID matches
    if ($token_file_id !== $file_id) {
        error_log(sprintf(
            'File ID mismatch: requested=%d, token=%d',
            $file_id,
            $token_file_id
        ));
        sendError('Token non valido per questo file', 403);
    }

    // Check if user has download permission
    if (!isset($permissions['download']) || !$permissions['download']) {
        error_log("User $token_user_id does not have download permission for file $file_id");
        sendError('Permessi insufficienti', 403);
    }

    // Get database connection
    $db = Database::getInstance();

    // Get file information with tenant isolation
    $file = $db->fetchOne(
        "SELECT f.*
         FROM files f
         WHERE f.id = ?
         AND f.tenant_id = ?
         AND f.deleted_at IS NULL",
        [$file_id, $token_tenant_id]
    );

    if (!$file) {
        error_log("File not found or access denied: file_id=$file_id, tenant_id=$token_tenant_id");
        sendError('File non trovato', 404);
    }

    // Build physical file path
    $filePath = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $file['file_path'];

    if (!file_exists($filePath)) {
        error_log("Physical file not found: $filePath");
        sendError('File fisico non trovato', 404);
    }

    // Verify file is readable
    if (!is_readable($filePath)) {
        error_log("File not readable: $filePath");
        sendError('File non leggibile', 500);
    }

    // Update session activity if session token provided
    if (!empty($session_token)) {
        updateSessionActivity($session_token);
    }

    // Get file info
    $fileSize = filesize($filePath);
    $mimeType = $file['mime_type'] ?? 'application/octet-stream';
    $fileName = $file['name'];

    // Log the download
    if (DEBUG_MODE) {
        error_log(sprintf(
            'OnlyOffice downloading file: id=%d, name=%s, size=%d, user=%d',
            $file_id,
            $fileName,
            $fileSize,
            $token_user_id
        ));
    }

    // Audit log
    try {
        $db->insert('audit_logs', [
            'user_id' => $token_user_id,
            'tenant_id' => $token_tenant_id,
            'action' => 'document_downloaded_for_editor',
            'entity_type' => 'document',
            'entity_id' => $file_id,
            'description' => "Documento scaricato per editor: {$fileName}",
            'new_values' => json_encode([
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'session_token' => substr($session_token, 0, 20) . '...'
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'severity' => 'info',
            'status' => 'success',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // Don't fail download if audit fails
        error_log('Failed to log download audit: ' . $e->getMessage());
    }

    // Set headers for file download
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
    header('Cache-Control: private, max-age=3600');
    header('Pragma: private');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

    // Prevent any output before file
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Handle range requests for large files
    $range = $_SERVER['HTTP_RANGE'] ?? '';

    if (!empty($range)) {
        // Parse range header
        if (preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches)) {
            $start = (int)$matches[1];
            $end = isset($matches[2]) ? (int)$matches[2] : $fileSize - 1;

            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                http_response_code(416); // Range Not Satisfiable
                header('Content-Range: bytes */' . $fileSize);
                exit();
            }

            $length = $end - $start + 1;

            http_response_code(206); // Partial Content
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . $length);

            // Read and send partial content
            $fp = fopen($filePath, 'rb');
            fseek($fp, $start);

            $chunkSize = 8192;
            $sent = 0;

            while (!feof($fp) && $sent < $length && connection_status() === 0) {
                $buffer = fread($fp, min($chunkSize, $length - $sent));
                echo $buffer;
                flush();
                $sent += strlen($buffer);
            }

            fclose($fp);
        }
    } else {
        // Send entire file
        // For large files, read in chunks to avoid memory issues
        if ($fileSize > 10 * 1024 * 1024) { // > 10MB
            $fp = fopen($filePath, 'rb');

            $chunkSize = 8192;
            while (!feof($fp) && connection_status() === 0) {
                echo fread($fp, $chunkSize);
                flush();
            }

            fclose($fp);
        } else {
            // Small file, read all at once
            readfile($filePath);
        }
    }

    exit();

} catch (Exception $e) {
    error_log('Download for editor error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendError('Errore interno del server', 500);
}