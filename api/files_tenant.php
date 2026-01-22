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
require_once '../includes/tenant_folder_helper.php';
require_once '../includes/zip_approval_recipients.php';
require_once '../includes/mailer.php';

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
$csrf_required = [
    'create_root_folder',
    'create_folder',
    'upload',
    'delete',
    'rename',
    'request_folder_zip_download',
    'approve_folder_zip_request',
    'reject_folder_zip_request',
    'request_file_download',
    'approve_file_download_request',
    'reject_file_download_request',
];
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
        case 'create_document':
            createNewDocument();
            break;
        case 'get_tenant_list':
            getTenantList();
            break;
        case 'download':
            downloadFile();
            break;
        case 'request_folder_zip_download':
            requestFolderZipDownload();
            break;
        case 'approve_folder_zip_request':
            approveFolderZipRequest();
            break;
        case 'reject_folder_zip_request':
            rejectFolderZipRequest();
            break;
        case 'request_file_download':
            requestFileDownload();
            break;
        case 'approve_file_download_request':
            approveFileDownloadRequest();
            break;
        case 'reject_file_download_request':
            rejectFileDownloadRequest();
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
    global $pdo, $db, $user_id, $tenant_id, $user_role;

    $folder_id = $_GET['folder_id'] ?? null;
    $search = $_GET['search'] ?? '';
    $isRootRequest = ($folder_id === null || $folder_id === '');
    $canSeeAssignedUserName = in_array((string)$user_role, ['manager', 'admin', 'super_admin'], true);

    // Company Filter (admin/super_admin): session-driven tenant scoping
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $companyFilterIds = null; // null => all
    if (isset($_SESSION['company_filter_ids']) && is_array($_SESSION['company_filter_ids'])) {
        $clean = [];
        foreach ($_SESSION['company_filter_ids'] as $v) {
            $id = (int)$v;
            if ($id > 0) $clean[] = $id;
        }
        $clean = array_values(array_unique($clean));
        $companyFilterIds = !empty($clean) ? $clean : null;
    } elseif (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null && $_SESSION['company_filter_id'] !== '') {
        $id = (int)$_SESSION['company_filter_id'];
        $companyFilterIds = ($id > 0) ? [$id] : null;
    }

    try {
        // Feature-detect assignments table (some installs may be missing migrations)
        $hasAssignmentsTable = false;
        try {
            $row = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'file_assignments'
                 LIMIT 1"
            );
            $hasAssignmentsTable = ((int)($row['ok'] ?? 0) === 1);
        } catch (Throwable $e) {
            $hasAssignmentsTable = false;
        }

        $hasTenantRolesTable = false;
        try {
            $row = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tenant_roles'
                 LIMIT 1"
            );
            $hasTenantRolesTable = ((int)($row['ok'] ?? 0) === 1);
        } catch (Throwable $e) {
            $hasTenantRolesTable = false;
        }

        // Migration 47: compliance_file_versions (visible document snapshots)
        $hasComplianceFileVersions = false;
        try {
            $row = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'compliance_file_versions'
                 LIMIT 1"
            );
            $hasComplianceFileVersions = ((int)($row['ok'] ?? 0) === 1);
        } catch (Throwable $e) {
            $hasComplianceFileVersions = false;
        }
        $canSeeComplianceVersions = in_array((string)$user_role, ['manager', 'super_admin'], true);

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
                (SELECT COUNT(*) FROM files fil WHERE fil.folder_id = f.id AND fil.is_folder = 0 AND fil.deleted_at IS NULL) as file_count" .
                (
                    ($hasComplianceFileVersions && $canSeeComplianceVersions)
                        ? ",
                cv.file_id AS version_of_file_id"
                        : ""
                ) .
                (
                    $hasAssignmentsTable
                        ? ",
                (SELECT fa.id
                 FROM file_assignments fa
                 WHERE fa.tenant_id = f.tenant_id
                   AND fa.file_id = f.id
                   AND (fa.deleted_at IS NULL OR fa.deleted_at = '')
                   AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
                 ORDER BY fa.created_at DESC
                 LIMIT 1) AS direct_assignment_id,
                (CASE
                    WHEN f.is_folder = 0 AND f.folder_id IS NOT NULL THEN (
                        SELECT fa2.id
                        FROM file_assignments fa2
                        WHERE fa2.tenant_id = f.tenant_id
                          AND fa2.file_id = f.folder_id
                          AND (fa2.deleted_at IS NULL OR fa2.deleted_at = '')
                          AND (fa2.expires_at IS NULL OR fa2.expires_at > NOW())
                        ORDER BY fa2.created_at DESC
                        LIMIT 1
                    )
                    ELSE NULL
                 END) AS inherited_assignment_id"
                        : ""
                ) . "
            FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            " . (
                ($hasComplianceFileVersions && $canSeeComplianceVersions)
                    ? "LEFT JOIN compliance_file_versions cv ON cv.tenant_id = f.tenant_id AND cv.version_file_id = f.id\n"
                    : ""
            ) . "
            WHERE f.deleted_at IS NULL
            " . (
                ($hasComplianceFileVersions && !$canSeeComplianceVersions)
                    ? " AND NOT EXISTS (SELECT 1 FROM compliance_file_versions cv2 WHERE cv2.tenant_id = f.tenant_id AND cv2.version_file_id = f.id)\n"
                    : ""
            ) . "
        ";

        $params = [];

        // Auto-repair legacy root folders: folder_id=0 should be NULL for root-level items.
        // We only apply this during root browse and only for folders (is_folder=1) not deleted.
        // This is idempotent and prevents “root appears empty” for normal users.
        try {
            if ($isRootRequest) {
                // Determine tenant scope for the repair (match the same scoping rules below)
                $repairTenantIds = [];

                if ($user_role === 'super_admin') {
                    if (is_array($companyFilterIds) && !empty($companyFilterIds)) {
                        $repairTenantIds = array_values(array_unique(array_map('intval', $companyFilterIds)));
                    } else {
                        // super_admin without filter: avoid scanning all tenants (keep minimal)
                        $repairTenantIds = [];
                    }
                } elseif ($user_role === 'admin') {
                    $tenant_access_query = "
                        SELECT tenant_id
                        FROM user_tenant_access
                        WHERE user_id = ?
                    ";
                    $stmt = $pdo->prepare($tenant_access_query);
                    $stmt->execute([$user_id]);
                    $accessible_tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (empty($accessible_tenants)) {
                        $accessible_tenants = [$tenant_id];
                    }
                    $repairTenantIds = array_values(array_unique(array_map('intval', $accessible_tenants)));

                    if (is_array($companyFilterIds) && !empty($companyFilterIds)) {
                        $repairTenantIds = array_values(array_intersect($repairTenantIds, array_map('intval', $companyFilterIds)));
                    }
                } else {
                    $repairTenantIds = [(int)$tenant_id];
                }

                if (!empty($repairTenantIds)) {
                    $ph = implode(',', array_fill(0, count($repairTenantIds), '?'));
                    $sql = "UPDATE files
                            SET folder_id = NULL, updated_at = NOW()
                            WHERE (folder_id = 0)
                              AND is_folder = 1
                              AND deleted_at IS NULL
                              AND tenant_id IN ($ph)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($repairTenantIds);
                }
            }
        } catch (Throwable $e) {
            // Non-blocking: log and continue
            error_log('[files_tenant:listFiles] root folder auto-repair failed: ' . $e->getMessage());
        }

        // Filtro per cartella
        // NOTE: alcune installazioni legacy salvano la root folder con folder_id=0 (invece di NULL).
        // Per compatibilità, trattiamo folder_id=0 come root.
        if ($folder_id !== null && $folder_id !== '') {
            $query .= " AND f.folder_id = ?";
            $params[] = $folder_id;
        } else {
            // Root level - items senza parent (NULL) oppure legacy (0)
            $query .= " AND (f.folder_id IS NULL OR f.folder_id = 0)";
        }

        // Filtro per tenant basato sul ruolo
        if ($user_role === 'super_admin') {
            // Super Admin: optional Company Filter scoping
            if (is_array($companyFilterIds) && !empty($companyFilterIds)) {
                // Lazy backfill: ensure selected tenants have a root folder (when browsing root)
                if ($isRootRequest) {
                    $ids = array_values(array_unique(array_map('intval', $companyFilterIds)));
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $rows = $db->fetchAll(
                        "SELECT id, COALESCE(denominazione, name) AS tenant_name
                         FROM tenants
                         WHERE deleted_at IS NULL
                           AND id IN ($placeholders)",
                        $ids
                    );
                    foreach ($rows as $r) {
                        $tid = (int)($r['id'] ?? 0);
                        if ($tid > 0) {
                            cnx_ensure_tenant_root_folder($db, $tid, (string)($r['tenant_name'] ?? ('Tenant ' . $tid)));
                        }
                    }
                }

                $placeholders = implode(',', array_fill(0, count($companyFilterIds), '?'));
                $query .= " AND f.tenant_id IN ($placeholders)";
                $params = array_merge($params, $companyFilterIds);
            }
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

            // Apply Company Filter scoping (intersection) if set
            if (is_array($companyFilterIds) && !empty($companyFilterIds)) {
                $accessible_tenants = array_values(array_intersect(array_map('intval', $accessible_tenants), array_map('intval', $companyFilterIds)));
                if (empty($accessible_tenants)) {
                    // No tenant in scope => empty result set
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'items' => [],
                            'breadcrumb' => $folder_id ? getBreadcrumb($folder_id) : [],
                            'current_folder' => null,
                            'user_role' => $user_role,
                            'can_create_root' => in_array($user_role, ['admin', 'super_admin'])
                        ]
                    ]);
                    return;
                }
            }

            // Lazy backfill: ensure each accessible tenant has a root folder (avoid for super_admin)
            if ($isRootRequest) {
                $ids = array_values(array_unique(array_map('intval', $accessible_tenants)));
                if (!in_array((int)$tenant_id, $ids, true)) {
                    $ids[] = (int)$tenant_id;
                }
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $rows = $db->fetchAll(
                        "SELECT id, COALESCE(denominazione, name) AS tenant_name
                         FROM tenants
                         WHERE deleted_at IS NULL
                           AND id IN ($placeholders)",
                        $ids
                    );
                    foreach ($rows as $r) {
                        $tid = (int)($r['id'] ?? 0);
                        if ($tid > 0) {
                            cnx_ensure_tenant_root_folder($db, $tid, (string)($r['tenant_name'] ?? ('Tenant ' . $tid)));
                        }
                    }
                }
            }

            $placeholders = implode(',', array_fill(0, count($accessible_tenants), '?'));
            $query .= " AND f.tenant_id IN ($placeholders)";
            $params = array_merge($params, $accessible_tenants);
        } else {
            // User e Manager vedono solo il proprio tenant
            // Lazy backfill: ensure own tenant root folder exists (when browsing root)
            if ($isRootRequest) {
                $row = $db->fetchOne(
                    "SELECT COALESCE(denominazione, name) AS tenant_name
                     FROM tenants
                     WHERE id = ? AND deleted_at IS NULL
                     LIMIT 1",
                    [(int)$tenant_id]
                );
                $tname = (string)(($row['tenant_name'] ?? '') ?: ('Tenant ' . (int)$tenant_id));
                cnx_ensure_tenant_root_folder($db, (int)$tenant_id, $tname);
            }
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

        // Add assignment label for immediate display in list/grid.
        // Rules:
        // - Role assignment: always show "Ruolo: <role>"
        // - User assignment: show user name ONLY to manager/admin/super_admin, otherwise "Utente assegnato"
        if ($hasAssignmentsTable && !empty($items)) {
            $assignmentIds = [];
            $effectiveByItemId = [];

            foreach ($items as $it) {
                $itemId = (int)($it['id'] ?? 0);
                if ($itemId <= 0) continue;

                $directId = isset($it['direct_assignment_id']) ? (int)$it['direct_assignment_id'] : 0;
                $inheritedId = isset($it['inherited_assignment_id']) ? (int)$it['inherited_assignment_id'] : 0;
                $isFolder = ((int)($it['is_folder'] ?? 0) === 1);

                $effectiveId = 0;
                $isInherited = false;
                if ($directId > 0) {
                    $effectiveId = $directId;
                } elseif (!$isFolder && $inheritedId > 0) {
                    $effectiveId = $inheritedId;
                    $isInherited = true;
                }

                if ($effectiveId > 0) {
                    $assignmentIds[] = $effectiveId;
                    $effectiveByItemId[$itemId] = [
                        'assignment_id' => $effectiveId,
                        'is_inherited' => $isInherited,
                    ];
                }
            }

            $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $assignmentIds), static fn($v) => $v > 0)));

            $assignmentMap = [];
            if (!empty($assignmentIds)) {
                $ph = implode(',', array_fill(0, count($assignmentIds), '?'));
                $sql = "
                    SELECT
                        fa.id,
                        fa.tenant_id,
                        fa.file_id,
                        fa.assigned_to_user_id,
                        u.name AS assigned_to_name,
                        fa.assigned_to_tenant_role_id" . ($hasTenantRolesTable ? ",
                        tr.name AS assigned_to_role_name" : "") . "
                    FROM file_assignments fa
                    LEFT JOIN users u ON fa.assigned_to_user_id = u.id" . ($hasTenantRolesTable ? "
                    LEFT JOIN tenant_roles tr ON fa.assigned_to_tenant_role_id = tr.id" : "") . "
                    WHERE (fa.deleted_at IS NULL OR fa.deleted_at = '')
                      AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
                      AND fa.id IN ($ph)
                ";

                $rows = $db->fetchAll($sql, $assignmentIds);
                foreach ($rows as $r) {
                    $aid = (int)($r['id'] ?? 0);
                    if ($aid > 0) {
                        $assignmentMap[$aid] = $r;
                    }
                }
            }

            // Attach derived fields to items
            foreach ($items as &$it) {
                $itemId = (int)($it['id'] ?? 0);
                $it['is_assigned'] = 0;
                $it['assignment_label'] = null;
                $it['assignment_is_inherited'] = 0;
                $it['assignment_target_type'] = null;

                if ($itemId <= 0 || empty($effectiveByItemId[$itemId])) {
                    continue;
                }

                $aid = (int)$effectiveByItemId[$itemId]['assignment_id'];
                $it['assignment_is_inherited'] = !empty($effectiveByItemId[$itemId]['is_inherited']) ? 1 : 0;

                $a = $assignmentMap[$aid] ?? null;
                if (!$a) {
                    continue;
                }

                $assignedRoleId = (int)($a['assigned_to_tenant_role_id'] ?? 0);
                $assignedUserId = (int)($a['assigned_to_user_id'] ?? 0);

                if ($assignedRoleId > 0) {
                    $roleName = (string)($a['assigned_to_role_name'] ?? '');
                    $it['assignment_target_type'] = 'tenant_role';
                    $it['assignment_label'] = 'Ruolo: ' . ($roleName !== '' ? $roleName : 'Ruolo assegnato');
                    $it['is_assigned'] = 1;
                } elseif ($assignedUserId > 0) {
                    $it['assignment_target_type'] = 'user';
                    if ($canSeeAssignedUserName) {
                        $uname = trim((string)($a['assigned_to_name'] ?? ''));
                        $it['assignment_label'] = ($uname !== '' ? $uname : 'Utente assegnato');
                    } else {
                        $it['assignment_label'] = 'Utente assegnato';
                    }
                    $it['is_assigned'] = 1;
                }
            }
            unset($it);
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
 * Parse browser-provided relative_path for directory uploads.
 *
 * Example input: "TopFolder/SubFolder/file.pdf"
 * Output: ["TopFolder", "SubFolder"]
 *
 * @return string[]
 */
function cnx_parse_upload_relative_folder_segments(?string $relativePath): array {
    $relativePath = trim((string)($relativePath ?? ''));
    if ($relativePath === '') return [];

    // Normalize separators (browser uses '/', but be defensive)
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');

    $parts = array_values(array_filter(explode('/', $relativePath), static function($p) {
        return $p !== null && $p !== '';
    }));

    // Must include at least one folder and a filename
    if (count($parts) < 2) return [];

    // Drop filename
    $parts = array_slice($parts, 0, -1);

    $out = [];
    foreach ($parts as $seg) {
        $seg = trim((string)$seg);
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            apiError('Percorso cartella non valido', 400);
        }
        // Remove control chars and any path separators just in case
        $seg = preg_replace('/[\x00-\x1F\x7F]/u', '', $seg);
        $seg = str_replace(['/', '\\'], '', $seg);
        $seg = trim((string)$seg);
        if ($seg === '' || $seg === '.' || $seg === '..') continue;
        $segLen = function_exists('mb_strlen') ? mb_strlen($seg) : strlen($seg);
        if ($segLen > 180) {
            $seg = function_exists('mb_substr') ? mb_substr($seg, 0, 180) : substr($seg, 0, 180);
        }
        $out[] = $seg;
        if (count($out) > 30) {
            apiError('Percorso cartella troppo profondo', 400);
        }
    }

    return $out;
}

/**
 * Idempotent: get or create a folder under a parent for a tenant.
 */
function cnx_get_or_create_folder_id(int $tenantId, ?int $parentFolderId, string $name, int $userId): int {
    global $pdo;

    $tenantId = (int)$tenantId;
    $userId = (int)$userId;
    $parentFolderId = ($parentFolderId !== null && (int)$parentFolderId > 0) ? (int)$parentFolderId : null;
    $name = trim((string)$name);

    if ($tenantId <= 0 || $userId <= 0 || $name === '') {
        apiError('Parametri cartella non validi', 400);
    }

    if ($parentFolderId !== null) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM files
            WHERE tenant_id = ?
              AND folder_id = ?
              AND is_folder = 1
              AND deleted_at IS NULL
              AND name = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $parentFolderId, $name]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM files
            WHERE tenant_id = ?
              AND folder_id IS NULL
              AND is_folder = 1
              AND deleted_at IS NULL
              AND name = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $name]);
    }

    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    // Build a best-effort fullPath (for folders we store a path-like value)
    if ($parentFolderId !== null) {
        $parentPath = getBreadcrumb($parentFolderId);
        $fullPath = '/' . implode('/', array_column($parentPath, 'name')) . '/' . $name;
    } else {
        $fullPath = '/' . $name;
    }

    $stmt = $pdo->prepare("
        INSERT INTO files (name, folder_id, tenant_id, uploaded_by, is_folder, file_path, created_at, updated_at)
        VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
    ");
    $stmt->execute([$name, $parentFolderId, $tenantId, $userId, $fullPath]);

    return (int)$pdo->lastInsertId();
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
    $relative_path = $_POST['relative_path'] ?? '';

    // Allow root uploads for admin/super_admin; otherwise require a folder
    if (empty($folder_id) && !hasApiRole('admin')) {
        apiError('Seleziona una cartella prima di caricare file', 400);
    }

    try {
        $target_tenant_id = $tenant_id;
        if (!empty($folder_id)) {
            $stmt = $pdo->prepare("SELECT tenant_id FROM files WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL");
            $stmt->execute([$folder_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                apiError('Cartella non trovata', 404);
            }

            if (!hasAccessToTenant($folder['tenant_id'])) {
                apiError('Non hai accesso a questo tenant', 403);
            }

            $target_tenant_id = (int)$folder['tenant_id'];
        }

        $file = $_FILES['file'];
        $original_name = $file['name'];
        $tmp_name = $file['tmp_name'];
        $size = $file['size'];
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            apiError('Errore durante il caricamento del file', 400);
        }

        if ($size > MAX_FILE_SIZE) {
            apiError('File troppo grande (max ' . (MAX_FILE_SIZE / 1048576) . 'MB)', 400);
        }

        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $filename = pathinfo($original_name, PATHINFO_FILENAME);
        $unique_name = $filename . '_' . uniqid() . ($extension ? ('.' . $extension) : '');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        $upload_dir = UPLOAD_PATH . '/' . $target_tenant_id;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Directory upload: create subfolders from relative_path and place file accordingly (best-effort)
        $effective_folder_id = !empty($folder_id) ? (int)$folder_id : null;
        $segments = cnx_parse_upload_relative_folder_segments($relative_path);
        if (!empty($segments)) {
            foreach ($segments as $seg) {
                $effective_folder_id = cnx_get_or_create_folder_id($target_tenant_id, $effective_folder_id, $seg, (int)$user_id);
            }
        }

        $file_path = $upload_dir . '/' . $unique_name;

        if (!move_uploaded_file($tmp_name, $file_path)) {
            apiError('Errore nel salvataggio del file', 500);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO files (
                folder_id, tenant_id, name, original_name, file_size,
                file_path, uploaded_by, mime_type, status, is_folder, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, 'in_approvazione', 0, NOW(), NOW()
            )"
        );

        $stmt->execute([
            ($effective_folder_id !== null && (int)$effective_folder_id > 0) ? (int)$effective_folder_id : null,
            $target_tenant_id,
            $original_name,
            $original_name,
            $size,
            $unique_name,
            $user_id,
            $mime_type
        ]);

        $file_id = $pdo->lastInsertId();

        logAudit('upload_file', 'files', $file_id, [
            'filename' => $original_name,
            'folder_id' => ($effective_folder_id !== null && (int)$effective_folder_id > 0) ? (int)$effective_folder_id : null,
            'relative_path' => $relative_path ? (string)$relative_path : null,
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

    // Recursive deletion is allowed ONLY for privileged roles and ONLY for folders with contents
    $recursive = $_GET['recursive'] ?? $input['recursive'] ?? null;
    $recursive = ($recursive === true || $recursive === '1' || $recursive === 1 || $recursive === 'true');

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

            $childrenCount = (int)($count_result['count'] ?? 0);
            if ($childrenCount > 0) {
                // Allow recursive delete for manager/admin/super_admin only
                if (!hasApiRole('manager')) {
                    apiError('La cartella non è vuota. Elimina prima il contenuto.', 400);
                }

                // Require explicit recursive flag for safety (prevents accidental data loss)
                if (!$recursive) {
                    apiError('La cartella non è vuota. Conferma eliminazione completa.', 409, [
                        'can_recursive_delete' => true,
                        'children_count' => $childrenCount
                    ]);
                }

                // Recursive soft-delete folder + all descendants (bounded)
                $pdo->beginTransaction();
                try {
                    $now = date('Y-m-d H:i:s');

                    $allIds = [(int)$item_id];
                    $queue = [(int)$item_id];
                    $visited = [(int)$item_id => true];
                    $maxItems = 5000; // safety bound

                    while (!empty($queue)) {
                        $batch = array_splice($queue, 0, 200);
                        $ph = implode(',', array_fill(0, count($batch), '?'));

                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM files
                            WHERE folder_id IN ($ph)
                              AND deleted_at IS NULL
                        ");
                        $stmt->execute($batch);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($rows as $r) {
                            $cid = (int)($r['id'] ?? 0);
                            if ($cid <= 0 || isset($visited[$cid])) continue;
                            $visited[$cid] = true;
                            $allIds[] = $cid;
                            $queue[] = $cid;

                            if (count($allIds) > $maxItems) {
                                $pdo->rollBack();
                                apiError('Cartella troppo grande per eliminazione massiva (limite 5000 elementi).', 400, [
                                    'max_items' => $maxItems
                                ]);
                            }
                        }
                    }

                    // Soft-delete all collected ids (folder + descendants)
                    $phAll = implode(',', array_fill(0, count($allIds), '?'));
                    $stmt = $pdo->prepare("
                        UPDATE files
                        SET deleted_at = ?
                        WHERE id IN ($phAll)
                          AND deleted_at IS NULL
                    ");
                    $stmt->execute(array_merge([$now], $allIds));

                    // Best-effort: soft-delete related assignments (if table exists)
                    try {
                        $hasFA = $pdo->query("SHOW TABLES LIKE 'file_assignments'")->rowCount() > 0;
                        if ($hasFA) {
                            $stmt = $pdo->prepare("
                                UPDATE file_assignments
                                SET deleted_at = ?, updated_at = ?
                                WHERE deleted_at IS NULL
                                  AND file_id IN ($phAll)
                            ");
                            $stmt->execute(array_merge([$now, $now], $allIds));
                        }
                    } catch (Throwable $e) {
                        // non-blocking
                    }

                    $pdo->commit();

                    // Log audit (folder delete with descendants count)
                    logAudit('delete_folder_recursive', 'files', (int)$item_id, [
                        'name' => $item['name'],
                        'deleted_items' => count($allIds)
                    ]);

                    apiSuccess([
                        'deleted_items' => count($allIds)
                    ], 'Cartella e contenuto eliminati con successo');

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
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
 * Crea un documento vuoto (docx, xlsx, pptx, txt)
 */
function createNewDocument() {
    global $pdo, $user_id, $tenant_id, $user_role;

    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? '';
    $name = trim($input['name'] ?? '');
    $folder_id = $input['folder_id'] ?? null;

    $supported = ['docx','xlsx','pptx','txt'];
    if (!in_array($type, $supported)) {
        apiError('Tipo di documento non supportato', 400);
    }
    if ($name === '') {
        apiError('Nome documento richiesto', 400);
    }

    // Normalizza nome ed estensione
    $name = preg_replace('/[^a-zA-Z0-9\s\-_\.]/', '', $name);
    if (!str_ends_with(strtolower($name), '.' . $type)) {
        $name .= '.' . $type;
    }

    try {
        $target_tenant_id = $tenant_id;
        if (!empty($folder_id)) {
            $stmt = $pdo->prepare("SELECT tenant_id FROM files WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL");
            $stmt->execute([$folder_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$folder) {
                apiError('Cartella non trovata', 404);
            }
            if (!hasAccessToTenant($folder['tenant_id'])) {
                apiError('Non hai accesso a questo tenant', 403);
            }
            $target_tenant_id = (int)$folder['tenant_id'];
        }

        // Genera contenuto/template minimo
        $content = '';
        if ($type === 'txt') {
            $content = '';
        } else {
            // usa l'endpoint esistente come riferimento
            // per semplicità, crea un file vuoto con estensione corretta
            $content = '';
        }

        // Salvataggio su disco
        $upload_dir = UPLOAD_PATH . '/' . $target_tenant_id;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Nome file univoco
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $unique_name = $base . '_' . uniqid() . ($extension ? ('.' . $extension) : '');
        $fullPath = $upload_dir . '/' . $unique_name;

        file_put_contents($fullPath, $content);

        // Metadati
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $fullPath) ?: 'application/octet-stream';
        finfo_close($finfo);
        $size = filesize($fullPath) ?: 0;

        // Inserimento DB - coerente con schema (file_path = unique filename)
        $stmt = $pdo->prepare("INSERT INTO files (
            folder_id, tenant_id, name, original_name, file_size,
            file_path, uploaded_by, mime_type, status, is_folder, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, 'in_approvazione', 0, NOW(), NOW()
        )");

        $stmt->execute([
            !empty($folder_id) ? $folder_id : null,
            $target_tenant_id,
            $name,
            $name,
            $size,
            $unique_name,
            $user_id,
            $mime_type
        ]);

        $file_id = $pdo->lastInsertId();

        apiSuccess(['file_id' => $file_id], 'Documento creato con successo');

    } catch (Exception $e) {
        logApiError('CreateNewDocument', $e);
        apiError('Errore nella creazione del documento', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
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
    global $pdo, $db, $user_id, $tenant_id, $user_role;

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
            WHERE f.id = :id AND f.deleted_at IS NULL
        ");
        $stmt->execute([':id' => $file_id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            apiError('File non trovato nel database', 404);
        }

        $is_folder = ((int)($file['is_folder'] ?? 0) === 1);

        // Verifica accesso al tenant
        if (!hasAccessToTenant($file['tenant_id'])) {
            apiError('Non hai accesso a questo elemento', 403);
        }

        // Folder download: stream as ZIP (with approval workflow for non-privileged users)
        if ($is_folder) {
            // Only super_admin/admin/manager can download immediately
            $privileged = in_array($user_role, ['super_admin', 'admin', 'manager'], true);
            if (!$privileged) {
                $t = cnx_folder_zip_requests_table_status($pdo);
                if (!($t['ready'] ?? false)) {
                    if (($t['missing'] ?? false) === true) {
                        cnx_json_error(503, 'Sistema di approvazione download non configurato (manca migrazione DB).');
                    }
                    cnx_json_error(500, 'Errore configurazione DB per approvazione download ZIP.', [
                        'details' => (string)($t['error'] ?? 'unknown'),
                        'code' => (string)($t['code'] ?? ''),
                    ]);
                }

                $approvedReq = $db->fetchOne(
                    "SELECT id
                     FROM folder_zip_download_requests
                     WHERE tenant_id = ?
                       AND folder_id = ?
                       AND requester_id = ?
                       AND status = 'approved'
                       AND used_at IS NULL
                     ORDER BY id DESC
                     LIMIT 1",
                    [(int)$file['tenant_id'], (int)$file_id, (int)$user_id]
                );

                if (!$approvedReq) {
                    cnx_json_error(403, 'Download ZIP cartella richiede approvazione del manager.', [
                        'requires_approval' => true,
                        'folder_id' => (int)$file_id,
                    ]);
                }

                // Consume one-time approval BEFORE generating ZIP
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE folder_zip_download_requests
                         SET status = 'consumed',
                             used_at = NOW(),
                             decided_at = IFNULL(decided_at, NOW())
                         WHERE id = ?
                           AND status = 'approved'
                           AND used_at IS NULL"
                    );
                    $stmt->execute([(int)$approvedReq['id']]);
                    if ($stmt->rowCount() !== 1) {
                        $pdo->rollBack();
                        cnx_json_error(403, 'Approvazione non valida o già utilizzata.', [
                            'requires_approval' => true,
                            'folder_id' => (int)$file_id,
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

            cnx_download_folder_as_zip($pdo, $file, (int)$file_id);
            exit;
        }

        // File download: enforce assignment access OR allow one-time manager approval.
        if (!in_array($user_role, ['super_admin', 'admin', 'manager'], true)) {
            require_once __DIR__ . '/../includes/file_access.php';

            $access = hasFileOrFolderAccess($db, (int)$file_id, (int)$user_id, (string)$user_role, (int)$file['tenant_id']);
            if (!($access['has_access'] ?? false)) {
                // Check if a one-time approval exists for this user+file
                $t = cnx_file_download_requests_table_status($pdo);
                if (!($t['ready'] ?? false)) {
                    if (($t['missing'] ?? false) === true) {
                        cnx_json_error(503, 'Sistema di approvazione download non configurato (manca migrazione DB).');
                    }
                    cnx_json_error(500, 'Errore configurazione DB per approvazione download file.', [
                        'details' => (string)($t['error'] ?? 'unknown'),
                        'code' => (string)($t['code'] ?? ''),
                    ]);
                }

                $approvedReq = $db->fetchOne(
                    "SELECT id
                     FROM file_download_requests
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND requester_id = ?
                       AND status = 'approved'
                       AND used_at IS NULL
                     ORDER BY id DESC
                     LIMIT 1",
                    [(int)$file['tenant_id'], (int)$file_id, (int)$user_id]
                );

                if (!$approvedReq) {
                    cnx_json_error(403, 'Download file richiede approvazione del manager.', [
                        'requires_approval' => true,
                        'file_id' => (int)$file_id,
                    ]);
                }

                // Consume one-time approval BEFORE streaming file
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE file_download_requests
                         SET status = 'consumed',
                             used_at = NOW(),
                             decided_at = IFNULL(decided_at, NOW())
                         WHERE id = ?
                           AND status = 'approved'
                           AND used_at IS NULL"
                    );
                    $stmt->execute([(int)$approvedReq['id']]);
                    if ($stmt->rowCount() !== 1) {
                        $pdo->rollBack();
                        cnx_json_error(403, 'Approvazione non valida o già utilizzata.', [
                            'requires_approval' => true,
                            'file_id' => (int)$file_id,
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
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
 * Create (or reuse) a request to download a folder as ZIP.
 * For non-privileged users, triggers email to tenant manager.
 */
function requestFolderZipDownload(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_folder_zip_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download ZIP.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    $folderId = (int)($input['folder_id'] ?? 0);
    if ($folderId <= 0) {
        apiError('folder_id richiesto', 400);
    }

    // Get folder + tenant
    $folder = $db->fetchOne(
        "SELECT id, tenant_id, name, is_folder
         FROM files
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1",
        [$folderId]
    );
    if (!$folder || (int)$folder['is_folder'] !== 1) {
        apiError('Cartella non trovata', 404);
    }

    if (!hasAccessToTenant((int)$folder['tenant_id'])) {
        apiError('Non hai accesso a questa cartella', 403);
    }

    // If privileged, approval not needed: return direct download url
    if (in_array($user_role, ['super_admin', 'admin', 'manager'], true)) {
        apiSuccess([
            'approved' => true,
            'download_url' => '/CollaboraNexio/api/files_tenant.php?action=download&id=' . $folderId,
            'note' => 'approval_not_required',
        ], 'Download consentito');
    }

    // If already approved and unused, return direct download url (one-time)
    $approved = $db->fetchOne(
        "SELECT id
         FROM folder_zip_download_requests
         WHERE tenant_id = ?
           AND folder_id = ?
           AND requester_id = ?
           AND status = 'approved'
           AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1",
        [(int)$folder['tenant_id'], $folderId, (int)$user_id]
    );
    if ($approved) {
        apiSuccess([
            'approved' => true,
            'download_url' => '/CollaboraNexio/api/files_tenant.php?action=download&id=' . $folderId,
            'request_id' => (int)$approved['id'],
        ], 'Richiesta già approvata: puoi scaricare ora');
    }

    // Reuse existing pending request if present
    $pending = $db->fetchOne(
        "SELECT id
         FROM folder_zip_download_requests
         WHERE tenant_id = ?
           AND folder_id = ?
           AND requester_id = ?
           AND status = 'pending'
         ORDER BY id DESC
         LIMIT 1",
        [(int)$folder['tenant_id'], $folderId, (int)$user_id]
    );

    $requestId = 0;
    if ($pending) {
        $requestId = (int)$pending['id'];
    } else {
        $requestId = $db->insert('folder_zip_download_requests', [
            'tenant_id' => (int)$folder['tenant_id'],
            'folder_id' => $folderId,
            'requester_id' => (int)$user_id,
            'status' => 'pending',
            'requested_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // Email manager (best-effort, non-blocking)
    try {
        $tenantRow = $db->fetchOne(
            "SELECT id, COALESCE(denominazione, name) AS tenant_name, manager_id
             FROM tenants
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [(int)$folder['tenant_id']]
        ) ?: [];

        $tenantName = (string)($tenantRow['tenant_name'] ?? ('Tenant ' . (int)$folder['tenant_id']));
        $requester = $db->fetchOne(
            "SELECT id, name, email
             FROM users
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [(int)$user_id]
        ) ?: [];

        $diag = null;
        $recipients = cnx_get_tenant_manager_recipients(
            $db,
            (int)$folder['tenant_id'],
            (int)($tenantRow['manager_id'] ?? 0),
            $diag
        );

        // Diagnostic logging (non-blocking)
        try {
            $emails = array_values(array_filter(array_map(static function ($r) {
                return (string)($r['email'] ?? '');
            }, $recipients)));
            error_log('[folder_zip_request][recipients] ' . json_encode([
                'tenant_id' => (int)$folder['tenant_id'],
                'manager_id' => (int)($tenantRow['manager_id'] ?? 0),
                'source' => (string)($diag['source'] ?? 'unknown'),
                'recipients' => $emails,
                'count' => count($emails),
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // ignore
        }

        if (!empty($recipients)) {
            $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
            $approveUrl = $baseUrl . '/zip_download_requests.php?request_id=' . urlencode((string)$requestId);
            foreach ($recipients as $r) {
                cnx_send_folder_zip_request_email(
                    (string)$r['email'],
                    (string)$r['name'],
                    $tenantName,
                    (string)($requester['name'] ?? 'Utente'),
                    (string)($folder['name'] ?? 'Cartella'),
                    $approveUrl,
                    [
                        'action' => 'folder_zip_request',
                        'tenant_id' => (int)$folder['tenant_id'],
                        'user_id' => (int)$user_id,
                    ]
                );
            }
        } else {
            error_log('[folder_zip_request] no manager recipients found for tenant ' . (int)$folder['tenant_id']);
        }
    } catch (Throwable $e) {
        error_log('[folder_zip_request] email send failed: ' . $e->getMessage());
    }

    apiSuccess([
        'approved' => false,
        'request_id' => $requestId,
    ], 'Richiesta inviata al manager del tenant');
}

/**
 * Create (or reuse) a request to download a file (one-time approval).
 * For non-privileged users, triggers email to tenant manager.
 */
function requestFileDownload(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_file_download_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download file.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    $fileId = (int)($input['file_id'] ?? 0);
    if ($fileId <= 0) {
        apiError('file_id richiesto', 400);
    }

    $file = $db->fetchOne(
        "SELECT id, tenant_id, name, is_folder
         FROM files
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1",
        [$fileId]
    );
    if (!$file || (int)$file['is_folder'] === 1) {
        apiError('File non trovato', 404);
    }

    if (!hasAccessToTenant((int)$file['tenant_id'])) {
        apiError('Non hai accesso a questo file', 403);
    }

    // Privileged users: approval not needed
    if (in_array($user_role, ['super_admin', 'admin', 'manager'], true)) {
        apiSuccess([
            'approved' => true,
            'download_url' => '/CollaboraNexio/api/files_tenant.php?action=download&id=' . $fileId,
            'note' => 'approval_not_required',
        ], 'Download consentito');
    }

    // If already approved and unused, return direct download url (one-time)
    $approved = $db->fetchOne(
        "SELECT id
         FROM file_download_requests
         WHERE tenant_id = ?
           AND file_id = ?
           AND requester_id = ?
           AND status = 'approved'
           AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1",
        [(int)$file['tenant_id'], $fileId, (int)$user_id]
    );
    if ($approved) {
        apiSuccess([
            'approved' => true,
            'download_url' => '/CollaboraNexio/api/files_tenant.php?action=download&id=' . $fileId,
            'request_id' => (int)$approved['id'],
        ], 'Richiesta già approvata: puoi scaricare ora');
    }

    // Reuse existing pending request if present
    $pending = $db->fetchOne(
        "SELECT id
         FROM file_download_requests
         WHERE tenant_id = ?
           AND file_id = ?
           AND requester_id = ?
           AND status = 'pending'
         ORDER BY id DESC
         LIMIT 1",
        [(int)$file['tenant_id'], $fileId, (int)$user_id]
    );

    $requestId = 0;
    if ($pending) {
        $requestId = (int)$pending['id'];
    } else {
        $requestId = $db->insert('file_download_requests', [
            'tenant_id' => (int)$file['tenant_id'],
            'file_id' => $fileId,
            'requester_id' => (int)$user_id,
            'status' => 'pending',
            'requested_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // Email manager (best-effort, non-blocking)
    try {
        $tenantRow = $db->fetchOne(
            "SELECT id, COALESCE(denominazione, name) AS tenant_name, manager_id
             FROM tenants
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [(int)$file['tenant_id']]
        ) ?: [];

        $tenantName = (string)($tenantRow['tenant_name'] ?? ('Tenant ' . (int)$file['tenant_id']));
        $requester = $db->fetchOne(
            "SELECT id, name, email
             FROM users
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [(int)$user_id]
        ) ?: [];

        $diag = null;
        $recipients = cnx_get_tenant_manager_recipients(
            $db,
            (int)$file['tenant_id'],
            (int)($tenantRow['manager_id'] ?? 0),
            $diag
        );

        if (!empty($recipients)) {
            $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
            $approveUrl = $baseUrl . '/file_download_requests.php?request_id=' . urlencode((string)$requestId);
            foreach ($recipients as $r) {
                cnx_send_file_download_request_email(
                    (string)$r['email'],
                    (string)$r['name'],
                    $tenantName,
                    (string)($requester['name'] ?? 'Utente'),
                    (string)($file['name'] ?? 'File'),
                    $approveUrl,
                    [
                        'action' => 'file_download_request',
                        'tenant_id' => (int)$file['tenant_id'],
                        'user_id' => (int)$user_id,
                    ]
                );
            }
        } else {
            error_log('[file_download_request] no manager recipients found for tenant ' . (int)$file['tenant_id']);
        }
    } catch (Throwable $e) {
        error_log('[file_download_request] email send failed: ' . $e->getMessage());
    }

    apiSuccess([
        'approved' => false,
        'request_id' => $requestId,
    ], 'Richiesta inviata al manager del tenant');
}

function approveFileDownloadRequest(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_file_download_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download file.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    if (!in_array($user_role, ['manager', 'admin', 'super_admin'], true)) {
        apiError('Non autorizzato', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    if ($requestId <= 0) {
        apiError('request_id richiesto', 400);
    }

    $req = $db->fetchOne(
        "SELECT r.*, f.name AS file_name, t.manager_id, COALESCE(t.denominazione, t.name) AS tenant_name,
                u.name AS requester_name, u.email AS requester_email
         FROM file_download_requests r
         INNER JOIN files f ON f.id = r.file_id AND f.deleted_at IS NULL
         INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
         INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
         WHERE r.id = ?
         LIMIT 1",
        [$requestId]
    );
    if (!$req) {
        apiError('Richiesta non trovata', 404);
    }

    $reqTenantId = (int)$req['tenant_id'];
    if (!hasAccessToTenant($reqTenantId)) {
        apiError('Non hai accesso a questo tenant', 403);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE file_download_requests
             SET status = 'approved',
                 decided_at = NOW(),
                 decided_by = ?,
                 note = ?
             WHERE id = ?
               AND status = 'pending'"
        );
        $stmt->execute([(int)$user_id, ($note !== '' ? $note : null), $requestId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            apiError('Richiesta non più in stato pending', 409);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Notify requester (best-effort)
    try {
        $downloadUrl = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio')
            . '/api/files_tenant.php?action=download&id=' . (int)$req['file_id'];
        cnx_send_file_download_decision_email(
            (string)$req['requester_email'],
            (string)$req['requester_name'],
            (string)$req['tenant_name'],
            (string)$req['file_name'],
            true,
            $downloadUrl,
            $note,
            [
                'action' => 'file_download_approved',
                'tenant_id' => $reqTenantId,
                'user_id' => (int)$user_id,
            ]
        );
    } catch (Throwable $e) {
        error_log('[file_download_approve] notify requester failed: ' . $e->getMessage());
    }

    apiSuccess(['request_id' => $requestId], 'Richiesta approvata');
}

function rejectFileDownloadRequest(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_file_download_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download file.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    if (!in_array($user_role, ['manager', 'admin', 'super_admin'], true)) {
        apiError('Non autorizzato', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    if ($requestId <= 0) {
        apiError('request_id richiesto', 400);
    }

    $req = $db->fetchOne(
        "SELECT r.*, f.name AS file_name, t.manager_id, COALESCE(t.denominazione, t.name) AS tenant_name,
                u.name AS requester_name, u.email AS requester_email
         FROM file_download_requests r
         INNER JOIN files f ON f.id = r.file_id AND f.deleted_at IS NULL
         INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
         INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
         WHERE r.id = ?
         LIMIT 1",
        [$requestId]
    );
    if (!$req) {
        apiError('Richiesta non trovata', 404);
    }

    $reqTenantId = (int)$req['tenant_id'];
    if (!hasAccessToTenant($reqTenantId)) {
        apiError('Non hai accesso a questo tenant', 403);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE file_download_requests
             SET status = 'rejected',
                 decided_at = NOW(),
                 decided_by = ?,
                 note = ?
             WHERE id = ?
               AND status = 'pending'"
        );
        $stmt->execute([(int)$user_id, ($note !== '' ? $note : null), $requestId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            apiError('Richiesta non più in stato pending', 409);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Notify requester (best-effort)
    try {
        cnx_send_file_download_decision_email(
            (string)$req['requester_email'],
            (string)$req['requester_name'],
            (string)$req['tenant_name'],
            (string)$req['file_name'],
            false,
            '',
            $note,
            [
                'action' => 'file_download_rejected',
                'tenant_id' => $reqTenantId,
                'user_id' => (int)$user_id,
            ]
        );
    } catch (Throwable $e) {
        error_log('[file_download_reject] notify requester failed: ' . $e->getMessage());
    }

    apiSuccess(['request_id' => $requestId], 'Richiesta rifiutata');
}

function approveFolderZipRequest(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_folder_zip_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download ZIP.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    if (!in_array($user_role, ['manager', 'admin', 'super_admin'], true)) {
        apiError('Non autorizzato', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    if ($requestId <= 0) {
        apiError('request_id richiesto', 400);
    }

    $req = $db->fetchOne(
        "SELECT r.*, f.name AS folder_name, t.manager_id, COALESCE(t.denominazione, t.name) AS tenant_name,
                u.name AS requester_name, u.email AS requester_email
         FROM folder_zip_download_requests r
         INNER JOIN files f ON f.id = r.folder_id AND f.deleted_at IS NULL
         INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
         INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
         WHERE r.id = ?
         LIMIT 1",
        [$requestId]
    );
    if (!$req) {
        apiError('Richiesta non trovata', 404);
    }

    $reqTenantId = (int)$req['tenant_id'];
    if (!hasAccessToTenant($reqTenantId)) {
        apiError('Non hai accesso a questo tenant', 403);
    }

    // Multi-manager policy:
    // Any manager with access to the tenant can approve/reject.
    // (tenants.manager_id is used only for email recipient preference, not for approval blocking)

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE folder_zip_download_requests
             SET status = 'approved',
                 decided_at = NOW(),
                 decided_by = ?,
                 note = ?
             WHERE id = ?
               AND status = 'pending'"
        );
        $stmt->execute([(int)$user_id, ($note !== '' ? $note : null), $requestId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            apiError('Richiesta non più in stato pending', 409);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Notify requester (best-effort)
    try {
        $downloadUrl = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio')
            . '/api/files_tenant.php?action=download&id=' . (int)$req['folder_id'];
        cnx_send_folder_zip_decision_email(
            (string)$req['requester_email'],
            (string)$req['requester_name'],
            (string)$req['tenant_name'],
            (string)$req['folder_name'],
            true,
            $downloadUrl,
            $note,
            [
                'action' => 'folder_zip_approved',
                'tenant_id' => $reqTenantId,
                'user_id' => (int)$user_id,
            ]
        );
    } catch (Throwable $e) {
        error_log('[folder_zip_approve] notify requester failed: ' . $e->getMessage());
    }

    apiSuccess(['request_id' => $requestId], 'Richiesta approvata');
}

function rejectFolderZipRequest(): void {
    global $pdo, $db, $input, $user_id, $tenant_id, $user_role;

    $t = cnx_folder_zip_requests_table_status($pdo);
    if (!($t['ready'] ?? false)) {
        if (($t['missing'] ?? false) === true) {
            apiError('Sistema di approvazione download non configurato (manca migrazione DB).', 503);
        }
        apiError('Errore configurazione DB per approvazione download ZIP.', 500, [
            'details' => (string)($t['error'] ?? 'unknown'),
            'code' => (string)($t['code'] ?? ''),
        ]);
    }

    if (!in_array($user_role, ['manager', 'admin', 'super_admin'], true)) {
        apiError('Non autorizzato', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    if ($requestId <= 0) {
        apiError('request_id richiesto', 400);
    }

    $req = $db->fetchOne(
        "SELECT r.*, f.name AS folder_name, t.manager_id, COALESCE(t.denominazione, t.name) AS tenant_name,
                u.name AS requester_name, u.email AS requester_email
         FROM folder_zip_download_requests r
         INNER JOIN files f ON f.id = r.folder_id AND f.deleted_at IS NULL
         INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
         INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
         WHERE r.id = ?
         LIMIT 1",
        [$requestId]
    );
    if (!$req) {
        apiError('Richiesta non trovata', 404);
    }

    $reqTenantId = (int)$req['tenant_id'];
    if (!hasAccessToTenant($reqTenantId)) {
        apiError('Non hai accesso a questo tenant', 403);
    }
    // Multi-manager policy: any manager with tenant access can reject.

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE folder_zip_download_requests
             SET status = 'rejected',
                 decided_at = NOW(),
                 decided_by = ?,
                 note = ?
             WHERE id = ?
               AND status = 'pending'"
        );
        $stmt->execute([(int)$user_id, ($note !== '' ? $note : null), $requestId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            apiError('Richiesta non più in stato pending', 409);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Notify requester (best-effort)
    try {
        cnx_send_folder_zip_decision_email(
            (string)$req['requester_email'],
            (string)$req['requester_name'],
            (string)$req['tenant_name'],
            (string)$req['folder_name'],
            false,
            '',
            $note,
            [
                'action' => 'folder_zip_rejected',
                'tenant_id' => $reqTenantId,
                'user_id' => (int)$user_id,
            ]
        );
    } catch (Throwable $e) {
        error_log('[folder_zip_reject] notify requester failed: ' . $e->getMessage());
    }

    apiSuccess(['request_id' => $requestId], 'Richiesta rifiutata');
}

/**
 * Find tenant manager email recipients:
 * - prefer tenants.manager_id if it points to a real manager with access to this tenant
 * - otherwise fallback to all users with role=manager in this tenant (via user_tenant_access + mono-tenant users.tenant_id)
 *
 * @return array<int, array{email:string,name:string,user_id:int}>
 */
function cnx_get_tenant_manager_recipients(Database $db, int $tenantId, int $managerId, ?array &$diag = null): array {
    return cnx_resolve_tenant_manager_recipients($db, $tenantId, $managerId, $diag);
}

/**
 * Send request email to manager (non-blocking wrapper over sendEmail).
 */
function cnx_send_folder_zip_request_email(
    string $to,
    string $managerName,
    string $tenantName,
    string $requesterName,
    string $folderName,
    string $approveUrl,
    array $context = []
): bool {
    try {
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
        $brandColor = '#1a2332';
        $wrappedApproveUrl = function_exists('cnx_email_wrap_link_for_login')
            ? cnx_email_wrap_link_for_login($approveUrl)
            : $approveUrl;
        $vars = [
            'BASE_URL' => $baseUrl,
            'TENANT_NAME' => $tenantName,
            'YEAR' => date('Y'),
            'MANAGER_NAME' => $managerName,
            'REQUESTER_NAME' => $requesterName,
            'FOLDER_NAME' => $folderName,
            'APPROVE_URL' => $wrappedApproveUrl,
            'CTA_BUTTON' => renderEmailPrimaryButton($wrappedApproveUrl, 'Apri richieste download ZIP', $brandColor),
        ];
        $templatePath = __DIR__ . '/../includes/email_templates/files/folder_zip_download_request.html';
        $body = cnx_render_email_template_file($templatePath, $vars, ['remove_unknown_placeholders' => true]);
        if ($body === '') {
            // Fallback minimal body
            $body = '<p>Ciao <strong>' . cnx_email_escape($managerName) . '</strong>,</p>'
                . '<p>L’utente <strong>' . cnx_email_escape($requesterName) . '</strong> ha richiesto di scaricare come ZIP la cartella <strong>' . cnx_email_escape($folderName) . '</strong>.</p>'
                . renderEmailPrimaryButton($wrappedApproveUrl, 'Gestisci richiesta', $brandColor);
        }
        $html = renderEmailLayout('Richiesta download cartella ZIP', $body, $vars, ['brandColor' => $brandColor]);
        $text = "Richiesta download ZIP\n\nManager: $managerName\nRichiedente: $requesterName\nCartella: $folderName\n\nGestisci richiesta: $wrappedApproveUrl\n";
        return sendEmail($to, 'Richiesta download cartella ZIP - ' . $tenantName, $html, $text, ['context' => array_merge(['action' => 'folder_zip_request'], $context)]);
    } catch (Throwable $e) {
        error_log('[folder_zip_email] send request failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send file download request email to manager.
 */
function cnx_send_file_download_request_email(
    string $to,
    string $managerName,
    string $tenantName,
    string $requesterName,
    string $fileName,
    string $approveUrl,
    array $context = []
): bool {
    try {
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
        $brandColor = '#1a2332';
        $wrappedApproveUrl = function_exists('cnx_email_wrap_link_for_login')
            ? cnx_email_wrap_link_for_login($approveUrl)
            : $approveUrl;
        $vars = [
            'BASE_URL' => $baseUrl,
            'TENANT_NAME' => $tenantName,
            'YEAR' => date('Y'),
            'MANAGER_NAME' => $managerName,
            'REQUESTER_NAME' => $requesterName,
            'FILE_NAME' => $fileName,
            'APPROVE_URL' => $wrappedApproveUrl,
            'CTA_BUTTON' => renderEmailPrimaryButton($wrappedApproveUrl, 'Apri richieste download file', $brandColor),
        ];
        $templatePath = __DIR__ . '/../includes/email_templates/files/file_download_request.html';
        $body = cnx_render_email_template_file($templatePath, $vars, ['remove_unknown_placeholders' => true]);
        if ($body === '') {
            $body = '<p>Ciao <strong>' . cnx_email_escape($managerName) . '</strong>,</p>'
                . '<p>L’utente <strong>' . cnx_email_escape($requesterName) . '</strong> ha richiesto di scaricare il file <strong>' . cnx_email_escape($fileName) . '</strong>.</p>'
                . renderEmailPrimaryButton($wrappedApproveUrl, 'Gestisci richiesta', $brandColor);
        }
        $html = renderEmailLayout('Richiesta download file', $body, $vars, ['brandColor' => $brandColor]);
        $text = "Richiesta download file\n\nManager: $managerName\nRichiedente: $requesterName\nFile: $fileName\n\nGestisci richiesta: $wrappedApproveUrl\n";
        return sendEmail($to, 'Richiesta download file - ' . $tenantName, $html, $text, ['context' => array_merge(['action' => 'file_download_request'], $context)]);
    } catch (Throwable $e) {
        error_log('[file_download_email] send request failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send file download decision email (approved/rejected) to requester.
 */
function cnx_send_file_download_decision_email(
    string $to,
    string $requesterName,
    string $tenantName,
    string $fileName,
    bool $approved,
    string $downloadUrl,
    string $note,
    array $context = []
): bool {
    try {
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
        $brandColor = '#1a2332';
        $wrappedDownloadUrl = function_exists('cnx_email_wrap_link_for_login')
            ? cnx_email_wrap_link_for_login($downloadUrl)
            : $downloadUrl;
        $vars = [
            'BASE_URL' => $baseUrl,
            'TENANT_NAME' => $tenantName,
            'YEAR' => date('Y'),
            'REQUESTER_NAME' => $requesterName,
            'FILE_NAME' => $fileName,
            'NOTE' => $note,
            'DOWNLOAD_URL' => $wrappedDownloadUrl,
            'CTA_BUTTON' => $approved ? renderEmailPrimaryButton($wrappedDownloadUrl, 'Scarica file (1 volta)', $brandColor) : '',
        ];
        $templatePath = $approved
            ? __DIR__ . '/../includes/email_templates/files/file_download_approved.html'
            : __DIR__ . '/../includes/email_templates/files/file_download_rejected.html';
        $body = cnx_render_email_template_file($templatePath, $vars, ['remove_unknown_placeholders' => true]);
        if ($body === '') {
            $body = '<p>Ciao <strong>' . cnx_email_escape($requesterName) . '</strong>,</p>';
            if ($approved) {
                $body .= '<p>La tua richiesta per scaricare il file <strong>' . cnx_email_escape($fileName) . '</strong> è stata approvata.</p>'
                    . renderEmailPrimaryButton($wrappedDownloadUrl, 'Scarica file (1 volta)', $brandColor);
            } else {
                $body .= '<p>La tua richiesta per scaricare il file <strong>' . cnx_email_escape($fileName) . '</strong> è stata rifiutata.</p>';
                if ($note !== '') {
                    $body .= '<p><strong>Motivo:</strong> ' . cnx_email_escape($note) . '</p>';
                }
            }
        }
        $title = $approved ? 'Richiesta approvata' : 'Richiesta rifiutata';
        $html = renderEmailLayout($title, $body, $vars, ['brandColor' => $brandColor]);
        $text = $approved
            ? "Richiesta approvata\n\nFile: $fileName\nDownload (1 volta): $wrappedDownloadUrl\n"
            : "Richiesta rifiutata\n\nFile: $fileName\nMotivo: $note\n";
        return sendEmail($to, $title . ' - download file', $html, $text, ['context' => array_merge(['action' => $approved ? 'file_download_approved' : 'file_download_rejected'], $context)]);
    } catch (Throwable $e) {
        error_log('[file_download_email] send decision failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send decision email (approved/rejected) to requester.
 */
function cnx_send_folder_zip_decision_email(
    string $to,
    string $requesterName,
    string $tenantName,
    string $folderName,
    bool $approved,
    string $downloadUrl,
    string $note,
    array $context = []
): bool {
    try {
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
        $brandColor = '#1a2332';
        $wrappedDownloadUrl = function_exists('cnx_email_wrap_link_for_login')
            ? cnx_email_wrap_link_for_login($downloadUrl)
            : $downloadUrl;
        $vars = [
            'BASE_URL' => $baseUrl,
            'TENANT_NAME' => $tenantName,
            'YEAR' => date('Y'),
            'REQUESTER_NAME' => $requesterName,
            'FOLDER_NAME' => $folderName,
            'NOTE' => $note,
            'DOWNLOAD_URL' => $wrappedDownloadUrl,
            'CTA_BUTTON' => $approved ? renderEmailPrimaryButton($wrappedDownloadUrl, 'Scarica ZIP (1 volta)', $brandColor) : '',
        ];
        $templatePath = $approved
            ? __DIR__ . '/../includes/email_templates/files/folder_zip_download_approved.html'
            : __DIR__ . '/../includes/email_templates/files/folder_zip_download_rejected.html';
        $body = cnx_render_email_template_file($templatePath, $vars, ['remove_unknown_placeholders' => true]);
        if ($body === '') {
            $body = '<p>Ciao <strong>' . cnx_email_escape($requesterName) . '</strong>,</p>';
            if ($approved) {
                $body .= '<p>La tua richiesta per scaricare come ZIP la cartella <strong>' . cnx_email_escape($folderName) . '</strong> è stata approvata.</p>'
                    . renderEmailPrimaryButton($wrappedDownloadUrl, 'Scarica ZIP (1 volta)', $brandColor);
            } else {
                $body .= '<p>La tua richiesta per scaricare come ZIP la cartella <strong>' . cnx_email_escape($folderName) . '</strong> è stata rifiutata.</p>';
                if ($note !== '') {
                    $body .= '<p><strong>Motivo:</strong> ' . cnx_email_escape($note) . '</p>';
                }
            }
        }
        $title = $approved ? 'Richiesta approvata' : 'Richiesta rifiutata';
        $html = renderEmailLayout($title, $body, $vars, ['brandColor' => $brandColor]);
        $text = $approved
            ? "Richiesta approvata\n\nCartella: $folderName\nDownload (1 volta): $wrappedDownloadUrl\n"
            : "Richiesta rifiutata\n\nCartella: $folderName\nMotivo: $note\n";
        return sendEmail($to, $title . ' - download ZIP cartella', $html, $text, ['context' => array_merge(['action' => $approved ? 'folder_zip_approved' : 'folder_zip_rejected'], $context)]);
    } catch (Throwable $e) {
        error_log('[folder_zip_email] send decision failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Table exists helper (MySQL).
 */
function cnx_table_exists(PDO $pdo, string $table): bool {
    try {
        // Preferred: try a lightweight query against the table.
        // This avoids false negatives when SHOW TABLES is restricted on some hosts.
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->query("SELECT 1 FROM $quoted LIMIT 1");
        return true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();

        // MySQL "table doesn't exist" (SQLSTATE 42S02)
        $isMissing = (stripos($msg, 'doesn\'t exist') !== false)
            || (stripos($msg, 'Base table or view not found') !== false)
            || ($code === '42S02');
        if ($isMissing) {
            return false;
        }

        // Fallback: SHOW TABLES LIKE (some setups allow it even if SELECT fails)
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return ($stmt->fetch(PDO::FETCH_NUM) !== false);
        } catch (Throwable $e2) {
            error_log('[cnx_table_exists] failed for ' . $table . ': ' . $msg . ' | fallback: ' . $e2->getMessage());
            return false;
        }
    }
}

/**
 * Determine whether folder_zip_download_requests table is usable.
 * Distinguishes between "missing migration" and "DB permission/config issue".
 *
 * @return array{ready:bool,missing:bool,error?:string,code?:string}
 */
function cnx_folder_zip_requests_table_status(PDO $pdo): array {
    $table = 'folder_zip_download_requests';
    try {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->query("SELECT 1 FROM $quoted LIMIT 1");
        return ['ready' => true, 'missing' => false];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        $isMissing = (stripos($msg, 'doesn\'t exist') !== false)
            || (stripos($msg, 'Base table or view not found') !== false)
            || ($code === '42S02');
        if ($isMissing) {
            return ['ready' => false, 'missing' => true, 'error' => $msg, 'code' => $code];
        }
        return ['ready' => false, 'missing' => false, 'error' => $msg, 'code' => $code];
    }
}

/**
 * Determine whether file_download_requests table is usable.
 * Distinguishes between "missing migration" and "DB permission/config issue".
 *
 * @return array{ready:bool,missing:bool,error?:string,code?:string}
 */
function cnx_file_download_requests_table_status(PDO $pdo): array {
    $table = 'file_download_requests';
    try {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->query("SELECT 1 FROM $quoted LIMIT 1");
        return ['ready' => true, 'missing' => false];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        $isMissing = (stripos($msg, 'doesn\'t exist') !== false)
            || (stripos($msg, 'Base table or view not found') !== false)
            || ($code === '42S02');
        if ($isMissing) {
            return ['ready' => false, 'missing' => true, 'error' => $msg, 'code' => $code];
        }
        return ['ready' => false, 'missing' => false, 'error' => $msg, 'code' => $code];
    }
}

/**
 * JSON error helper for download endpoint (which might not have JSON headers set).
 */
function cnx_json_error(int $code, string $message, array $data = []): void {
    while (ob_get_level()) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'data' => $data ?: null,
    ]);
    exit;
}

/**
 * Download a folder as ZIP (tenant-safe traversal by DB folder tree).
 *
 * @throws Exception
 */
function cnx_download_folder_as_zip(PDO $pdo, array $folderRow, int $folderId): void {
    if (!class_exists('ZipArchive')) {
        apiError('Download cartella non disponibile: ZipArchive non installato sul server', 501);
    }

    $tenantId = (int)($folderRow['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        apiError('Tenant non valido per la cartella', 500);
    }

    // Ensure it's really a folder
    if ((int)($folderRow['is_folder'] ?? 0) !== 1) {
        apiError('Elemento non è una cartella', 400);
    }

    $folderName = (string)($folderRow['name'] ?? 'cartella');
    $zipDisplayName = cnx_sanitize_download_filename($folderName) . '.zip';

    // Prepare temp zip file
    $tmpDir = (defined('TEMP_PATH') && is_string(TEMP_PATH) && TEMP_PATH !== '') ? TEMP_PATH : sys_get_temp_dir();
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    $tmpBase = rtrim((string)$tmpDir, '\\/') . DIRECTORY_SEPARATOR;
    $tmpZip = tempnam($tmpBase, 'cnx_zip_');
    if ($tmpZip === false) {
        throw new Exception('Impossibile creare file temporaneo per lo ZIP');
    }
    // Ensure .zip extension
    $zipPath = $tmpZip . '.zip';
    @rename($tmpZip, $zipPath);

    $zip = new ZipArchive();
    $openRes = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openRes !== true) {
        @unlink($zipPath);
        throw new Exception('Impossibile aprire lo ZIP (code=' . (string)$openRes . ')');
    }

    // Add root folder entry (optional, keeps structure)
    $rootDirName = cnx_sanitize_zip_entry_name($folderName);
    if ($rootDirName === '') {
        $rootDirName = 'cartella';
    }
    $rootPrefix = $rootDirName . '/';
    $zip->addEmptyDir($rootPrefix);

    // Build base path safety
    $tenantBaseDir = rtrim((string)UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR . $tenantId;
    $tenantBaseReal = realpath($tenantBaseDir) ?: null;
    if ($tenantBaseReal === null || !is_dir($tenantBaseReal)) {
        $zip->close();
        @unlink($zipPath);
        apiError('Directory del tenant non trovata', 500);
    }

    // Traverse folder tree by DB
    cnx_zip_add_folder_contents($pdo, $zip, $tenantId, $folderId, $rootPrefix, $tenantBaseReal);

    $zip->close();

    // Audit (non-blocking)
    try {
        logAudit('download_folder_zip', 'files', $folderId, [
            'filename' => $zipDisplayName,
            'tenant_id' => $tenantId,
        ]);
    } catch (Exception $e) {
        // ignore
    }

    // Clean output buffer completely before sending file
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipDisplayName . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    $handle = fopen($zipPath, 'rb');
    if ($handle === false) {
        @unlink($zipPath);
        apiError('Impossibile aprire lo ZIP', 500);
    }

    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);

    @unlink($zipPath);
    exit;
}

/**
 * Recursively add folder contents to zip using DB tree.
 *
 * @param string $zipPrefix Zip path prefix (must end with '/')
 * @param string $tenantBaseReal Realpath of tenant base dir
 */
function cnx_zip_add_folder_contents(PDO $pdo, ZipArchive $zip, int $tenantId, int $folderId, string $zipPrefix, string $tenantBaseReal): void {
    $stmt = $pdo->prepare(
        "SELECT id, name, is_folder, file_path
         FROM files
         WHERE tenant_id = ?
           AND folder_id = ?
           AND deleted_at IS NULL
         ORDER BY is_folder DESC, name ASC, id ASC"
    );
    $stmt->execute([$tenantId, $folderId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate names inside this zip directory
    $used = [];

    foreach ($children as $child) {
        $childId = (int)($child['id'] ?? 0);
        if ($childId <= 0) continue;

        $childNameRaw = (string)($child['name'] ?? '');
        $childIsFolder = ((int)($child['is_folder'] ?? 0) === 1);

        if ($childIsFolder) {
            $dirName = cnx_sanitize_zip_entry_name($childNameRaw);
            if ($dirName === '') {
                $dirName = 'cartella_' . $childId;
            }
            $dirName = cnx_zip_dedup_name($dirName, $used);
            $dirPrefix = $zipPrefix . $dirName . '/';
            $zip->addEmptyDir($dirPrefix);
            cnx_zip_add_folder_contents($pdo, $zip, $tenantId, $childId, $dirPrefix, $tenantBaseReal);
            continue;
        }

        // File entry
        $entryName = cnx_sanitize_zip_entry_name($childNameRaw);
        if ($entryName === '') {
            $entryName = 'file_' . $childId;
        }
        $entryName = cnx_zip_dedup_name($entryName, $used);

        $filePathDb = (string)($child['file_path'] ?? '');
        if ($filePathDb === '') {
            continue;
        }

        // Defensive: file_path for files should be a basename; if not, strip
        $filePathDb = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $filePathDb);
        $fileDiskName = basename($filePathDb);
        if ($fileDiskName === '' || $fileDiskName === '.' || $fileDiskName === '..') {
            continue;
        }

        $diskPath = $tenantBaseReal . DIRECTORY_SEPARATOR . $fileDiskName;
        $diskReal = realpath($diskPath);
        if ($diskReal === false) {
            continue;
        }
        // Ensure file is within tenant base dir
        if (strpos($diskReal, $tenantBaseReal) !== 0) {
            continue;
        }
        if (!is_file($diskReal) || !is_readable($diskReal)) {
            continue;
        }

        $zip->addFile($diskReal, $zipPrefix . $entryName);
    }
}

/**
 * Sanitize filename for download headers.
 */
function cnx_sanitize_download_filename(string $name): string {
    $name = trim($name);
    if ($name === '') return 'download';
    $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name);
    $name = preg_replace('/[^a-zA-Z0-9\\s._-]/u', '_', $name);
    $name = trim($name, ' .');
    return $name !== '' ? $name : 'download';
}

/**
 * Sanitize zip entry names (no directory traversal).
 */
function cnx_sanitize_zip_entry_name(string $name): string {
    $name = trim($name);
    $name = str_replace(["\0", '/', '\\'], '_', $name);
    $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name);
    $name = preg_replace('/\\s+/u', ' ', $name);
    $name = preg_replace('/[^a-zA-Z0-9\\s._-]/u', '_', $name);
    $name = trim($name, ' .');
    // Avoid special path segments
    if ($name === '.' || $name === '..') return '';
    return $name;
}

/**
 * Deduplicate names within one directory (zip).
 */
function cnx_zip_dedup_name(string $name, array &$used): string {
    $base = $name;
    $candidate = $base;
    $i = 2;
    while (isset($used[$candidate])) {
        $candidate = $base . ' (' . $i . ')';
        $i++;
        if ($i > 200) {
            // extreme fallback
            $candidate = $base . ' (' . uniqid('', false) . ')';
            break;
        }
    }
    $used[$candidate] = true;
    return $candidate;
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

    // Admin/Manager: verifica accesso tramite user_tenant_access (multi-tenant support)
    if ($user_role === 'admin' || $user_role === 'manager') {
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