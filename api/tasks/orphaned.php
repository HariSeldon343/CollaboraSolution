<?php
/**
 * Orphaned Tasks API Endpoint
 * GET /api/tasks/orphaned.php
 *
 * Returns tasks with assigned_to pointing to deleted or invalid users
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Find orphaned tasks (assigned_to deleted users or wrong tenant)
    $orphanedTasks = $db->fetchAll("
        SELECT
            t.id,
            t.title,
            t.description,
            t.status,
            t.priority,
            t.assigned_to,
            t.created_by,
            t.due_date,
            t.created_at,
            u_creator.name as created_by_name,
            CASE
                WHEN u_assigned.id IS NULL THEN 'User deleted or not found'
                WHEN u_assigned.tenant_id != t.tenant_id THEN 'User from different tenant'
                ELSE 'Unknown reason'
            END as orphan_reason
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id AND u_assigned.deleted_at IS NULL
        LEFT JOIN users u_creator ON t.created_by = u_creator.id AND u_creator.deleted_at IS NULL
        WHERE
            t.tenant_id = ?
            AND t.deleted_at IS NULL
            AND t.assigned_to IS NOT NULL
            AND (u_assigned.id IS NULL OR u_assigned.tenant_id != t.tenant_id)
    ", [$userInfo['tenant_id']]);

    $count = count($orphanedTasks);

    api_success([
        'orphaned_tasks' => $orphanedTasks,
        'count' => $count
    ], "Trovati $count task orfani");

} catch (Exception $e) {
    error_log("Orphaned tasks error: " . $e->getMessage());
    api_error('Errore nel recupero dei task orfani: ' . $e->getMessage(), 500);
}
