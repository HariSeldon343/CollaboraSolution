<?php
// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Include required files
    require_once '../config.php';
    require_once '../includes/db.php';

    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Get current user details
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $currentTenantId = $_SESSION['tenant_id'] ?? null;
    $isSuperAdmin = ($userRole === 'super_admin');
    $isAdmin = in_array($userRole, ['admin', 'super_admin']);

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Get request details
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    // CSRF validation for state-changing operations
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($csrfToken)) {
            $csrfToken = $input['csrf_token'] ?? '';
        }

        if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
            ob_clean();
            http_response_code(403);
            die(json_encode(['error' => 'Token CSRF non valido']));
        }
    }

    // Route based on action
    switch ($action) {
        case 'list-folders':
            listFolders($conn, $currentUserId, $userRole, $currentTenantId);
            break;

        case 'create-folder':
            createFolder($conn, $currentUserId, $userRole, $currentTenantId);
            break;

        case 'list-files':
            listFiles($conn, $currentUserId, $userRole, $currentTenantId);
            break;

        case 'upload':
            uploadFile($conn, $currentUserId, $userRole, $currentTenantId);
            break;

        default:
            ob_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Azione non valida']);
            exit;
    }

} catch (PDOException $e) {
    error_log('Files API PDO Error: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    error_log('Files API Error: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

/**
 * List folders accessible to the user
 */
function listFolders($conn, $userId, $userRole, $tenantId) {
    try {
        $parentId = $_GET['parent_id'] ?? null;

        // Check if stored procedure exists
        $checkProc = $conn->prepare("SELECT COUNT(*) as count FROM information_schema.ROUTINES
                                     WHERE ROUTINE_SCHEMA = 'collaboranexio'
                                     AND ROUTINE_NAME = 'GetAccessibleFolders'");
        $checkProc->execute();
        $procExists = $checkProc->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        if ($procExists) {
            // Use stored procedure
            $stmt = $conn->prepare("CALL GetAccessibleFolders(:user_id)");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Manual query based on role
            if ($userRole === 'super_admin') {
                // Super Admin sees all folders including NULL tenant folders
                $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name
                         FROM folders f
                         LEFT JOIN users u ON f.owner_id = u.id
                         LEFT JOIN tenants t ON f.tenant_id = t.id
                         WHERE f.deleted_at IS NULL";

                if ($parentId !== null) {
                    $query .= " AND f.parent_id = :parent_id";
                } else {
                    $query .= " AND f.parent_id IS NULL";
                }

                $query .= " ORDER BY f.tenant_id IS NULL DESC, f.name ASC";

                $stmt = $conn->prepare($query);
                if ($parentId !== null) {
                    $stmt->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
                }

            } elseif ($userRole === 'admin') {
                // Admin sees folders from their tenants and NULL tenant folders
                $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name
                         FROM folders f
                         LEFT JOIN users u ON f.owner_id = u.id
                         LEFT JOIN tenants t ON f.tenant_id = t.id
                         WHERE f.deleted_at IS NULL
                         AND (
                             f.tenant_id IS NULL
                             OR f.tenant_id = :tenant_id
                             OR f.tenant_id IN (
                                 SELECT tenant_id FROM user_tenant_access WHERE user_id = :user_id
                             )
                         )";

                if ($parentId !== null) {
                    $query .= " AND f.parent_id = :parent_id";
                } else {
                    $query .= " AND f.parent_id IS NULL";
                }

                $query .= " ORDER BY f.tenant_id IS NULL DESC, f.name ASC";

                $stmt = $conn->prepare($query);
                $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                if ($parentId !== null) {
                    $stmt->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
                }

            } else {
                // Regular users see only their tenant folders
                if (empty($tenantId)) {
                    ob_clean();
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }

                $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name
                         FROM folders f
                         LEFT JOIN users u ON f.owner_id = u.id
                         LEFT JOIN tenants t ON f.tenant_id = t.id
                         WHERE f.tenant_id = :tenant_id
                         AND f.deleted_at IS NULL";

                if ($parentId !== null) {
                    $query .= " AND f.parent_id = :parent_id";
                } else {
                    $query .= " AND f.parent_id IS NULL";
                }

                $query .= " ORDER BY f.name ASC";

                $stmt = $conn->prepare($query);
                $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
                if ($parentId !== null) {
                    $stmt->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
                }
            }

            $stmt->execute();
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Add metadata to folders
        foreach ($folders as &$folder) {
            $folder['is_super_admin_folder'] = empty($folder['tenant_id']);
            $folder['is_orphaned'] = !empty($folder['original_tenant_id']);
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $folders
        ]);
        exit;

    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Create a new folder
 */
function createFolder($conn, $userId, $userRole, $tenantId) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $name = trim($input['name'] ?? '');
        $parentId = $input['parent_id'] ?? null;
        $targetTenantId = $input['tenant_id'] ?? $tenantId;
        $isPublic = $input['is_public'] ?? false;

        // Validation
        if (empty($name)) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Nome cartella richiesto']);
            exit;
        }

        // Check permissions for creating folder without tenant
        if (empty($targetTenantId)) {
            if (!in_array($userRole, ['super_admin', 'admin'])) {
                ob_clean();
                http_response_code(403);
                echo json_encode(['error' => 'Solo Admin e Super Admin possono creare cartelle globali']);
                exit;
            }
            $targetTenantId = null; // Explicitly set to NULL for database
        }

        // Check if parent folder exists and user has access
        if ($parentId) {
            $checkParent = $conn->prepare("SELECT tenant_id, path FROM folders WHERE id = :id AND deleted_at IS NULL");
            $checkParent->bindParam(':id', $parentId, PDO::PARAM_INT);
            $checkParent->execute();
            $parent = $checkParent->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                ob_clean();
                http_response_code(404);
                echo json_encode(['error' => 'Cartella parent non trovata']);
                exit;
            }

            // Check access to parent folder
            if ($userRole !== 'super_admin') {
                if ($parent['tenant_id'] != $tenantId && !empty($parent['tenant_id'])) {
                    ob_clean();
                    http_response_code(403);
                    echo json_encode(['error' => 'Accesso negato alla cartella parent']);
                    exit;
                }
            }

            $path = $parent['path'] . '/' . $name;
        } else {
            $path = '/' . $name;
        }

        // Check if folder already exists
        $checkExisting = $conn->prepare("SELECT id FROM folders WHERE name = :name AND parent_id " .
                                        ($parentId ? "= :parent_id" : "IS NULL") .
                                        " AND tenant_id " . ($targetTenantId ? "= :tenant_id" : "IS NULL") .
                                        " AND deleted_at IS NULL");
        $checkExisting->bindParam(':name', $name);
        if ($parentId) {
            $checkExisting->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
        }
        if ($targetTenantId) {
            $checkExisting->bindParam(':tenant_id', $targetTenantId, PDO::PARAM_INT);
        }
        $checkExisting->execute();

        if ($checkExisting->fetch()) {
            ob_clean();
            http_response_code(409);
            echo json_encode(['error' => 'Una cartella con questo nome esiste già']);
            exit;
        }

        // Create the folder
        $insertQuery = "INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, settings, created_at)
                       VALUES (:tenant_id, :parent_id, :name, :path, :owner_id, :is_public, :settings, NOW())";

        $stmt = $conn->prepare($insertQuery);

        // Bind parameters handling NULL values properly
        if ($targetTenantId === null) {
            $stmt->bindValue(':tenant_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':tenant_id', $targetTenantId, PDO::PARAM_INT);
        }

        if ($parentId === null) {
            $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
        }

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':path', $path);
        $stmt->bindParam(':owner_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':is_public', $isPublic, PDO::PARAM_BOOL);

        $settings = json_encode([
            'created_by_role' => $userRole,
            'is_global' => empty($targetTenantId)
        ]);
        $stmt->bindParam(':settings', $settings);

        $stmt->execute();
        $folderId = $conn->lastInsertId();

        // Get the created folder
        $getFolder = $conn->prepare("SELECT f.*, u.name as owner_name, t.name as tenant_name
                                     FROM folders f
                                     LEFT JOIN users u ON f.owner_id = u.id
                                     LEFT JOIN tenants t ON f.tenant_id = t.id
                                     WHERE f.id = :id");
        $getFolder->bindParam(':id', $folderId, PDO::PARAM_INT);
        $getFolder->execute();
        $folder = $getFolder->fetch(PDO::FETCH_ASSOC);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Cartella creata con successo',
            'data' => $folder
        ]);
        exit;

    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * List files in a folder
 */
function listFiles($conn, $userId, $userRole, $tenantId) {
    try {
        $folderId = $_GET['folder_id'] ?? null;

        // Build query based on user role
        if ($userRole === 'super_admin') {
            // Super Admin sees all files
            $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name, fo.name as folder_name
                     FROM files f
                     LEFT JOIN users u ON f.owner_id = u.id
                     LEFT JOIN tenants t ON f.tenant_id = t.id
                     LEFT JOIN folders fo ON f.folder_id = fo.id
                     WHERE f.deleted_at IS NULL";

            if ($folderId !== null) {
                $query .= " AND f.folder_id = :folder_id";
            }

            $query .= " ORDER BY f.tenant_id IS NULL DESC, f.name ASC";

            $stmt = $conn->prepare($query);
            if ($folderId !== null) {
                $stmt->bindParam(':folder_id', $folderId, PDO::PARAM_INT);
            }

        } elseif ($userRole === 'admin') {
            // Admin sees files from their tenants and global files
            $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name, fo.name as folder_name
                     FROM files f
                     LEFT JOIN users u ON f.owner_id = u.id
                     LEFT JOIN tenants t ON f.tenant_id = t.id
                     LEFT JOIN folders fo ON f.folder_id = fo.id
                     WHERE f.deleted_at IS NULL
                     AND (
                         f.tenant_id IS NULL
                         OR f.tenant_id = :tenant_id
                         OR f.tenant_id IN (
                             SELECT tenant_id FROM user_tenant_access WHERE user_id = :user_id
                         )
                     )";

            if ($folderId !== null) {
                $query .= " AND f.folder_id = :folder_id";
            }

            $query .= " ORDER BY f.tenant_id IS NULL DESC, f.name ASC";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            if ($folderId !== null) {
                $stmt->bindParam(':folder_id', $folderId, PDO::PARAM_INT);
            }

        } else {
            // Regular users see only their tenant files
            if (empty($tenantId)) {
                ob_clean();
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            $query = "SELECT f.*, u.name as owner_name, t.name as tenant_name, fo.name as folder_name
                     FROM files f
                     LEFT JOIN users u ON f.owner_id = u.id
                     LEFT JOIN tenants t ON f.tenant_id = t.id
                     LEFT JOIN folders fo ON f.folder_id = fo.id
                     WHERE f.tenant_id = :tenant_id
                     AND f.deleted_at IS NULL";

            if ($folderId !== null) {
                $query .= " AND f.folder_id = :folder_id";
            }

            $query .= " ORDER BY f.name ASC";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
            if ($folderId !== null) {
                $stmt->bindParam(':folder_id', $folderId, PDO::PARAM_INT);
            }
        }

        $stmt->execute();
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add metadata
        foreach ($files as &$file) {
            $file['is_super_admin_file'] = empty($file['tenant_id']);
            $file['is_orphaned'] = !empty($file['original_tenant_id']);
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $files
        ]);
        exit;

    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Upload a file
 */
function uploadFile($conn, $userId, $userRole, $tenantId) {
    try {
        // This is a placeholder for file upload functionality
        // Would need proper file handling implementation

        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Upload functionality not yet implemented'
        ]);
        exit;

    } catch (Exception $e) {
        throw $e;
    }
}
?>