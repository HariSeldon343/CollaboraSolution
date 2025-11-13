<?php
/**
 * Task Create API Endpoint
 * POST /api/tasks/create.php
 *
 * Creates a new task with validation and optional multi-user assignment
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/task_notification_helper.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Verify CSRF token for POST request
verifyApiCsrfToken();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        api_error('Invalid JSON data', 400);
    }

    // Validate required fields
    if (empty($data['title'])) {
        api_error('Il titolo è obbligatorio', 400);
    }

    // Sanitize and validate input
    $title = trim($data['title']);
    if (strlen($title) > 500) {
        api_error('Il titolo non può superare 500 caratteri', 400);
    }

    $description = isset($data['description']) ? trim($data['description']) : null;
    $status = $data['status'] ?? 'todo';
    $priority = $data['priority'] ?? 'medium';
    $dueDate = !empty($data['due_date']) ? $data['due_date'] : null;
    $estimatedHours = isset($data['estimated_hours']) ? (float)$data['estimated_hours'] : null;
    $parentTaskId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;  // Fixed: parent_id not parent_task_id
    $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
    $assignedTo = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
    $assignees = $data['assignees'] ?? []; // Array of user IDs for multi-assignment
    $tags = !empty($data['tags']) ? json_encode($data['tags']) : null;
    $attachments = !empty($data['attachments']) ? json_encode($data['attachments']) : null;

    // Validate status
    $validStatuses = ['todo', 'in_progress', 'review', 'done', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        api_error('Status non valido', 400);
    }

    // Validate priority
    $validPriorities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($priority, $validPriorities)) {
        api_error('Priorità non valida', 400);
    }

    // Validate due date format
    if ($dueDate) {
        $dueDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $dueDate);
        if (!$dueDateTime) {
            // Try date only format
            $dueDateTime = DateTime::createFromFormat('Y-m-d', $dueDate);
            if ($dueDateTime) {
                $dueDate = $dueDateTime->format('Y-m-d 23:59:59');
            } else {
                api_error('Formato data scadenza non valido (usare Y-m-d o Y-m-d H:i:s)', 400);
            }
        }
    }

    // Validate parent task exists and belongs to same tenant
    if ($parentTaskId) {
        $parentTask = $db->fetchOne(
            'SELECT id FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$parentTaskId, $userInfo['tenant_id']]
        );

        if (!$parentTask) {
            api_error('Task padre non trovato o non accessibile', 404);
        }
    }

    // Validate assigned_to user exists and belongs to same tenant
    if ($assignedTo) {
        $assignedUser = $db->fetchOne(
            'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$assignedTo, $userInfo['tenant_id']]
        );

        if (!$assignedUser) {
            api_error('Utente assegnatario non trovato o non accessibile', 404);
        }
    }

    // Validate all assignees exist and belong to same tenant
    if (!empty($assignees) && is_array($assignees)) {
        foreach ($assignees as $assigneeId) {
            $assignee = $db->fetchOne(
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$assigneeId, $userInfo['tenant_id']]
            );

            if (!$assignee) {
                api_error("Utente assegnatario con ID $assigneeId non trovato o non accessibile", 404);
            }
        }
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Insert task
        $taskId = $db->insert('tasks', [
            'tenant_id' => $userInfo['tenant_id'],
            'project_id' => $projectId,
            'parent_id' => $parentTaskId,  // Fixed: parent_id not parent_task_id
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'estimated_hours' => $estimatedHours,
            'assigned_to' => $assignedTo,
            'created_by' => $userInfo['user_id'],
            'tags' => $tags,
            'attachments' => $attachments,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if (!$taskId) {
            throw new Exception('Errore durante la creazione del task');
        }

        // Create task assignments for multiple assignees
        if (!empty($assignees) && is_array($assignees)) {
            foreach ($assignees as $assigneeId) {
                $db->insert('task_assignments', [
                    'tenant_id' => $userInfo['tenant_id'],
                    'task_id' => $taskId,
                    'user_id' => $assigneeId,
                    'assigned_by' => $userInfo['user_id'],
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }

        // Log to audit trail
        $db->insert('task_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'task_id' => $taskId,
            'user_id' => $userInfo['user_id'],
            'action' => 'created',
            'field_name' => null,
            'old_value' => null,
            'new_value' => json_encode([
                'title' => $title,
                'status' => $status,
                'priority' => $priority
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // BUG-047: Audit log task creation (non-blocking)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logCreate(
                $userInfo['user_id'],
                $userInfo['tenant_id'],
                'task',
                $taskId,
                "Creato task: $title",
                [
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority,
                    'status' => 'pending',
                    'assignees' => $assignees
                ]
            );
        } catch (Exception $auditEx) {
            error_log('[TASK_CREATE] Audit log failed: ' . $auditEx->getMessage());
        }

        // Fetch the created task with related data
        $task = $db->fetchOne(
            "SELECT
                t.*,
                u_assigned.name as assigned_to_name,
                u_creator.name as created_by_name
            FROM tasks t
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            LEFT JOIN users u_creator ON t.created_by = u_creator.id
            WHERE t.id = ?",
            [$taskId]
        );

        // Get assignees if any
        $task['assignees'] = $db->fetchAll(
            "SELECT
                ta.id as assignment_id,
                ta.user_id,
                u.name,
                u.email
            FROM task_assignments ta
            JOIN users u ON ta.user_id = u.id
            WHERE ta.task_id = ? AND ta.deleted_at IS NULL",
            [$taskId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        try {
            $notifier = new TaskNotification();

            // Collect all assignee user IDs
            $allAssignees = [];

            // Add primary assignee if set
            if ($assignedTo) {
                $allAssignees[] = $assignedTo;
            }

            // Add multi-assignees
            if (!empty($assignees) && is_array($assignees)) {
                foreach ($assignees as $assigneeId) {
                    if (!in_array($assigneeId, $allAssignees)) {
                        $allAssignees[] = $assigneeId;
                    }
                }
            }

            // Send task created notifications to all assignees
            if (!empty($allAssignees)) {
                $notifier->sendTaskCreatedNotification(
                    $taskId,
                    $allAssignees,
                    $userInfo['user_id']
                );
            }

        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Task notification error (create): " . $e->getMessage());
        }

        api_success([
            'task' => $task,
            'task_id' => $taskId
        ], 'Task creato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Task create error: " . $e->getMessage());
    api_error('Errore nella creazione del task: ' . $e->getMessage(), 500);
}
