<?php
declare(strict_types=1);
require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/file_access.php';

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
$fileId = (int)($payload['file_id'] ?? 0);
if ($checklistId <= 0 || $itemKey === '' || $fileId <= 0) api_error('Parametri mancanti', 400);

$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
$userRole = (string)($userInfo['role'] ?? 'user');
try {
    $acc = hasFileOrFolderAccess($db, $fileId, $userId, $userRole, $tenantId);
    if (empty($acc['has_access'])) api_error('Accesso negato al file', 403);
} catch (Throwable $e) {
    api_error('Accesso negato al file', 403);
}

$row = $db->fetchOne(
    "SELECT evidences_json
     FROM tenant_checklist_item_values
     WHERE tenant_id = ? AND checklist_id = ? AND item_key = ?
     LIMIT 1",
    [$tenantId, $checklistId, $itemKey]
);
$cur = [];
if ($row && !empty($row['evidences_json'])) {
    $dec = json_decode((string)$row['evidences_json'], true);
    if (is_array($dec)) $cur = $dec;
}
$cur[] = ['file_id' => $fileId];
$seen = [];
$ded = [];
foreach ($cur as $ev) {
    $fid = (int)($ev['file_id'] ?? 0);
    if ($fid <= 0 || isset($seen[$fid])) continue;
    $seen[$fid] = true;
    $ded[] = ['file_id' => $fid];
}

$stmt = $db->getConnection()->prepare(
    "INSERT INTO tenant_checklist_item_values
        (tenant_id, checklist_id, item_key, status, answer_text, evidences_json, updated_at)
     VALUES (?, ?, ?, 'present', NULL, ?, NOW())
     ON DUPLICATE KEY UPDATE
        evidences_json = VALUES(evidences_json),
        updated_at = NOW()"
);
$stmt->execute([$tenantId, $checklistId, $itemKey, json_encode($ded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

api_success(['updated' => true]);

