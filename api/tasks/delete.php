<?php
/**
 * Task Delete API Endpoint
 * DELETE/POST /api/tasks/delete.php
 *
 * Soft-deletes a task (super_admin only)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();

$userInfo = getApiUserInfo();

// Only super_admin can delete tasks
if ($userInfo['role'] !== 'super_admin') {
    api_error('Solo il super admin può eliminare task', 403);
}

$db = Database::getInstance();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) {
        api_error('ID task obbligatorio', 400);
    }

    $taskId = (int)$data['id'];

    // Verify task exists
    $task = $db->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['tenant_id']]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    $db->beginTransaction();

    try {
        // Soft delete task
        $db->update('tasks', [
            'deleted_at' => date('Y-m-d H:i:s')
        ], ['id' => $taskId]);

        // Log deletion
        $db->insert('task_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'task_id' => $taskId,
            'user_id' => $userInfo['user_id'],
            'action' => 'deleted',
            'old_value' => $task['title'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $db->commit();

        api_success(['task_id' => $taskId], 'Task eliminato con successo');
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Task delete error: " . $e->getMessage());
    api_error('Errore nell\'eliminazione del task: ' . $e->getMessage(), 500);
}
