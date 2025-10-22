<?php
/**
 * List Files API Endpoint
 *
 * Elenca file e cartelle con supporto per paginazione,
 * ordinamento, filtri e ricerca
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

// Initialize API environment
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();

// Get current user info
$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];
$tenantId = $userInfo['tenant_id'];
$userRole = $userInfo['role'];

// Get database connection
$db = Database::getInstance();

// Get query parameters
$folderId = isset($_GET['folder_id']) ? (int)$_GET['folder_id'] : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
$sort = $_GET['sort'] ?? 'name';
$order = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$search = trim($_GET['search'] ?? '');
$fileType = $_GET['file_type'] ?? '';
$showDeleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] === 'true';

// Validate sort column
$allowedSorts = ['name', 'size', 'created_at', 'updated_at'];
if (!in_array($sort, $allowedSorts)) {
    $sort = 'name';
}

try {
    // Build breadcrumb path
    $breadcrumb = buildBreadcrumb($folderId, $tenantId, $db);

    // Validate folder access if specified
    if ($folderId !== null && $folderId > 0) {
        $folder = $db->fetchOne(
            "SELECT * FROM files
             WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL",
            [$folderId, $tenantId]
        );

        if (!$folder) {
            apiError('Cartella non trovata o accesso negato', 404);
        }
    }

    // Build base query
    $conditions = ['tenant_id = ?'];
    $params = [$tenantId];

    // Filter by folder
    if ($folderId === null || $folderId === 0) {
        $conditions[] = 'folder_id IS NULL';
    } else {
        $conditions[] = 'folder_id = ?';
        $params[] = $folderId;
    }

    // Filter by deleted status
    if (!$showDeleted) {
        $conditions[] = 'deleted_at IS NULL';
    }

    // Search filter
    if ($search) {
        $conditions[] = 'name LIKE ?';
        $params[] = '%' . $search . '%';
    }

    // File type filter
    if ($fileType) {
        switch ($fileType) {
            case 'document':
                $conditions[] = "extension IN ('doc', 'docx', 'pdf', 'txt', 'odt', 'rtf')";
                break;
            case 'spreadsheet':
                $conditions[] = "extension IN ('xls', 'xlsx', 'csv', 'ods')";
                break;
            case 'presentation':
                $conditions[] = "extension IN ('ppt', 'pptx', 'odp')";
                break;
            case 'image':
                $conditions[] = "mime_type LIKE 'image/%'";
                break;
            case 'video':
                $conditions[] = "mime_type LIKE 'video/%'";
                break;
            case 'archive':
                $conditions[] = "extension IN ('zip', 'rar', '7z', 'tar', 'gz')";
                break;
        }
    }

    $whereClause = implode(' AND ', $conditions);

    // Get total count
    $totalQuery = "SELECT COUNT(*) as total FROM files WHERE $whereClause";
    $totalResult = $db->fetchOne($totalQuery, $params);
    $total = $totalResult['total'] ?? 0;

    // Calculate pagination
    $totalPages = ceil($total / $limit);
    $offset = ($page - 1) * $limit;

    // Get files and folders
    $query = "
        SELECT f.*,
               u.name as uploaded_by_name,
               CASE
                   WHEN f.is_folder = 1 THEN 'folder'
                   WHEN f.is_editable = 1 THEN 'document'
                   WHEN f.mime_type LIKE 'image/%' THEN 'image'
                   WHEN f.mime_type LIKE 'video/%' THEN 'video'
                   ELSE 'file'
               END as item_type,
               (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND deleted_at IS NULL) as item_count
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE $whereClause
        ORDER BY f.is_folder DESC, f.$sort $order
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $items = $db->fetchAll($query, $params);

    // Format items for response
    $files = [];
    $folders = [];

    foreach ($items as $item) {
        $formattedItem = [
            'id' => $item['id'],
            'name' => $item['name'],
            'size' => $item['size'],
            'mime_type' => $item['mime_type'],
            'extension' => $item['extension'],
            'path' => $item['path'] ? '/' . $item['path'] : null,
            'is_folder' => (bool)$item['is_folder'],
            'is_editable' => (bool)$item['is_editable'],
            'editor_format' => $item['editor_format'],
            'thumbnail_path' => $item['thumbnail_path'] ? '/' . $item['thumbnail_path'] : null,
            'uploaded_by' => $item['uploaded_by_name'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
            'deleted_at' => $item['deleted_at'],
            'formatted_size' => $item['size'] ? FileHelper::formatFileSize($item['size']) : null,
            'icon' => $item['is_folder'] ? 'folder' : FileHelper::getFileIcon($item['extension'] ?? ''),
            'item_type' => $item['item_type']
        ];

        if ($item['is_folder']) {
            $formattedItem['item_count'] = (int)$item['item_count'];
            $folders[] = $formattedItem;
        } else {
            // Add image dimensions if available
            if (strpos($item['mime_type'], 'image/') === 0 && $item['path']) {
                $fullPath = dirname(dirname(__DIR__)) . '/' . $item['path'];
                if (file_exists($fullPath)) {
                    $dimensions = FileHelper::getImageDimensions($fullPath);
                    if ($dimensions) {
                        $formattedItem['image_width'] = $dimensions['width'];
                        $formattedItem['image_height'] = $dimensions['height'];
                    }
                }
            }
            $files[] = $formattedItem;
        }
    }

    // Get storage usage for tenant
    $storageQuery = "
        SELECT
            COUNT(*) as total_files,
            SUM(size) as total_size,
            COUNT(CASE WHEN is_folder = 1 THEN 1 END) as total_folders
        FROM files
        WHERE tenant_id = ? AND deleted_at IS NULL
    ";
    $storageResult = $db->fetchOne($storageQuery, [$tenantId]);

    $storageInfo = [
        'total_files' => (int)($storageResult['total_files'] ?? 0),
        'total_folders' => (int)($storageResult['total_folders'] ?? 0),
        'total_size' => (int)($storageResult['total_size'] ?? 0),
        'formatted_size' => FileHelper::formatFileSize((int)($storageResult['total_size'] ?? 0))
    ];

    // Build response
    $response = [
        'files' => $files,
        'folders' => $folders,
        'breadcrumb' => $breadcrumb,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'pages' => $totalPages,
            'limit' => $limit,
            'has_more' => $page < $totalPages
        ],
        'storage' => $storageInfo,
        'current_folder' => $folderId
    ];

    apiSuccess($response, 'File caricati con successo');

} catch (Exception $e) {
    logApiError('List Files', $e);
    apiError(
        'Errore durante il caricamento dei file',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Build breadcrumb path for navigation
 */
function buildBreadcrumb(?int $folderId, int $tenantId, $db): array {
    $breadcrumb = [
        [
            'id' => null,
            'name' => 'I Miei File',
            'path' => '/',
            'is_root' => true
        ]
    ];

    if ($folderId === null || $folderId === 0) {
        return $breadcrumb;
    }

    // Build path from current folder to root
    $currentId = $folderId;
    $path = [];

    while ($currentId !== null) {
        $folder = $db->fetchOne(
            "SELECT id, name, folder_id
             FROM files
             WHERE id = ? AND tenant_id = ? AND is_folder = 1",
            [$currentId, $tenantId]
        );

        if (!$folder) {
            break;
        }

        array_unshift($path, [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'path' => '/folder/' . $folder['id'],
            'is_root' => false
        ]);

        $currentId = $folder['folder_id'];
    }

    return array_merge($breadcrumb, $path);
}