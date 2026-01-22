<?php
declare(strict_types=1);
require_once __DIR__ . '/ai/_common.php';

cnx_ai_require_csrf_for_write();

$checklistId = isset($_GET['checklist_id']) ? (int)$_GET['checklist_id'] : 0;
if ($checklistId <= 0) api_error('checklist_id richiesto', 400);

$tenantId = cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_checklists',
    'tenant_checklist_items',
    'tenant_checklist_item_values',
], 'database/migrations/67_tenant_ai_onboarding.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile', 503, $storage);
}

$chk = $db->fetchOne(
    "SELECT *
     FROM tenant_checklists
     WHERE id = ? AND tenant_id = ?
     LIMIT 1",
    [$checklistId, $tenantId]
);
if (!$chk) api_error('Checklist non trovata', 404);

$items = $db->fetchAll(
    "SELECT i.*, v.status, v.answer_text, v.evidences_json
     FROM tenant_checklist_items i
     LEFT JOIN tenant_checklist_item_values v
       ON v.tenant_id = i.tenant_id AND v.checklist_id = i.checklist_id AND v.item_key = i.item_key
     WHERE i.checklist_id = ? AND i.tenant_id = ?
     ORDER BY i.section_key ASC, i.id ASC",
    [$checklistId, $tenantId]
) ?: [];

api_success(['checklist' => $chk, 'items' => $items]);

