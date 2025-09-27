<?php declare(strict_types=1);

/**
 * CollaboraNexio - API Files Completo
 * Gestione file system con upload, download, sharing e versioning
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Headers CORS e JSON
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Gestione preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verifica autenticazione
$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// Configurazione storage
$storage_base = __DIR__ . '/../storage/tenants/tenant_' . $tenant_id;
$max_file_size = 100 * 1024 * 1024; // 100MB
$allowed_extensions = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'json', 'xml', 'html', 'css', 'js',
    'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp',
    'mp4', 'webm', 'mp3', 'wav',
    'zip', 'rar', '7z', 'tar', 'gz'
];

// Crea directory storage se non esiste
if (!file_exists($storage_base)) {
    mkdir($storage_base, 0755, true);
}

/**
 * Router principale
 */
try {
    $response = match($method) {
        'GET' => handleGet($path),
        'POST' => handlePost($path),
        'PUT' => handlePut($path),
        'DELETE' => handleDelete($path),
        default => throw new Exception('Metodo non supportato', 405)
    };

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * GET - Lista file, download, ricerca
 */
function handleGet(string $path): array {
    global $pdo, $tenant_id, $user_id;

    // Parse del path
    $parts = explode('/', trim($path, '/'));
    $action = $parts[0] ?? 'list';

    switch ($action) {
        case 'list':
            return listFiles();

        case 'download':
            $file_id = $parts[1] ?? 0;
            return downloadFile((int)$file_id);

        case 'search':
            $query = $_GET['q'] ?? '';
            return searchFiles($query);

        case 'shared':
            return getSharedFiles();

        case 'recent':
            return getRecentFiles();

        case 'trash':
            return getTrashedFiles();

        case 'versions':
            $file_id = $parts[1] ?? 0;
            return getFileVersions((int)$file_id);

        case 'info':
            $file_id = $parts[1] ?? 0;
            return getFileInfo((int)$file_id);

        default:
            throw new Exception('Azione non valida', 400);
    }
}

/**
 * POST - Upload file, crea cartella, condividi
 */
function handlePost(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $action = $parts[0] ?? 'upload';

    switch ($action) {
        case 'upload':
            return uploadFile();

        case 'folder':
            $data = json_decode(file_get_contents('php://input'), true);
            return createFolder($data);

        case 'share':
            $data = json_decode(file_get_contents('php://input'), true);
            return shareFile($data);

        case 'copy':
            $data = json_decode(file_get_contents('php://input'), true);
            return copyFile($data);

        case 'restore':
            $file_id = $parts[1] ?? 0;
            return restoreFile((int)$file_id);

        default:
            throw new Exception('Azione non valida', 400);
    }
}

/**
 * PUT - Rinomina, sposta, aggiorna
 */
function handlePut(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $file_id = (int)($parts[0] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['name'])) {
        return renameFile($file_id, $data['name']);
    } elseif (isset($data['folder_id'])) {
        return moveFile($file_id, $data['folder_id']);
    } elseif (isset($data['tags'])) {
        return updateFileTags($file_id, $data['tags']);
    } else {
        throw new Exception('Parametri mancanti', 400);
    }
}

/**
 * DELETE - Elimina file o cartella
 */
function handleDelete(string $path): array {
    global $pdo, $tenant_id, $user_id;

    $parts = explode('/', trim($path, '/'));
    $type = $parts[0] ?? 'file';
    $id = (int)($parts[1] ?? 0);

    if ($type === 'folder') {
        return deleteFolder($id);
    } else {
        return deleteFile($id);
    }
}

/**
 * Lista file e cartelle
 */
function listFiles(): array {
    global $pdo, $tenant_id, $user_id;

    $folder_id = $_GET['folder_id'] ?? null;
    $sort = $_GET['sort'] ?? 'name';
    $order = $_GET['order'] ?? 'asc';
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);

    // Query per le cartelle
    $folders_sql = "
        SELECT f.*, u.display_name as owner_name,
               (SELECT COUNT(*) FROM files WHERE folder_id = f.id) as file_count,
               (SELECT COUNT(*) FROM folders WHERE parent_id = f.id) as folder_count
        FROM folders f
        LEFT JOIN users u ON f.owner_id = u.id
        WHERE f.tenant_id = ?
        AND f.deleted_at IS NULL
    ";

    $params = [$tenant_id];

    if ($folder_id !== null) {
        $folders_sql .= " AND f.parent_id = ?";
        $params[] = $folder_id;
    } else {
        $folders_sql .= " AND f.parent_id IS NULL";
    }

    $folders_sql .= " ORDER BY f.name ASC";

    $stmt = $pdo->prepare($folders_sql);
    $stmt->execute($params);
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query per i file
    $files_sql = "
        SELECT f.*, u.display_name as owner_name,
               COALESCE(fv.version_count, 0) as version_count,
               COALESCE(fs.share_count, 0) as share_count
        FROM files f
        LEFT JOIN users u ON f.owner_id = u.id
        LEFT JOIN (
            SELECT file_id, COUNT(*) as version_count
            FROM file_versions
            GROUP BY file_id
        ) fv ON f.id = fv.file_id
        LEFT JOIN (
            SELECT file_id, COUNT(*) as share_count
            FROM file_shares
            WHERE expires_at IS NULL OR expires_at > NOW()
            GROUP BY file_id
        ) fs ON f.id = fs.file_id
        WHERE f.tenant_id = ?
        AND f.deleted_at IS NULL
    ";

    $params = [$tenant_id];

    if ($folder_id !== null) {
        $files_sql .= " AND f.folder_id = ?";
        $params[] = $folder_id;
    } else {
        $files_sql .= " AND f.folder_id IS NULL";
    }

    // Ordinamento
    $order_clause = match($sort) {
        'size' => "f.size_bytes",
        'modified' => "f.updated_at",
        'created' => "f.created_at",
        default => "f.name"
    };

    $files_sql .= " ORDER BY $order_clause " . ($order === 'desc' ? 'DESC' : 'ASC');
    $files_sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($files_sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatta dimensioni file
    foreach ($files as &$file) {
        $file['size_formatted'] = formatFileSize($file['size_bytes']);
        $file['icon'] = getFileIcon($file['mime_type']);
    }

    // Breadcrumb per navigazione
    $breadcrumb = [];
    if ($folder_id) {
        $current_id = $folder_id;
        while ($current_id) {
            $stmt = $pdo->prepare("
                SELECT id, name, parent_id
                FROM folders
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$current_id, $tenant_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($folder) {
                array_unshift($breadcrumb, [
                    'id' => $folder['id'],
                    'name' => $folder['name']
                ]);
                $current_id = $folder['parent_id'];
            } else {
                break;
            }
        }
    }

    // Storage usage
    $stmt = $pdo->prepare("
        SELECT
            SUM(size_bytes) as used_bytes,
            COUNT(*) as total_files
        FROM files
        WHERE tenant_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$tenant_id]);
    $storage = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'folders' => $folders,
        'files' => $files,
        'breadcrumb' => $breadcrumb,
        'storage' => [
            'used' => $storage['used_bytes'] ?? 0,
            'used_formatted' => formatFileSize($storage['used_bytes'] ?? 0),
            'total_files' => $storage['total_files'] ?? 0
        ]
    ];
}

/**
 * Upload file
 */
function uploadFile(): array {
    global $pdo, $tenant_id, $user_id, $storage_base, $max_file_size, $allowed_extensions;

    if (!isset($_FILES['file'])) {
        throw new Exception('Nessun file caricato', 400);
    }

    $file = $_FILES['file'];
    $folder_id = $_POST['folder_id'] ?? null;
    $description = $_POST['description'] ?? '';
    $tags = $_POST['tags'] ?? '';

    // Validazioni
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Errore upload: ' . $file['error'], 400);
    }

    if ($file['size'] > $max_file_size) {
        throw new Exception('File troppo grande. Max: ' . formatFileSize($max_file_size), 400);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        throw new Exception('Tipo file non permesso', 400);
    }

    // Genera nome univoco
    $file_hash = sha1_file($file['tmp_name']);
    $storage_name = $file_hash . '.' . $extension;
    $storage_path = $storage_base . '/files/' . substr($file_hash, 0, 2) . '/' . substr($file_hash, 2, 2);

    // Crea directory se non esiste
    if (!file_exists($storage_path)) {
        mkdir($storage_path, 0755, true);
    }

    $full_path = $storage_path . '/' . $storage_name;

    // Verifica se il file esiste già (deduplicazione)
    $stmt = $pdo->prepare("
        SELECT id FROM files
        WHERE tenant_id = ? AND file_hash = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$tenant_id, $file_hash]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // File già esistente, crea solo riferimento
        $storage_exists = true;
    } else {
        // Sposta il file
        if (!move_uploaded_file($file['tmp_name'], $full_path)) {
            throw new Exception('Impossibile salvare il file', 500);
        }
        $storage_exists = false;
    }

    // Inserisci record nel database
    $stmt = $pdo->prepare("
        INSERT INTO files (
            tenant_id, folder_id, name, original_name, mime_type,
            size_bytes, storage_path, file_hash, owner_id,
            description, tags
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $folder_id,
        $file['name'],
        $file['name'],
        $file['type'] ?: mime_content_type($full_path),
        $file['size'],
        'files/' . substr($file_hash, 0, 2) . '/' . substr($file_hash, 2, 2) . '/' . $storage_name,
        $file_hash,
        $user_id,
        $description,
        $tags
    ]);

    $file_id = $pdo->lastInsertId();

    // Log attività
    logActivity('file_upload', 'file', $file_id, [
        'filename' => $file['name'],
        'size' => $file['size'],
        'deduped' => $storage_exists
    ]);

    return [
        'success' => true,
        'file_id' => $file_id,
        'name' => $file['name'],
        'size' => $file['size'],
        'size_formatted' => formatFileSize($file['size'])
    ];
}

/**
 * Download file
 */
function downloadFile(int $file_id): void {
    global $pdo, $tenant_id, $user_id, $storage_base;

    // Verifica permessi
    $stmt = $pdo->prepare("
        SELECT f.*,
               (SELECT COUNT(*) FROM file_shares
                WHERE file_id = f.id AND user_id = ?
                AND (expires_at IS NULL OR expires_at > NOW())) as is_shared
        FROM files f
        WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL
    ");
    $stmt->execute([$user_id, $file_id, $tenant_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        throw new Exception('File non trovato', 404);
    }

    // Verifica accesso
    if ($file['owner_id'] != $user_id && !$file['is_shared']) {
        // Verifica se l'utente ha accesso alla cartella
        if ($file['folder_id']) {
            $has_access = checkFolderAccess($file['folder_id'], $user_id);
            if (!$has_access) {
                throw new Exception('Accesso negato', 403);
            }
        } else {
            throw new Exception('Accesso negato', 403);
        }
    }

    $full_path = $storage_base . '/' . $file['storage_path'];

    if (!file_exists($full_path)) {
        throw new Exception('File fisico non trovato', 404);
    }

    // Incrementa download counter
    $stmt = $pdo->prepare("
        UPDATE files
        SET download_count = download_count + 1
        WHERE id = ?
    ");
    $stmt->execute([$file_id]);

    // Log download
    logActivity('file_download', 'file', $file_id, [
        'filename' => $file['name']
    ]);

    // Invia file
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
    header('Content-Length: ' . $file['size_bytes']);
    header('Cache-Control: no-cache, must-revalidate');

    readfile($full_path);
    exit;
}

/**
 * Crea cartella
 */
function createFolder(array $data): array {
    global $pdo, $tenant_id, $user_id;

    $name = trim($data['name'] ?? '');
    $parent_id = $data['parent_id'] ?? null;
    $description = $data['description'] ?? '';

    if (empty($name)) {
        throw new Exception('Nome cartella richiesto', 400);
    }

    // Costruisci path
    $path = '/';
    if ($parent_id) {
        $stmt = $pdo->prepare("
            SELECT path FROM folders
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$parent_id, $tenant_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            throw new Exception('Cartella parent non trovata', 404);
        }

        $path = $parent['path'] . '/' . $name;
    } else {
        $path = '/' . $name;
    }

    // Verifica unicità
    $stmt = $pdo->prepare("
        SELECT id FROM folders
        WHERE tenant_id = ? AND parent_id = ? AND name = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$tenant_id, $parent_id, $name]);

    if ($stmt->fetch()) {
        throw new Exception('Cartella già esistente', 409);
    }

    // Crea cartella
    $stmt = $pdo->prepare("
        INSERT INTO folders (tenant_id, name, path, parent_id, owner_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $name,
        $path,
        $parent_id,
        $user_id,
        $description
    ]);

    $folder_id = $pdo->lastInsertId();

    logActivity('folder_create', 'folder', $folder_id, [
        'name' => $name,
        'path' => $path
    ]);

    return [
        'success' => true,
        'folder_id' => $folder_id,
        'name' => $name,
        'path' => $path
    ];
}

/**
 * Condividi file
 */
function shareFile(array $data): array {
    global $pdo, $tenant_id, $user_id;

    $file_id = (int)($data['file_id'] ?? 0);
    $share_with = $data['users'] ?? [];
    $permissions = $data['permissions'] ?? 'read';
    $expires_at = $data['expires_at'] ?? null;
    $message = $data['message'] ?? '';

    // Verifica proprietà
    $stmt = $pdo->prepare("
        SELECT * FROM files
        WHERE id = ? AND tenant_id = ? AND owner_id = ?
    ");
    $stmt->execute([$file_id, $tenant_id, $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        throw new Exception('File non trovato o non sei il proprietario', 404);
    }

    $shared_with = [];

    foreach ($share_with as $target_user_id) {
        // Verifica che l'utente esista nel tenant
        $stmt = $pdo->prepare("
            SELECT id, email, display_name FROM users
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$target_user_id, $tenant_id]);
        $target_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_user) {
            continue;
        }

        // Crea condivisione
        $stmt = $pdo->prepare("
            INSERT INTO file_shares (
                tenant_id, file_id, user_id, shared_by,
                permissions, expires_at, message
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                permissions = VALUES(permissions),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ");

        $stmt->execute([
            $tenant_id,
            $file_id,
            $target_user_id,
            $user_id,
            $permissions,
            $expires_at,
            $message
        ]);

        // Invia notifica
        sendNotification($target_user_id, 'file_shared',
            'File condiviso con te',
            $user['display_name'] . ' ha condiviso "' . $file['name'] . '" con te',
            ['file_id' => $file_id, 'permissions' => $permissions]
        );

        $shared_with[] = $target_user['display_name'];
    }

    return [
        'success' => true,
        'shared_with' => $shared_with,
        'file' => $file['name']
    ];
}

/**
 * Helper: Formatta dimensione file
 */
function formatFileSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Helper: Ottieni icona file
 */
function getFileIcon(string $mime_type): string {
    $icons = [
        'application/pdf' => 'file-pdf',
        'application/msword' => 'file-word',
        'application/vnd.ms-excel' => 'file-excel',
        'application/vnd.ms-powerpoint' => 'file-powerpoint',
        'text/plain' => 'file-text',
        'text/html' => 'file-code',
        'image/' => 'file-image',
        'video/' => 'file-video',
        'audio/' => 'file-audio',
        'application/zip' => 'file-archive'
    ];

    foreach ($icons as $pattern => $icon) {
        if (str_starts_with($mime_type, $pattern)) {
            return $icon;
        }
    }

    return 'file';
}

/**
 * Helper: Log attività
 */
function logActivity(string $action, string $resource_type, int $resource_id, array $metadata = []): void {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            tenant_id, user_id, action, resource_type,
            resource_id, ip_address, user_agent, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $user_id,
        $action,
        $resource_type,
        $resource_id,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        json_encode($metadata)
    ]);
}

/**
 * Helper: Invia notifica
 */
function sendNotification(int $to_user_id, string $type, string $title, string $message, array $data = []): void {
    global $pdo, $tenant_id;

    $stmt = $pdo->prepare("
        INSERT INTO notifications (tenant_id, user_id, type, title, message, data)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $to_user_id,
        $type,
        $title,
        $message,
        json_encode($data)
    ]);
}

/**
 * Helper: Verifica accesso cartella
 */
function checkFolderAccess(int $folder_id, int $user_id): bool {
    global $pdo, $tenant_id;

    // Per ora tutti hanno accesso alle cartelle del proprio tenant
    // In futuro si può implementare ACL più granulare
    $stmt = $pdo->prepare("
        SELECT id FROM folders
        WHERE id = ? AND tenant_id = ?
    ");
    $stmt->execute([$folder_id, $tenant_id]);

    return $stmt->fetch() !== false;
}

// Funzioni aggiuntive da implementare
function searchFiles(string $query): array {
    global $pdo, $tenant_id;

    $stmt = $pdo->prepare("
        SELECT f.*, u.display_name as owner_name
        FROM files f
        LEFT JOIN users u ON f.owner_id = u.id
        WHERE f.tenant_id = ?
        AND f.deleted_at IS NULL
        AND (f.name LIKE ? OR f.description LIKE ? OR f.tags LIKE ?)
        ORDER BY f.updated_at DESC
        LIMIT 50
    ");

    $search_term = '%' . $query . '%';
    $stmt->execute([$tenant_id, $search_term, $search_term, $search_term]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSharedFiles(): array {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        SELECT f.*, u.display_name as owner_name,
               fs.permissions, fs.shared_by, fs.created_at as shared_at
        FROM file_shares fs
        JOIN files f ON fs.file_id = f.id
        LEFT JOIN users u ON f.owner_id = u.id
        WHERE fs.tenant_id = ?
        AND fs.user_id = ?
        AND f.deleted_at IS NULL
        AND (fs.expires_at IS NULL OR fs.expires_at > NOW())
        ORDER BY fs.created_at DESC
    ");

    $stmt->execute([$tenant_id, $user_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentFiles(): array {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        SELECT f.*, u.display_name as owner_name
        FROM files f
        LEFT JOIN users u ON f.owner_id = u.id
        WHERE f.tenant_id = ?
        AND (f.owner_id = ? OR EXISTS(
            SELECT 1 FROM file_shares
            WHERE file_id = f.id AND user_id = ?
        ))
        AND f.deleted_at IS NULL
        ORDER BY f.updated_at DESC
        LIMIT 20
    ");

    $stmt->execute([$tenant_id, $user_id, $user_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deleteFile(int $file_id): array {
    global $pdo, $tenant_id, $user_id;

    // Soft delete
    $stmt = $pdo->prepare("
        UPDATE files
        SET deleted_at = NOW(), deleted_by = ?
        WHERE id = ? AND tenant_id = ? AND owner_id = ?
    ");

    $stmt->execute([$user_id, $file_id, $tenant_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('File non trovato o non autorizzato', 404);
    }

    return ['success' => true];
}

function renameFile(int $file_id, string $new_name): array {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        UPDATE files
        SET name = ?, updated_at = NOW()
        WHERE id = ? AND tenant_id = ? AND owner_id = ?
    ");

    $stmt->execute([$new_name, $file_id, $tenant_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('File non trovato o non autorizzato', 404);
    }

    return ['success' => true, 'name' => $new_name];
}