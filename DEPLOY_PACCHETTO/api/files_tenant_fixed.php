<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Error handling setup - suppress PHP warnings/errors from output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth_simple.php';

// Clean any output that might have been generated
ob_clean();

// Initialize authentication
$auth = new AuthSimple();

// Check if user is authenticated
if (!$auth->checkAuth()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Non autorizzato']));
}

// Get database connection
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Errore connessione database']));
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Utente non trovato']));
}

// User session data
$user_id = $currentUser['id'];
$tenant_id = $currentUser['tenant_id'];
$user_role = $currentUser['role'] ?? 'user';

// Input handling
$action = $_GET['action'] ?? '';

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
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Azione non valida: ' . $action]);
    }
} catch (Exception $e) {
    error_log('Files API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => 'Errore del server',
        'details' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}

/**
 * Lista files e cartelle filtrati per tenant
 */
function listFiles() {
    global $pdo, $user_id, $tenant_id, $user_role;

    $folder_id = $_GET['folder_id'] ?? null;
    $search = $_GET['search'] ?? '';

    // Inizializza array accessible_tenants per tutti i ruoli
    $accessible_tenants = [];

    try {
        // Usa i nomi delle colonne corretti basati sullo schema del database
        // Files table usa: name, size_bytes, mime_type
        // Folders table usa: name, parent_id
        $file_name_col = 'name';
        $file_size_col = 'size_bytes';

        // Array per risultati
        $items = [];

        // Query per cartelle
        $folder_params = [];
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
                t.name as tenant_name
            FROM folders f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.deleted_at IS NULL
        ";

        // Filtro per cartella
        if ($folder_id !== null && $folder_id !== '') {
            $folder_query .= " AND f.parent_id = :folder_id";
            $folder_params[':folder_id'] = $folder_id;
        } else {
            // Root level - solo cartelle senza parent
            $folder_query .= " AND f.parent_id IS NULL";
        }

        // Filtro per tenant basato sul ruolo
        if ($user_role === 'super_admin') {
            // Super Admin vede tutto
        } elseif ($user_role === 'admin') {
            // Admin vede i tenant a cui ha accesso
            $accessible_query = "
                SELECT DISTINCT tenant_id
                FROM user_tenant_access
                WHERE user_id = :user_id
                UNION
                SELECT :tenant_id
            ";
            $stmt = $pdo->prepare($accessible_query);
            $stmt->execute([':user_id' => $user_id, ':tenant_id' => $tenant_id]);
            $accessible_tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($accessible_tenants)) {
                $placeholders = [];
                foreach ($accessible_tenants as $i => $tid) {
                    $placeholder = ":tenant_$i";
                    $placeholders[] = $placeholder;
                    $folder_params[$placeholder] = $tid;
                }
                $folder_query .= " AND f.tenant_id IN (" . implode(',', $placeholders) . ")";
            } else {
                $folder_query .= " AND f.tenant_id = :tenant_id";
                $folder_params[':tenant_id'] = $tenant_id;
            }
        } else {
            // User e Manager vedono solo il proprio tenant
            $folder_query .= " AND f.tenant_id = :tenant_id";
            $folder_params[':tenant_id'] = $tenant_id;
        }

        // Ricerca
        if (!empty($search)) {
            $folder_query .= " AND f.name LIKE :search";
            $folder_params[':search'] = '%' . $search . '%';
        }

        $folder_query .= " ORDER BY f.name ASC";

        // Esegui query cartelle
        $stmt = $pdo->prepare($folder_query);
        $stmt->execute($folder_params);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggiungi conteggi per cartelle
        foreach ($folders as &$folder) {
            // Conta sotto-cartelle
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM folders
                WHERE parent_id = :parent_id AND deleted_at IS NULL
            ");
            $stmt->execute([':parent_id' => $folder['id']]);
            $folder['subfolder_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Conta files
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM files
                WHERE folder_id = :folder_id AND deleted_at IS NULL
            ");
            $stmt->execute([':folder_id' => $folder['id']]);
            $folder['file_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        $items = array_merge($items, $folders);

        // Query per files (solo se siamo in una cartella, non al root)
        if ($folder_id !== null && $folder_id !== '') {
            $file_params = [':folder_id' => $folder_id];

            // Files table ha sempre mime_type secondo lo schema
            $file_query = "
                SELECT
                    f.id,
                    f.$file_name_col as name,
                    f.folder_id as parent_id,
                    f.tenant_id,
                    f.created_at,
                    f.updated_at,
                    'file' as type,
                    f.$file_size_col as size,
                    f.mime_type,
                    t.name as tenant_name,
                    0 as subfolder_count,
                    0 as file_count
                FROM files f
                LEFT JOIN tenants t ON f.tenant_id = t.id
                WHERE f.deleted_at IS NULL
                AND f.folder_id = :folder_id
            ";

            // Applica stesso filtro tenant delle cartelle
            if ($user_role === 'super_admin') {
                // Super Admin vede tutto
            } elseif ($user_role === 'admin' && !empty($accessible_tenants)) {
                $placeholders = [];
                foreach ($accessible_tenants as $i => $tid) {
                    $placeholder = ":ftenant_$i";
                    $placeholders[] = $placeholder;
                    $file_params[$placeholder] = $tid;
                }
                $file_query .= " AND f.tenant_id IN (" . implode(',', $placeholders) . ")";
            } else {
                $file_query .= " AND f.tenant_id = :tenant_id";
                $file_params[':tenant_id'] = $tenant_id;
            }

            // Ricerca nei files
            if (!empty($search)) {
                $file_query .= " AND f.$file_name_col LIKE :search";
                $file_params[':search'] = '%' . $search . '%';
            }

            $file_query .= " ORDER BY f.$file_name_col ASC";

            // Esegui query files
            $stmt = $pdo->prepare($file_query);
            $stmt->execute($file_params);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = array_merge($items, $files);
        }

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

        // Pulisci output buffer e invia risposta
        ob_clean();
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
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nel caricamento dei file',
            'details' => DEBUG_MODE ? [
                'message' => $e->getMessage(),
                'sql_state' => $e->errorInfo[0] ?? null,
                'driver_error' => $e->errorInfo[1] ?? null
            ] : null
        ]);
    }
}

/**
 * Crea una cartella root (solo Admin/Super Admin)
 */
function createRootFolder() {
    global $pdo, $user_id, $tenant_id, $user_role;

    // Leggi input JSON
    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF validation
    $csrf_token = $input['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

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

    if (empty($target_tenant_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tenant richiesto per cartella root']);
        return;
    }

    try {
        // Verifica accesso al tenant per Admin
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

            if ($stmt->fetchColumn() == 0 && $target_tenant_id != $tenant_id) {
                http_response_code(403);
                echo json_encode(['error' => 'Non hai accesso a questo tenant']);
                return;
            }
        }

        // Verifica unicità
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

        // Inserisci cartella
        $stmt = $pdo->prepare("
            INSERT INTO folders (name, parent_id, tenant_id, created_at, updated_at)
            VALUES (:name, NULL, :tenant_id, NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => $folder_name,
            ':tenant_id' => $target_tenant_id
        ]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_root_folder', 'folders', $folder_id, [
            'name' => $folder_name,
            'tenant_id' => $target_tenant_id
        ]);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Cartella root creata con successo',
            'folder_id' => $folder_id
        ]);

    } catch (Exception $e) {
        error_log('CreateRootFolder Error: ' . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nella creazione della cartella root',
            'details' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Crea una sotto-cartella
 */
function createFolder() {
    global $pdo, $user_id, $tenant_id, $user_role;

    // Leggi input JSON
    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF validation
    $csrf_token = $input['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    $folder_name = trim($input['name'] ?? '');
    $parent_id = $input['parent_id'] ?? null;

    if (empty($folder_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome cartella richiesto']);
        return;
    }

    if (empty($parent_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cartella padre richiesta']);
        return;
    }

    try {
        // Verifica cartella padre
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

        // Verifica accesso
        if (!hasAccessToTenant($parent['tenant_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Non hai accesso a questo tenant']);
            return;
        }

        // Verifica unicità
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

        // Inserisci cartella
        $stmt = $pdo->prepare("
            INSERT INTO folders (name, parent_id, tenant_id, created_at, updated_at)
            VALUES (:name, :parent_id, :tenant_id, NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => $folder_name,
            ':parent_id' => $parent_id,
            ':tenant_id' => $parent['tenant_id']
        ]);

        $folder_id = $pdo->lastInsertId();

        // Log audit
        logAudit('create_folder', 'folders', $folder_id, [
            'name' => $folder_name,
            'parent_id' => $parent_id
        ]);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Cartella creata con successo',
            'folder_id' => $folder_id
        ]);

    } catch (Exception $e) {
        error_log('CreateFolder Error: ' . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nella creazione della cartella',
            'details' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    }
}

/**
 * Altri metodi stub per ora
 */
function uploadFile() {
    ob_clean();
    echo json_encode(['error' => 'Upload non ancora implementato']);
}

function deleteItem() {
    ob_clean();
    echo json_encode(['error' => 'Eliminazione non ancora implementata']);
}

function renameItem() {
    ob_clean();
    echo json_encode(['error' => 'Rinomina non ancora implementata']);
}

function getTenantList() {
    global $pdo, $user_id, $user_role, $tenant_id;

    if (!in_array($user_role, ['admin', 'super_admin'])) {
        ob_clean();
        http_response_code(403);
        echo json_encode(['error' => 'Non autorizzato']);
        return;
    }

    try {
        if ($user_role === 'super_admin') {
            // Super Admin vede tutti i tenant
            $stmt = $pdo->prepare("
                SELECT id, name,
                       CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
                       status
                FROM tenants
                WHERE status != 'suspended'
                ORDER BY name
            ");
            $stmt->execute();
        } else {
            // Admin vede solo i tenant a cui ha accesso esplicito + il proprio tenant corrente
            // Usando UNION per evitare problemi con LEFT JOIN e WHERE
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.id, t.name,
                       CASE WHEN t.status = 'active' THEN '1' ELSE '0' END as is_active,
                       t.status
                FROM tenants t
                WHERE t.id IN (
                    SELECT tenant_id FROM user_tenant_access WHERE user_id = :user_id
                    UNION
                    SELECT :tenant_id
                )
                AND t.status != 'suspended'
                ORDER BY t.name
            ");
            $stmt->execute([':user_id' => $user_id, ':tenant_id' => $tenant_id]);
        }

        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $tenants
        ]);

    } catch (PDOException $e) {
        $errorMsg = 'GetTenantList SQL Error: ' . $e->getMessage();
        if (isset($stmt)) {
            $errorMsg .= ' - Query: ' . $stmt->queryString;
        }
        error_log($errorMsg);
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'error' => 'Errore nel caricamento dei tenant',
            'details' => DEBUG_MODE ? $e->getMessage() : null
        ]);
    } catch (Exception $e) {
        error_log('GetTenantList Error: ' . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Errore nel caricamento dei tenant']);
    }
}

function downloadFile() {
    ob_clean();
    echo json_encode(['error' => 'Download non ancora implementato']);
}

function getFolderPath() {
    global $pdo;

    $folder_id = $_GET['folder_id'] ?? null;

    if (empty($folder_id)) {
        ob_clean();
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }

    $breadcrumb = getBreadcrumb($folder_id);

    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $breadcrumb
    ]);
}

/**
 * Funzioni di supporto
 */
function hasAccessToTenant($check_tenant_id) {
    global $user_id, $tenant_id, $user_role, $pdo;

    if ($user_role === 'super_admin') {
        return true;
    }

    if ($check_tenant_id == $tenant_id) {
        return true;
    }

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

function getBreadcrumb($folder_id) {
    global $pdo;

    $breadcrumb = [];
    $current_id = $folder_id;
    $max_depth = 10; // Previeni loop infiniti

    while ($current_id && $max_depth > 0) {
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
        $max_depth--;
    }

    return $breadcrumb;
}

function logAudit($action, $entity_type, $entity_id, $details) {
    global $pdo, $user_id, $tenant_id;

    try {
        // Verifica se la tabella audit_logs esiste
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
        if ($stmt->rowCount() == 0) {
            return; // Tabella non esiste, skip logging
        }

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