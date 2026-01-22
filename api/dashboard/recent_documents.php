<?php
/**
 * Dashboard API - Get Recent Documents
 *
 * Returns the 10 most recently uploaded files/documents
 * with formatted file sizes and upload timestamps
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - limit (optional, default: 10, max: 50)
 * Response: Array of recent documents
 *
 * @package CollaboraNexio
 * @subpackage Dashboard API
 * @version 1.0.0
 * @since 2025-11-16
 */

declare(strict_types=1);

// API Authentication (BUG-011 pattern)
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();

// Force no-cache headers (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();  // IMMEDIATELY after init

$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];  // BUG-070: both 'id' and 'user_id' required
$userRole = $userInfo['role'];

// No CSRF required for GET requests (read-only operation)

// Database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

// ============================================
// REQUEST VALIDATION
// ============================================

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Metodo non consentito', 405);
}

// Multi-tenant parameter pattern (BUG-066)
$requestedTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;

if ($requestedTenantId !== null) {
    if ($userRole === 'super_admin') {
        $tenantId = $requestedTenantId; // explicit selection
    } else {
        // Validate via user_tenant_access
        $accessCheck = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?
             AND (deleted_at IS NULL OR deleted_at = '')",  // BUG-090: check both NULL and empty string
            [$userId, $requestedTenantId]
        );
        if ($accessCheck && $accessCheck['cnt'] > 0) {
            $tenantId = $requestedTenantId;
        } else {
            api_error('Non hai accesso a questo tenant', 403);
        }
    }
} else {
    // No tenant specified: super_admin sees all tenants, others see their own
    $tenantId = ($userRole === 'super_admin') ? null : ($userInfo['tenant_id'] ?? null);
}

// Limit parameter (optional)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1 || $limit > 50) {
    $limit = 10;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Format file size to human readable format
 *
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function formatFileSize(int $bytes): string {
    if ($bytes === 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log(1024));

    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Format timestamp to relative time in Italian
 *
 * @param string $timestamp Timestamp string
 * @return string Formatted relative time
 */
function formatRelativeTime(string $timestamp): string {
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 60) {
        return 'Adesso';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' ' . ($minutes == 1 ? 'minuto' : 'minuti') . ' fa';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ' . ($hours == 1 ? 'ora' : 'ore') . ' fa';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' ' . ($days == 1 ? 'giorno' : 'giorni') . ' fa';
    } else {
        // More than a week, show date
        return date('d/m/Y', $time);
    }
}

/**
 * Get file icon class based on MIME type
 *
 * @param string|null $mimeType File MIME type
 * @return string Icon class for frontend
 */
function getFileIcon(?string $mimeType): string {
    if ($mimeType === null) {
        return 'fas fa-file';
    }

    $icons = [
        // Documents
        'application/pdf' => 'fas fa-file-pdf',
        'application/msword' => 'fas fa-file-word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'fas fa-file-word',
        'application/vnd.ms-excel' => 'fas fa-file-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'fas fa-file-excel',
        'application/vnd.ms-powerpoint' => 'fas fa-file-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'fas fa-file-powerpoint',

        // Images
        'image/jpeg' => 'fas fa-file-image',
        'image/png' => 'fas fa-file-image',
        'image/gif' => 'fas fa-file-image',
        'image/svg+xml' => 'fas fa-file-image',

        // Archives
        'application/zip' => 'fas fa-file-archive',
        'application/x-rar-compressed' => 'fas fa-file-archive',
        'application/x-7z-compressed' => 'fas fa-file-archive',

        // Text
        'text/plain' => 'fas fa-file-alt',
        'text/html' => 'fas fa-file-code',
        'text/css' => 'fas fa-file-code',
        'application/json' => 'fas fa-file-code',

        // Videos
        'video/mp4' => 'fas fa-file-video',
        'video/mpeg' => 'fas fa-file-video',
        'video/quicktime' => 'fas fa-file-video',

        // Audio
        'audio/mpeg' => 'fas fa-file-audio',
        'audio/wav' => 'fas fa-file-audio',
    ];

    return $icons[$mimeType] ?? 'fas fa-file';
}

/**
 * Get file type label in Italian
 *
 * @param string|null $mimeType File MIME type
 * @return string File type label
 */
function getFileTypeLabel(?string $mimeType): string {
    if ($mimeType === null) {
        return 'File';
    }

    $labels = [
        'application/pdf' => 'PDF',
        'application/msword' => 'Word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word',
        'application/vnd.ms-excel' => 'Excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel',
        'application/vnd.ms-powerpoint' => 'PowerPoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint',
        'image/jpeg' => 'Immagine',
        'image/png' => 'Immagine',
        'image/gif' => 'Immagine',
        'application/zip' => 'Archivio',
        'text/plain' => 'Testo',
    ];

    // Try exact match first
    if (isset($labels[$mimeType])) {
        return $labels[$mimeType];
    }

    // Try category match
    $category = explode('/', $mimeType)[0];
    $categoryLabels = [
        'image' => 'Immagine',
        'video' => 'Video',
        'audio' => 'Audio',
        'text' => 'Testo',
    ];

    return $categoryLabels[$category] ?? 'File';
}

// ============================================
// FETCH RECENT DOCUMENTS
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // Query recent documents with uploader information
    // BUG-096 FIX: Database has 'file_size' not 'size'
    $recentDocumentsSql = "
        SELECT
            f.id,
            f.name,
            f.mime_type,
            f.file_size as size,
            f.created_at,
            f.updated_at,
            f.tenant_id,
            t.name AS tenant_name,
            u.name as uploaded_by_name,
            u.avatar as uploaded_by_avatar
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        LEFT JOIN tenants t ON f.tenant_id = t.id
        WHERE (f.deleted_at IS NULL OR f.deleted_at = '')
        /**TENANT_FILTER**/
        ORDER BY COALESCE(f.updated_at, f.created_at) DESC
        LIMIT ?
    ";

    $tenantFilter = '';
    $params = [];
    if ($tenantId !== null) {
        $tenantFilter = "AND f.tenant_id = ?";
        $params[] = $tenantId;
    }
    $recentDocumentsSql = str_replace('/**TENANT_FILTER**/', $tenantFilter, $recentDocumentsSql);
    $params[] = $limit;

    $documents = $db->fetchAll($recentDocumentsSql, $params);

    // Format documents for frontend
    $formattedDocuments = [];
    foreach ($documents as $doc) {
        $formattedDocuments[] = [
            'id' => (int)$doc['id'],
            'name' => $doc['name'],
            'mime_type' => $doc['mime_type'],
            'file_type_label' => getFileTypeLabel($doc['mime_type']),
            'file_icon' => getFileIcon($doc['mime_type']),
            'size' => (int)$doc['size'],
            'size_formatted' => formatFileSize((int)$doc['size']),
            'uploaded_by' => $doc['uploaded_by_name'] ?? 'N/A',
            'uploaded_by_avatar' => $doc['uploaded_by_avatar'] ?? null,
            'created_at' => $doc['created_at'],
            'uploaded_at' => formatRelativeTime($doc['updated_at'] ?: $doc['created_at']),
            'formatted_date' => date('d/m/Y H:i', strtotime($doc['updated_at'] ?: $doc['created_at'])),
            'tenant_name' => $doc['tenant_name'] ?? null
        ];
    }

    // Metadata
    $metadata = [
        'total_documents' => count($formattedDocuments),
        'limit' => $limit,
        'tenant_id' => $tenantId,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'documents' => $formattedDocuments,
        'metadata' => $metadata
    ], 'Documenti recenti caricati con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading recent documents: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento dei documenti recenti', 500);
}
