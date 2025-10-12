<?php
/**
 * Move File/Folder API Endpoint
 *
 * Sposta file o cartelle in una nuova posizione
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/config.php';

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
$fileIds = $input['file_ids'] ?? [];
$targetFolderId = isset($input['target_folder_id']) ? (int)$input['target_folder_id'] : null;

if (!is_array($fileIds) || empty($fileIds)) {
    apiError('Seleziona almeno un file o cartella da spostare', 400);
}

// Convert to integers
$fileIds = array_map('intval', $fileIds);

try {
    // Validate target folder if specified
    $targetPath = 'uploads/' . $tenantId;
    if ($targetFolderId !== null && $targetFolderId > 0) {
        $targetFolder = $db->fetchOne(
            "SELECT * FROM files
             WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL",
            [$targetFolderId, $tenantId]
        );

        if (!$targetFolder) {
            apiError('Cartella di destinazione non trovata', 404);
        }

        $targetPath = $targetFolder['path'];

        // Check for circular reference (moving a folder into itself or its children)
        foreach ($fileIds as $fileId) {
            if ($fileId === $targetFolderId) {
                apiError('Non puoi spostare una cartella dentro se stessa', 400);
            }

            // Check if target is a child of the folder being moved
            if (isChildFolder($fileId, $targetFolderId, $db)) {
                apiError('Non puoi spostare una cartella dentro una sua sottocartella', 400);
            }
        }
    }

    $movedItems = [];
    $errors = [];

    foreach ($fileIds as $fileId) {
        // Get item info
        $item = $db->fetchOne(
            "SELECT * FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$fileId, $tenantId]
        );

        if (!$item) {
            $errors[] = ['id' => $fileId, 'error' => 'File non trovato'];
            continue;
        }

        // Check if item with same name exists in target
        $duplicate = $db->fetchOne(
            "SELECT id FROM files
             WHERE tenant_id = ? AND name = ? AND folder_id <=> ? AND deleted_at IS NULL",
            [$tenantId, $item['name'], $targetFolderId]
        );

        if ($duplicate) {
            $errors[] = [
                'id' => $fileId,
                'name' => $item['name'],
                'error' => 'Un file con questo nome esiste già nella destinazione'
            ];
            continue;
        }

        // Move physical file/folder
        $oldFullPath = dirname(dirname(__DIR__)) . '/' . $item['path'];
        $newFullPath = dirname(dirname(__DIR__)) . '/' . $targetPath . '/' . basename($item['path']);

        if (file_exists($oldFullPath)) {
            // Create target directory if it doesn't exist
            $targetDir = dirname($newFullPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (!rename($oldFullPath, $newFullPath)) {
                $errors[] = [
                    'id' => $fileId,
                    'name' => $item['name'],
                    'error' => 'Impossibile spostare il file sul disco'
                ];
                continue;
            }
        }

        // Update database
        $newRelativePath = $targetPath . '/' . basename($item['path']);

        $db->update('files', [
            'folder_id' => $targetFolderId,
            'path' => $newRelativePath,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $fileId]);

        // If it's a folder, update paths of all children
        if ($item['is_folder']) {
            updateChildrenPathsAfterMove($fileId, $item['path'], $newRelativePath, $db);
        }

        // Move thumbnail if exists
        if (!$item['is_folder'] && $item['thumbnail_path']) {
            $oldThumbPath = dirname(dirname(__DIR__)) . '/' . $item['thumbnail_path'];
            $newThumbPath = dirname(dirname(__DIR__)) . '/' . $targetPath . '/thumbnails/' . basename($item['thumbnail_path']);

            if (file_exists($oldThumbPath)) {
                $thumbDir = dirname($newThumbPath);
                if (!is_dir($thumbDir)) {
                    mkdir($thumbDir, 0755, true);
                }
                rename($oldThumbPath, $newThumbPath);

                $newThumbRelativePath = $targetPath . '/thumbnails/' . basename($item['thumbnail_path']);
                $db->update('files', [
                    'thumbnail_path' => $newThumbRelativePath
                ], ['id' => $fileId]);
            }
        }

        $movedItems[] = [
            'id' => $fileId,
            'name' => $item['name'],
            'new_folder_id' => $targetFolderId
        ];

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => 'file_moved',
            'entity_type' => $item['is_folder'] ? 'folder' : 'file',
            'entity_id' => $fileId,
            'details' => json_encode([
                'file_name' => $item['name'],
                'old_folder_id' => $item['folder_id'],
                'new_folder_id' => $targetFolderId
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    if (count($movedItems) > 0) {
        $message = count($movedItems) . ' elementi spostati con successo';
        if (count($errors) > 0) {
            $message .= ', ' . count($errors) . ' errori';
        }

        apiSuccess([
            'moved' => $movedItems,
            'errors' => $errors
        ], $message);
    } else {
        apiError('Nessun elemento spostato', 400, ['errors' => $errors]);
    }

} catch (Exception $e) {
    logApiError('Move File', $e);
    apiError(
        'Errore durante lo spostamento',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Check if target folder is a child of source folder
 */
function isChildFolder(int $sourceFolderId, int $targetFolderId, $db): bool {
    $currentId = $targetFolderId;

    while ($currentId !== null) {
        if ($currentId === $sourceFolderId) {
            return true;
        }

        $parent = $db->fetchOne(
            "SELECT folder_id FROM files WHERE id = ?",
            [$currentId]
        );

        $currentId = $parent ? $parent['folder_id'] : null;
    }

    return false;
}

/**
 * Update paths of all children after a folder is moved
 */
function updateChildrenPathsAfterMove(int $folderId, string $oldPath, string $newPath, $db): void {
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
    }
}