<?php
// POST: link an existing task (tenant 28) to a plan item
require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $planItemId = (int)($data['plan_item_id'] ?? 0);
    $taskId = (int)($data['task_id'] ?? 0);
    if ($planItemId <= 0 || $taskId <= 0) api_error('Parametri non validi', 400);

    $item = $db->fetchOne("SELECT * FROM consulting_plan_items WHERE id = ? AND deleted_at IS NULL", [$planItemId]);
    if (!$item) api_error('Attività non trovata', 404);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [(int)$item['plan_id']]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    // Only allow linking tasks that belong to tenant 28
    $task = $db->fetchOne("SELECT id FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL", [$taskId, CNX_VENDOR_TENANT_ID]);
    if (!$task) api_error('Task non trovato (solo tenant 28)', 404);

    // Insert ignore-like behavior via try/catch
    try {
        $db->insert('consulting_plan_task_links', [
            'plan_item_id' => $planItemId,
            'task_id' => $taskId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $dup) {
        // ignore duplicates
    }

    api_success(['plan_item_id' => $planItemId, 'task_id' => $taskId], 'Task collegato');
} catch (Exception $e) {
    error_log('[CONSULTING_LINK_TASK] ' . $e->getMessage());
    api_error('Errore collegamento task', 500);
}


