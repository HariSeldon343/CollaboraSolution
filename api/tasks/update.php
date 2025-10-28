<?php
/**
 * Task Update API Endpoint
 * PUT/POST /api/tasks/update.php
 *
 * Updates an existing task with validation and audit logging
 * Only task owner, assigned users, or super_admin can update
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/task_notification_helper.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Verify CSRF token
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
    if (empty($data['id'])) {
        api_error('ID task obbligatorio', 400);
    }

    $taskId = (int)$data['id'];

    // Fetch existing task with tenant isolation
    $existingTask = $db->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['tenant_id']]
    );

    if (!$existingTask) {
        api_error('Task non trovato o non accessibile', 404);
    }

    // Check permissions: owner, assigned user, or super_admin
    $isOwner = ($existingTask['created_by'] == $userInfo['user_id']);
    $isAssigned = ($existingTask['assigned_to'] == $userInfo['user_id']);
    $isSuperAdmin = ($userInfo['role'] === 'super_admin');

    // Check if user is in task_assignments
    $assignment = $db->fetchOne(
        'SELECT id FROM task_assignments WHERE task_id = ? AND user_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['user_id']]
    );
    $isAssignee = ($assignment !== null);

    if (!$isOwner && !$isAssigned && !$isAssignee && !$isSuperAdmin) {
        api_error('Non sei autorizzato a modificare questo task', 403);
    }

    // Prepare update data
    $updateData = [];
    $changes = []; // Track changes for audit log

    // Only update fields that are provided
    $updatableFields = [
        'title', 'description', 'status', 'priority', 'due_date',
        'estimated_hours', 'actual_hours', 'progress_percentage',
        'assigned_to', 'parent_id', 'tags', 'attachments'  // Fixed: parent_id not parent_task_id
    ];

    foreach ($updatableFields as $field) {
        if (array_key_exists($field, $data)) {
            $newValue = $data[$field];
            $oldValue = $existingTask[$field];

            // Skip if value hasn't changed
            if ($newValue === $oldValue) {
                continue;
            }

            // Validate specific fields
            if ($field === 'title') {
                $newValue = trim($newValue);
                if (empty($newValue)) {
                    api_error('Il titolo non può essere vuoto', 400);
                }
                if (strlen($newValue) > 500) {
                    api_error('Il titolo non può superare 500 caratteri', 400);
                }
            }

            if ($field === 'status') {
                $validStatuses = ['todo', 'in_progress', 'review', 'done', 'cancelled'];
                if (!in_array($newValue, $validStatuses)) {
                    api_error('Status non valido', 400);
                }
                // Set completed_at when status changes to 'done'
                if ($newValue === 'done' && $oldValue !== 'done') {
                    $updateData['completed_at'] = date('Y-m-d H:i:s');
                }
            }

            if ($field === 'priority') {
                $validPriorities = ['low', 'medium', 'high', 'critical'];
                if (!in_array($newValue, $validPriorities)) {
                    api_error('Priorità non valida', 400);
                }
            }

            if ($field === 'progress_percentage') {
                $newValue = (int)$newValue;
                if ($newValue < 0 || $newValue > 100) {
                    api_error('La percentuale deve essere tra 0 e 100', 400);
                }
            }

            if ($field === 'assigned_to' && $newValue !== null) {
                // Validate user exists and belongs to same tenant
                $assignedUser = $db->fetchOne(
                    'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                    [$newValue, $userInfo['tenant_id']]
                );

                if (!$assignedUser) {
                    api_error('Utente assegnatario non trovato o non accessibile', 404);
                }
            }

            if ($field === 'parent_id' && $newValue !== null) {
                // Validate parent task exists and is not a circular reference
                if ($newValue == $taskId) {
                    api_error('Un task non può essere padre di se stesso', 400);
                }

                $parentTask = $db->fetchOne(
                    'SELECT id, parent_id FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                    [$newValue, $userInfo['tenant_id']]
                );

                if (!$parentTask) {
                    api_error('Task padre non trovato o non accessibile', 404);
                }
            }

            if (in_array($field, ['tags', 'attachments']) && is_array($newValue)) {
                $newValue = json_encode($newValue);
            }

            $updateData[$field] = $newValue;
            $changes[] = [
                'field' => $field,
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }

    if (empty($updateData)) {
        api_error('Nessuna modifica da applicare', 400);
    }

    // Add updated_at timestamp
    $updateData['updated_at'] = date('Y-m-d H:i:s');

    // Start transaction
    $db->beginTransaction();

    try {
        // Update task
        $updated = $db->update('tasks', $updateData, ['id' => $taskId]);

        if (!$updated) {
            throw new Exception('Errore durante l\'aggiornamento del task');
        }

        // Log each change to task_history
        foreach ($changes as $change) {
            $db->insert('task_history', [
                'tenant_id' => $userInfo['tenant_id'],
                'task_id' => $taskId,
                'user_id' => $userInfo['user_id'],
                'action' => 'updated',
                'field_name' => $change['field'],
                'old_value' => is_array($change['old']) ? json_encode($change['old']) : $change['old'],
                'new_value' => is_array($change['new']) ? json_encode($change['new']) : $change['new'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Commit transaction
        $db->commit();

        // Fetch updated task
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

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        if (!empty($changes)) {
            try {
                $notifier = new TaskNotification();

                // Prepare changed fields for notification
                $changedFields = [];
                foreach ($changes as $change) {
                    $changedFields[$change['field']] = [
                        'old' => $change['old'],
                        'new' => $change['new']
                    ];
                }

                // Send update notification to all assigned users
                $notifier->sendTaskUpdatedNotification(
                    $taskId,
                    $changedFields,
                    $userInfo['user_id']
                );

            } catch (Exception $e) {
                error_log("Task notification error (update): " . $e->getMessage());
            }
        }

        api_success([
            'task' => $task,
            'changes' => count($changes)
        ], 'Task aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Task update error: " . $e->getMessage());
    api_error('Errore nell\'aggiornamento del task: ' . $e->getMessage(), 500);
}
