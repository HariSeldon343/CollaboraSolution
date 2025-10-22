<?php
/**
 * Rename File/Folder API Endpoint
 *
 * Rinomina file o cartelle
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
$fileId = isset($input['file_id']) ? (int)$input['file_id'] : 0;
$newName = trim($input['new_name'] ?? '');

if ($fileId <= 0) {
    apiError('ID file non valido', 400);
}

if (empty($newName)) {
    apiError('Il nuovo nome è obbligatorio', 400);
}

// Sanitize name
$newName = preg_replace('/[^a-zA-Z0-9\s\-_\.]/', '', $newName);
if (strlen($newName) > 100) {
    $newName = substr($newName, 0, 100);
}

try {
    // Get file/folder info
    $item = $db->fetchOne(
        "SELECT * FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if (!$item) {
        apiError('File o cartella non trovato', 404);
    }

    // If it's a file, preserve the extension
    if (!$item['is_folder']) {
        $originalExtension = pathinfo($item['name'], PATHINFO_EXTENSION);
        $newExtension = pathinfo($newName, PATHINFO_EXTENSION);

        // If new name doesn't have extension, add the original one
        if (empty($newExtension) && !empty($originalExtension)) {
            $newName .= '.' . $originalExtension;
        }
        // If extensions differ, keep the original
        else if ($newExtension !== $originalExtension) {
            $newNameWithoutExt = pathinfo($newName, PATHINFO_FILENAME);
            $newName = $newNameWithoutExt . '.' . $originalExtension;
        }
    } else {
        // For folders, remove any extension
        $newName = preg_replace('/\.[^.]+$/', '', $newName);
    }

    // Check if new name already exists in the same location
    $duplicate = $db->fetchOne(
        "SELECT id FROM files
         WHERE tenant_id = ? AND name = ? AND folder_id <=> ? AND id != ? AND deleted_at IS NULL",
        [$tenantId, $newName, $item['folder_id'], $fileId]
    );

    if ($duplicate) {
        apiError('Un file o cartella con questo nome esiste già in questa posizione', 409);
    }

    // Rename physical file/folder
    $oldPath = dirname(dirname(__DIR__)) . '/' . $item['path'];
    $newPath = dirname($oldPath) . '/' . FileHelper::generateSafeFilename($newName, dirname($oldPath));

    if (file_exists($oldPath)) {
        if (!rename($oldPath, $newPath)) {
            apiError('Impossibile rinominare il file sul disco', 500);
        }

        // Update path in database
        $newRelativePath = str_replace(dirname(dirname(__DIR__)) . '/', '', $newPath);

        // Update file record
        $db->update('files', [
            'name' => $newName,
            'path' => $newRelativePath,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $fileId]);

        // If it's a folder, update paths of all children
        if ($item['is_folder']) {
            updateChildrenPaths($fileId, $item['path'], $newRelativePath, $db);
        }

        // Update thumbnail path if exists
        if (!$item['is_folder'] && $item['thumbnail_path']) {
            $oldThumbPath = dirname(dirname(__DIR__)) . '/' . $item['thumbnail_path'];
            $newThumbPath = dirname($oldThumbPath) . '/' . basename($newPath);

            if (file_exists($oldThumbPath)) {
                rename($oldThumbPath, $newThumbPath);

                $newThumbRelativePath = str_replace(dirname(dirname(__DIR__)) . '/', '', $newThumbPath);
                $db->update('files', [
                    'thumbnail_path' => $newThumbRelativePath
                ], ['id' => $fileId]);
            }
        }
    }

    // Log audit
    $db->insert('audit_logs', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'action' => 'file_renamed',
        'entity_type' => $item['is_folder'] ? 'folder' : 'file',
        'entity_id' => $fileId,
        'description' => ($item['is_folder'] ? 'Cartella rinominata' : 'File rinominato') . ": {$item['name']} → {$newName}",
        'old_values' => json_encode([
            'name' => $item['name']
        ]),
        'new_values' => json_encode([
            'name' => $newName
        ]),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'severity' => 'info',
        'status' => 'success',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    apiSuccess([
        'id' => $fileId,
        'name' => $newName,
        'old_name' => $item['name']
    ], 'Rinominato con successo');

} catch (Exception $e) {
    logApiError('Rename File', $e);
    apiError(
        'Errore durante la rinomina',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Update paths of all children when a folder is renamed
 */
function updateChildrenPaths(int $folderId, string $oldPath, string $newPath, $db): void {
    // Get all children
    $children = $db->fetchAll(
        "SELECT id, path, is_folder FROM files WHERE path LIKE ?",
        [$oldPath . '/%']
    );

    foreach ($children as $child) {
        $childNewPath = str_replace($oldPath, $newPath, $child['path']);

        $db->update('files', [
            'path' => $childNewPath,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $child['id']]);

        // Recursively update if it's a folder
        if ($child['is_folder']) {
            updateChildrenPaths($child['id'], $child['path'], $childNewPath, $db);
        }
    }
}