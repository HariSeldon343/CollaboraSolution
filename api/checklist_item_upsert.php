<?php
declare(strict_types=1);
require_once __DIR__ . '/ai/_common.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_checklists',
    'tenant_checklist_item_values',
], 'database/migrations/67_tenant_ai_onboarding.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile', 503, $storage);
}

$checklistId = (int)($payload['checklist_id'] ?? 0);
$itemKey = trim((string)($payload['item_key'] ?? ''));
if ($checklistId <= 0 || $itemKey === '') api_error('checklist_id e item_key richiesti', 400);

$status = strtolower(trim((string)($payload['status'] ?? '')));
$allowed = ['missing','present','to_review','done','not_applicable'];
if (!in_array($status, $allowed, true)) $status = 'missing';
$answerText = array_key_exists('answer_text', $payload) ? (string)($payload['answer_text'] ?? '') : null;

$stmt = $db->getConnection()->prepare(
    "INSERT INTO tenant_checklist_item_values
        (tenant_id, checklist_id, item_key, status, answer_text, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        answer_text = VALUES(answer_text),
        updated_at = NOW()"
);
$stmt->execute([$tenantId, $checklistId, $itemKey, $status, $answerText]);

api_success(['updated' => true]);

