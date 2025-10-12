<?php
/**
 * File Upload API Endpoint
 *
 * Gestisce l'upload di file con supporto multi-file,
 * chunk upload per file grandi e validazioni di sicurezza
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

// Get current user info
$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];
$tenantId = $userInfo['tenant_id'];
$userRole = $userInfo['role'];

// Get database connection
$db = Database::getInstance();

// Handle preflight requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    exit();
}

// Verify CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyApiCsrfToken();
}

try {
    // Get parameters
    $folderId = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
    $isChunked = isset($_POST['is_chunked']) && $_POST['is_chunked'] === 'true';
    $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : 0;
    $totalChunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : 1;
    $fileId = isset($_POST['file_id']) ? $_POST['file_id'] : null;

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

    // Check if files were uploaded
    if (!isset($_FILES['files']) && !isset($_FILES['file'])) {
        apiError('Nessun file caricato', 400);
    }

    // Normalize files array (support both single and multiple uploads)
    $files = [];
    if (isset($_FILES['files'])) {
        // Multiple files
        if (is_array($_FILES['files']['name'])) {
            for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
                $files[] = [
                    'name' => $_FILES['files']['name'][$i],
                    'type' => $_FILES['files']['type'][$i],
                    'tmp_name' => $_FILES['files']['tmp_name'][$i],
                    'error' => $_FILES['files']['error'][$i],
                    'size' => $_FILES['files']['size'][$i]
                ];
            }
        } else {
            // Single file with name 'files'
            $files[] = $_FILES['files'];
        }
    } elseif (isset($_FILES['file'])) {
        // Single file with name 'file'
        $files[] = $_FILES['file'];
    }

    $uploadedFiles = [];
    $errors = [];

    // Handle chunk upload
    if ($isChunked) {
        if (count($files) > 1) {
            apiError('Upload chunk supporta solo un file alla volta', 400);
        }

        $file = $files[0];
        $result = handleChunkedUpload(
            $file,
            $fileId,
            $chunkIndex,
            $totalChunks,
            $tenantId,
            $folderId,
            $userId,
            $db
        );

        if ($result['complete']) {
            $uploadedFiles[] = $result['file'];
        } else {
            apiSuccess([
                'chunk_received' => true,
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
                'file_id' => $result['file_id']
            ], 'Chunk ricevuto');
        }
    } else {
        // Handle regular upload
        foreach ($files as $file) {
            // Validate file
            $validation = FileHelper::validateUploadedFile($file);
            if (!$validation['valid']) {
                $errors[] = [
                    'file' => $file['name'],
                    'error' => $validation['error']
                ];
                continue;
            }

            // Process file upload
            $result = processFileUpload($file, $tenantId, $folderId, $userId, $db);
            if ($result['success']) {
                $uploadedFiles[] = $result['file'];
            } else {
                $errors[] = [
                    'file' => $file['name'],
                    'error' => $result['error']
                ];
            }
        }
    }

    // Prepare response
    if (count($uploadedFiles) > 0) {
        $message = count($uploadedFiles) . ' file caricati con successo';
        if (count($errors) > 0) {
            $message .= ', ' . count($errors) . ' errori';
        }

        apiSuccess([
            'files' => $uploadedFiles,
            'errors' => $errors
        ], $message);
    } else {
        apiError('Nessun file caricato con successo', 400, ['errors' => $errors]);
    }

} catch (Exception $e) {
    logApiError('File Upload', $e);
    apiError(
        'Errore durante l\'upload del file',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Process a single file upload
 */
function processFileUpload(array $file, int $tenantId, ?int $folderId, int $userId, $db): array {
    try {
        // Generate safe filename
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Create tenant upload path
        $uploadPath = FileHelper::getTenantUploadPath($tenantId);
        $safeName = FileHelper::generateSafeFilename($originalName, $uploadPath);
        $fullPath = $uploadPath . '/' . $safeName;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return [
                'success' => false,
                'error' => 'Impossibile salvare il file'
            ];
        }

        // Get file info
        $mimeType = FileHelper::getMimeType($fullPath);
        $fileSize = filesize($fullPath);
        $fileHash = FileHelper::getFileHash($fullPath);
        $isEditable = FileHelper::isEditable($extension);
        $editorFormat = FileHelper::getEditorFormat($mimeType);

        // Create relative path for database
        $relativePath = 'uploads/' . $tenantId . '/' . $safeName;

        // Generate thumbnail for images
        $thumbnailPath = null;
        if (FileHelper::isImage($mimeType)) {
            $thumbDir = $uploadPath . '/thumbnails';
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }
            $thumbPath = $thumbDir . '/' . $safeName;
            if (FileHelper::createThumbnail($fullPath, $thumbPath)) {
                $thumbnailPath = 'uploads/' . $tenantId . '/thumbnails/' . $safeName;
            }
        }

        // Insert file record in database
        $fileId = $db->insert('files', [
            'tenant_id' => $tenantId,
            'name' => $originalName,
            'path' => $relativePath,
            'size' => $fileSize,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'folder_id' => $folderId,
            'uploaded_by' => $userId,
            'is_folder' => 0,
            'is_editable' => $isEditable ? 1 : 0,
            'editor_format' => $editorFormat,
            'file_hash' => $fileHash,
            'thumbnail_path' => $thumbnailPath,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // If file is editable, create document_editor entry
        if ($isEditable) {
            $db->insert('document_editor', [
                'file_id' => $fileId,
                'tenant_id' => $tenantId,
                'document_type' => $editorFormat,
                'version_count' => 0,
                'last_edited_by' => null,
                'last_edited_at' => null,
                'is_locked' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => 'file_uploaded',
            'entity_type' => 'file',
            'entity_id' => $fileId,
            'details' => json_encode([
                'file_name' => $originalName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'folder_id' => $folderId
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'success' => true,
            'file' => [
                'id' => $fileId,
                'name' => $originalName,
                'size' => $fileSize,
                'path' => '/' . $relativePath,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'is_editable' => $isEditable,
                'editor_format' => $editorFormat,
                'thumbnail_path' => $thumbnailPath ? '/' . $thumbnailPath : null,
                'icon' => FileHelper::getFileIcon($extension),
                'formatted_size' => FileHelper::formatFileSize($fileSize),
                'uploaded_at' => date('Y-m-d H:i:s')
            ]
        ];

    } catch (Exception $e) {
        // Clean up file if database operation failed
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }

        return [
            'success' => false,
            'error' => 'Errore durante il salvataggio: ' . $e->getMessage()
        ];
    }
}

/**
 * Handle chunked file upload for large files
 */
function handleChunkedUpload(
    array $file,
    ?string $fileId,
    int $chunkIndex,
    int $totalChunks,
    int $tenantId,
    ?int $folderId,
    int $userId,
    $db
): array {
    // Generate unique file ID if not provided
    if (!$fileId) {
        $fileId = uniqid('upload_', true);
    }

    // Create temp directory for chunks
    $tempDir = sys_get_temp_dir() . '/uploads/' . $fileId;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    // Save chunk
    $chunkPath = $tempDir . '/chunk_' . $chunkIndex;
    if (!move_uploaded_file($file['tmp_name'], $chunkPath)) {
        throw new Exception('Impossibile salvare il chunk');
    }

    // Check if all chunks are received
    $receivedChunks = glob($tempDir . '/chunk_*');
    if (count($receivedChunks) < $totalChunks) {
        return [
            'complete' => false,
            'file_id' => $fileId
        ];
    }

    // Combine chunks
    $originalName = $file['name'];
    $uploadPath = FileHelper::getTenantUploadPath($tenantId);
    $safeName = FileHelper::generateSafeFilename($originalName, $uploadPath);
    $fullPath = $uploadPath . '/' . $safeName;

    $outputFile = fopen($fullPath, 'wb');
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tempDir . '/chunk_' . $i;
        if (!file_exists($chunkPath)) {
            fclose($outputFile);
            unlink($fullPath);
            throw new Exception('Chunk mancante: ' . $i);
        }

        $chunkData = file_get_contents($chunkPath);
        fwrite($outputFile, $chunkData);
        unlink($chunkPath); // Delete chunk after combining
    }
    fclose($outputFile);

    // Clean up temp directory
    rmdir($tempDir);

    // Process the combined file
    $combinedFile = [
        'name' => $originalName,
        'type' => mime_content_type($fullPath),
        'tmp_name' => $fullPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fullPath)
    ];

    // Use existing processFileUpload logic
    $result = processFileUploadFromPath($fullPath, $originalName, $tenantId, $folderId, $userId, $db);

    return [
        'complete' => true,
        'file' => $result['file']
    ];
}

/**
 * Process file upload from an existing path (used for chunked uploads)
 */
function processFileUploadFromPath(
    string $fullPath,
    string $originalName,
    int $tenantId,
    ?int $folderId,
    int $userId,
    $db
): array {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeType = FileHelper::getMimeType($fullPath);
    $fileSize = filesize($fullPath);
    $fileHash = FileHelper::getFileHash($fullPath);
    $isEditable = FileHelper::isEditable($extension);
    $editorFormat = FileHelper::getEditorFormat($mimeType);

    // Create relative path for database
    $safeName = basename($fullPath);
    $relativePath = 'uploads/' . $tenantId . '/' . $safeName;

    // Generate thumbnail for images
    $thumbnailPath = null;
    if (FileHelper::isImage($mimeType)) {
        $uploadPath = dirname($fullPath);
        $thumbDir = $uploadPath . '/thumbnails';
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }
        $thumbPath = $thumbDir . '/' . $safeName;
        if (FileHelper::createThumbnail($fullPath, $thumbPath)) {
            $thumbnailPath = 'uploads/' . $tenantId . '/thumbnails/' . $safeName;
        }
    }

    // Insert file record in database
    $fileId = $db->insert('files', [
        'tenant_id' => $tenantId,
        'name' => $originalName,
        'path' => $relativePath,
        'size' => $fileSize,
        'mime_type' => $mimeType,
        'extension' => $extension,
        'folder_id' => $folderId,
        'uploaded_by' => $userId,
        'is_folder' => 0,
        'is_editable' => $isEditable ? 1 : 0,
        'editor_format' => $editorFormat,
        'file_hash' => $fileHash,
        'thumbnail_path' => $thumbnailPath,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // If file is editable, create document_editor entry
    if ($isEditable) {
        $db->insert('document_editor', [
            'file_id' => $fileId,
            'tenant_id' => $tenantId,
            'document_type' => $editorFormat,
            'version_count' => 0,
            'last_edited_by' => null,
            'last_edited_at' => null,
            'is_locked' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    return [
        'success' => true,
        'file' => [
            'id' => $fileId,
            'name' => $originalName,
            'size' => $fileSize,
            'path' => '/' . $relativePath,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'is_editable' => $isEditable,
            'editor_format' => $editorFormat,
            'thumbnail_path' => $thumbnailPath ? '/' . $thumbnailPath : null,
            'icon' => FileHelper::getFileIcon($extension),
            'formatted_size' => FileHelper::formatFileSize($fileSize),
            'uploaded_at' => date('Y-m-d H:i:s')
        ]
    ];
}