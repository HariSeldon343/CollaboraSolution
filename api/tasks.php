<?php
/**
 * RESTful Task Management API for CollaboraNexio
 *
 * Comprehensive task management system with Kanban board support
 *
 * Endpoint: /api/tasks.php
 *
 * Core Endpoints:
 * - GET    ?list_id=X&filters     - Get filtered tasks
 * - GET    ?id=X                   - Get single task details
 * - POST   (body)                  - Create new task
 * - PUT    ?id=X                   - Full update of task
 * - PATCH  ?id=X                   - Partial update of task
 * - DELETE ?id=X                   - Delete task
 *
 * Action Endpoints:
 * - POST   ?id=X&action=comment    - Add comment
 * - POST   ?id=X&action=watch      - Start watching task
 * - POST   ?id=X&action=unwatch    - Stop watching task
 * - POST   ?id=X&action=assign     - Assign users
 * - POST   ?id=X&action=unassign   - Remove assignees
 * - POST   ?id=X&action=move       - Move task to different column/list
 * - POST   ?id=X&action=clone      - Duplicate task
 * - POST   ?id=X&action=complete   - Mark as complete
 * - POST   ?id=X&action=reopen     - Reopen completed task
 * - POST   ?id=X&action=log_time   - Log time entry
 * - GET    ?action=my_tasks        - Get current user's tasks
 * - GET    ?action=overdue         - Get overdue tasks
 * - GET    ?id=X&action=activity   - Get task activity feed
 * - POST   ?action=bulk_update     - Update multiple tasks
 * - GET    ?action=export          - Export tasks
 *
 * @version 1.0.0
 * @since PHP 8.0
 */

declare(strict_types=1);

// Error reporting configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Session configuration with security settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_start();

// Required security headers
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS headers for cross-origin requests
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
}

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/taskmanager.php';

// Initialize connections
try {
    $auth = new Auth();
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API initialization error: ' . $e->getMessage());
    sendErrorResponse(500, 'Server configuration error');
}

// Check authentication
if (!$auth->isLoggedIn()) {
    sendErrorResponse(401, 'Authentication required');
}

// Get current user and tenant information
$userId = $_SESSION['user_id'] ?? 0;
$tenantId = $_SESSION['tenant_id'] ?? 0;

if (!$userId || !$tenantId) {
    sendErrorResponse(401, 'Invalid session');
}

// Initialize TaskManager
try {
    $taskManager = new TaskManager($db, $tenantId, $userId);
} catch (Exception $e) {
    error_log('TaskManager initialization error: ' . $e->getMessage());
    sendErrorResponse(500, 'Task manager initialization failed');
}

// Get request method and parameters
$method = $_SERVER['REQUEST_METHOD'];
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

// Get request body for POST/PUT/PATCH
$inputData = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $inputData = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendErrorResponse(400, 'Invalid JSON in request body');
        }
    }
}

/**
 * Send standardized JSON response
 */
function sendResponse(
    bool $success,
    $data,
    string $message,
    int $httpCode = 200,
    array $metadata = []
): void {
    http_response_code($httpCode);

    $response = [
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'metadata' => array_merge([
            'timestamp' => date('c')
        ], $metadata)
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Send error response
 */
function sendErrorResponse(int $httpCode, string $message, $data = null): void {
    sendResponse(false, $data, $message, $httpCode);
}

/**
 * Validate required fields
 */
function validateRequired(array $data, array $required): ?string {
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return "Field '$field' is required";
        }
    }
    return null;
}

/**
 * Convert task data for API response
 */
function formatTaskForResponse(array $task, PDO $db, int $tenantId): array {
    // Get assignees
    $assignees = [];
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.name, u.avatar
        FROM task_assignees ta
        JOIN users u ON ta.user_id = u.id
        WHERE ta.task_id = :task_id AND ta.tenant_id = :tenant_id
    ");
    $stmt->execute([':task_id' => $task['id'], ':tenant_id' => $tenantId]);
    $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get watchers
    $watchers = [];
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.name
        FROM task_watchers tw
        JOIN users u ON tw.user_id = u.id
        WHERE tw.task_id = :task_id AND tw.tenant_id = :tenant_id
    ");
    $stmt->execute([':task_id' => $task['id'], ':tenant_id' => $tenantId]);
    $watchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tags
    $tags = [];
    $stmt = $db->prepare("
        SELECT tg.name
        FROM task_tags tt
        JOIN tags tg ON tt.tag_id = tg.id
        WHERE tt.task_id = :task_id AND tt.tenant_id = :tenant_id
    ");
    $stmt->execute([':task_id' => $task['id'], ':tenant_id' => $tenantId]);
    $tags = $stmt->fetchColumn();

    // Get dependencies
    $dependencies = [];
    $stmt = $db->prepare("
        SELECT depends_on_id as depends_on, dependency_type as type
        FROM task_dependencies
        WHERE task_id = :task_id AND tenant_id = :tenant_id
    ");
    $stmt->execute([':task_id' => $task['id'], ':tenant_id' => $tenantId]);
    $dependencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count attachments and comments
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM task_attachments WHERE task_id = :task_id1 AND tenant_id = :tenant_id1) as attachments_count,
            (SELECT COUNT(*) FROM task_comments WHERE task_id = :task_id2 AND tenant_id = :tenant_id2) as comments_count
    ");
    $stmt->execute([
        ':task_id1' => $task['id'], ':tenant_id1' => $tenantId,
        ':task_id2' => $task['id'], ':tenant_id2' => $tenantId
    ]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get creator info
    $creator = null;
    if ($task['created_by']) {
        $stmt = $db->prepare("SELECT id as user_id, name FROM users WHERE id = :id");
        $stmt->execute([':id' => $task['created_by']]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Calculate permissions
    global $userId;
    $canEdit = ($task['created_by'] == $userId) || in_array($userId, array_column($assignees, 'user_id'));
    $canDelete = ($task['created_by'] == $userId);

    return [
        'id' => (int)$task['id'],
        'title' => $task['title'],
        'description' => $task['description'],
        'list_id' => (int)($task['board_id'] ?? 0),
        'column_id' => (int)($task['column_id'] ?? 0),
        'position' => (int)($task['position'] ?? 0),
        'status' => $task['status'] ?? 'pending',
        'priority' => (int)($task['priority'] ?? 2),
        'due_date' => $task['due_date'] ? date('c', strtotime($task['due_date'])) : null,
        'start_date' => $task['start_date'] ?? null,
        'estimated_hours' => (float)($task['estimated_hours'] ?? 0),
        'actual_hours' => (float)($task['actual_hours'] ?? 0),
        'progress' => (int)($task['progress_percentage'] ?? 0),
        'assignees' => $assignees,
        'watchers' => $watchers,
        'parent_id' => $task['parent_task_id'] ? (int)$task['parent_task_id'] : null,
        'subtasks' => [], // Would need recursive query
        'dependencies' => $dependencies,
        'tags' => $tags,
        'attachments_count' => (int)($counts['attachments_count'] ?? 0),
        'comments_count' => (int)($counts['comments_count'] ?? 0),
        'is_recurring' => (bool)($task['recurring_task_id'] ?? false),
        'created_by' => $creator,
        'created_at' => $task['created_at'] ? date('c', strtotime($task['created_at'])) : null,
        'updated_at' => $task['updated_at'] ? date('c', strtotime($task['updated_at'])) : null,
        'can_edit' => $canEdit,
        'can_delete' => $canDelete
    ];
}

// Route the request based on method and parameters
try {
    switch ($method) {
        case 'GET':
            if ($taskId) {
                // GET /api/tasks.php?id=X - Get single task
                handleGetTask($taskId);
            } elseif ($action) {
                // Handle special GET actions
                switch ($action) {
                    case 'my_tasks':
                        handleGetMyTasks();
                        break;
                    case 'overdue':
                        handleGetOverdueTasks();
                        break;
                    case 'activity':
                        if (!$taskId) {
                            sendErrorResponse(400, 'Task ID required for activity');
                        }
                        handleGetTaskActivity($taskId);
                        break;
                    case 'export':
                        handleExportTasks();
                        break;
                    default:
                        sendErrorResponse(400, 'Unknown action: ' . $action);
                }
            } else {
                // GET /api/tasks.php?filters - Get filtered tasks
                handleGetTasks();
            }
            break;

        case 'POST':
            if ($taskId && $action) {
                // Handle task actions
                switch ($action) {
                    case 'comment':
                        handleAddComment($taskId, $inputData);
                        break;
                    case 'watch':
                        handleWatchTask($taskId, $inputData);
                        break;
                    case 'unwatch':
                        handleUnwatchTask($taskId);
                        break;
                    case 'assign':
                        handleAssignUsers($taskId, $inputData);
                        break;
                    case 'unassign':
                        handleUnassignUsers($taskId, $inputData);
                        break;
                    case 'move':
                        handleMoveTask($taskId, $inputData);
                        break;
                    case 'clone':
                        handleCloneTask($taskId, $inputData);
                        break;
                    case 'complete':
                        handleCompleteTask($taskId);
                        break;
                    case 'reopen':
                        handleReopenTask($taskId);
                        break;
                    case 'log_time':
                        handleLogTime($taskId, $inputData);
                        break;
                    default:
                        sendErrorResponse(400, 'Unknown action: ' . $action);
                }
            } elseif ($action === 'bulk_update') {
                handleBulkUpdate($inputData);
            } else {
                // POST /api/tasks.php - Create new task
                handleCreateTask($inputData);
            }
            break;

        case 'PUT':
            if (!$taskId) {
                sendErrorResponse(400, 'Task ID required for update');
            }
            handleFullUpdateTask($taskId, $inputData);
            break;

        case 'PATCH':
            if (!$taskId) {
                sendErrorResponse(400, 'Task ID required for partial update');
            }
            handlePartialUpdateTask($taskId, $inputData);
            break;

        case 'DELETE':
            if (!$taskId) {
                sendErrorResponse(400, 'Task ID required for deletion');
            }
            handleDeleteTask($taskId);
            break;

        default:
            sendErrorResponse(405, 'Method not allowed');
    }
} catch (Exception $e) {
    error_log('Task API error: ' . $e->getMessage());
    sendErrorResponse(500, 'An error occurred: ' . $e->getMessage());
}

// ========================================
// REQUEST HANDLERS
// ========================================

/**
 * Handle GET /api/tasks.php - Get filtered tasks
 */
function handleGetTasks(): void {
    global $db, $tenantId, $taskManager;

    // Build filter query
    $where = ["t.tenant_id = :tenant_id", "t.deleted_at IS NULL"];
    $params = [':tenant_id' => $tenantId];
    $joins = [];

    // Filter by list_id
    if (isset($_GET['list_id'])) {
        $where[] = "t.board_id = :list_id";
        $params[':list_id'] = (int)$_GET['list_id'];
    }

    // Filter by column_id
    if (isset($_GET['column_id'])) {
        $where[] = "t.column_id = :column_id";
        $params[':column_id'] = (int)$_GET['column_id'];
    }

    // Filter by assignee_id
    if (isset($_GET['assignee_id'])) {
        $joins[] = "JOIN task_assignees ta ON ta.task_id = t.id";
        $where[] = "ta.user_id = :assignee_id";
        $params[':assignee_id'] = (int)$_GET['assignee_id'];
    }

    // Filter by status
    if (isset($_GET['status'])) {
        $statuses = ['todo' => 'pending', 'in_progress' => 'in_progress', 'done' => 'completed', 'blocked' => 'on_hold'];
        $status = $statuses[$_GET['status']] ?? $_GET['status'];
        $where[] = "t.status = :status";
        $params[':status'] = $status;
    }

    // Filter by priority
    if (isset($_GET['priority'])) {
        $where[] = "t.priority = :priority";
        $params[':priority'] = (int)$_GET['priority'];
    }

    // Filter by date range
    if (isset($_GET['due_date_from'])) {
        $where[] = "t.due_date >= :due_date_from";
        $params[':due_date_from'] = $_GET['due_date_from'];
    }
    if (isset($_GET['due_date_to'])) {
        $where[] = "t.due_date <= :due_date_to";
        $params[':due_date_to'] = $_GET['due_date_to'];
    }

    // Search filter
    if (isset($_GET['search']) && $_GET['search']) {
        $where[] = "(t.title LIKE :search OR t.description LIKE :search)";
        $params[':search'] = '%' . $_GET['search'] . '%';
    }

    // Sorting
    $sortField = $_GET['sort'] ?? 'position';
    $sortMap = [
        'position' => 't.position',
        'priority' => 't.priority DESC',
        'due_date' => 't.due_date',
        'created_at' => 't.created_at DESC'
    ];
    $orderBy = $sortMap[$sortField] ?? 't.position';

    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    // Count total tasks
    $countSql = "SELECT COUNT(DISTINCT t.id) as total FROM tasks t " .
                implode(' ', $joins) .
                " WHERE " . implode(' AND ', $where);

    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();

    // Fetch tasks
    $sql = "SELECT DISTINCT t.* FROM tasks t " .
           implode(' ', $joins) .
           " WHERE " . implode(' AND ', $where) .
           " ORDER BY $orderBy" .
           " LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $tasks = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tasks[] = formatTaskForResponse($row, $db, $tenantId);
    }

    // Include subtasks if requested
    if (isset($_GET['include_subtasks']) && $_GET['include_subtasks'] === 'true') {
        foreach ($tasks as &$task) {
            $subtasks = [];
            $stmt = $db->prepare("
                SELECT * FROM tasks
                WHERE parent_task_id = :parent_id
                AND tenant_id = :tenant_id
                AND deleted_at IS NULL
                ORDER BY position
            ");
            $stmt->execute([':parent_id' => $task['id'], ':tenant_id' => $tenantId]);
            while ($subtask = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $subtasks[] = formatTaskForResponse($subtask, $db, $tenantId);
            }
            $task['subtasks'] = $subtasks;
        }
    }

    sendResponse(true, [
        'tasks' => $tasks,
        'pagination' => [
            'total' => (int)$totalCount,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int)ceil($totalCount / $limit)
        ]
    ], 'Tasks retrieved successfully');
}

/**
 * Handle GET /api/tasks.php?id=X - Get single task
 */
function handleGetTask(int $taskId): void {
    global $taskManager, $db, $tenantId;

    try {
        $stmt = $db->prepare("
            SELECT * FROM tasks
            WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $taskId, ':tenant_id' => $tenantId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            sendErrorResponse(404, 'Task not found');
        }

        $formattedTask = formatTaskForResponse($task, $db, $tenantId);

        // Include subtasks
        $subtasks = [];
        $stmt = $db->prepare("
            SELECT * FROM tasks
            WHERE parent_task_id = :parent_id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
            ORDER BY position
        ");
        $stmt->execute([':parent_id' => $taskId, ':tenant_id' => $tenantId]);
        while ($subtask = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subtasks[] = formatTaskForResponse($subtask, $db, $tenantId);
        }
        $formattedTask['subtasks'] = $subtasks;

        sendResponse(true, $formattedTask, 'Task retrieved successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to retrieve task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php - Create new task
 */
function handleCreateTask(array $data): void {
    global $taskManager;

    // Validate required fields
    $error = validateRequired($data, ['list_id', 'title']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    // Map API fields to TaskManager fields
    $taskData = [
        'board_id' => $data['list_id'],
        'column_id' => $data['column_id'] ?? null,
        'title' => $data['title'],
        'description' => $data['description'] ?? null,
        'priority' => $data['priority'] ?? 2,
        'due_date' => $data['due_date'] ?? null,
        'start_date' => $data['start_date'] ?? null,
        'estimated_hours' => $data['estimated_hours'] ?? null,
        'parent_task_id' => $data['parent_id'] ?? null,
        'assignees' => $data['assignees'] ?? [],
        'tags' => $data['tags'] ?? [],
        'auto_assign' => false
    ];

    try {
        $taskId = $taskManager->createTask($taskData);

        // Handle additional fields
        if (isset($data['watchers'])) {
            foreach ($data['watchers'] as $watcherId) {
                $taskManager->addWatcher($taskId, $watcherId);
            }
        }

        if (isset($data['dependencies'])) {
            foreach ($data['dependencies'] as $dep) {
                $taskManager->addDependency($taskId, $dep['depends_on'], $dep['type'] ?? 'FS');
            }
        }

        // Retrieve and return the created task
        global $db, $tenantId;
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([':id' => $taskId, ':tenant_id' => $tenantId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse(true, formatTaskForResponse($task, $db, $tenantId), 'Task created successfully', 201);
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to create task: ' . $e->getMessage());
    }
}

/**
 * Handle PUT /api/tasks.php?id=X - Full update
 */
function handleFullUpdateTask(int $taskId, array $data): void {
    global $taskManager;

    // Validate required fields for full update
    $error = validateRequired($data, ['title']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    try {
        $updateData = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 2,
            'due_date' => $data['due_date'] ?? null,
            'estimated_hours' => $data['estimated_hours'] ?? null,
            'status' => mapApiStatusToInternal($data['status'] ?? 'pending'),
            'progress_percentage' => $data['progress'] ?? 0
        ];

        $success = $taskManager->updateTask($taskId, $updateData);

        if (!$success) {
            sendErrorResponse(500, 'Failed to update task');
        }

        // Update assignees if provided
        if (isset($data['assignees'])) {
            $taskManager->assignToUsers($taskId, $data['assignees']);
        }

        // Retrieve and return updated task
        global $db, $tenantId;
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([':id' => $taskId, ':tenant_id' => $tenantId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse(true, formatTaskForResponse($task, $db, $tenantId), 'Task fully updated');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to update task: ' . $e->getMessage());
    }
}

/**
 * Handle PATCH /api/tasks.php?id=X - Partial update
 */
function handlePartialUpdateTask(int $taskId, array $data): void {
    global $taskManager;

    if (empty($data)) {
        sendErrorResponse(400, 'No data provided for update');
    }

    try {
        $updateData = [];

        // Map only provided fields
        if (isset($data['title'])) $updateData['title'] = $data['title'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['priority'])) $updateData['priority'] = $data['priority'];
        if (isset($data['due_date'])) $updateData['due_date'] = $data['due_date'];
        if (isset($data['status'])) $updateData['status'] = mapApiStatusToInternal($data['status']);
        if (isset($data['progress'])) $updateData['progress_percentage'] = $data['progress'];

        // Handle position/column updates
        if (isset($data['position']) || isset($data['column_id'])) {
            global $db, $tenantId;

            if (isset($data['column_id'])) {
                $taskManager->moveTaskToColumn($taskId, $data['column_id']);
            }

            if (isset($data['position'])) {
                $stmt = $db->prepare("
                    UPDATE tasks SET position = :position
                    WHERE id = :id AND tenant_id = :tenant_id
                ");
                $stmt->execute([
                    ':position' => $data['position'],
                    ':id' => $taskId,
                    ':tenant_id' => $tenantId
                ]);
            }
        }

        if (!empty($updateData)) {
            $taskManager->updateTask($taskId, $updateData);
        }

        sendResponse(true, ['updated_fields' => array_keys($data)], 'Task updated');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to update task: ' . $e->getMessage());
    }
}

/**
 * Handle DELETE /api/tasks.php?id=X
 */
function handleDeleteTask(int $taskId): void {
    global $taskManager, $db, $tenantId;

    try {
        $deleteSubtasks = isset($_GET['delete_subtasks']) && $_GET['delete_subtasks'] === 'true';

        if ($deleteSubtasks) {
            // Delete all subtasks recursively
            $stmt = $db->prepare("
                UPDATE tasks SET deleted_at = NOW()
                WHERE parent_task_id = :parent_id AND tenant_id = :tenant_id
            ");
            $stmt->execute([':parent_id' => $taskId, ':tenant_id' => $tenantId]);
        }

        $success = $taskManager->deleteTask($taskId);

        if (!$success) {
            sendErrorResponse(500, 'Failed to delete task');
        }

        sendResponse(true, null, 'Task deleted successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to delete task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=comment
 */
function handleAddComment(int $taskId, array $data): void {
    global $taskManager, $db, $tenantId, $userId;

    $error = validateRequired($data, ['content']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    try {
        $commentId = $taskManager->addComment(
            $taskId,
            $data['content'],
            $data['mentions'] ?? []
        );

        // Handle attachments if provided
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachmentId) {
                $stmt = $db->prepare("
                    INSERT INTO comment_attachments (comment_id, attachment_id, tenant_id)
                    VALUES (:comment_id, :attachment_id, :tenant_id)
                ");
                $stmt->execute([
                    ':comment_id' => $commentId,
                    ':attachment_id' => $attachmentId,
                    ':tenant_id' => $tenantId
                ]);
            }
        }

        // Get user info for response
        $stmt = $db->prepare("SELECT name FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $userName = $stmt->fetchColumn();

        // Get mention details
        $mentions = [];
        if (isset($data['mentions']) && is_array($data['mentions'])) {
            foreach ($data['mentions'] as $mentionId) {
                $stmt = $db->prepare("SELECT id as user_id, name FROM users WHERE id = :id");
                $stmt->execute([':id' => $mentionId]);
                $mention = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($mention) {
                    $mention['notified'] = true;
                    $mentions[] = $mention;
                }
            }
        }

        sendResponse(true, [
            'comment_id' => $commentId,
            'content' => $data['content'],
            'mentions' => $mentions,
            'created_at' => date('c')
        ], 'Comment added successfully', 201);
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to add comment: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=watch
 */
function handleWatchTask(int $taskId, array $data): void {
    global $taskManager, $userId, $db, $tenantId;

    try {
        $success = $taskManager->addWatcher($taskId, $userId, $data['reason'] ?? '');

        // Store notification preferences if provided
        if (isset($data['notification_preferences']) && is_array($data['notification_preferences'])) {
            $stmt = $db->prepare("
                UPDATE task_watchers
                SET notification_preferences = :prefs
                WHERE task_id = :task_id AND user_id = :user_id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':prefs' => json_encode($data['notification_preferences']),
                ':task_id' => $taskId,
                ':user_id' => $userId,
                ':tenant_id' => $tenantId
            ]);
        }

        if (!$success) {
            sendErrorResponse(500, 'Failed to add watcher');
        }

        sendResponse(true, null, 'You are now watching this task');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to watch task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=unwatch
 */
function handleUnwatchTask(int $taskId): void {
    global $db, $tenantId, $userId;

    try {
        $stmt = $db->prepare("
            DELETE FROM task_watchers
            WHERE task_id = :task_id AND user_id = :user_id AND tenant_id = :tenant_id
        ");
        $success = $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $tenantId
        ]);

        if (!$success) {
            sendErrorResponse(500, 'Failed to remove watcher');
        }

        sendResponse(true, null, 'You are no longer watching this task');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to unwatch task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=assign
 */
function handleAssignUsers(int $taskId, array $data): void {
    global $taskManager;

    $error = validateRequired($data, ['user_ids']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    if (!is_array($data['user_ids'])) {
        sendErrorResponse(400, 'user_ids must be an array');
    }

    try {
        $success = $taskManager->assignToUsers($taskId, $data['user_ids']);

        if (!$success) {
            sendErrorResponse(500, 'Failed to assign users');
        }

        // Send notifications if requested
        if (isset($data['notify']) && $data['notify'] === true) {
            // Notification logic would go here
        }

        sendResponse(true, null, 'Users assigned successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to assign users: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=unassign
 */
function handleUnassignUsers(int $taskId, array $data): void {
    global $taskManager;

    $error = validateRequired($data, ['user_ids']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    try {
        foreach ($data['user_ids'] as $userId) {
            $taskManager->removeAssignee($taskId, $userId);
        }

        sendResponse(true, null, 'Users unassigned successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to unassign users: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=move
 */
function handleMoveTask(int $taskId, array $data): void {
    global $taskManager, $db, $tenantId;

    try {
        // Move to different column
        if (isset($data['target_column_id'])) {
            $taskManager->moveTaskToColumn($taskId, $data['target_column_id']);
        }

        // Move to different list/board
        if (isset($data['target_list_id'])) {
            $stmt = $db->prepare("
                UPDATE tasks
                SET board_id = :board_id
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':board_id' => $data['target_list_id'],
                ':id' => $taskId,
                ':tenant_id' => $tenantId
            ]);
        }

        // Update position
        if (isset($data['position'])) {
            $stmt = $db->prepare("
                UPDATE tasks
                SET position = :position
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':position' => $data['position'],
                ':id' => $taskId,
                ':tenant_id' => $tenantId
            ]);
        }

        sendResponse(true, null, 'Task moved successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to move task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=clone
 */
function handleCloneTask(int $taskId, array $data): void {
    global $db, $tenantId, $taskManager;

    try {
        // Get original task
        $stmt = $db->prepare("
            SELECT * FROM tasks
            WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $taskId, ':tenant_id' => $tenantId]);
        $originalTask = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$originalTask) {
            sendErrorResponse(404, 'Task not found');
        }

        // Create clone
        $cloneData = [
            'board_id' => $data['target_list_id'] ?? $originalTask['board_id'],
            'column_id' => $originalTask['column_id'],
            'title' => '[Clone] ' . $originalTask['title'],
            'description' => $originalTask['description'],
            'priority' => $originalTask['priority'],
            'due_date' => $originalTask['due_date'],
            'estimated_hours' => $originalTask['estimated_hours']
        ];

        $clonedId = $taskManager->createTask($cloneData);

        // Clone subtasks if requested
        if (isset($data['include_subtasks']) && $data['include_subtasks'] === true) {
            $stmt = $db->prepare("
                SELECT * FROM tasks
                WHERE parent_task_id = :parent_id AND tenant_id = :tenant_id
            ");
            $stmt->execute([':parent_id' => $taskId, ':tenant_id' => $tenantId]);

            while ($subtask = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $subtaskData = [
                    'board_id' => $cloneData['board_id'],
                    'column_id' => $subtask['column_id'],
                    'title' => $subtask['title'],
                    'description' => $subtask['description'],
                    'priority' => $subtask['priority'],
                    'parent_task_id' => $clonedId
                ];
                $taskManager->createTask($subtaskData);
            }
        }

        // Clone attachments if requested
        if (isset($data['include_attachments']) && $data['include_attachments'] === true) {
            $stmt = $db->prepare("
                INSERT INTO task_attachments (task_id, file_id, tenant_id)
                SELECT :new_task_id, file_id, tenant_id
                FROM task_attachments
                WHERE task_id = :old_task_id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':new_task_id' => $clonedId,
                ':old_task_id' => $taskId,
                ':tenant_id' => $tenantId
            ]);
        }

        // Get and return cloned task
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([':id' => $clonedId, ':tenant_id' => $tenantId]);
        $clonedTask = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse(true, formatTaskForResponse($clonedTask, $db, $tenantId), 'Task cloned successfully', 201);
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to clone task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=complete
 */
function handleCompleteTask(int $taskId): void {
    global $taskManager;

    try {
        $success = $taskManager->markAsComplete($taskId);

        if (!$success) {
            sendErrorResponse(500, 'Failed to complete task');
        }

        sendResponse(true, null, 'Task marked as complete');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to complete task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=reopen
 */
function handleReopenTask(int $taskId): void {
    global $taskManager;

    try {
        $success = $taskManager->reopenTask($taskId);

        if (!$success) {
            sendErrorResponse(500, 'Failed to reopen task');
        }

        sendResponse(true, null, 'Task reopened successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to reopen task: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?id=X&action=log_time
 */
function handleLogTime(int $taskId, array $data): void {
    global $taskManager;

    $error = validateRequired($data, ['hours']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    try {
        $success = $taskManager->logTime(
            $taskId,
            (float)$data['hours'],
            $data['description'] ?? ''
        );

        if (!$success) {
            sendErrorResponse(500, 'Failed to log time');
        }

        // Store additional fields if provided
        if (isset($data['date']) || isset($data['billable'])) {
            global $db, $tenantId, $userId;

            $stmt = $db->prepare("
                UPDATE time_entries
                SET date = COALESCE(:date, date),
                    billable = COALESCE(:billable, billable)
                WHERE task_id = :task_id
                AND user_id = :user_id
                AND tenant_id = :tenant_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':date' => $data['date'] ?? null,
                ':billable' => isset($data['billable']) ? (int)$data['billable'] : null,
                ':task_id' => $taskId,
                ':user_id' => $userId,
                ':tenant_id' => $tenantId
            ]);
        }

        sendResponse(true, null, 'Time logged successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to log time: ' . $e->getMessage());
    }
}

/**
 * Handle GET /api/tasks.php?action=my_tasks
 */
function handleGetMyTasks(): void {
    global $taskManager, $userId, $db, $tenantId;

    try {
        $tasks = $taskManager->getMyTasks($userId);

        $formattedTasks = [];
        foreach ($tasks as $task) {
            $formattedTasks[] = formatTaskForResponse($task, $db, $tenantId);
        }

        sendResponse(true, ['tasks' => $formattedTasks], 'My tasks retrieved successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to retrieve tasks: ' . $e->getMessage());
    }
}

/**
 * Handle GET /api/tasks.php?action=overdue
 */
function handleGetOverdueTasks(): void {
    global $taskManager, $db, $tenantId;

    try {
        $tasks = $taskManager->getOverdueTasks();

        $formattedTasks = [];
        foreach ($tasks as $task) {
            $formattedTasks[] = formatTaskForResponse($task, $db, $tenantId);
        }

        sendResponse(true, ['tasks' => $formattedTasks], 'Overdue tasks retrieved successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to retrieve overdue tasks: ' . $e->getMessage());
    }
}

/**
 * Handle GET /api/tasks.php?id=X&action=activity
 */
function handleGetTaskActivity(int $taskId): void {
    global $db, $tenantId;

    try {
        $stmt = $db->prepare("
            SELECT
                al.action,
                al.data,
                al.created_at,
                u.name as user_name,
                u.avatar as user_avatar
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'task'
            AND al.entity_id = :task_id
            AND al.tenant_id = :tenant_id
            ORDER BY al.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([':task_id' => $taskId, ':tenant_id' => $tenantId]);

        $activities = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $activities[] = [
                'action' => $row['action'],
                'data' => json_decode($row['data'], true),
                'created_at' => date('c', strtotime($row['created_at'])),
                'user' => [
                    'name' => $row['user_name'],
                    'avatar' => $row['user_avatar']
                ]
            ];
        }

        sendResponse(true, ['activities' => $activities], 'Activity feed retrieved successfully');
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to retrieve activity: ' . $e->getMessage());
    }
}

/**
 * Handle POST /api/tasks.php?action=bulk_update
 */
function handleBulkUpdate(array $data): void {
    global $taskManager, $db, $tenantId;

    $error = validateRequired($data, ['task_ids', 'updates']);
    if ($error) {
        sendErrorResponse(400, $error);
    }

    if (!is_array($data['task_ids']) || empty($data['task_ids'])) {
        sendErrorResponse(400, 'task_ids must be a non-empty array');
    }

    try {
        $successCount = 0;
        $failedTasks = [];

        foreach ($data['task_ids'] as $taskId) {
            try {
                // Apply updates
                if (isset($data['updates']['status'])) {
                    $data['updates']['status'] = mapApiStatusToInternal($data['updates']['status']);
                }

                if (isset($data['updates']['assignees'])) {
                    $taskManager->assignToUsers($taskId, $data['updates']['assignees']);
                    unset($data['updates']['assignees']);
                }

                if (!empty($data['updates'])) {
                    $taskManager->updateTask($taskId, $data['updates']);
                }

                $successCount++;
            } catch (Exception $e) {
                $failedTasks[] = ['task_id' => $taskId, 'error' => $e->getMessage()];
            }
        }

        sendResponse(true, [
            'updated' => $successCount,
            'failed' => $failedTasks
        ], "Bulk update completed: $successCount tasks updated");
    } catch (Exception $e) {
        sendErrorResponse(500, 'Bulk update failed: ' . $e->getMessage());
    }
}

/**
 * Handle GET /api/tasks.php?action=export
 */
function handleExportTasks(): void {
    global $db, $tenantId;

    $format = $_GET['format'] ?? 'json';

    if (!in_array($format, ['json', 'csv'])) {
        sendErrorResponse(400, 'Invalid export format. Use json or csv');
    }

    try {
        // Build query with same filters as handleGetTasks
        $where = ["t.tenant_id = :tenant_id", "t.deleted_at IS NULL"];
        $params = [':tenant_id' => $tenantId];

        if (isset($_GET['list_id'])) {
            $where[] = "t.board_id = :list_id";
            $params[':list_id'] = (int)$_GET['list_id'];
        }

        $sql = "SELECT t.*,
                GROUP_CONCAT(DISTINCT u.name) as assignees
                FROM tasks t
                LEFT JOIN task_assignees ta ON ta.task_id = t.id
                LEFT JOIN users u ON ta.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY t.id
                ORDER BY t.position";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'csv') {
            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="tasks_export_' . date('Y-m-d') . '.csv"');

            // Output CSV
            $output = fopen('php://output', 'w');

            // Header row
            fputcsv($output, [
                'ID', 'Title', 'Description', 'Status', 'Priority',
                'Due Date', 'Assignees', 'Progress', 'Created At'
            ]);

            // Data rows
            foreach ($tasks as $task) {
                fputcsv($output, [
                    $task['id'],
                    $task['title'],
                    $task['description'] ?? '',
                    $task['status'],
                    $task['priority'],
                    $task['due_date'] ?? '',
                    $task['assignees'] ?? '',
                    $task['progress_percentage'] ?? 0,
                    $task['created_at']
                ]);
            }

            fclose($output);
            exit;
        } else {
            // JSON export
            $formattedTasks = [];
            foreach ($tasks as $task) {
                $formattedTasks[] = formatTaskForResponse($task, $db, $tenantId);
            }

            sendResponse(true, ['tasks' => $formattedTasks], 'Tasks exported successfully');
        }
    } catch (Exception $e) {
        sendErrorResponse(500, 'Failed to export tasks: ' . $e->getMessage());
    }
}

/**
 * Map API status to internal status
 */
function mapApiStatusToInternal(string $apiStatus): string {
    $statusMap = [
        'todo' => 'pending',
        'in_progress' => 'in_progress',
        'done' => 'completed',
        'blocked' => 'on_hold'
    ];

    return $statusMap[$apiStatus] ?? $apiStatus;
}