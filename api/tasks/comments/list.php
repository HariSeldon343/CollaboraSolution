<?php
/**
 * Task Comments List API Endpoint
 * GET /api/tasks/comments/list.php?task_id=123
 *
 * Returns all comments for a task
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    if (empty($_GET['task_id'])) {
        api_error('task_id obbligatorio', 400);
    }

    $taskId = (int)$_GET['task_id'];

    // Verify task exists and is accessible
    $task = $db->fetchOne(
        'SELECT id FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['tenant_id']]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    // Get all comments for this task
    $comments = $db->fetchAll("
        SELECT
            c.*,
            u.name as user_name,
            u.email as user_email
        FROM task_comments c
        JOIN users u ON c.user_id = u.id AND u.deleted_at IS NULL
        WHERE
            c.task_id = ?
            AND c.tenant_id = ?
            AND c.deleted_at IS NULL
        ORDER BY c.created_at ASC
    ", [$taskId, $userInfo['tenant_id']]);

    api_success([
        'comments' => $comments,
        'count' => count($comments)
    ], 'Commenti recuperati con successo');

} catch (Exception $e) {
    error_log("Task comments list error: " . $e->getMessage());
    api_error('Errore nel recupero dei commenti: ' . $e->getMessage(), 500);
}
