<?php
/**
 * BUG-140: Unified Tasks API Endpoint
 *
 * SOLUZIONE: Endpoint unico per TUTTE le operazioni Tasks
 * Bypassa problemi di routing/CORS/Cloudflare che causavano 404 dal browser
 *
 * PROBLEM:
 * - curl funzionava (HEAD/GET requests)
 * - Browser POST falliva con 404 nonostante file esistesse
 * - Cloudflare/WAF potrebbe bloccare POST a .php con certi pattern
 * - CORS preflight potrebbe fallire su file diretti
 *
 * SOLUTION:
 * - Singolo endpoint che accetta TUTTE le richieste
 * - Action passata via JSON body o query string
 * - Processa internamente senza delegare a file esterni
 * - Stesso comportamento su localhost:8888 e app.nexiosolution.it
 *
 * USAGE:
 * POST /api/tasks.php
 * Body: { "action": "create", "title": "...", ... }
 *
 * Or: GET /api/tasks.php?action=list
 *
 * @version 1.0.0 - BUG-140
 */

// Prevent any output before headers
ob_start();

// Required includes
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/task_notification_helper.php';

// Initialize API environment
initializeApiEnvironment();

// BUG-140: Enhanced CORS headers for browser compatibility
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}

// BUG-140: Verify authentication
verifyApiAuthentication();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

// Effective role (BUG-146): treat user as super_admin if either session role key says so
$effectiveRole = $userInfo['role'] ?? 'user';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
    $effectiveRole = 'super_admin';
}

// BUG-140: Get request data
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true) ?: [];

// Priority: POST body > GET query > default
$action = $data['action']
    ?? $_GET['action']
    ?? $_POST['action']
    ?? 'list';

// Normalize action
$action = preg_replace('/\.php$/', '', $action);
$action = strtolower(trim($action));

// Log for debugging
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_log("[BUG-140] tasks.php - action: '$action', method: {$_SERVER['REQUEST_METHOD']}");
}

// Valid actions
$validActions = ['list', 'create', 'update', 'delete', 'assign', 'orphaned'];

if (!in_array($action, $validActions)) {
    ob_end_clean();
    api_error("Azione non valida: $action. Azioni disponibili: " . implode(', ', $validActions), 400);
}

// BUG-140: Verify CSRF for write operations
if (!in_array($action, ['list', 'orphaned'])) {
    verifyApiCsrfToken();
}

ob_end_clean();

// ============================================
// ACTION HANDLERS
// ============================================

try {
    switch ($action) {
        case 'list':
            handleList($db, $userInfo);
            break;

        case 'create':
            handleCreate($db, $userInfo, $data);
            break;

        case 'update':
            handleUpdate($db, $userInfo, $data);
            break;

        case 'delete':
            handleDelete($db, $userInfo, $data);
            break;

        case 'assign':
            handleAssign($db, $userInfo, $data);
            break;

        case 'orphaned':
            handleOrphaned($db, $userInfo);
            break;

        default:
            api_error("Azione non implementata: $action", 501);
    }
} catch (Exception $e) {
    error_log("[BUG-140] Task API error: " . $e->getMessage());
    api_error('Errore: ' . $e->getMessage(), 500);
}

// ============================================
// SCHEMA HELPERS (BUG-144): make API resilient across environments
// ============================================
function getTableColumns(Database $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $cols = [];
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM `$table`");
        foreach ($rows as $row) {
            if (!empty($row['Field'])) {
                $cols[$row['Field']] = true;
            }
        }
    } catch (Exception $e) {
        // If table doesn't exist or no permission, keep empty map
        error_log("[BUG-144] SHOW COLUMNS failed for {$table}: " . $e->getMessage());
        $cols = [];
    }

    $cache[$table] = $cols;
    return $cols;
}

function filterByExistingColumns(array $data, array $colMap): array {
    if (empty($colMap)) return $data; // If unknown schema, don't filter (best effort)
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($colMap[$k])) $out[$k] = $v;
    }
    return $out;
}

function deleteAllAssignmentsForTask(Database $db, int $tenantId, int $taskId, array $taCols): void {
    // Prefer soft-delete if schema supports it; otherwise hard-delete.
    if (isset($taCols['deleted_at'])) {
        $db->update('task_assignments', [
            'deleted_at' => date('Y-m-d H:i:s')
        ], [
            'task_id' => $taskId,
            'tenant_id' => $tenantId
        ]);
        return;
    }
    $db->query('DELETE FROM task_assignments WHERE task_id = ? AND tenant_id = ?', [$taskId, $tenantId]);
}

function insertTaskAssignment(Database $db, int $tenantId, int $taskId, int $userId, int $assignedBy, array $taCols): void {
    $base = [
        'tenant_id' => $tenantId,
        'task_id' => $taskId,
        'user_id' => $userId,
        'assigned_by' => $assignedBy,
        'assigned_at' => date('Y-m-d H:i:s'),
    ];

    // Optional columns across environments
    if (isset($taCols['role'])) $base['role'] = 'contributor';
    if (isset($taCols['created_at'])) $base['created_at'] = date('Y-m-d H:i:s');
    if (isset($taCols['updated_at'])) $base['updated_at'] = date('Y-m-d H:i:s');
    if (isset($taCols['deleted_at'])) $base['deleted_at'] = null;

    $db->insert('task_assignments', filterByExistingColumns($base, $taCols));
}

function getAssignmentState(Database $db, int $tenantId, int $taskId, array $taCols): array {
    $hasTaDeletedAt = isset($taCols['deleted_at']);

    $tasksCols = getTableColumns($db, 'tasks');
    $taskNotDeleted = isset($tasksCols['deleted_at']) ? " AND (deleted_at IS NULL OR deleted_at = '')" : '';
    $task = $db->fetchOne("SELECT id, assigned_to FROM tasks WHERE id = ? AND tenant_id = ?{$taskNotDeleted}", [$taskId, $tenantId]);
    if (!$task) {
        return [
            'task_id' => $taskId,
            'assigned_to' => null,
            'assignee_ids' => [],
            'assignees_count' => 0
        ];
    }

    $whereTa = $hasTaDeletedAt ? ' AND deleted_at IS NULL' : '';
    $rows = $db->fetchAll("SELECT user_id FROM task_assignments WHERE task_id = ? AND tenant_id = ?{$whereTa} ORDER BY assigned_at ASC, id ASC", [$taskId, $tenantId]);
    $ids = [];
    foreach ($rows as $r) {
        if (isset($r['user_id'])) $ids[] = (int)$r['user_id'];
    }

    return [
        'task_id' => (int)$task['id'],
        'assigned_to' => isset($task['assigned_to']) ? (int)$task['assigned_to'] : null,
        'assignee_ids' => $ids,
        'assignees_count' => count($ids),
    ];
}

function insertTaskHistory(Database $db, int $tenantId, int $taskId, int $userId, string $action, ?string $fieldName = null, $oldValue = null, $newValue = null): void {
    $thCols = getTableColumns($db, 'task_history');
    if (empty($thCols)) {
        // If schema unknown, best-effort insert with common fields
        $thCols = [
            'tenant_id' => true,
            'task_id' => true,
            'user_id' => true,
            'action' => true,
            'field_name' => true,
            'old_value' => true,
            'new_value' => true,
            'ip_address' => true,
            'user_agent' => true,
            'created_at' => true,
        ];
    }

    $data = [
        'tenant_id' => $tenantId,
        'task_id' => $taskId,
        'user_id' => $userId,
        'action' => $action,
        // Some schemas require field_name; use '' as safe default
        'field_name' => $fieldName ?? '',
        'old_value' => is_string($oldValue) ? $oldValue : ($oldValue !== null ? json_encode($oldValue) : null),
        'new_value' => is_string($newValue) ? $newValue : ($newValue !== null ? json_encode($newValue) : null),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $db->insert('task_history', filterByExistingColumns($data, $thCols));
}

// ============================================
// LIST - Get all tasks
// ============================================
function handleList($db, $userInfo) {
    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
    $search = $_GET['search'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    // Validate sort
    if (!in_array($sortOrder, ['ASC', 'DESC'])) $sortOrder = 'DESC';
    $validSortFields = ['due_date', 'priority', 'created_at', 'updated_at', 'title', 'status'];
    if (!in_array($sortBy, $validSortFields)) $sortBy = 'created_at';

    // Effective tenant scoping:
    // - Non super_admin: always restricted to their tenant_id
    // - Super_admin: can view all tenants; if company_filter_id set, restrict to that tenant
    $tasksCols = getTableColumns($db, 'tasks');
    $hasTasksDeletedAt = isset($tasksCols['deleted_at']);

    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $filterTenantId = null;
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $filterTenantId = (int)$_SESSION['company_filter_id'];
        }
    }

    // Build WHERE clause
    $where = [];
    $params = [];
    if ($role !== 'super_admin') {
        $where[] = 't.tenant_id = ?';
        $params[] = (int)$userInfo['tenant_id'];
    } elseif ($filterTenantId !== null) {
        $where[] = 't.tenant_id = ?';
        $params[] = (int)$filterTenantId;
    }
    if ($hasTasksDeletedAt) {
        $where[] = "(t.deleted_at IS NULL OR t.deleted_at = '')";
    }

    if ($status) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }

    if ($priority) {
        $where[] = 't.priority = ?';
        $params[] = $priority;
    }

    if ($assignedTo !== null) {
        if ($assignedTo === 0) {
            $where[] = 't.assigned_to IS NULL';
        } else {
            $where[] = 't.assigned_to = ?';
            $params[] = $assignedTo;
        }
    }

    if ($search) {
        $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = !empty($where) ? implode(' AND ', $where) : '1=1';

    // Count
    $total = (int)$db->fetchOne("SELECT COUNT(*) as total FROM tasks t WHERE $whereClause", $params)['total'];

    // Schema-sensitive joins (BUG-144)
    $taCols = getTableColumns($db, 'task_assignments');
    $uCols = getTableColumns($db, 'users');
    $hasTaDeletedAt = isset($taCols['deleted_at']);
    $hasUserDeletedAt = isset($uCols['deleted_at']);

    $taActiveJoin = $hasTaDeletedAt ? ' AND ta.deleted_at IS NULL' : '';
    // Used after an explicit "AND" in SQL; must NOT start with AND
    $ta2ActiveWhere = $hasTaDeletedAt ? 'ta2.deleted_at IS NULL' : '1=1';
    $uActiveJoin = $hasUserDeletedAt ? ' AND u_assigned.deleted_at IS NULL' : '';
    $u2ActiveJoin = $hasUserDeletedAt ? ' AND u2.deleted_at IS NULL' : '';
    $uCreatorJoin = $hasUserDeletedAt ? ' AND u_creator.deleted_at IS NULL' : '';

    // Tenants join for super_admin "view all" label (safe join)
    $tnCols = getTableColumns($db, 'tenants');
    $tnJoin = '';
    $tnSelect = '';
    if (!empty($tnCols)) {
        $tnJoin = ' LEFT JOIN tenants tn ON tn.id = t.tenant_id ' . (isset($tnCols['deleted_at']) ? 'AND tn.deleted_at IS NULL' : '');
        $tnSelect = ', tn.name as tenant_name';
    }

    // Get tasks
    $sql = "
        SELECT
            t.*,
            -- Prefer explicit assigned_to name, else first valid assignee name
            COALESCE(
                u_assigned.name,
                (
                    SELECT u2.name
                    FROM task_assignments ta2
                    JOIN users u2 ON u2.id = ta2.user_id{$u2ActiveJoin}
                    WHERE ta2.task_id = t.id
                      AND {$ta2ActiveWhere}
                    ORDER BY ta2.assigned_at ASC, ta2.id ASC
                    LIMIT 1
                )
            ) AS assignee_name,
            u_creator.name as creator_name,
            GROUP_CONCAT(DISTINCT ta.user_id) as assignee_ids,
            COUNT(DISTINCT ta.user_id) as assignees_count
            {$tnSelect}
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id{$uActiveJoin}
        LEFT JOIN users u_creator ON t.created_by = u_creator.id{$uCreatorJoin}
        LEFT JOIN task_assignments ta ON t.id = ta.task_id{$taActiveJoin}
        {$tnJoin}
        WHERE $whereClause
        GROUP BY t.id
        ORDER BY t.$sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $tasks = $db->fetchAll($sql, $params);

    api_success([
        'tasks' => $tasks,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ], 'Tasks retrieved');
}

// ============================================
// CREATE - Create a new task
// ============================================
function handleCreate($db, $userInfo, $data) {
    if (empty($data['title'])) {
        api_error('Il titolo e obbligatorio', 400);
    }

    $title = trim($data['title']);
    if (strlen($title) > 500) {
        api_error('Il titolo non puo superare 500 caratteri', 400);
    }

    $description = isset($data['description']) ? trim($data['description']) : null;
    $status = $data['status'] ?? 'todo';
    $priority = $data['priority'] ?? 'medium';
    $dueDate = !empty($data['due_date']) ? $data['due_date'] : null;
    $assignedTo = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
    $assignees = $data['assignees'] ?? [];
    $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;

    // BUG-142: Validate assigned_to exists in same tenant before insert
    if ($assignedTo !== null) {
        $validUser = $db->fetchOne(
            'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$assignedTo, $userInfo['tenant_id']]
        );
        if (!$validUser) {
            $assignedTo = null; // Reset invalid assigned_to
        }
    }

    // BUG-141+142: Se non c'e assigned_to ma ci sono assignees, usa il primo VALIDO come primary
    if (empty($assignedTo) && !empty($assignees) && is_array($assignees)) {
        foreach ($assignees as $candidateId) {
            $validUser = $db->fetchOne(
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$candidateId, $userInfo['tenant_id']]
            );
            if ($validUser) {
                $assignedTo = (int)$candidateId;
                break; // Use first valid assignee
            }
        }
    }

    // Validate status
    $validStatuses = ['todo', 'in_progress', 'review', 'done', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        api_error('Status non valido', 400);
    }

    // Validate priority
    $validPriorities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($priority, $validPriorities)) {
        api_error('Priorita non valida', 400);
    }

    // Validate due date
    if ($dueDate) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dueDate);
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d', $dueDate);
            if ($dt) {
                $dueDate = $dt->format('Y-m-d 23:59:59');
            } else {
                api_error('Formato data non valido (Y-m-d)', 400);
            }
        }
    }

    $tasksCols = getTableColumns($db, 'tasks');
    $taCols = getTableColumns($db, 'task_assignments');

    // Super admin must select an active company filter before creating
    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $effectiveTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $cf = $_SESSION['company_filter_id'] ?? null;
        if ($cf === null) {
            api_error('Seleziona prima un’azienda per creare un task', 400);
        }
        $effectiveTenantId = (int)$cf;
    }

    $db->beginTransaction();

    try {
        $insertTask = [
            'tenant_id' => (int)$effectiveTenantId,
            'project_id' => $projectId,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'assigned_to' => $assignedTo,
            'created_by' => (int)$userInfo['user_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $taskId = $db->insert('tasks', filterByExistingColumns($insertTask, $tasksCols));

        if (!$taskId) {
            throw new Exception('Errore creazione task');
        }

        // Create assignments
        // BUG-144: Validate each assignee exists in same tenant before insert (and do not silently succeed with 0 valid)
        $validAssignees = [];
        if (!empty($assignees) && is_array($assignees)) {
            foreach ($assignees as $assigneeId) {
                // Verify user exists and belongs to same tenant (prevents FK violation)
                $user = $db->fetchOne(
                    'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                    [$assigneeId, $effectiveTenantId]
                );

                if (!$user) {
                    continue; // Skip invalid/cross-tenant users
                }

                $validAssignees[] = (int)$assigneeId;
                insertTaskAssignment($db, (int)$effectiveTenantId, (int)$taskId, (int)$assigneeId, (int)$userInfo['user_id'], $taCols);
            }

            // If we have at least one valid assignee, ensure primary assigned_to is set
            if (!empty($validAssignees)) {
                $db->update('tasks', filterByExistingColumns([
                    'assigned_to' => $validAssignees[0],
                    'updated_at' => date('Y-m-d H:i:s')
                ], $tasksCols), ['id' => $taskId]);
            }
        }

        // Log history (schema-aware, tenant-correct)
        insertTaskHistory(
            $db,
            (int)$effectiveTenantId,
            (int)$taskId,
            (int)$userInfo['user_id'],
            'created',
            null,
            null,
            ['title' => $title, 'status' => $status]
        );

        // If user explicitly selected assignees but none are valid, fail loudly (prevents “success but not assigned”)
        if (!empty($assignees) && is_array($assignees) && empty($validAssignees)) {
            throw new Exception('Assegnatari non validi per questo tenant (seleziona un utente della stessa azienda)');
        }

        $db->commit();

        // Post-write verification (BUG-144)
        $assignmentState = getAssignmentState($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

        // Email notifications must run BEFORE api_success (api_success exits)
        if (!empty($validAssignees)) {
            try {
                $notifier = new TaskNotification();
                foreach ($validAssignees as $assigneeId) {
                    $notifier->sendTaskAssignedNotification((int)$taskId, (int)$assigneeId, (int)$userInfo['user_id']);
                }
            } catch (Exception $e) {
                error_log("[BUG-144] Task notification error (create): " . $e->getMessage());
            }
        }

        // Fetch created task
        $task = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);

        api_success([
            'task' => $task,
            'task_id' => $taskId,
            'assignment_state' => $assignmentState
        ], 'Task creato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// UPDATE - Update an existing task
// ============================================
function handleUpdate($db, $userInfo, $data) {
    $taskId = $data['id'] ?? null;
    if (!$taskId) {
        api_error('ID task richiesto', 400);
    }

    // Super admin must select an active company filter before updating
    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $effectiveTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $cf = $_SESSION['company_filter_id'] ?? null;
        if ($cf === null) {
            api_error('Seleziona prima un’azienda per modificare/assegnare task', 400);
        }
        $effectiveTenantId = (int)$cf;
    }

    // Verify task exists and belongs to tenant (deleted_at can be NULL or empty string depending on env)
    $tasksCols = getTableColumns($db, 'tasks');
    $taskNotDeleted = isset($tasksCols['deleted_at']) ? " AND (deleted_at IS NULL OR deleted_at = '')" : '';
    $task = $db->fetchOne(
        "SELECT * FROM tasks WHERE id = ? AND tenant_id = ?{$taskNotDeleted}",
        [$taskId, $effectiveTenantId]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    // Permission (BUG-148): non-super-admin can update only if creator or assignee
    // Assignee restriction: assignee (not creator) may only update progress + add comment + request due date change
    $uid = (int)($userInfo['user_id'] ?? 0);
    $isCreator = ((int)($task['created_by'] ?? 0) === $uid);
    $isAssignedTo = ((int)($task['assigned_to'] ?? 0) === $uid);

    $taColsPerm = getTableColumns($db, 'task_assignments');
    $taWhere = isset($taColsPerm['deleted_at']) ? ' AND deleted_at IS NULL' : '';
    $assRow = $db->fetchOne("SELECT id FROM task_assignments WHERE task_id = ? AND user_id = ?{$taWhere} LIMIT 1", [(int)$taskId, $uid]);
    $isAssignee = !empty($assRow);

    if ($role !== 'super_admin') {
        if (!$isCreator && !$isAssignedTo && !$isAssignee) {
            api_error('Non autorizzato a modificare questo task', 403);
        }
    }

    $isLimitedAssignee = ($role !== 'super_admin') && !$isCreator && ($isAssignedTo || $isAssignee);

    // Allowed fields for limited assignee
    if ($isLimitedAssignee) {
        $allowedKeys = ['id', 'action', 'progress', 'progress_percentage', 'comment', 'requested_due_date', 'request_reason', 'reopen_reason'];
        foreach ($data as $k => $_v) {
            if (!in_array($k, $allowedKeys, true)) {
                api_error('Permesso negato: puoi aggiornare solo avanzamento, aggiungere commenti o richiedere cambio scadenza', 403);
            }
        }
    }

    $tasksCols = getTableColumns($db, 'tasks');
    $taCols = getTableColumns($db, 'task_assignments');

    // Build update data
    $updateData = ['updated_at' => date('Y-m-d H:i:s')];
    $changes = [];
    $assigneeEmailOps = [
        'progress' => null,
        'comment' => null,
        'requested_due_date' => null,
        'request_reason' => null,
        'reopen_reason' => null,
        'reopened' => false
    ];

    if (!$isLimitedAssignee && isset($data['title'])) {
        $updateData['title'] = trim($data['title']);
        $changes['title'] = ['old' => $task['title'], 'new' => $updateData['title']];
    }

    if (!$isLimitedAssignee && isset($data['description'])) {
        $updateData['description'] = trim($data['description']);
    }

    if (!$isLimitedAssignee && isset($data['status'])) {
        $validStatuses = ['todo', 'in_progress', 'review', 'done', 'cancelled'];
        if (!in_array($data['status'], $validStatuses)) {
            api_error('Status non valido', 400);
        }
        $updateData['status'] = $data['status'];
        $changes['status'] = ['old' => $task['status'], 'new' => $updateData['status']];
    }

    if (!$isLimitedAssignee && isset($data['priority'])) {
        $validPriorities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($data['priority'], $validPriorities)) {
            api_error('Priorita non valida', 400);
        }
        $updateData['priority'] = $data['priority'];
        $changes['priority'] = ['old' => $task['priority'], 'new' => $updateData['priority']];
    }

    if (!$isLimitedAssignee && array_key_exists('due_date', $data)) {
        $updateData['due_date'] = !empty($data['due_date']) ? $data['due_date'] : null;
    }

    if (!$isLimitedAssignee && array_key_exists('assigned_to', $data)) {
        $updateData['assigned_to'] = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
    }

    // BUG-143: Fix column name - table has 'progress_percentage' not 'progress'
    if (isset($data['progress'])) {
        $progress = max(0, min(100, (int)$data['progress']));
        if ($isLimitedAssignee) {
            $progress = (int)(round($progress / 25) * 25);
            $progress = max(0, min(100, $progress));
        }
        $updateData['progress_percentage'] = $progress;
        $changes['progress_percentage'] = ['old' => (int)($task['progress_percentage'] ?? 0), 'new' => $progress];
        $assigneeEmailOps['progress'] = $progress;
    }
    if (isset($data['progress_percentage'])) {
        $progress = max(0, min(100, (int)$data['progress_percentage']));
        if ($isLimitedAssignee) {
            $progress = (int)(round($progress / 25) * 25);
            $progress = max(0, min(100, $progress));
        }
        $updateData['progress_percentage'] = $progress;
        $changes['progress_percentage'] = ['old' => (int)($task['progress_percentage'] ?? 0), 'new' => $progress];
        $assigneeEmailOps['progress'] = $progress;
    }

    // Progress -> status rules for limited assignee
    if ($isLimitedAssignee && array_key_exists('progress_percentage', $updateData)) {
        $oldProgress = (int)($task['progress_percentage'] ?? 0);
        $newProgress = (int)$updateData['progress_percentage'];
        $oldStatus = strtolower((string)($task['status'] ?? 'todo'));

        if ($newProgress >= 100) {
            if (($task['status'] ?? null) !== 'done') {
                $updateData['status'] = 'done';
                $changes['status'] = ['old' => ($task['status'] ?? null), 'new' => 'done'];
            }
        } else {
            // Reopen: if dropping below 100 from a completed task/progress
            if (($oldProgress >= 100 || $oldStatus === 'done') && $newProgress < 100) {
                $reason = trim((string)($data['reopen_reason'] ?? ''));
                if ($reason === '') {
                    api_error('Motivazione riapertura obbligatoria', 400);
                }
                $updateData['status'] = 'in_progress';
                $changes['status'] = ['old' => ($task['status'] ?? null), 'new' => 'in_progress'];
                $assigneeEmailOps['reopen_reason'] = $reason;
                $assigneeEmailOps['reopened'] = true;
            }
        }
    }

    $db->beginTransaction();

    try {
        $db->update('tasks', filterByExistingColumns($updateData, $tasksCols), ['id' => $taskId]);

        // Limited assignee: comment insertion (task_comments) + due date change request (task_history only)
        if ($isLimitedAssignee) {
            // Comment
            if (!empty($data['comment'])) {
                $tcCols = getTableColumns($db, 'task_comments');
                $insert = [
                    'tenant_id' => (int)$effectiveTenantId,
                    'task_id' => (int)$taskId,
                    'user_id' => (int)$uid,
                    'parent_comment_id' => null,
                    'content' => trim((string)$data['comment']),
                    'attachments' => null,
                    'is_edited' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'deleted_at' => null
                ];
                $db->insert('task_comments', filterByExistingColumns($insert, $tcCols));
                $assigneeEmailOps['comment'] = $insert['content'];

                insertTaskHistory(
                    $db,
                    (int)$effectiveTenantId,
                    (int)$taskId,
                    (int)$uid,
                    'commented',
                    'comment',
                    null,
                    ['len' => strlen($insert['content'])]
                );
            }

            // Due date request (does not change due_date)
            if (!empty($data['requested_due_date'])) {
                $assigneeEmailOps['requested_due_date'] = (string)$data['requested_due_date'];
                $assigneeEmailOps['request_reason'] = (string)($data['request_reason'] ?? '');
                insertTaskHistory(
                    $db,
                    (int)$effectiveTenantId,
                    (int)$taskId,
                    (int)$uid,
                    'due_date_change_requested',
                    'due_date',
                    $task['due_date'] ?? null,
                    [
                        'requested_due_date' => (string)$data['requested_due_date'],
                        'reason' => (string)($data['request_reason'] ?? '')
                    ]
                );
            }

            // Reopen history entry (explicit)
            if (!empty($assigneeEmailOps['reopened'])) {
                insertTaskHistory(
                    $db,
                    (int)$effectiveTenantId,
                    (int)$taskId,
                    (int)$uid,
                    'reopened',
                    'status',
                    ($task['status'] ?? null),
                    [
                        'status' => 'in_progress',
                        'reason' => (string)$assigneeEmailOps['reopen_reason'],
                        'progress' => (int)($updateData['progress_percentage'] ?? 0)
                    ]
                );
            }
        }

        // Update assignees if provided
        $validAssignees = [];
        if (isset($data['assignees']) && is_array($data['assignees'])) {
            // Remove old assignments (schema-aware)
            deleteAllAssignmentsForTask($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

            // Add new assignments
            // BUG-142: Validate each assignee exists in same tenant before insert
            foreach ($data['assignees'] as $assigneeId) {
                // Verify user exists and belongs to same tenant (prevents FK violation)
                $user = $db->fetchOne(
                    'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                    [$assigneeId, $effectiveTenantId]
                );

                if (!$user) {
                    continue; // Skip invalid/cross-tenant users
                }

                $validAssignees[] = (int)$assigneeId;
                insertTaskAssignment($db, (int)$effectiveTenantId, (int)$taskId, (int)$assigneeId, (int)$userInfo['user_id'], $taCols);
            }

            // BUG-142: Update assigned_to only if we have valid assignees
            if (!empty($validAssignees)) {
                $db->update('tasks', filterByExistingColumns([
                    'assigned_to' => $validAssignees[0],
                    'updated_at' => date('Y-m-d H:i:s')
                ], $tasksCols), ['id' => $taskId]);
            }

            // If user explicitly selected assignees but none are valid, fail loudly
            if (!empty($data['assignees']) && empty($validAssignees)) {
                throw new Exception('Assegnatari non validi per questo tenant (seleziona un utente della stessa azienda)');
            }
        }

        // Log history (schema-aware, tenant-correct)
        if (!empty($changes)) {
            insertTaskHistory(
                $db,
                (int)$effectiveTenantId,
                (int)$taskId,
                (int)$userInfo['user_id'],
                'updated',
                null,
                array_column($changes, 'old'),
                array_column($changes, 'new')
            );
        }

        $db->commit();

        // Post-write verification (BUG-144)
        $assignmentState = getAssignmentState($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

        // Email notifications must run BEFORE api_success (api_success exits)
        if (!empty($validAssignees)) {
            try {
                $notifier = new TaskNotification();
                foreach ($validAssignees as $assigneeId) {
                    $notifier->sendTaskAssignedNotification((int)$taskId, (int)$assigneeId, (int)$userInfo['user_id']);
                }
            } catch (Exception $e) {
                error_log("[BUG-144] Task notification error (update): " . $e->getMessage());
            }
        }

        // Confirmation emails to creator for limited assignee actions (best-effort)
        if ($isLimitedAssignee) {
            try {
                $notifier = new TaskNotification();
                if ($assigneeEmailOps['progress'] !== null) {
                    $notifier->sendTaskProgressUpdatedConfirmation((int)$taskId, (int)$uid, (int)$assigneeEmailOps['progress']);
                }
                if (!empty($assigneeEmailOps['comment'])) {
                    $notifier->sendTaskCommentedConfirmation((int)$taskId, (int)$uid, (string)$assigneeEmailOps['comment']);
                }
                if (!empty($assigneeEmailOps['requested_due_date'])) {
                    $notifier->sendTaskDueDateChangeRequestedConfirmation(
                        (int)$taskId,
                        (int)$uid,
                        (string)$assigneeEmailOps['requested_due_date'],
                        (string)($assigneeEmailOps['request_reason'] ?? '')
                    );
                }
                if (!empty($assigneeEmailOps['reopened'])) {
                    $notifier->sendTaskReopenedConfirmation(
                        (int)$taskId,
                        (int)$uid,
                        (string)$assigneeEmailOps['reopen_reason']
                    );
                }
            } catch (Exception $e) {
                error_log("[BUG-148] Task confirmation email error (update): " . $e->getMessage());
            }
        }

        // Fetch updated task
        $task = $db->fetchOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);

        api_success([
            'task' => $task,
            'assignment_state' => $assignmentState
        ], 'Task aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// DELETE - Soft delete a task
// ============================================
function handleDelete($db, $userInfo, $data) {
    $taskId = $data['id'] ?? null;
    if (!$taskId) {
        api_error('ID task richiesto', 400);
    }

    // Super admin must select an active company filter before deleting
    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $effectiveTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $cf = $_SESSION['company_filter_id'] ?? null;
        if ($cf === null) {
            api_error('Seleziona prima un’azienda per eliminare task', 400);
        }
        $effectiveTenantId = (int)$cf;
    }

    // Verify task exists and belongs to tenant
    $tasksCols = getTableColumns($db, 'tasks');
    $taskNotDeleted = isset($tasksCols['deleted_at']) ? " AND (deleted_at IS NULL OR deleted_at = '')" : '';
    $task = $db->fetchOne(
        "SELECT * FROM tasks WHERE id = ? AND tenant_id = ?{$taskNotDeleted}",
        [$taskId, $effectiveTenantId]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    $db->beginTransaction();

    try {
        // Soft delete task
        $db->update('tasks', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $taskId]);

        // Soft delete assignments
        // Soft-delete or hard-delete assignments (schema-aware)
        $taCols = getTableColumns($db, 'task_assignments');
        deleteAllAssignmentsForTask($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

        // Log history (schema-aware, tenant-correct)
        insertTaskHistory(
            $db,
            (int)$effectiveTenantId,
            (int)$taskId,
            (int)$userInfo['user_id'],
            'deleted',
            null,
            ['title' => $task['title']],
            null
        );

        $db->commit();

        api_success([
            'task_id' => $taskId
        ], 'Task eliminato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// ASSIGN - Assign users to a task
// ============================================
function handleAssign($db, $userInfo, $data) {
    $taskId = $data['task_id'] ?? $data['id'] ?? null;
    $assignees = $data['assignees'] ?? $data['user_ids'] ?? [];

    if (!$taskId) {
        api_error('ID task richiesto', 400);
    }

    if (empty($assignees) || !is_array($assignees)) {
        api_error('Lista assegnatari richiesta', 400);
    }

    // Super admin must select an active company filter before assigning
    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $effectiveTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $cf = $_SESSION['company_filter_id'] ?? null;
        if ($cf === null) {
            api_error('Seleziona prima un’azienda per assegnare task', 400);
        }
        $effectiveTenantId = (int)$cf;
    }

    // Verify task exists
    $tasksCols = getTableColumns($db, 'tasks');
    $taskNotDeleted = isset($tasksCols['deleted_at']) ? " AND (deleted_at IS NULL OR deleted_at = '')" : '';
    $task = $db->fetchOne(
        "SELECT * FROM tasks WHERE id = ? AND tenant_id = ?{$taskNotDeleted}",
        [$taskId, $effectiveTenantId]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    $taCols = getTableColumns($db, 'task_assignments');
    $tasksCols = getTableColumns($db, 'tasks');

    $db->beginTransaction();

    try {
        // Remove old assignments (schema-aware)
        deleteAllAssignmentsForTask($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

        // Add new assignments
        $validAssignees = [];
        foreach ($assignees as $assigneeId) {
            // Verify user exists
            $user = $db->fetchOne(
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$assigneeId, $effectiveTenantId]
            );

            if (!$user) {
                continue; // Skip invalid users
            }

            $validAssignees[] = (int)$assigneeId;
            insertTaskAssignment($db, (int)$effectiveTenantId, (int)$taskId, (int)$assigneeId, (int)$userInfo['user_id'], $taCols);
        }

        // Update primary assignee using first valid assignee (if any)
        if (!empty($validAssignees)) {
            $db->update('tasks', filterByExistingColumns([
                'assigned_to' => (int)$validAssignees[0],
                'updated_at' => date('Y-m-d H:i:s')
            ], $tasksCols), ['id' => $taskId]);
        }

        // If user explicitly selected assignees but none are valid, fail loudly
        if (!empty($assignees) && empty($validAssignees)) {
            throw new Exception('Assegnatari non validi per questo tenant (seleziona un utente della stessa azienda)');
        }

        $db->commit();

        // Post-write verification (BUG-144)
        $assignmentState = getAssignmentState($db, (int)$effectiveTenantId, (int)$taskId, $taCols);

        // Email notifications must run BEFORE api_success (api_success exits)
        if (!empty($validAssignees)) {
            try {
                $notifier = new TaskNotification();
                foreach ($validAssignees as $assigneeId) {
                    $notifier->sendTaskAssignedNotification((int)$taskId, (int)$assigneeId, (int)$userInfo['user_id']);
                }
            } catch (Exception $e) {
                error_log("[BUG-144] Task notification error (assign): " . $e->getMessage());
            }
        }

        api_success([
            'task_id' => $taskId,
            'assignees' => $assignees,
            'assignment_state' => $assignmentState
        ], 'Assegnazioni aggiornate');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// ORPHANED - Get tasks with deleted assignees
// ============================================
function handleOrphaned($db, $userInfo) {
    $role = $userInfo['role'] ?? 'user';
    if (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin') {
        $role = 'super_admin';
    }
    $filterTenantId = null;
    if ($role === 'super_admin') {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $filterTenantId = (int)$_SESSION['company_filter_id'];
        }
    }

    $where = [];
    $params = [];
    if ($role !== 'super_admin') {
        $where[] = 't.tenant_id = ?';
        $params[] = (int)$userInfo['tenant_id'];
    } elseif ($filterTenantId !== null) {
        $where[] = 't.tenant_id = ?';
        $params[] = (int)$filterTenantId;
    }
    $tasksCols = getTableColumns($db, 'tasks');
    if (isset($tasksCols['deleted_at'])) {
        $where[] = "(t.deleted_at IS NULL OR t.deleted_at = '')";
    }
    $where[] = 't.assigned_to IS NOT NULL';

    $tasks = $db->fetchAll("
        SELECT t.*, u.name as assignee_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
        WHERE " . implode(' AND ', $where) . "
          AND u.id IS NULL
        ORDER BY t.created_at DESC
    ", $params);

    api_success([
        'tasks' => $tasks,
        'count' => count($tasks)
    ], 'Orphaned tasks retrieved');
}
