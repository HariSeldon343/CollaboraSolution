<?php
// POST: update a checklist item (answer/status/evidence) (tenant 28 tool)
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

    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $itemId = (int)($payload['item_id'] ?? 0);
    if ($itemId <= 0) api_error('item_id obbligatorio', 400);

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

    $planId = (int)($row['plan_id'] ?? 0);
    $clientTenantId = (int)($row['client_tenant_id'] ?? 0);
    if ($planId <= 0 || $clientTenantId <= 0) api_error('Item non valido (piano/cliente)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    $allowedStatuses = cnx_checklists_allowed_statuses();
    $statusIn = isset($payload['status']) ? strtolower(trim((string)$payload['status'])) : '';
    if ($statusIn !== '' && !in_array($statusIn, $allowedStatuses, true)) {
        api_error('status non valido', 400, ['allowed' => $allowedStatuses]);
    }

    $answerText = array_key_exists('answer_text', $payload) ? (string)($payload['answer_text'] ?? '') : null;
    if ($answerText !== null) {
        $answerText = trim($answerText);
        if ($answerText === '') $answerText = null;
        if ($answerText !== null && mb_strlen($answerText, 'UTF-8') > 20000) {
            $answerText = mb_substr($answerText, 0, 20000, 'UTF-8');
        }
    }

    $answerJsonRaw = $payload['answer_json'] ?? null;
    $answerJson = null;
    if (array_key_exists('answer_json', $payload)) {
        if (is_string($answerJsonRaw)) {
            $s = trim($answerJsonRaw);
            if ($s !== '') {
                $decoded = json_decode($s, true);
                $answerJson = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $s;
            }
        } else {
            $answerJson = $answerJsonRaw;
        }
    }
    $answerJsonStr = array_key_exists('answer_json', $payload) ? cnx_checklists_json_stringify($answerJson, 20000) : null;

    $evidenceRaw = $payload['evidence_json'] ?? null;
    $evidenceArr = null;
    if (array_key_exists('evidence_json', $payload)) {
        if (is_string($evidenceRaw)) {
            $s = trim($evidenceRaw);
            $evidenceArr = ($s === '') ? [] : (json_decode($s, true) ?: []);
        } elseif (is_array($evidenceRaw)) {
            $evidenceArr = $evidenceRaw;
        } elseif ($evidenceRaw === null) {
            $evidenceArr = [];
        }
        if (!is_array($evidenceArr)) $evidenceArr = [];
    }

    $evidenceSanitized = null;
    if ($evidenceArr !== null) {
        $out = [];
        foreach ($evidenceArr as $ev) {
            if (!is_array($ev)) continue;
            $tid = (int)($ev['tenant_id'] ?? 0);
            $fid = (int)($ev['file_id'] ?? 0);
            $path = trim((string)($ev['path'] ?? ''));
            $name = trim((string)($ev['name'] ?? ''));
            if ($tid <= 0 || $fid <= 0) continue;
            if (mb_strlen($path, 'UTF-8') > 600) $path = mb_substr($path, 0, 600, 'UTF-8');
            if (mb_strlen($name, 'UTF-8') > 255) $name = mb_substr($name, 0, 255, 'UTF-8');
            $out[] = [
                'tenant_id' => $tid,
                'file_id' => $fid,
                'path' => $path,
                'name' => $name,
            ];
            if (count($out) >= 10) break;
        }
        $evidenceSanitized = $out;
    }
    $evidenceJsonStr = ($evidenceSanitized !== null) ? cnx_checklists_json_stringify($evidenceSanitized, 20000) : null;

    $upd = [];
    if ($statusIn !== '') $upd['status'] = $statusIn;
    if (array_key_exists('answer_text', $payload)) $upd['answer_text'] = $answerText;
    if (array_key_exists('answer_json', $payload)) $upd['answer_json'] = $answerJsonStr;
    if (array_key_exists('evidence_json', $payload)) $upd['evidence_json'] = $evidenceJsonStr;
    $upd['updated_at'] = date('Y-m-d H:i:s');
    if (empty($upd)) api_error('Nessun campo da aggiornare', 400);

    $db->update('consulting_project_checklist_items', $upd, ['id' => $itemId]);

    $updated = $db->fetchOne(
        "SELECT *
         FROM consulting_project_checklist_items
         WHERE id = ?
         LIMIT 1",
        [$itemId]
    );

    api_success([
        'updated' => true,
        'item' => $updated ?: null,
    ]);
} catch (Throwable $e) {
    $errId = 'chk_upd_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_ITEM_UPDATE][{$errId}] " . $e->getMessage());
    api_error('Errore aggiornamento item', 500, ['error_id' => $errId]);
}

