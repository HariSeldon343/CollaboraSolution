<?php
declare(strict_types=1);
require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/ai/tenant_checklists.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
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

$templateKey = (string)($payload['template_key'] ?? '');
$scopeType = (string)($payload['scope_type'] ?? 'company');
$scopeId = isset($payload['scope_id']) ? (int)$payload['scope_id'] : null;
$res = cnx_tenant_checklists_get_or_create($db, $tenantId, $scopeType, $scopeId, $templateKey);
api_success($res);

