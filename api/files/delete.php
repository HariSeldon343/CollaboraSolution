<?php
/**
 * Delete File/Folder API Endpoint
 *
 * Soft delete di file o cartelle (imposta deleted_at)
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';
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
$userRole = $userInfo['role'];

// Get database connection
$db = Database::getInstance();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$fileIds = $input['file_ids'] ?? [];
$permanent = isset($input['permanent']) && $input['permanent'] === true;

if (!is_array($fileIds) || empty($fileIds)) {
    apiError('Seleziona almeno un file o cartella da eliminare', 400);
}

// Convert to integers
$fileIds = array_map('intval', $fileIds);

// Only super_admin can permanently delete
if ($permanent && $userRole !== 'super_admin') {
    apiError('Solo un super admin può eliminare permanentemente i file', 403);
}

try {
    $deletedItems = [];
    $errors = [];

    foreach ($fileIds as $fileId) {
        // Get item info
        $item = $db->fetchOne(
            "SELECT * FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$fileId, $tenantId]
        );

        if (!$item) {
            $errors[] = ['id' => $fileId, 'error' => 'File non trovato o già eliminato'];
            continue;
        }

        if ($permanent) {
            // Permanent delete - remove physical file and database record
            $result = permanentDelete($item, $db);
        } else {
            // Soft delete - just mark as deleted
            $result = softDelete($item, $userId, $db);
        }

        if ($result['success']) {
            $deletedItems[] = [
                'id' => $fileId,
                'name' => $item['name'],
                'type' => $item['is_folder'] ? 'folder' : 'file'
            ];
        } else {
            $errors[] = [
                'id' => $fileId,
                'name' => $item['name'],
                'error' => $result['error']
            ];
        }
    }

    if (count($deletedItems) > 0) {
        $message = count($deletedItems) . ' elementi eliminati con successo';
        if (count($errors) > 0) {
            $message .= ', ' . count($errors) . ' errori';
        }

        apiSuccess([
            'deleted' => $deletedItems,
            'errors' => $errors,
            'permanent' => $permanent
        ], $message);
    } else {
        apiError('Nessun elemento eliminato', 400, ['errors' => $errors]);
    }

} catch (Exception $e) {
    logApiError('Delete File', $e);
    apiError(
        'Errore durante l\'eliminazione',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Soft delete a file or folder
 */
function softDelete(array $item, int $userId, $db): array {
    try {
        $timestamp = date('Y-m-d H:i:s');

        // If it's a folder, soft delete all children
        if ($item['is_folder']) {
            softDeleteChildren($item['id'], $timestamp, $db);
        }

        // Soft delete the item
        $db->update('files', [
            'deleted_at' => $timestamp,
            'updated_at' => $timestamp
        ], ['id' => $item['id']]);

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $item['tenant_id'],
            'user_id' => $userId,
            'action' => 'file_deleted',
            'entity_type' => $item['is_folder'] ? 'folder' : 'file',
            'entity_id' => $item['id'],
            'details' => json_encode([
                'file_name' => $item['name'],
                'soft_delete' => true
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => $timestamp
        ]);

        return ['success' => true];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Errore durante l\'eliminazione: ' . $e->getMessage()
        ];
    }
}

/**
 * Recursively soft delete all children of a folder
 */
function softDeleteChildren(int $folderId, string $timestamp, $db): void {
    // Get all direct children
    $children = $db->fetchAll(
        "SELECT id, is_folder FROM files WHERE folder_id = ? AND deleted_at IS NULL",
        [$folderId]
    );

    foreach ($children as $child) {
        // If it's a folder, recursively delete its children
        if ($child['is_folder']) {
            softDeleteChildren($child['id'], $timestamp, $db);
        }

        // Soft delete the child
        $db->update('files', [
            'deleted_at' => $timestamp,
            'updated_at' => $timestamp
        ], ['id' => $child['id']]);
    }
}

/**
 * Permanently delete a file or folder
 */
function permanentDelete(array $item, $db): array {
    try {
        // Delete physical file/folder
        if ($item['path']) {
            $fullPath = dirname(dirname(__DIR__)) . '/' . $item['path'];

            if (file_exists($fullPath)) {
                if ($item['is_folder']) {
                    // Recursively delete folder
                    deleteDirectory($fullPath);
                } else {
                    unlink($fullPath);

                    // Delete thumbnail if exists
                    if ($item['thumbnail_path']) {
                        $thumbPath = dirname(dirname(__DIR__)) . '/' . $item['thumbnail_path'];
                        if (file_exists($thumbPath)) {
                            unlink($thumbPath);
                        }
                    }
                }
            }
        }

        // If it's a folder, permanently delete all children from database
        if ($item['is_folder']) {
            permanentDeleteChildren($item['id'], $db);
        }

        // Delete related records
        if (!$item['is_folder']) {
            // Delete document_editor records
            $db->execute(
                "DELETE FROM document_editor WHERE file_id = ?",
                [$item['id']]
            );

            // Delete document_versions
            $db->execute(
                "DELETE FROM document_versions WHERE file_id = ?",
                [$item['id']]
            );

            // Delete editor_sessions
            $db->execute(
                "DELETE FROM editor_sessions WHERE file_id = ?",
                [$item['id']]
            );
        }

        // Delete the file record
        $db->execute(
            "DELETE FROM files WHERE id = ?",
            [$item['id']]
        );

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $item['tenant_id'],
            'user_id' => $_SESSION['user_id'],
            'action' => 'file_deleted_permanently',
            'entity_type' => $item['is_folder'] ? 'folder' : 'file',
            'entity_id' => $item['id'],
            'details' => json_encode([
                'file_name' => $item['name'],
                'permanent_delete' => true
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return ['success' => true];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Errore durante l\'eliminazione permanente: ' . $e->getMessage()
        ];
    }
}

/**
 * Recursively delete all children from database
 */
function permanentDeleteChildren(int $folderId, $db): void {
    // Get all children
    $children = $db->fetchAll(
        "SELECT id, is_folder FROM files WHERE folder_id = ?",
        [$folderId]
    );

    foreach ($children as $child) {
        if ($child['is_folder']) {
            permanentDeleteChildren($child['id'], $db);
        }

        // Delete related records
        $db->execute(
            "DELETE FROM document_editor WHERE file_id = ?",
            [$child['id']]
        );

        $db->execute(
            "DELETE FROM document_versions WHERE file_id = ?",
            [$child['id']]
        );

        $db->execute(
            "DELETE FROM editor_sessions WHERE file_id = ?",
            [$child['id']]
        );

        // Delete the file record
        $db->execute(
            "DELETE FROM files WHERE id = ?",
            [$child['id']]
        );
    }
}

/**
 * Recursively delete a directory
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}