<?php
// POST: clear evidence links for a checklist item (all or single) (tenant 28 tool)
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

    $fileId = array_key_exists('file_id', $payload) ? (int)($payload['file_id'] ?? 0) : 0;
    if ($fileId < 0) $fileId = 0;

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

    if ($fileId > 0) {
        $db->query(
            "DELETE FROM consulting_project_checklist_evidence
             WHERE tenant_id = ?
               AND checklist_item_id = ?
               AND file_tenant_id = ?
               AND file_id = ?",
            [CNX_VENDOR_TENANT_ID, $itemId, $clientTenantId, $fileId]
        );
    } else {
        $db->query(
            "DELETE FROM consulting_project_checklist_evidence
             WHERE tenant_id = ?
               AND checklist_item_id = ?
               AND file_tenant_id = ?",
            [CNX_VENDOR_TENANT_ID, $itemId, $clientTenantId]
        );
    }

    // Also update evidence_json on item for UI compatibility
    $evOld = [];
    $evOldRaw = trim((string)($row['evidence_json'] ?? ''));
    if ($evOldRaw !== '') {
        $dec = json_decode($evOldRaw, true);
        if (is_array($dec)) $evOld = $dec;
    }
    if ($fileId > 0) {
        $next = [];
        foreach ($evOld as $x) {
            if (!is_array($x)) continue;
            $fid = (int)($x['file_id'] ?? 0);
            $tid = (int)($x['tenant_id'] ?? 0);
            if ($fid <= 0 || $tid <= 0) continue;
            if ($tid === $clientTenantId && $fid === $fileId) continue;
            $next[] = $x;
        }
        $evidenceJson = cnx_checklists_json_stringify($next, 20000);
        $db->update('consulting_project_checklist_items', ['evidence_json' => $evidenceJson, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $itemId]);
    } else {
        $db->update('consulting_project_checklist_items', ['evidence_json' => cnx_checklists_json_stringify([], 20000), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $itemId]);
    }

    $updated = $db->fetchOne("SELECT * FROM consulting_project_checklist_items WHERE id = ? LIMIT 1", [$itemId]);
    api_success(['updated' => true, 'item' => $updated ?: null]);
} catch (Throwable $e) {
    $errId = 'chk_ev_clr_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_EVIDENCE_CLEAR][{$errId}] " . $e->getMessage());
    api_error('Errore rimozione evidenze', 500, ['error_id' => $errId]);
}

