<?php
/**
 * Create Folder API Endpoint
 *
 * Crea nuove cartelle nel file system
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

// Verify CSRF token
verifyApiCsrfToken();

// Get current user info
$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];
$tenantId = $userInfo['tenant_id'];

// Get database connection
$db = Database::getInstance();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$name = trim($input['name'] ?? '');
$parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : null;

if (empty($name)) {
    apiError('Il nome della cartella è obbligatorio', 400);
}

// Sanitize folder name
$name = preg_replace('/[^a-zA-Z0-9\s\-_\.]/', '', $name);
if (strlen($name) > 100) {
    $name = substr($name, 0, 100);
}

// Remove any file extensions from folder names
$name = preg_replace('/\.[^.]+$/', '', $name);

try {
    // Validate parent folder access if specified
    if ($parentId !== null && $parentId > 0) {
        $parentFolder = $db->fetchOne(
            "SELECT * FROM files
             WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL",
            [$parentId, $tenantId]
        );

        if (!$parentFolder) {
            apiError('Cartella padre non trovata o accesso negato', 404);
        }
    }

    // Check if folder already exists
    $existingFolder = $db->fetchOne(
        "SELECT id FROM files
         WHERE tenant_id = ? AND name = ? AND folder_id <=> ? AND is_folder = 1 AND deleted_at IS NULL",
        [$tenantId, $name, $parentId]
    );

    if ($existingFolder) {
        apiError('Una cartella con questo nome esiste già in questa posizione', 409);
    }

    // Create physical folder on disk
    $folderPath = createPhysicalFolder($tenantId, $name, $parentId, $db);

    // Insert folder record in database
    $folderId = $db->insert('files', [
        'tenant_id' => $tenantId,
        'name' => $name,
        'path' => $folderPath,
        'size' => 0,
        'mime_type' => 'inode/directory',
        'extension' => null,
        'folder_id' => $parentId,
        'uploaded_by' => $userId,
        'is_folder' => 1,
        'is_editable' => 0,
        'editor_format' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // Log audit
    $db->insert('audit_logs', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'action' => 'folder_created',
        'entity_type' => 'folder',
        'entity_id' => $folderId,
        'description' => "Cartella creata: {$name}",
        'new_values' => json_encode([
            'folder_name' => $name,
            'parent_folder_id' => $parentId
        ]),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'severity' => 'info',
        'status' => 'success',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Get full folder info
    $folder = $db->fetchOne(
        "SELECT f.*,
                u.name as created_by_name,
                (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND deleted_at IS NULL) as item_count
         FROM files f
         LEFT JOIN users u ON f.uploaded_by = u.id
         WHERE f.id = ?",
        [$folderId]
    );

    apiSuccess([
        'folder' => [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'path' => $folder['path'],
            'parent_id' => $folder['folder_id'],
            'created_by' => $folder['created_by_name'],
            'created_at' => $folder['created_at'],
            'item_count' => 0,
            'icon' => 'folder'
        ]
    ], 'Cartella creata con successo');

} catch (Exception $e) {
    logApiError('Create Folder', $e);
    apiError(
        'Errore durante la creazione della cartella',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Create physical folder on disk
 */
function createPhysicalFolder(int $tenantId, string $name, ?int $parentId, $db): string {
    // Build folder path
    $basePath = dirname(dirname(__DIR__)) . '/uploads/' . $tenantId;
    $relativePath = 'uploads/' . $tenantId;

    // If there's a parent folder, get its path
    if ($parentId !== null && $parentId > 0) {
        $parentFolder = $db->fetchOne(
            "SELECT path FROM files WHERE id = ? AND is_folder = 1",
            [$parentId]
        );

        if ($parentFolder && $parentFolder['path']) {
            $basePath = dirname(dirname(__DIR__)) . '/' . $parentFolder['path'];
            $relativePath = $parentFolder['path'];
        }
    }

    // Create safe folder name
    $safeName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $name);
    $fullPath = $basePath . '/' . $safeName;
    $relativePath = $relativePath . '/' . $safeName;

    // Create directory if it doesn't exist
    if (!is_dir($fullPath)) {
        if (!mkdir($fullPath, 0755, true)) {
            throw new Exception('Impossibile creare la cartella sul disco');
        }
    }

    return $relativePath;
}