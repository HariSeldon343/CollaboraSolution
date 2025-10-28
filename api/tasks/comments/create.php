<?php
/**
 * Task Comment Create API Endpoint
 * POST /api/tasks/comments/create.php
 *
 * Adds a comment to a task
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || empty($data['task_id']) || empty($data['content'])) {
        api_error('task_id e content obbligatori', 400);
    }

    $taskId = (int)$data['task_id'];
    $content = trim($data['content']);
    $parentCommentId = !empty($data['parent_comment_id']) ? (int)$data['parent_comment_id'] : null;
    $attachments = !empty($data['attachments']) && is_array($data['attachments']) ? json_encode($data['attachments']) : null;

    if (strlen($content) > 10000) {
        api_error('Il commento non può superare 10000 caratteri', 400);
    }

    // Verify task exists and is accessible
    $task = $db->fetchOne(
        'SELECT id FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$taskId, $userInfo['tenant_id']]
    );

    if (!$task) {
        api_error('Task non trovato', 404);
    }

    // Verify parent comment if specified
    if ($parentCommentId) {
        $parentComment = $db->fetchOne(
            'SELECT id FROM task_comments WHERE id = ? AND task_id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$parentCommentId, $taskId, $userInfo['tenant_id']]
        );

        if (!$parentComment) {
            api_error('Commento padre non trovato', 404);
        }
    }

    // Insert comment
    $commentId = $db->insert('task_comments', [
        'tenant_id' => $userInfo['tenant_id'],
        'task_id' => $taskId,
        'user_id' => $userInfo['user_id'],
        'parent_comment_id' => $parentCommentId,
        'content' => $content,
        'attachments' => $attachments,
        'is_edited' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // Log to task history
    $db->insert('task_history', [
        'tenant_id' => $userInfo['tenant_id'],
        'task_id' => $taskId,
        'user_id' => $userInfo['user_id'],
        'action' => 'commented',
        'new_value' => substr($content, 0, 100),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Fetch created comment with user info
    $comment = $db->fetchOne("
        SELECT
            c.*,
            u.name as user_name,
            u.email as user_email
        FROM task_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ", [$commentId]);

    api_success(['comment' => $comment], 'Commento aggiunto con successo');

} catch (Exception $e) {
    error_log("Task comment create error: " . $e->getMessage());
    api_error('Errore nella creazione del commento: ' . $e->getMessage(), 500);
}
