<?php
/**
 * Files Tenant API - Tenant-aware file management
 *
 * @version 2.0.0 - Refactored to use centralized api_auth.php
 */

// Include centralized API authentication
require_once __DIR__ . '/../includes/api_auth.php';

// Check if this is a download action BEFORE initializing API environment
// Download actions need to set their own headers (binary content, not JSON)
$action = $_GET['action'] ?? '';
$is_download = ($action === 'download');

// Initialize API environment (session, headers, error handling)
// Skip JSON headers for download actions
if (!$is_download) {
    initializeApiEnvironment();
} else {
    // For downloads, only start session and auth, but don't set JSON headers
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ob_start();
    require_once __DIR__ . '/../includes/session_init.php';
}

// Include required files
require_once '../config.php';
require_once '../includes/db.php';

// Verify authentication
verifyApiAuthentication();

// Get current user info
$userInfo = getApiUserInfo();
$user_id = $userInfo['user_id'];
$tenant_id = $userInfo['tenant_id'];
$user_role = $userInfo['role'];

// Get database connection
$db = Database::getInstance();
$pdo = $db->getConnection();

// Input handling
$input = json_decode(file_get_contents('php://input'), true);

// CSRF validation for state-changing operations
$csrf_required = ['create_root_folder', 'create_folder', 'upload', 'delete', 'rename'];
if (in_array($action, $csrf_required)) {
    verifyApiCsrfToken();
}

/**
 * SCHEMA DOCUMENTATION - UPDATED 2025-10-12
 *
 * UNIFIED files table (handles both files AND folders):
 * - id: Primary key
 * - tenant_id: Multi-tenant isolation
 * - name: File or folder name
 * - file_path: Storage path (for files) or directory path (for folders)
 * - file_size: Size in bytes (NULL for folders)
 * - mime_type: MIME type (NULL for folders)
 * - is_folder: 1 = folder, 0 = file
 * - folder_id: Parent folder ID (NULL = root level, self-referencing FK)
 * - uploaded_by: User who created/uploaded
 * - original_name: Original filename
 * - status: 'in_approvazione', 'approvato', 'rifiutato' (for approval workflow)
 * - deleted_at: Soft delete timestamp
 *
 * Key differences from old schema:
 * - NO separate 'folders' table - everything is in 'files'
 * - Use is_folder flag to distinguish files from folders
 * - folder_id replaced parent_id (self-referencing)
 * - uploaded_by used for both files and folders (was owner_id for folders)
 */

try {
    switch ($action) {
        case 'list':
            listFiles();
            break;
        case 'create_root_folder':
            createRootFolder();
            break;
        case 'create_folder':
            createFolder();
            break;
        case 'upload':
            uploadFile();
            break;
        case 'delete':
            deleteItem();
            break;
        case 'rename':
            renameItem();
            break;
        case 'get_tenant_list':
            getTenantList();
            break;
        case 'download':
            downloadFile();
            break;
        case 'get_folder_path':
            getFolderPath();
            break;
        case 'debug_columns':
            // Endpoint di debug per verificare schema corrente
            if (DEBUG_MODE) {
                apiSuccess([
                    'schema' => [
                        'files' => ['file_size', 'file_path', 'uploaded_by', 'name', 'mime_type', 'status'],
                        'folders' => ['owner_id', 'name', 'path', 'parent_id']
                    ]
                ], 'Schema information');
            } else {
                apiError('Debug mode non attivo', 403);
            }
            break;
        default:
            apiError('Azione non valida', 400);
    }
} catch (Exception $e) {
    logApiError('Files Tenant API', $e);
    apiError('Errore del server', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

/**
 * Lista files e cartelle filtrati per tenant
 * UPDATED: Uses unified files table with is_folder flag
 */
function listFiles() {
    global $pdo, $user_id, $tenant_id, $user_role;

    $folder_id = $_GET['folder_id'] ?? null;
    $search = $_GET['search'] ?? '';

    try {
        // Build query for unified files table (handles both files and folders)
        $query = "
            SELECT
                f.id,
                f.name,
                f.folder_id as parent_id,
                f.tenant_id,
                f.created_at,
                f.updated_at,
                CASE WHEN f.is_folder = 1 THEN 'folder' ELSE 'file' END as type,
                f.file_size as size,
                f.mime_type,
                f.is_folder,
                t.name as tenant_name,
                (SELECT COUNT(*) FROM files sf WHERE sf.folder_id = f.id AND sf.is_folder = 1 AND sf.deleted_at IS NULL) as subfolder_count,
                (SELECT COUNT(*) FROM files fil WHERE fil.folder_id = f.id AND fil.is_folder = 0 AND fil.deleted_at IS NULL) as file_count
            FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.deleted_at IS NULL
        ";

        $params = [];

        // Filtro per cartella
        if ($folder_id !== null && $folder_id !== '') {
            $query .= " AND f.folder_id = ?";
            $params[] = $folder_id;
        } else {
            // Root level - solo items senza parent
            $query .= " AND f.folder_id IS NULL";
        }

        // Filtro per tenant basato sul ruolo
        if ($user_role === 'super_admin') {
            // Super Admin vede tutto
        } elseif ($user_role === 'admin') {
            // Admin vede solo i tenant a cui ha accesso
            $tenant_access_query = "
                SELECT tenant_id
                FROM user_tenant_access
                WHERE user_id = ?
            ";
            $stmt = $pdo->prepare($tenant_access_query);
            $stmt->execute([$user_id]);
            $accessible_tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($accessible_tenants)) {
                $accessible_tenants = [$tenant_id]; // Almeno il proprio tenant
            }

            $placeholders = implode(',', array_fill(0, count($accessible_tenants), '?'));
            $query .= " AND f.tenant_id IN ($placeholders)";
            $params = array_merge($params, $accessible_tenants);
        } else {
            // User e Manager vedono solo il proprio tenant
            $query .= " AND f.tenant_id = ?";
            $params[] = $tenant_id;
        }

        // Ricerca
        if (!empty($search)) {
            $query .= " AND f.name LIKE ?";
            $params[] = '%' . $search . '%';
        }

        // Ordinamento: cartelle prima, poi per nome
        $query .= " ORDER BY f.is_folder DESC, f.name ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Breadcrumb
        $breadcrumb = [];
        if ($folder_id) {
            $breadcrumb = getBreadcrumb($folder_id);
        }

        // Informazioni sulla cartella corrente
        $current_folder = null;
        if ($folder_id) {
            $stmt = $pdo->prepare("
                SELECT f.*, t.name as tenant_name
                FROM files f
                LEFT JOIN tenants t ON f.tenant_id = t.id
                WHERE f.id = ? AND f.is_folder = 1 AND f.deleted_at IS NULL
            ");
            $stmt->execute([$folder_id]);
            $current_folder = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $items,
                'breadcrumb' => $breadcrumb,
                'current_folder' => $current_folder,
                'user_role' => $user_role,
                'can_create_root' => in_array($user_role, ['admin', 'super_admin'])
            ]
        ]);

    } catch (PDOException $e) {
        logApiError('ListFiles SQL', $e);
        error_log('SQL State: ' . $e->getCode());
        apiError('Errore nel caricamento dei file', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    } catch (Exception $e) {
        logApiError('ListFiles', $e);
        apiError('Errore nel caricamento dei file', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Crea una cartella root (solo Admin/Super Admin)
 * UPDATED: Uses unified files table with is_folder flag
 */
function createRootFolder() {
    global $pdo, $input, $user_id, $tenant_id, $user_role;

    // Verifica permessi
    if (!hasApiRole('admin')) {
        apiError('Non autorizzato a creare cartelle root', 403);
    }

    $folder_name = trim($input['name'] ?? '');
    $target_tenant_id = $input['tenant_id'] ?? null;

    if (empty($folder_name)) {
        apiError('Nome cartella richiesto', 400);
    }

    // Validazione tenant_id
    if (empty($target_tenant_id)) {
        apiError('Tenant richiesto per cartella root', 400);
    }

    // Verifica che l'admin abbia accesso al tenant selezionato
    if ($user_role === 'admin') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM user_tenant_access
            WHERE user_id = ? AND tenant_id = ?
        ");
        $stmt->execute([$user_id, $target_tenant_id]);

        if ($stmt->fetchColumn() == 0) {
            apiError('Non hai accesso a questo tenant', 403);
        }
    }

    try {
        // Verifica se esiste già una cartella root con lo stesso nome per questo tenant
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM files
            WHERE name = ?
            AND folder_id IS NULL
            AND tenant_id = ?
            AND is_folder = 1
            AND deleted_at IS NULL
        ");
        $stmt->execute([$folder_name, $target_tenant_id]);

        if ($stmt->fetchColumn() > 0) {
            apiError('Una cartella root con questo nome esiste già per il tenant', 400);
        }

        // Create folder in unified files table
        $stmt = $pdo->prepare("
            INSERT INTO files (name, folder_id, tenant_id, uploaded_by, is_folder, file_path, created_at, updated_at)
            VALUES (?, NULL, ?, ?, 1, '/', NOW(), NOW())
        ");

        $stmt->execute([$folder_name, $target_tenant_id, $user_id]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_root_folder', 'files', $folder_id, [
            'name' => $folder_name,
            'tenant_id' => $target_tenant_id
        ]);

        apiSuccess(['folder_id' => $folder_id], 'Cartella root creata con successo');

    } catch (Exception $e) {
        logApiError('CreateRootFolder', $e);
        apiError('Errore nella creazione della cartella root', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Crea una sotto-cartella
 * UPDATED: Uses unified files table with is_folder flag
 */
function createFolder() {
    global $pdo, $input, $user_id, $tenant_id, $user_role;

    $folder_name = trim($input['name'] ?? '');
    $parent_id = $input['parent_id'] ?? null;

    if (empty($folder_name)) {
        apiError('Nome cartella richiesto', 400);
    }

    if (empty($parent_id)) {
        apiError('Cartella padre richiesta per sotto-cartelle', 400);
    }

    try {
        // Verifica che la cartella padre esista e ottieni il suo tenant_id
        $stmt = $pdo->prepare("
            SELECT tenant_id, name
            FROM files
            WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL
        ");
        $stmt->execute([$parent_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            apiError('Cartella padre non trovata', 404);
        }

        // Verifica accesso al tenant della cartella padre
        if (!hasAccessToTenant($parent['tenant_id'])) {
            apiError('Non hai accesso a questo tenant', 403);
        }

        // Verifica unicità nome nella cartella padre
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM files
            WHERE name = ?
            AND folder_id = ?
            AND deleted_at IS NULL
        ");
        $stmt->execute([$folder_name, $parent_id]);

        if ($stmt->fetchColumn() > 0) {
            apiError('Una cartella con questo nome esiste già', 400);
        }

        // Costruisci path completo
        $parentPath = getBreadcrumb($parent_id);
        $fullPath = '/' . implode('/', array_column($parentPath, 'name')) . '/' . $folder_name;

        $stmt = $pdo->prepare("
            INSERT INTO files (name, folder_id, tenant_id, uploaded_by, is_folder, file_path, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $folder_name,
            $parent_id,
            $parent['tenant_id'],
            $user_id,
            $fullPath
        ]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_folder', 'files', $folder_id, [
            'name' => $folder_name,
            'parent_id' => $parent_id
        ]);

        apiSuccess(['folder_id' => $folder_id], 'Cartella creata con successo');

    } catch (Exception $e) {
        logApiError('CreateFolder', $e);
        apiError('Errore nella creazione della cartella', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Upload di un file
 * UPDATED: Uses unified files table
 */
function uploadFile() {
    global $pdo, $user_id, $tenant_id, $user_role;

    if (!isset($_FILES['file'])) {
        apiError('Nessun file caricato', 400);
    }

    $folder_id = $_POST['folder_id'] ?? null;

    if (empty($folder_id)) {
        apiError('Non è possibile caricare file nella root. Seleziona una cartella.', 400);
    }

    try {
        // Verifica che la cartella esista e ottieni il suo tenant_id
        $stmt = $pdo->prepare("
            SELECT tenant_id
            FROM files
            WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL
        ");
        $stmt->execute([$folder_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            apiError('Cartella non trovata', 404);
        }

        // Verifica accesso al tenant
        if (!hasAccessToTenant($folder['tenant_id'])) {
            apiError('Non hai accesso a questo tenant', 403);
        }

        $file = $_FILES['file'];
        $original_name = $file['name'];
        $tmp_name = $file['tmp_name'];
        $size = $file['size'];
        $error = $file['error'];

        // Verifica errori upload
        if ($error !== UPLOAD_ERR_OK) {
            apiError('Errore durante il caricamento del file', 400);
        }

        // Verifica dimensione file
        if ($size > MAX_FILE_SIZE) {
            apiError('File troppo grande (max ' . (MAX_FILE_SIZE / 1048576) . 'MB)', 400);
        }

        // Genera nome file univoco
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $filename = pathinfo($original_name, PATHINFO_FILENAME);
        $unique_name = $filename . '_' . uniqid() . '.' . $extension;

        // Determina MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        // Crea directory per il tenant se non esiste
        $upload_dir = UPLOAD_PATH . '/' . $folder['tenant_id'];
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_path = $upload_dir . '/' . $unique_name;

        // Sposta il file
        if (!move_uploaded_file($tmp_name, $file_path)) {
            apiError('Errore nel salvataggio del file', 500);
        }

        // Insert into unified files table (is_folder = 0 for files)
        $stmt = $pdo->prepare("
            INSERT INTO files (
                folder_id, tenant_id, name, original_name, file_size,
                file_path, uploaded_by, mime_type, status, is_folder, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, 'in_approvazione', 0, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $folder_id,
            $folder['tenant_id'],
            $original_name,
            $original_name,
            $size,
            $unique_name,
            $user_id,
            $mime_type
        ]);

        $file_id = $pdo->lastInsertId();

        // Log audit
        logAudit('upload_file', 'files', $file_id, [
            'filename' => $original_name,
            'folder_id' => $folder_id,
            'size' => $size
        ]);

        apiSuccess(['file_id' => $file_id], 'File caricato con successo');

    } catch (Exception $e) {
        logApiError('UploadFile', $e);
        apiError('Errore nel caricamento del file', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Elimina file o cartella
 * UPDATED: Uses unified files table, accepts both JSON body and query string
 */
function deleteItem() {
    global $pdo, $input, $user_id, $user_role;

    // Accept id from query string OR JSON body (backwards compatible)
    $item_id = $_GET['id'] ?? $input['id'] ?? null;

    // Type is optional - we'll detect it from the database
    $item_type = $_GET['type'] ?? $input['type'] ?? null;

    if (empty($item_id)) {
        apiError('ID richiesto', 400);
    }

    try {
        // Get item from unified files table
        $stmt = $pdo->prepare("
            SELECT tenant_id, uploaded_by, is_folder, folder_id, name
            FROM files
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            apiError('Elemento non trovato', 404);
        }

        // Detect type from database if not provided
        $is_folder = ($item['is_folder'] == 1);
        $detected_type = $is_folder ? 'folder' : 'file';

        // If type was provided, verify it matches
        if ($item_type !== null && !in_array($item_type, ['file', 'folder'])) {
            apiError('Tipo non valido', 400);
        }

        if ($item_type !== null && $item_type !== $detected_type) {
            apiError('Tipo elemento non corrispondente', 400);
        }

        // Use detected type
        $item_type = $detected_type;

        // Verifica accesso
        if (!hasAccessToTenant($item['tenant_id'])) {
            apiError('Non hai accesso a questo ' . $item_type, 403);
        }

        if ($item_type === 'file') {
            // Solo chi ha caricato il file, manager, admin o super_admin può eliminare
            if ($item['uploaded_by'] != $user_id && !hasApiRole('manager')) {
                apiError('Non hai i permessi per eliminare questo file', 403);
            }
        } else {
            // Per cartelle root, solo admin/super_admin
            if ($item['folder_id'] === null && !hasApiRole('admin')) {
                apiError('Solo Admin può eliminare cartelle root', 403);
            }

            // Verifica che la cartella sia vuota
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM files
                WHERE folder_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$item_id]);
            $count_result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($count_result['count'] > 0) {
                apiError('La cartella non è vuota. Elimina prima il contenuto.', 400);
            }
        }

        // Soft delete
        $stmt = $pdo->prepare("
            UPDATE files
            SET deleted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$item_id]);

        // Log audit
        logAudit('delete_' . $item_type, 'files', $item_id, [
            'name' => $item['name']
        ]);

        apiSuccess(null, ucfirst($item_type) . ' eliminato con successo');

    } catch (Exception $e) {
        logApiError('DeleteItem', $e);
        apiError('Errore nell\'eliminazione', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Rinomina file o cartella
 * UPDATED: Uses unified files table
 */
function renameItem() {
    global $pdo, $input, $user_id, $user_role;

    $item_id = $input['id'] ?? null;
    $item_type = $input['type'] ?? null;
    $new_name = trim($input['name'] ?? '');

    if (empty($item_id) || empty($item_type) || empty($new_name)) {
        apiError('ID, tipo e nuovo nome richiesti', 400);
    }

    try {
        // Get item from unified files table
        $stmt = $pdo->prepare("
            SELECT tenant_id, uploaded_by, folder_id, is_folder
            FROM files
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            apiError(ucfirst($item_type) . ' non trovato', 404);
        }

        // Verify the type matches
        $is_folder = ($item['is_folder'] == 1);
        if (($item_type === 'folder' && !$is_folder) || ($item_type === 'file' && $is_folder)) {
            apiError('Tipo elemento non corrispondente', 400);
        }

        // Verifica accesso
        if (!hasAccessToTenant($item['tenant_id'])) {
            apiError('Non hai accesso a questo ' . $item_type, 403);
        }

        // Verifica unicità nome nella cartella/livello corrente
        if ($item['folder_id']) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM files
                WHERE name = ?
                AND folder_id = ?
                AND id != ?
                AND deleted_at IS NULL
            ");
            $stmt->execute([$new_name, $item['folder_id'], $item_id]);
        } else {
            // Root level
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM files
                WHERE name = ?
                AND folder_id IS NULL
                AND tenant_id = ?
                AND id != ?
                AND deleted_at IS NULL
            ");
            $stmt->execute([$new_name, $item['tenant_id'], $item_id]);
        }

        if ($stmt->fetchColumn() > 0) {
            apiError('Un elemento con questo nome esiste già', 400);
        }

        // Aggiorna nome
        $stmt = $pdo->prepare("
            UPDATE files
            SET name = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_name, $item_id]);

        // Log audit
        logAudit('rename_' . $item_type, 'files', $item_id, ['new_name' => $new_name]);

        apiSuccess(null, ucfirst($item_type) . ' rinominato con successo');

    } catch (Exception $e) {
        logApiError('RenameItem', $e);
        apiError('Errore nella rinomina', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Ottiene la lista dei tenant per Admin/Super Admin
 */
function getTenantList() {
    global $pdo, $user_id, $user_role;

    if (!hasApiRole('admin')) {
        apiError('Non autorizzato', 403);
    }

    try {
        if ($user_role === 'super_admin') {
            // Super Admin vede tutti i tenant
            $stmt = $pdo->prepare("
                SELECT id, name, is_active
                FROM tenants
                WHERE deleted_at IS NULL
                ORDER BY name
            ");
            $stmt->execute();
        } else {
            // Admin vede solo i tenant a cui ha accesso
            $stmt = $pdo->prepare("
                SELECT t.id, t.name, t.is_active
                FROM tenants t
                INNER JOIN user_tenant_access uta ON t.id = uta.tenant_id
                WHERE uta.user_id = :user_id
                AND t.deleted_at IS NULL
                ORDER BY t.name
            ");
            $stmt->execute([':user_id' => $user_id]);
        }

        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $tenants
        ]);

    } catch (Exception $e) {
        logApiError('GetTenantList', $e);
        apiError('Errore nel caricamento dei tenant', 500);
    }
}

/**
 * Download di un file
 * FIXED: Improved error handling and path validation
 */
function downloadFile() {
    global $pdo, $user_id, $user_role;

    $file_id = $_GET['id'] ?? null;

    if (empty($file_id)) {
        apiError('ID file richiesto', 400);
    }

    try {
        // Schema: files table uses file_path (not storage_path)
        $stmt = $pdo->prepare("
            SELECT f.*, t.name as tenant_name
            FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.id = :id AND f.deleted_at IS NULL AND f.is_folder = 0
        ");
        $stmt->execute([':id' => $file_id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            apiError('File non trovato nel database', 404);
        }

        // Verifica accesso al tenant
        if (!hasAccessToTenant($file['tenant_id'])) {
            apiError('Non hai accesso a questo file', 403);
        }

        // Schema: files table stores ONLY the unique filename in file_path
        // Construct full path: UPLOAD_PATH/tenant_id/file_path
        $file_path = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $file['file_path'];

        // Detailed error logging if file doesn't exist
        if (!file_exists($file_path)) {
            $error_details = [
                'file_id' => $file_id,
                'file_name' => $file['name'],
                'file_path_db' => $file['file_path'],
                'constructed_path' => $file_path,
                'upload_path_constant' => UPLOAD_PATH,
                'tenant_id' => $file['tenant_id'],
                'tenant_dir_exists' => is_dir(UPLOAD_PATH . '/' . $file['tenant_id'])
            ];

            // Log detailed error
            error_log('FILE_NOT_FOUND: ' . json_encode($error_details));

            // Check if tenant directory exists
            $tenant_dir = UPLOAD_PATH . '/' . $file['tenant_id'];
            if (!is_dir($tenant_dir)) {
                apiError('Directory del tenant non trovata', 500, DEBUG_MODE ? ['debug' => 'Tenant directory missing'] : null);
            }

            // List files in tenant directory for debugging
            if (DEBUG_MODE) {
                $available_files = [];
                if (is_dir($tenant_dir)) {
                    $files = scandir($tenant_dir);
                    foreach ($files as $f) {
                        if ($f !== '.' && $f !== '..') {
                            $available_files[] = $f;
                        }
                    }
                }
                apiError('File fisico non trovato sul server', 404, [
                    'debug' => 'File not found on disk',
                    'expected_path' => $file_path,
                    'available_files' => $available_files
                ]);
            }

            apiError('File fisico non trovato sul server', 404);
        }

        // Verify it's a regular file (not a directory)
        if (!is_file($file_path)) {
            apiError('Il percorso non punta a un file valido', 500);
        }

        // Verify file is readable
        if (!is_readable($file_path)) {
            apiError('File non leggibile - verifica i permessi', 500);
        }

        // Nome del file per il download
        $file_display_name = $file['name'] ?? 'download';

        // Sanitize filename for Content-Disposition header
        $file_display_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_display_name);

        // Log audit
        logAudit('download_file', 'files', $file_id, [
            'filename' => $file_display_name,
            'file_size' => filesize($file_path)
        ]);

        // Determina MIME type
        $mime_type = $file['mime_type'] ?? 'application/octet-stream';

        // Pulisci output buffer completamente prima di inviare file
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Determina se il file deve essere visualizzato inline o scaricato
        // PDF e immagini dovrebbero essere visualizzati inline per il browser
        $inline_types = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
        $disposition = in_array($mime_type, $inline_types) ? 'inline' : 'attachment';

        // Invia file con headers appropriati
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: ' . $disposition . '; filename="' . $file_display_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Accept-Ranges: bytes'); // Required for PDF.js and video streaming
        header('Cache-Control: public, max-age=3600'); // Allow caching for better performance
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');

        // Read and output file in chunks to handle large files
        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            apiError('Impossibile aprire il file', 500);
        }

        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }

        fclose($handle);
        exit;

    } catch (Exception $e) {
        logApiError('DownloadFile', $e);
        // Clear any output that might have been sent
        while (ob_get_level()) {
            ob_end_clean();
        }
        apiError('Errore nel download del file', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
    }
}

/**
 * Ottiene il percorso completo di una cartella
 */
function getFolderPath() {
    global $pdo;

    $folder_id = $_GET['folder_id'] ?? null;

    if (empty($folder_id)) {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }

    $breadcrumb = getBreadcrumb($folder_id);

    echo json_encode([
        'success' => true,
        'data' => $breadcrumb
    ]);
}

/**
 * Funzioni di supporto
 */

/**
 * Verifica se l'utente ha accesso a un tenant
 */
function hasAccessToTenant($check_tenant_id) {
    global $user_id, $tenant_id, $user_role, $pdo;

    // Super Admin ha sempre accesso
    if ($user_role === 'super_admin') {
        return true;
    }

    // Se è il proprio tenant
    if ($check_tenant_id == $tenant_id) {
        return true;
    }

    // Admin verifica accesso tramite user_tenant_access
    if ($user_role === 'admin') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM user_tenant_access
            WHERE user_id = :user_id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $check_tenant_id
        ]);

        return $stmt->fetchColumn() > 0;
    }

    return false;
}

/**
 * Genera breadcrumb per navigazione
 * UPDATED: Uses unified files table
 */
function getBreadcrumb($folder_id) {
    global $pdo;

    $breadcrumb = [];
    $current_id = $folder_id;

    while ($current_id) {
        $stmt = $pdo->prepare("
            SELECT id, name, folder_id
            FROM files
            WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL
        ");
        $stmt->execute([$current_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) break;

        array_unshift($breadcrumb, [
            'id' => $folder['id'],
            'name' => $folder['name']
        ]);

        $current_id = $folder['folder_id'];
    }

    return $breadcrumb;
}

/**
 * Log delle azioni per audit
 */
function logAudit($action, $entity_type, $entity_id, $details) {
    global $pdo, $user_id, $tenant_id;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, tenant_id, action, entity_type, entity_id,
                description, ip_address, user_agent, severity, status, created_at
            ) VALUES (
                :user_id, :tenant_id, :action, :entity_type, :entity_id,
                :description, :ip, :agent, :severity, :status, NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id,
            ':action' => $action,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':description' => json_encode($details),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':severity' => 'info',
            ':status' => 'success'
        ]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// End of file - output buffer handled by apiSuccess/apiError