<?php
/**
 * Task Assignment API Endpoint
 * POST /api/tasks/assign.php - Add assignment
 * DELETE /api/tasks/assign.php - Remove assignment
 *
 * Manages multi-user task assignments
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/task_notification_helper.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || empty($data['task_id']) || empty($data['user_id'])) {
        api_error('task_id e user_id obbligatori', 400);
    }

    $taskId = (int)$data['task_id'];
    $userId = (int)$data['user_id'];

    // Verify task exists and is accessible
    $task = $db->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['tenant_id']]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    // Only super_admin or task owner can manage assignments
    if ($userInfo['role'] !== 'super_admin' && $task['created_by'] != $userInfo['user_id']) {
        api_error('Solo il creatore o super admin può gestire le assegnazioni', 403);
    }

    // Verify user exists and is in same tenant
    $user = $db->fetchOne(
        'SELECT id, name FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$userId, $userInfo['tenant_id']]
    );

    if (!$user) {
        api_error('Utente non trovato', 404);
    }

    if ($method === 'POST') {
        // ADD assignment
        // Check if already assigned
        $existing = $db->fetchOne(
            'SELECT id FROM task_assignments WHERE task_id = ? AND user_id = ? AND deleted_at IS NULL',
            [$taskId, $userId]
        );

        if ($existing) {
            api_error('Utente già assegnato a questo task', 400);
        }

        $assignmentId = $db->insert('task_assignments', [
            'tenant_id' => $userInfo['tenant_id'],
            'task_id' => $taskId,
            'user_id' => $userId,
            'assigned_by' => $userInfo['user_id'],
            'assigned_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Update task assigned_to if not set
        if (!$task['assigned_to']) {
            $db->update('tasks', ['assigned_to' => $userId], ['id' => $taskId]);
        }

        // Log to history
        $db->insert('task_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'task_id' => $taskId,
            'user_id' => $userInfo['user_id'],
            'action' => 'assigned',
            'new_value' => $user['name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // ========================================
        // EMAIL NOTIFICATION (NON-BLOCKING)
        // ========================================
        try {
            $notifier = new TaskNotification();
            $notifier->sendTaskAssignedNotification(
                $taskId,
                $userId,
                $userInfo['user_id']
            );
        } catch (Exception $e) {
            error_log("Task notification error (assign): " . $e->getMessage());
        }

        api_success(['assignment_id' => $assignmentId], 'Utente assegnato con successo');

    } elseif ($method === 'DELETE') {
        // REMOVE assignment
        $assignment = $db->fetchOne(
            'SELECT id FROM task_assignments WHERE task_id = ? AND user_id = ? AND deleted_at IS NULL',
            [$taskId, $userId]
        );

        if (!$assignment) {
            api_error('Assegnazione non trovata', 404);
        }

        // Soft delete assignment
        $db->update('task_assignments', [
            'deleted_at' => date('Y-m-d H:i:s')
        ], ['id' => $assignment['id']]);

        // Log to history
        $db->insert('task_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'task_id' => $taskId,
            'user_id' => $userInfo['user_id'],
            'action' => 'unassigned',
            'old_value' => $user['name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // ========================================
        // EMAIL NOTIFICATION (NON-BLOCKING)
        // ========================================
        try {
            $notifier = new TaskNotification();
            $notifier->sendTaskRemovedNotification(
                $taskId,
                $userId,
                $userInfo['user_id']
            );
        } catch (Exception $e) {
            error_log("Task notification error (unassign): " . $e->getMessage());
        }

        api_success(null, 'Assegnazione rimossa con successo');

    } else {
        api_error('Metodo non supportato', 405);
    }

} catch (Exception $e) {
    error_log("Task assign error: " . $e->getMessage());
    api_error('Errore nella gestione delle assegnazioni: ' . $e->getMessage(), 500);
}
