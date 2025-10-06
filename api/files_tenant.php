<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth_simple.php';
require_once '../includes/api_response.php';

// Authentication validation
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Non autorizzato']));
}

// Get database connection
$db = Database::getInstance();
$pdo = $db->getConnection();

// User session data
$user_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Input handling
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

// CSRF validation for state-changing operations
$csrf_required = ['create_root_folder', 'create_folder', 'upload', 'delete', 'rename'];
if (in_array($action, $csrf_required)) {
    $csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }
}

/**
 * SCHEMA DOCUMENTATION
 *
 * files table columns:
 * - file_size (NOT size_bytes)
 * - file_path (NOT storage_path)
 * - uploaded_by (NOT owner_id)
 * - name, original_name, mime_type, status
 *
 * folders table columns:
 * - owner_id (correct for folders, different from files!)
 * - name, path, parent_id
 *
 * Note: file_versions table still uses old schema (size_bytes, storage_path)
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
                echo json_encode([
                    'success' => true,
                    'schema' => [
                        'files' => ['file_size', 'file_path', 'uploaded_by', 'name', 'mime_type', 'status'],
                        'folders' => ['owner_id', 'name', 'path', 'parent_id']
                    ]
                ]);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Debug mode non attivo']);
            }
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Azione non valida']);
    }
} catch (Exception $e) {
    error_log('Files Tenant API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore del server', 'debug' => DEBUG_MODE ? $e->getMessage() : null]);
}

/**
 * Lista files e cartelle filtrati per tenant
 */
function listFiles() {
    global $pdo, $user_id, $tenant_id, $user_role;

    $folder_id = $_GET['folder_id'] ?? null;
    $search = $_GET['search'] ?? '';

    try {
        // Query per cartelle (folders usa owner_id)
        $folder_query = "
            SELECT
                f.id,
                f.name,
                f.parent_id,
                f.tenant_id,
                f.created_at,
                f.updated_at,
                'folder' as type,
                NULL as size,
                NULL as mime_type,
                t.name as tenant_name,
                COUNT(DISTINCT sf.id) as subfolder_count,
                COUNT(DISTINCT fil.id) as file_count
            FROM folders f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            LEFT JOIN folders sf ON sf.parent_id = f.id AND sf.deleted_at IS NULL
            LEFT JOIN files fil ON fil.folder_id = f.id AND fil.deleted_at IS NULL
            WHERE f.deleted_at IS NULL
        ";

        // Query per files - Schema: files usa file_size, file_path, uploaded_by
        $file_query = "
            SELECT
                f.id,
                f.name,
                f.folder_id as parent_id,
                f.tenant_id,
                f.created_at,
                f.updated_at,
                'file' as type,
                f.file_size as size,
                f.mime_type,
                t.name as tenant_name,
                0 as subfolder_count,
                0 as file_count
            FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.deleted_at IS NULL
        ";

        $params = [];

        // Filtro per cartella
        if ($folder_id !== null && $folder_id !== '') {
            $folder_query .= " AND f.parent_id = :folder_id";
            $file_query .= " AND f.folder_id = :folder_id";
            $params[':folder_id'] = $folder_id;
        } else {
            // Root level - solo cartelle root
            $folder_query .= " AND f.parent_id IS NULL";
            $file_query .= " AND 1=0"; // Nessun file al root
        }

        // Filtro per tenant basato sul ruolo
        if ($user_role === 'super_admin') {
            // Super Admin vede tutto
        } elseif ($user_role === 'admin') {
            // Admin vede solo i tenant a cui ha accesso
            $tenant_access_query = "
                SELECT tenant_id
                FROM user_tenant_access
                WHERE user_id = :user_id
            ";
            $stmt = $pdo->prepare($tenant_access_query);
            $stmt->execute([':user_id' => $user_id]);
            $accessible_tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($accessible_tenants)) {
                $accessible_tenants = [$tenant_id]; // Almeno il proprio tenant
            }

            $tenant_placeholders = implode(',', array_map(fn($i) => ":tenant_$i", array_keys($accessible_tenants)));
            $folder_query .= " AND f.tenant_id IN ($tenant_placeholders)";
            $file_query .= " AND f.tenant_id IN ($tenant_placeholders)";

            foreach ($accessible_tenants as $i => $tid) {
                $params[":tenant_$i"] = $tid;
            }
        } else {
            // User e Manager vedono solo il proprio tenant
            $folder_query .= " AND f.tenant_id = :tenant_id";
            $file_query .= " AND f.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenant_id;
        }

        // Ricerca - Schema: files.name, folders.name
        if (!empty($search)) {
            $folder_query .= " AND f.name LIKE :search";
            $file_query .= " AND f.name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        // Aggiunta GROUP BY per le cartelle
        $folder_query .= " GROUP BY f.id, f.name, f.parent_id, f.tenant_id, f.created_at, f.updated_at, t.name";

        // Unione delle query
        $full_query = "($folder_query) UNION ALL ($file_query) ORDER BY type ASC, name ASC";

        $stmt = $pdo->prepare($full_query);
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
                FROM folders f
                LEFT JOIN tenants t ON f.tenant_id = t.id
                WHERE f.id = :id AND f.deleted_at IS NULL
            ");
            $stmt->execute([':id' => $folder_id]);
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
        error_log('ListFiles SQL Error: ' . $e->getMessage());
        error_log('SQL State: ' . $e->getCode());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nel caricamento dei file',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    } catch (Exception $e) {
        error_log('ListFiles Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nel caricamento dei file',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Crea una cartella root (solo Admin/Super Admin)
 */
function createRootFolder() {
    global $pdo, $input, $user_id, $tenant_id, $user_role;

    // Verifica permessi
    if (!in_array($user_role, ['admin', 'super_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Non autorizzato a creare cartelle root']);
        return;
    }

    $folder_name = trim($input['name'] ?? '');
    $target_tenant_id = $input['tenant_id'] ?? null;

    if (empty($folder_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome cartella richiesto']);
        return;
    }

    // Validazione tenant_id
    if (empty($target_tenant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tenant richiesto per cartella root']);
        return;
    }

    // Verifica che l'admin abbia accesso al tenant selezionato
    if ($user_role === 'admin') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM user_tenant_access
            WHERE user_id = :user_id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $target_tenant_id
        ]);

        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Non hai accesso a questo tenant']);
            return;
        }
    }

    try {
        // Verifica se esiste già una cartella root con lo stesso nome per questo tenant
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM folders
            WHERE name = :name
            AND parent_id IS NULL
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':name' => $folder_name,
            ':tenant_id' => $target_tenant_id
        ]);

        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Una cartella root con questo nome esiste già per il tenant']);
            return;
        }

        // Schema: folders table uses owner_id (not uploaded_by) and has path column
        $stmt = $pdo->prepare("
            INSERT INTO folders (name, parent_id, tenant_id, owner_id, path, created_at, updated_at)
            VALUES (:name, NULL, :tenant_id, :user_id, '/', NOW(), NOW())
        ");

        $stmt->execute([
            ':name' => $folder_name,
            ':tenant_id' => $target_tenant_id,
            ':user_id' => $user_id
        ]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_root_folder', 'folders', $folder_id, [
            'name' => $folder_name,
            'tenant_id' => $target_tenant_id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Cartella root creata con successo',
            'folder_id' => $folder_id
        ]);

    } catch (Exception $e) {
        error_log('CreateRootFolder Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nella creazione della cartella root',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Crea una sotto-cartella
 */
function createFolder() {
    global $pdo, $input, $user_id, $tenant_id, $user_role;

    $folder_name = trim($input['name'] ?? '');
    $parent_id = $input['parent_id'] ?? null;

    if (empty($folder_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome cartella richiesto']);
        return;
    }

    if (empty($parent_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cartella padre richiesta per sotto-cartelle']);
        return;
    }

    try {
        // Verifica che la cartella padre esista e ottieni il suo tenant_id
        $stmt = $pdo->prepare("
            SELECT tenant_id, name
            FROM folders
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $parent_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            http_response_code(404);
            echo json_encode(['error' => 'Cartella padre non trovata']);
            return;
        }

        // Verifica accesso al tenant della cartella padre
        if (!hasAccessToTenant($parent['tenant_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Non hai accesso a questo tenant']);
            return;
        }

        // Verifica unicità nome nella cartella padre
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM folders
            WHERE name = :name
            AND parent_id = :parent_id
            AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':name' => $folder_name,
            ':parent_id' => $parent_id
        ]);

        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Una cartella con questo nome esiste già']);
            return;
        }

        // Schema: folders table uses owner_id and path
        // Costruisci path completo
        $parentPath = getBreadcrumb($parent_id);
        $fullPath = '/' . implode('/', array_column($parentPath, 'name')) . '/' . $folder_name;

        $stmt = $pdo->prepare("
            INSERT INTO folders (name, parent_id, tenant_id, owner_id, path, created_at, updated_at)
            VALUES (:name, :parent_id, :tenant_id, :user_id, :path, NOW(), NOW())
        ");

        $stmt->execute([
            ':name' => $folder_name,
            ':parent_id' => $parent_id,
            ':tenant_id' => $parent['tenant_id'],
            ':user_id' => $user_id,
            ':path' => $fullPath
        ]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_folder', 'folders', $folder_id, [
            'name' => $folder_name,
            'parent_id' => $parent_id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Cartella creata con successo',
            'folder_id' => $folder_id
        ]);

    } catch (Exception $e) {
        error_log('CreateFolder Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nella creazione della cartella',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Upload di un file
 */
function uploadFile() {
    global $pdo, $user_id, $tenant_id, $user_role;

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nessun file caricato']);
        return;
    }

    $folder_id = $_POST['folder_id'] ?? null;

    if (empty($folder_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Non è possibile caricare file nella root. Seleziona una cartella.']);
        return;
    }

    try {
        // Verifica che la cartella esista e ottieni il suo tenant_id
        $stmt = $pdo->prepare("
            SELECT tenant_id
            FROM folders
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $folder_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            http_response_code(404);
            echo json_encode(['error' => 'Cartella non trovata']);
            return;
        }

        // Verifica accesso al tenant
        if (!hasAccessToTenant($folder['tenant_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Non hai accesso a questo tenant']);
            return;
        }

        $file = $_FILES['file'];
        $original_name = $file['name'];
        $tmp_name = $file['tmp_name'];
        $size = $file['size'];
        $error = $file['error'];

        // Verifica errori upload
        if ($error !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Errore durante il caricamento del file']);
            return;
        }

        // Verifica dimensione file
        if ($size > MAX_FILE_SIZE) {
            http_response_code(400);
            echo json_encode(['error' => 'File troppo grande (max ' . (MAX_FILE_SIZE / 1048576) . 'MB)']);
            return;
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
            http_response_code(500);
            echo json_encode(['error' => 'Errore nel salvataggio del file']);
            return;
        }

        // Schema: files table uses file_size, file_path, uploaded_by, mime_type, status
        $stmt = $pdo->prepare("
            INSERT INTO files (
                folder_id, tenant_id, name, original_name, file_size,
                file_path, uploaded_by, mime_type, status, created_at, updated_at
            ) VALUES (
                :folder_id, :tenant_id, :name, :original_name, :size,
                :path, :user_id, :mime_type, 'in_approvazione', NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':folder_id' => $folder_id,
            ':tenant_id' => $folder['tenant_id'],
            ':name' => $original_name,
            ':original_name' => $original_name,
            ':size' => $size,
            ':path' => $unique_name,
            ':user_id' => $user_id,
            ':mime_type' => $mime_type
        ]);

        $file_id = $pdo->lastInsertId();

        // Log audit
        logAudit('upload_file', 'files', $file_id, [
            'filename' => $original_name,
            'folder_id' => $folder_id,
            'size' => $size
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'File caricato con successo',
            'file_id' => $file_id
        ]);

    } catch (Exception $e) {
        error_log('UploadFile Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nel caricamento del file',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Elimina file o cartella
 */
function deleteItem() {
    global $pdo, $input, $user_id, $user_role;

    $item_id = $input['id'] ?? null;
    $item_type = $input['type'] ?? null;

    if (empty($item_id) || empty($item_type)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e tipo richiesti']);
        return;
    }

    if (!in_array($item_type, ['file', 'folder'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo non valido']);
        return;
    }

    try {
        if ($item_type === 'file') {
            // Schema: files table uses uploaded_by (not owner_id)
            $stmt = $pdo->prepare("
                SELECT tenant_id, uploaded_by
                FROM files
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $item_id]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$file) {
                http_response_code(404);
                echo json_encode(['error' => 'File non trovato']);
                return;
            }

            // Verifica accesso
            if (!hasAccessToTenant($file['tenant_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai accesso a questo file']);
                return;
            }

            // Solo chi ha caricato il file, manager, admin o super_admin può eliminare
            if ($file['uploaded_by'] != $user_id && !in_array($user_role, ['manager', 'admin', 'super_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai i permessi per eliminare questo file']);
                return;
            }

            // Soft delete
            $stmt = $pdo->prepare("
                UPDATE files
                SET deleted_at = NOW(), deleted_by = :user_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':user_id' => $user_id,
                ':id' => $item_id
            ]);

        } else {
            // Schema: folders table uses owner_id
            $stmt = $pdo->prepare("
                SELECT tenant_id, owner_id, parent_id
                FROM folders
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $item_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                http_response_code(404);
                echo json_encode(['error' => 'Cartella non trovata']);
                return;
            }

            // Verifica accesso
            if (!hasAccessToTenant($folder['tenant_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai accesso a questa cartella']);
                return;
            }

            // Per cartelle root, solo admin/super_admin
            if ($folder['parent_id'] === null && !in_array($user_role, ['admin', 'super_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo Admin può eliminare cartelle root']);
                return;
            }

            // Verifica che la cartella sia vuota
            $stmt = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM folders WHERE parent_id = :id AND deleted_at IS NULL) as subfolders,
                    (SELECT COUNT(*) FROM files WHERE folder_id = :id2 AND deleted_at IS NULL) as files
            ");
            $stmt->execute([':id' => $item_id, ':id2' => $item_id]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($counts['subfolders'] > 0 || $counts['files'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'La cartella non è vuota']);
                return;
            }

            // Soft delete
            $stmt = $pdo->prepare("
                UPDATE folders
                SET deleted_at = NOW(), deleted_by = :user_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':user_id' => $user_id,
                ':id' => $item_id
            ]);
        }

        // Log audit
        logAudit('delete_' . $item_type, $item_type . 's', $item_id, []);

        echo json_encode([
            'success' => true,
            'message' => ucfirst($item_type) . ' eliminato con successo'
        ]);

    } catch (Exception $e) {
        error_log('DeleteItem Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nell\'eliminazione',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Rinomina file o cartella
 */
function renameItem() {
    global $pdo, $input, $user_id, $user_role;

    $item_id = $input['id'] ?? null;
    $item_type = $input['type'] ?? null;
    $new_name = trim($input['name'] ?? '');

    if (empty($item_id) || empty($item_type) || empty($new_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID, tipo e nuovo nome richiesti']);
        return;
    }

    try {
        if ($item_type === 'file') {
            // Schema: files table uses uploaded_by (not owner_id)
            $stmt = $pdo->prepare("
                SELECT tenant_id, uploaded_by, folder_id
                FROM files
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $item_id]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$file) {
                http_response_code(404);
                echo json_encode(['error' => 'File non trovato']);
                return;
            }

            // Verifica accesso
            if (!hasAccessToTenant($file['tenant_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai accesso a questo file']);
                return;
            }

            // Schema: files table uses 'name' column
            // Verifica unicità nome nella cartella
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM files
                WHERE name = :name
                AND folder_id = :folder_id
                AND id != :id
                AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':name' => $new_name,
                ':folder_id' => $file['folder_id'],
                ':id' => $item_id
            ]);

            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Un file con questo nome esiste già']);
                return;
            }

            // Aggiorna nome
            $stmt = $pdo->prepare("
                UPDATE files
                SET name = :name, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $new_name,
                ':id' => $item_id
            ]);

        } else {
            // Verifica permessi cartella
            $stmt = $pdo->prepare("
                SELECT tenant_id, parent_id
                FROM folders
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $item_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                http_response_code(404);
                echo json_encode(['error' => 'Cartella non trovata']);
                return;
            }

            // Verifica accesso
            if (!hasAccessToTenant($folder['tenant_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai accesso a questa cartella']);
                return;
            }

            // Verifica unicità nome al livello corrente
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM folders
                WHERE name = :name
                AND " . ($folder['parent_id'] ? "parent_id = :parent_id" : "parent_id IS NULL") . "
                AND tenant_id = :tenant_id
                AND id != :id
                AND deleted_at IS NULL
            ");

            $params = [
                ':name' => $new_name,
                ':tenant_id' => $folder['tenant_id'],
                ':id' => $item_id
            ];

            if ($folder['parent_id']) {
                $params[':parent_id'] = $folder['parent_id'];
            }

            $stmt->execute($params);

            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Una cartella con questo nome esiste già']);
                return;
            }

            // Aggiorna nome
            $stmt = $pdo->prepare("
                UPDATE folders
                SET name = :name, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $new_name,
                ':id' => $item_id
            ]);
        }

        // Log audit
        logAudit('rename_' . $item_type, $item_type . 's', $item_id, ['new_name' => $new_name]);

        echo json_encode([
            'success' => true,
            'message' => ucfirst($item_type) . ' rinominato con successo'
        ]);

    } catch (Exception $e) {
        error_log('RenameItem Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nella rinomina',
            'debug' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Ottiene la lista dei tenant per Admin/Super Admin
 */
function getTenantList() {
    global $pdo, $user_id, $user_role;

    if (!in_array($user_role, ['admin', 'super_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Non autorizzato']);
        return;
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
        error_log('GetTenantList Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Errore nel caricamento dei tenant']);
    }
}

/**
 * Download di un file
 */
function downloadFile() {
    global $pdo, $user_id, $user_role;

    $file_id = $_GET['id'] ?? null;

    if (empty($file_id)) {
        http_response_code(400);
        die(json_encode(['error' => 'ID file richiesto']));
    }

    try {
        // Schema: files table uses file_path (not storage_path)
        $stmt = $pdo->prepare("
            SELECT f.*, t.name as tenant_name
            FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.id = :id AND f.deleted_at IS NULL
        ");
        $stmt->execute([':id' => $file_id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            die(json_encode(['error' => 'File non trovato']));
        }

        // Verifica accesso al tenant
        if (!hasAccessToTenant($file['tenant_id'])) {
            http_response_code(403);
            die(json_encode(['error' => 'Non hai accesso a questo file']));
        }

        // Schema: files table uses file_path
        $file_path = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $file['file_path'];

        if (!file_exists($file_path)) {
            http_response_code(404);
            die(json_encode(['error' => 'File fisico non trovato']));
        }

        // Nome del file per il download
        $file_display_name = $file['name'] ?? 'download';

        // Log audit
        logAudit('download_file', 'files', $file_id, ['filename' => $file_display_name]);

        // Determina MIME type
        $mime_type = $file['mime_type'] ?? 'application/octet-stream';

        // Invia file per download
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $file_display_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-cache, must-revalidate');

        // Pulisci output buffer per evitare problemi con file binari
        ob_clean();
        flush();

        readfile($file_path);
        exit;

    } catch (Exception $e) {
        error_log('DownloadFile Error: ' . $e->getMessage());
        http_response_code(500);
        die(json_encode(['error' => 'Errore nel download del file']));
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
 */
function getBreadcrumb($folder_id) {
    global $pdo;

    $breadcrumb = [];
    $current_id = $folder_id;

    while ($current_id) {
        $stmt = $pdo->prepare("
            SELECT id, name, parent_id
            FROM folders
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $current_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) break;

        array_unshift($breadcrumb, [
            'id' => $folder['id'],
            'name' => $folder['name']
        ]);

        $current_id = $folder['parent_id'];
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
                details, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :tenant_id, :action, :entity_type, :entity_id,
                :details, :ip, :agent, NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id,
            ':action' => $action,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':details' => json_encode($details),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// Pulisci qualsiasi output buffer residuo
ob_end_flush();
?>