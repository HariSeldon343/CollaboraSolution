<?php
// GET: list items for a plan
require_once __DIR__ . '/_common.php';

try {
    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) {
        api_error('plan_id obbligatorio', 400);
    }

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) {
        api_error('Piano non trovato', 404);
    }

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato', 403);
    }

    $items = $db->fetchAll(
        "SELECT * FROM consulting_plan_items WHERE plan_id = ? AND deleted_at IS NULL ORDER BY COALESCE(activity_date, '9999-12-31') ASC, id ASC",
        [$planId]
    );

    // Attach linked tasks (tenant 28 tasks only)
    $out = [];
    foreach (($items ?: []) as $it) {
        $itemId = (int)$it['id'];
        $links = $db->fetchAll(
            "SELECT l.task_id, t.title
             FROM consulting_plan_task_links l
             JOIN tasks t ON t.id = l.task_id
             WHERE l.plan_item_id = ?
               AND t.deleted_at IS NULL",
            [$itemId]
        );
        $it['linked_tasks'] = $links ?: [];
        $out[] = $it;
    }

    api_success(['items' => $out]);
} catch (Exception $e) {
    error_log('[CONSULTING_ITEMS] ' . $e->getMessage());
    api_error('Errore caricamento attività', 500);
}


