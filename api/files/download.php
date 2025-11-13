<?php
/**
 * Download File API Endpoint
 *
 * Download di file con controllo accessi
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../config.php';  // Config should be loaded first
require_once __DIR__ . '/../../includes/db.php';  // Load Database class
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/file_helper.php';

// Initialize API environment but don't send JSON headers yet
require_once __DIR__ . '/../../includes/session_init.php';

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Non autorizzato', 'success' => false]));
}

// Get current user info
$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'];
$userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user';

// Get database connection
$db = Database::getInstance();

// Get parameters
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$thumbnail = isset($_GET['thumbnail']) && $_GET['thumbnail'] === 'true';
$inline = isset($_GET['inline']) && $_GET['inline'] === 'true';
$token = $_GET['token'] ?? '';

// Validate file ID
if ($fileId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'ID file non valido', 'success' => false]));
}

try {
    // Get file info
    $file = $db->fetchOne(
        "SELECT * FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if (!$file) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'File non trovato o accesso negato', 'success' => false]));
    }

    // Can't download folders
    if ($file['is_folder']) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Non è possibile scaricare una cartella', 'success' => false]));
    }

    // Determine which file to serve
    if ($thumbnail && $file['thumbnail_path']) {
        $filePath = dirname(dirname(__DIR__)) . '/' . $file['thumbnail_path'];
        $fileName = 'thumb_' . $file['name'];
        $mimeType = 'image/jpeg'; // Thumbnails are always JPEG
    } else {
        $filePath = dirname(dirname(__DIR__)) . '/' . $file['path'];
        $fileName = $file['name'];
        $mimeType = $file['mime_type'];
    }

    // Check if file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'File fisico non trovato', 'success' => false]));
    }

    // Get file size
    $fileSize = filesize($filePath);

    // Audit log - Track file download (only for non-thumbnail downloads)
    if (!$thumbnail) {
        try {
            require_once '../../includes/audit_helper.php';
            AuditLogger::logFileDownload(
                $userId,
                $tenantId,
                $fileId,
                $file['name'],
                $fileSize
            );
        } catch (Exception $e) {
            error_log("[AUDIT LOG FAILURE] File download tracking failed: " . $e->getMessage());
        }
    }

    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set headers for download
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    if ($inline && in_array($file['extension'], ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'])) {
        // Display inline in browser
        header('Content-Disposition: inline; filename="' . $fileName . '"');
    } else {
        // Force download
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
    }

    // Support for range requests (for video/audio streaming)
    if (isset($_SERVER['HTTP_RANGE'])) {
        handleRangeRequest($filePath, $fileSize, $mimeType);
    } else {
        // Output file
        readfile($filePath);
    }

    exit();

} catch (Exception $e) {
    error_log('Download error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'error' => 'Errore durante il download del file',
        'success' => false,
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]));
}

/**
 * Handle HTTP range requests for streaming
 */
function handleRangeRequest(string $filePath, int $fileSize, string $mimeType): void {
    $range = $_SERVER['HTTP_RANGE'];
    list($sizeUnit, $rangeOrig) = explode('=', $range, 2);

    if ($sizeUnit != 'bytes') {
        http_response_code(416);
        header("Content-Range: bytes */$fileSize");
        return;
    }

    // Multiple ranges could be specified at the same time, but we only handle single ranges
    list($range, $extraRanges) = explode(',', $rangeOrig, 2);

    // Figure out download piece from range (if set)
    list($seekStart, $seekEnd) = explode('-', $range, 2);

    // Set start and end based on range (if set), else set defaults
    $seekEnd = (empty($seekEnd)) ? ($fileSize - 1) : min(abs(intval($seekEnd)), ($fileSize - 1));
    $seekStart = (empty($seekStart) || $seekEnd < abs(intval($seekStart))) ? 0 : max(abs(intval($seekStart)), 0);

    // Only send partial content header if downloading a piece of the file (IE workaround)
    if ($seekStart > 0 || $seekEnd < ($fileSize - 1)) {
        http_response_code(206);
        header('Content-Range: bytes ' . $seekStart . '-' . $seekEnd . '/' . $fileSize);
        header('Content-Length: ' . ($seekEnd - $seekStart + 1));
    } else {
        header("Content-Length: $fileSize");
    }

    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mimeType);

    $fp = fopen($filePath, 'rb');
    fseek($fp, $seekStart);

    while (!feof($fp)) {
        print(fread($fp, 1024 * 8));
        ob_flush();
        flush();

        if (connection_status() != 0) {
            break;
        }
    }

    fclose($fp);
}