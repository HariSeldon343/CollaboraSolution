<?php
// IMPORTANTE: Non chiamare session_start() qui - viene gestito da session_init.php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Gestione errori per debug
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Includi configurazione e inizializzazione sessione
require_once dirname(__DIR__) . '/includes/session_init.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

// Funzione helper per verifica CSRF (versione semplificata)
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Controllo autenticazione semplificato
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Non autorizzato', 'session' => $_SESSION]));
}

// Tenant isolation
$tenant_id = $_SESSION['tenant_id'] ?? null;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Validazione tenant_id
if (!$tenant_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Tenant non specificato']));
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$file_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Initialize database connection
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Errore di connessione al database', 'details' => $e->getMessage()]));
}

try {
    switch ($method) {
        case 'GET':
            if ($file_id) {
                // Get single file details
                getFileDetails($pdo, $file_id, $tenant_id, $user_id);
            } else {
                // List files
                listFiles($pdo, $tenant_id, $user_id, $_GET);
            }
            break;

        case 'POST':
            // Verify CSRF token for state-changing operations (temporaneamente disabilitato per test)
            $input = json_decode(file_get_contents('php://input'), true);
            // TODO: Riabilitare verifica CSRF in produzione
            // $csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
            // if (!verifyCSRFToken($csrf_token)) {
            //     http_response_code(403);
            //     die(json_encode(['error' => 'Token CSRF non valido']));
            // }

            if ($action === 'upload') {
                uploadFile($pdo, $tenant_id, $user_id);
            } elseif ($action === 'create_folder') {
                createFolder($pdo, $tenant_id, $user_id, $input);
            } else {
                http_response_code(400);
                die(json_encode(['error' => 'Azione non valida']));
            }
            break;

        case 'PUT':
            // Update file/folder (rename, move, etc.)
            $input = json_decode(file_get_contents('php://input'), true);
            // TODO: Riabilitare verifica CSRF in produzione
            // $csrf_token = $input['csrf_token'] ?? '';
            // if (!verifyCSRFToken($csrf_token)) {
            //     http_response_code(403);
            //     die(json_encode(['error' => 'Token CSRF non valido']));
            // }

            if (!$file_id) {
                http_response_code(400);
                die(json_encode(['error' => 'ID file richiesto']));
            }

            updateFile($pdo, $file_id, $tenant_id, $user_id, $input);
            break;

        case 'DELETE':
            // Soft delete file
            $input = json_decode(file_get_contents('php://input'), true);
            // TODO: Riabilitare verifica CSRF in produzione
            // $csrf_token = $input['csrf_token'] ?? '';
            // if (!verifyCSRFToken($csrf_token)) {
            //     http_response_code(403);
            //     die(json_encode(['error' => 'Token CSRF non valido']));
            // }

            if (!$file_id) {
                http_response_code(400);
                die(json_encode(['error' => 'ID file richiesto']));
            }

            deleteFile($pdo, $file_id, $tenant_id, $user_id);
            break;

        default:
            http_response_code(405);
            die(json_encode(['error' => 'Metodo non permesso']));
    }

} catch (Exception $e) {
    error_log('API Files Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Errore del server', 'details' => DEBUG_MODE ? $e->getMessage() : null]));
}

/**
 * Lista i file con tenant isolation
 */
function listFiles($pdo, $tenant_id, $user_id, $params) {
    // Gestisce folder_id vuoto come null
    $folder_id = null;
    if (isset($params['folder_id']) && $params['folder_id'] !== '') {
        $folder_id = (int)$params['folder_id'];
    }
    $search = $params['search'] ?? '';
    $type_filter = $params['type'] ?? '';
    $sort_by = $params['sort'] ?? 'name';
    $sort_order = $params['order'] ?? 'ASC';
    $page = isset($params['page']) ? (int)$params['page'] : 1;
    $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
    $offset = ($page - 1) * $limit;

    $results = [];
    $total_items = 0;

    // Prima recupera le cartelle
    $folders_sql = "SELECT
            fo.id,
            fo.name,
            fo.path,
            'folder' as type,
            1 as is_folder,
            0 as size,
            NULL as mime_type,
            fo.owner_id,
            u.id as user_id,
            u.name as owner_name,
            u.email as owner_email,
            fo.created_at,
            fo.updated_at,
            (SELECT COUNT(*) FROM files WHERE folder_id = fo.id AND deleted_at IS NULL) as file_count,
            (SELECT COUNT(*) FROM folders WHERE parent_id = fo.id AND deleted_at IS NULL) as folder_count
        FROM folders fo
        LEFT JOIN users u ON fo.owner_id = u.id
        WHERE fo.tenant_id = :tenant_id
        AND fo.deleted_at IS NULL";

    $folders_params = [':tenant_id' => $tenant_id];

    // Filtro cartella
    if ($folder_id !== null) {
        $folders_sql .= " AND fo.parent_id = :folder_id";
        $folders_params[':folder_id'] = $folder_id;
    } else {
        $folders_sql .= " AND fo.parent_id IS NULL";
    }

    // Ricerca nelle cartelle
    if (!empty($search)) {
        $folders_sql .= " AND fo.name LIKE :search";
        $folders_params[':search'] = '%' . $search . '%';
    }

    // Filtro tipo (se cerchiamo solo file, skippiamo le cartelle)
    if ($type_filter !== 'file') {
        $stmt = $pdo->prepare($folders_sql);
        $stmt->execute($folders_params);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($folders as $folder) {
            $results[] = [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'path' => $folder['path'],
                'type' => 'folder',
                'is_folder' => true,
                'size' => 0,
                'mime_type' => null,
                'item_count' => (int)$folder['file_count'] + (int)$folder['folder_count'],
                'uploaded_by' => [
                    'id' => $folder['owner_id'],
                    'name' => $folder['owner_name'],
                    'email' => $folder['owner_email']
                ],
                'created_at' => $folder['created_at'],
                'updated_at' => $folder['updated_at']
            ];
        }
        $total_items += count($folders);
    }

    // Poi recupera i file (se non stiamo filtrando solo per cartelle)
    if ($type_filter !== 'folder') {
        $files_sql = "SELECT
                f.id,
                f.name,
                f.file_path as path,
                f.mime_type,
                f.is_folder,
                f.file_size as size,
                f.uploaded_by as owner_id,
                u.id as user_id,
                u.name as owner_name,
                u.email as owner_email,
                f.created_at,
                f.updated_at
            FROM files f
            LEFT JOIN users u ON f.uploaded_by = u.id
            WHERE f.tenant_id = :tenant_id
            AND f.deleted_at IS NULL
            AND (f.is_folder = 0 OR f.is_folder IS NULL)";

        $files_params = [':tenant_id' => $tenant_id];

        // Filtro cartella per i file
        if ($folder_id !== null) {
            $files_sql .= " AND f.folder_id = :folder_id";
            $files_params[':folder_id'] = $folder_id;
        } else {
            $files_sql .= " AND f.folder_id IS NULL";
        }

        // Ricerca nei file
        if (!empty($search)) {
            $files_sql .= " AND f.name LIKE :search";
            $files_params[':search'] = '%' . $search . '%';
        }

        // Filtro tipo specifico di file
        if (!empty($type_filter) && $type_filter !== 'file' && $type_filter !== 'folder') {
            // Assumiamo che type_filter sia un'estensione o mime_type
            $files_sql .= " AND (f.mime_type LIKE :file_type OR f.name LIKE :ext)";
            $files_params[':file_type'] = '%' . $type_filter . '%';
            $files_params[':ext'] = '%.' . $type_filter;
        }

        $stmt = $pdo->prepare($files_sql);
        $stmt->execute($files_params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $file) {
            // Estrai l'estensione del file
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

            $results[] = [
                'id' => $file['id'],
                'name' => $file['name'],
                'path' => $file['path'],
                'type' => strtolower($ext) ?: 'file',
                'is_folder' => false,
                'size' => (int)$file['size'],
                'mime_type' => $file['mime_type'],
                'item_count' => null,
                'uploaded_by' => [
                    'id' => $file['owner_id'],
                    'name' => $file['owner_name'],
                    'email' => $file['owner_email']
                ],
                'created_at' => $file['created_at'],
                'updated_at' => $file['updated_at']
            ];
        }
        $total_items += count($files);
    }

    // Ordinamento dei risultati combinati
    usort($results, function($a, $b) use ($sort_by, $sort_order) {
        // Cartelle sempre prima dei file
        if ($a['is_folder'] !== $b['is_folder']) {
            return $b['is_folder'] - $a['is_folder'];
        }

        // Poi ordina per campo richiesto
        $field_value_a = match($sort_by) {
            'size' => $a['size'],
            'modified' => $a['updated_at'],
            'created' => $a['created_at'],
            default => $a['name']
        };

        $field_value_b = match($sort_by) {
            'size' => $b['size'],
            'modified' => $b['updated_at'],
            'created' => $b['created_at'],
            default => $b['name']
        };

        $comparison = $field_value_a <=> $field_value_b;
        return strtoupper($sort_order) === 'DESC' ? -$comparison : $comparison;
    });

    // Applica paginazione
    $paginated_results = array_slice($results, $offset, $limit);

    echo json_encode([
        'success' => true,
        'data' => $paginated_results,
        'pagination' => [
            'total' => $total_items,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total_items / $limit)
        ]
    ]);
}

/**
 * Ottieni dettagli di un singolo file
 */
function getFileDetails($pdo, $file_id, $tenant_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT f.*,
               u.id as user_id,
               u.name as owner_name,
               u.email as owner_email
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.id = :file_id
        AND f.tenant_id = :tenant_id
        AND f.deleted_at IS NULL
    ");

    $stmt->execute([
        ':file_id' => $file_id,
        ':tenant_id' => $tenant_id
    ]);

    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        die(json_encode(['error' => 'File non trovato']));
    }

    // Log attività
    logFileActivity($pdo, $file_id, $user_id, $tenant_id, 'view');

    // Estrai l'estensione del file
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $file['id'],
            'name' => $file['name'],
            'path' => $file['file_path'],
            'type' => strtolower($ext) ?: 'file',
            'is_folder' => (bool)$file['is_folder'],
            'size' => (int)$file['file_size'],
            'mime_type' => $file['mime_type'],
            'uploaded_by' => [
                'id' => $file['uploaded_by'],
                'name' => $file['owner_name'],
                'email' => $file['owner_email']
            ],
            'created_at' => $file['created_at'],
            'updated_at' => $file['updated_at']
        ]
    ]);
}

/**
 * Upload di un file
 */
function uploadFile($pdo, $tenant_id, $user_id) {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Nessun file caricato']));
    }

    $file = $_FILES['file'];
    $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;

    // Validazione file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        die(json_encode(['error' => 'Errore durante il caricamento del file']));
    }

    // Controllo dimensione
    $max_size = 104857600; // 100MB
    if ($file['size'] > $max_size) {
        http_response_code(400);
        die(json_encode(['error' => 'File troppo grande. Massimo 100MB']));
    }

    // Estrai informazioni file
    $original_name = $file['name'];
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $mime_type = $file['type'] ?: mime_content_type($file['tmp_name']);
    $file_size = $file['size'];

    // Genera nome univoco e hash per deduplicazione
    $file_hash = sha1_file($file['tmp_name']);
    $unique_name = $file_hash . '_' . time() . '.' . $file_ext;

    // Crea percorso di upload usando struttura semplice per tenant
    $upload_dir = '../uploads/tenant_' . $tenant_id;
    $relative_path = 'uploads/tenant_' . $tenant_id . '/' . $unique_name;

    // Crea directory se non esiste
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $upload_path = $upload_dir . '/' . $unique_name;

    // Sposta il file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        http_response_code(500);
        die(json_encode(['error' => 'Impossibile salvare il file']));
    }

    try {
        // Inserisci record nel database con la struttura corretta
        $stmt = $pdo->prepare("
            INSERT INTO files (
                tenant_id, folder_id, name, original_name,
                mime_type, file_size, file_path, file_type,
                uploaded_by, is_folder, created_at, updated_at
            ) VALUES (
                :tenant_id, :folder_id, :name, :original_name,
                :mime_type, :file_size, :file_path, :file_type,
                :uploaded_by, 0, NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':folder_id' => $folder_id,
            ':name' => $original_name,
            ':original_name' => $original_name,
            ':mime_type' => $mime_type,
            ':file_size' => $file_size,
            ':file_path' => $relative_path,
            ':file_type' => $file_ext,
            ':uploaded_by' => $user_id
        ]);

        $file_id = $pdo->lastInsertId();

        // Log attività
        logFileActivity($pdo, $file_id, $user_id, $tenant_id, 'upload');

        echo json_encode([
            'success' => true,
            'message' => 'File caricato con successo',
            'data' => [
                'id' => $file_id,
                'name' => $original_name,
                'path' => $relative_path,
                'size' => $file_size
            ]
        ]);

    } catch (Exception $e) {
        // Rimuovi il file in caso di errore DB
        @unlink($upload_path);
        throw $e;
    }
}

/**
 * Crea una nuova cartella
 */
function createFolder($pdo, $tenant_id, $user_id, $input) {
    $folder_name = trim($input['name'] ?? '');
    $parent_folder_id = isset($input['parent_id']) ? (int)$input['parent_id'] : null;
    $description = $input['description'] ?? '';

    if (empty($folder_name)) {
        http_response_code(400);
        die(json_encode(['error' => 'Nome cartella richiesto']));
    }

    // Sanitizza nome cartella
    $folder_name = preg_replace('/[^a-zA-Z0-9\s\-_()]/', '', $folder_name);
    $folder_name = substr($folder_name, 0, 255);

    // Costruisci il percorso della cartella
    $folder_path = '/';
    if ($parent_folder_id) {
        // Recupera il percorso della cartella parent
        $stmt = $pdo->prepare("
            SELECT path FROM folders
            WHERE id = :parent_id AND tenant_id = :tenant_id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':parent_id' => $parent_folder_id,
            ':tenant_id' => $tenant_id
        ]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            http_response_code(404);
            die(json_encode(['error' => 'Cartella parent non trovata']));
        }

        $folder_path = rtrim($parent['path'], '/') . '/' . $folder_name;
    } else {
        $folder_path = '/' . $folder_name;
    }

    // Verifica unicità nome nella stessa directory
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM folders
        WHERE tenant_id = :tenant_id
        AND name = :name
        AND deleted_at IS NULL
        AND " . ($parent_folder_id ? "parent_id = :parent_id" : "parent_id IS NULL")
    );

    $params = [
        ':tenant_id' => $tenant_id,
        ':name' => $folder_name
    ];
    if ($parent_folder_id) {
        $params[':parent_id'] = $parent_folder_id;
    }

    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        http_response_code(400);
        die(json_encode(['error' => 'Una cartella con questo nome esiste già']));
    }

    // Inserisci nel database nella tabella folders
    $stmt = $pdo->prepare("
        INSERT INTO folders (
            tenant_id, name, path, parent_id,
            owner_id, created_at, updated_at
        ) VALUES (
            :tenant_id, :name, :path, :parent_id,
            :owner_id, NOW(), NOW()
        )
    ");

    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':name' => $folder_name,
        ':path' => $folder_path,
        ':parent_id' => $parent_folder_id,
        ':owner_id' => $user_id
    ]);

    $folder_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Cartella creata con successo',
        'data' => [
            'id' => $folder_id,
            'name' => $folder_name,
            'path' => $folder_path
        ]
    ]);
}

/**
 * Aggiorna un file/cartella (rinomina, sposta, ecc.)
 */
function updateFile($pdo, $file_id, $tenant_id, $user_id, $input) {
    // Determina se stiamo aggiornando un file o una cartella
    $is_folder = isset($input['is_folder']) ? (bool)$input['is_folder'] : false;

    if ($is_folder) {
        // Aggiorna cartella
        $stmt = $pdo->prepare("
            SELECT * FROM folders
            WHERE id = :folder_id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':folder_id' => $file_id,
            ':tenant_id' => $tenant_id
        ]);

        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            http_response_code(404);
            die(json_encode(['error' => 'Cartella non trovata']));
        }

        $updates = [];
        $params = [':folder_id' => $file_id];

        // Rinomina cartella
        if (isset($input['name'])) {
            $new_name = trim($input['name']);
            if (!empty($new_name)) {
                $updates[] = "name = :name";
                $params[':name'] = $new_name;

                // Aggiorna anche il path
                if ($folder['parent_id']) {
                    $stmt = $pdo->prepare("SELECT path FROM folders WHERE id = :parent_id");
                    $stmt->execute([':parent_id' => $folder['parent_id']]);
                    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
                    $new_path = rtrim($parent['path'], '/') . '/' . $new_name;
                } else {
                    $new_path = '/' . $new_name;
                }
                $updates[] = "path = :path";
                $params[':path'] = $new_path;
            }
        }

        // Sposta in altra cartella
        if (array_key_exists('parent_id', $input)) {
            $new_parent_id = $input['parent_id'] ? (int)$input['parent_id'] : null;

            if ($new_parent_id) {
                $stmt = $pdo->prepare("
                    SELECT id, path FROM folders
                    WHERE id = :parent_id
                    AND tenant_id = :tenant_id
                    AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':parent_id' => $new_parent_id,
                    ':tenant_id' => $tenant_id
                ]);

                $new_parent = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$new_parent) {
                    http_response_code(400);
                    die(json_encode(['error' => 'Cartella di destinazione non valida']));
                }
            }

            $updates[] = "parent_id = :parent_id";
            $params[':parent_id'] = $new_parent_id;
        }

        if (empty($updates)) {
            http_response_code(400);
            die(json_encode(['error' => 'Nessuna modifica richiesta']));
        }

        $sql = "UPDATE folders SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :folder_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

    } else {
        // Aggiorna file
        $stmt = $pdo->prepare("
            SELECT * FROM files
            WHERE id = :file_id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':file_id' => $file_id,
            ':tenant_id' => $tenant_id
        ]);

        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            die(json_encode(['error' => 'File non trovato']));
        }

        $updates = [];
        $params = [':file_id' => $file_id];

        // Rinomina
        if (isset($input['name'])) {
            $new_name = trim($input['name']);
            if (!empty($new_name)) {
                $updates[] = "name = :name";
                $params[':name'] = $new_name;
            }
        }

        // Sposta in altra cartella
        if (array_key_exists('folder_id', $input)) {
            $new_folder_id = $input['folder_id'] ? (int)$input['folder_id'] : null;

            if ($new_folder_id) {
                $stmt = $pdo->prepare("
                    SELECT id FROM folders
                    WHERE id = :folder_id
                    AND tenant_id = :tenant_id
                    AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':folder_id' => $new_folder_id,
                    ':tenant_id' => $tenant_id
                ]);

                if (!$stmt->fetch()) {
                    http_response_code(400);
                    die(json_encode(['error' => 'Cartella di destinazione non valida']));
                }
            }

            $updates[] = "folder_id = :folder_id";
            $params[':folder_id'] = $new_folder_id;
        }

        if (empty($updates)) {
            http_response_code(400);
            die(json_encode(['error' => 'Nessuna modifica richiesta']));
        }

        $sql = "UPDATE files SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :file_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Log attività
    $action = isset($input['name']) ? 'rename' : 'move';
    logFileActivity($pdo, $file_id, $user_id, $tenant_id, $action, json_encode($input));

    echo json_encode([
        'success' => true,
        'message' => $is_folder ? 'Cartella aggiornata con successo' : 'File aggiornato con successo'
    ]);
}

/**
 * Elimina un file o cartella (soft delete)
 */
function deleteFile($pdo, $file_id, $tenant_id, $user_id) {
    // Prova prima come cartella
    $stmt = $pdo->prepare("
        SELECT *, 'folder' as type FROM folders
        WHERE id = :id
        AND tenant_id = :tenant_id
        AND deleted_at IS NULL
    ");

    $stmt->execute([
        ':id' => $file_id,
        ':tenant_id' => $tenant_id
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_folder = false;

    if ($item) {
        $is_folder = true;
    } else {
        // Se non è una cartella, cerca nei file
        $stmt = $pdo->prepare("
            SELECT *, 'file' as type FROM files
            WHERE id = :id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':id' => $file_id,
            ':tenant_id' => $tenant_id
        ]);

        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$item) {
        http_response_code(404);
        die(json_encode(['error' => 'Elemento non trovato']));
    }

    // Verifica permessi (solo chi ha creato l'elemento o admin può eliminarlo)
    $owner_field = $is_folder ? 'owner_id' : 'uploaded_by';
    if ($item[$owner_field] != $user_id && !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
        http_response_code(403);
        die(json_encode(['error' => 'Non hai i permessi per eliminare questo elemento']));
    }

    // Se è una cartella, verifica che sia vuota
    if ($is_folder) {
        // Controlla file nella cartella
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM files
            WHERE folder_id = :folder_id
            AND deleted_at IS NULL
        ");
        $stmt->execute([':folder_id' => $file_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $file_count = $result['count'];

        // Controlla sottocartelle
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM folders
            WHERE parent_id = :parent_id
            AND deleted_at IS NULL
        ");
        $stmt->execute([':parent_id' => $file_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $folder_count = $result['count'];

        if ($file_count > 0 || $folder_count > 0) {
            http_response_code(400);
            die(json_encode(['error' => 'La cartella non è vuota']));
        }

        // Soft delete della cartella
        $stmt = $pdo->prepare("
            UPDATE folders
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $file_id]);

    } else {
        // Soft delete del file
        $stmt = $pdo->prepare("
            UPDATE files
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $file_id]);
    }

    // Log attività
    logFileActivity($pdo, $file_id, $user_id, $tenant_id, 'delete');

    echo json_encode([
        'success' => true,
        'message' => $is_folder ? 'Cartella eliminata con successo' : 'File eliminato con successo'
    ]);
}

/**
 * Log delle attività sui file
 */
function logFileActivity($pdo, $file_id, $user_id, $tenant_id, $action, $details = null) {
    try {
        // Verifica se la tabella esiste
        $stmt = $pdo->query("SHOW TABLES LIKE 'file_activity_logs'");
        if (!$stmt->fetch()) {
            return; // La tabella non esiste ancora, skip logging
        }

        $stmt = $pdo->prepare("
            INSERT INTO file_activity_logs (
                file_id, user_id, tenant_id, action,
                details, ip_address, user_agent, created_at
            ) VALUES (
                :file_id, :user_id, :tenant_id, :action,
                :details, :ip_address, :user_agent, NOW()
            )
        ");

        $stmt->execute([
            ':file_id' => $file_id,
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id,
            ':action' => $action,
            ':details' => $details,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Non bloccare l'operazione se il logging fallisce
        error_log('File activity logging failed: ' . $e->getMessage());
    }
}