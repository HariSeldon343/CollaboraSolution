<?php
// POST: soft-delete an item
require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $itemId = (int)($data['id'] ?? 0);
    if ($itemId <= 0) api_error('ID attività non valido', 400);

    $item = $db->fetchOne("SELECT * FROM consulting_plan_items WHERE id = ? AND deleted_at IS NULL", [$itemId]);
    if (!$item) api_error('Attività non trovata', 404);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [(int)$item['plan_id']]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $ok = $db->update('consulting_plan_items', [
        'deleted_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $itemId]);

    if (!$ok) api_error('Eliminazione fallita', 500);
    api_success(['id' => $itemId], 'Attività eliminata');
} catch (Exception $e) {
    error_log('[CONSULTING_ITEM_DELETE] ' . $e->getMessage());
    api_error('Errore eliminazione attività', 500);
}


