<?php
// POST: add a file evidence link to a checklist item (idempotent) (tenant 28 tool)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

try {
    $storage = cnx_checklists_check_storage($db);
    if (empty($storage['ok'])) {
        api_error(
            'Checklist non disponibile (schema non aggiornato)',
            503,
            ['storage_available' => false, 'missing' => $storage['missing'] ?? [], 'migration' => $storage['migration'] ?? CNX_CHECKLISTS_MIGRATION_HINT]
        );
    }

    if (!cnx_checklists_has_table($db, 'consulting_project_checklist_evidence')) {
        api_error('Evidenze checklist non disponibili (schema non aggiornato)', 503, ['migration' => CNX_CHECKLISTS_MIGRATION_HINT_V2]);
    }

    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $itemId = (int)($payload['item_id'] ?? 0);
    if ($itemId <= 0) api_error('item_id obbligatorio', 400);

    $fileId = (int)($payload['file_id'] ?? 0);
    if ($fileId <= 0) api_error('file_id obbligatorio', 400);

    $row = $db->fetchOne(
        "SELECT i.*, c.plan_id, c.client_tenant_id, c.tenant_id AS checklist_tenant_id
         FROM consulting_project_checklist_items i
         JOIN consulting_project_checklists c ON c.id = i.checklist_id
         WHERE i.id = ?
         LIMIT 1",
        [$itemId]
    );
    if (!$row) api_error('Item non trovato', 404);
    if ((int)($row['checklist_tenant_id'] ?? 0) !== CNX_VENDOR_TENANT_ID) api_error('Accesso negato', 403);

    $clientTenantId = (int)($row['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Item non valido (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    // Validate file exists in client tenant (best-effort)
    $f = $db->fetchOne(
        "SELECT id, name, file_path
         FROM files
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_folder = 0
         LIMIT 1",
        [$fileId, $clientTenantId]
    );
    if (!$f) api_error('File non trovato nel tenant cliente', 404);
    $fileName = trim((string)($f['name'] ?? ''));
    if ($fileName === '') $fileName = 'file #' . $fileId;
    if (mb_strlen($fileName, 'UTF-8') > 255) $fileName = mb_substr($fileName, 0, 255, 'UTF-8');
    $filePath = trim((string)($f['file_path'] ?? ''));
    if (mb_strlen($filePath, 'UTF-8') > 600) $filePath = mb_substr($filePath, 0, 600, 'UTF-8');

    $metaJson = null;
    if (array_key_exists('meta', $payload)) {
        $metaJson = cnx_checklists_json_stringify($payload['meta'], 8000);
    }

    // Insert evidence row idempotently
    $db->query(
        "INSERT INTO consulting_project_checklist_evidence (tenant_id, checklist_item_id, file_tenant_id, file_id, file_name, file_path, meta_json, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), meta_json = COALESCE(VALUES(meta_json), meta_json)",
        [
            CNX_VENDOR_TENANT_ID,
            $itemId,
            $clientTenantId,
            $fileId,
            $fileName,
            $filePath,
            $metaJson,
            (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0),
        ]
    );

    // Also update evidence_json on item for UI compatibility (merge + dedup)
    $qType = strtolower(trim((string)($row['question_type'] ?? '')));
    $cap = ($qType === 'multi_file') ? 8 : 1;
    $evOld = [];
    $evOldRaw = trim((string)($row['evidence_json'] ?? ''));
    if ($evOldRaw !== '') {
        $dec = json_decode($evOldRaw, true);
        if (is_array($dec)) $evOld = $dec;
    }
    $next = [];
    $next[] = [
        'tenant_id' => $clientTenantId,
        'file_id' => $fileId,
        'name' => $fileName,
        'path' => $filePath,
    ];
    foreach ($evOld as $x) {
        if (!is_array($x)) continue;
        $fid = (int)($x['file_id'] ?? 0);
        $tid = (int)($x['tenant_id'] ?? 0);
        if ($fid <= 0 || $tid <= 0) continue;
        $next[] = $x;
    }
    $seen = [];
    $ded = [];
    foreach ($next as $x) {
        if (!is_array($x)) continue;
        $fid = (int)($x['file_id'] ?? 0);
        $tid = (int)($x['tenant_id'] ?? 0);
        if ($fid <= 0 || $tid <= 0) continue;
        $k = $tid . ':' . $fid;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $ded[] = $x;
        if (count($ded) >= $cap) break;
    }
    $evidenceJson = cnx_checklists_json_stringify($ded, 20000);

    $upd = ['evidence_json' => $evidenceJson, 'updated_at' => date('Y-m-d H:i:s')];
    $status = strtolower(trim((string)($row['status'] ?? 'missing')));
    if ($status === '' || $status === 'missing') $upd['status'] = 'present';
    $db->update('consulting_project_checklist_items', $upd, ['id' => $itemId]);

    $updated = $db->fetchOne("SELECT * FROM consulting_project_checklist_items WHERE id = ? LIMIT 1", [$itemId]);
    api_success(['updated' => true, 'item' => $updated ?: null]);
} catch (Throwable $e) {
    $errId = 'chk_ev_add_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_EVIDENCE_ADD][{$errId}] " . $e->getMessage());
    api_error('Errore aggiunta evidenza', 500, ['error_id' => $errId]);
}

