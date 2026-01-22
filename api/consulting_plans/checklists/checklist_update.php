<?php
// POST: update checklist header fields (objective/status) (tenant 28 tool)
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

    if (!cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text')) {
        api_error(
            'Checklist non aggiornata (manca objective_text)',
            503,
            ['migration' => CNX_CHECKLISTS_MIGRATION_HINT_V2]
        );
    }

    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $checklistId = (int)($payload['checklist_id'] ?? 0);
    if ($checklistId <= 0) api_error('checklist_id obbligatorio', 400);

    $chk = $db->fetchOne("SELECT * FROM consulting_project_checklists WHERE id = ? LIMIT 1", [$checklistId]);
    if (!$chk) api_error('Checklist non trovata', 404);
    if ((int)($chk['tenant_id'] ?? 0) !== CNX_VENDOR_TENANT_ID) api_error('Accesso negato', 403);

    $clientTenantId = (int)($chk['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Checklist non valida (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    $upd = [];

    if (array_key_exists('objective_text', $payload)) {
        $obj = trim((string)($payload['objective_text'] ?? ''));
        if ($obj === '') api_error('objective_text obbligatorio', 400);
        if (mb_strlen($obj, 'UTF-8') > 20000) $obj = mb_substr($obj, 0, 20000, 'UTF-8');
        $upd['objective_text'] = $obj;
    }

    if (array_key_exists('status', $payload)) {
        $st = strtolower(trim((string)($payload['status'] ?? '')));
        $allowed = ['draft', 'in_progress', 'done'];
        if ($st === '' || !in_array($st, $allowed, true)) api_error('status non valido', 400, ['allowed' => $allowed]);
        $upd['status'] = $st;
    }

    if (empty($upd)) api_error('Nessun campo da aggiornare', 400);

    $upd['updated_at'] = date('Y-m-d H:i:s');
    $db->update('consulting_project_checklists', $upd, ['id' => $checklistId]);

    $updated = $db->fetchOne("SELECT * FROM consulting_project_checklists WHERE id = ? LIMIT 1", [$checklistId]);
    api_success(['updated' => true, 'checklist' => $updated ?: null]);
} catch (Throwable $e) {
    $errId = 'chk_hdr_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_UPDATE][{$errId}] " . $e->getMessage());
    api_error('Errore aggiornamento checklist', 500, ['error_id' => $errId]);
}

