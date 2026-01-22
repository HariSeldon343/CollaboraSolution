<?php
// POST: create checklist + items from template (idempotent) (tenant 28 tool)
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

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $companyId = (int)($payload['company_id'] ?? 0);
    if ($companyId < 0) $companyId = 0;

    $templateKeyIn = (string)($payload['template_key'] ?? ($payload['template_code'] ?? 'SGQ_ISO9001_BANDO'));
    $templateKey = cnx_checklists_norm_template_key($templateKeyIn);
    if ($templateKey === '') api_error('template_key non valido', 400);

    $objectiveText = isset($payload['objective_text']) ? trim((string)$payload['objective_text']) : '';
    if ($objectiveText !== '' && mb_strlen($objectiveText, 'UTF-8') > 20000) {
        $objectiveText = mb_substr($objectiveText, 0, 20000, 'UTF-8');
    }

    $tpl = cnx_checklists_load_template($templateKey);
    if (empty($tpl['ok']) || !is_array($tpl['template'] ?? null)) {
        api_error('Template non trovato', 404);
    }
    $template = $tpl['template'];

    // Load plan + access check
    $planHasDeletedAt = cnx_checklists_has_col($db, 'consulting_plans', 'deleted_at');
    $planWhere = "id = ?" . ($planHasDeletedAt ? " AND deleted_at IS NULL" : "");
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE {$planWhere} LIMIT 1", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Piano non valido (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    if ($companyId <= 0) $companyId = $clientTenantId; // best-effort mapping

    // Idempotent: return existing checklist if present
    $existing = $db->fetchOne(
        "SELECT *
         FROM consulting_project_checklists
         WHERE tenant_id = ?
           AND plan_id = ?
           AND template_key = ?
         ORDER BY id DESC
         LIMIT 1",
        [CNX_VENDOR_TENANT_ID, $planId, $templateKey]
    );
    $reuseChecklistId = 0;
    $reuseChecklistRow = null;
    if ($existing && !empty($existing['id'])) {
        $cid = (int)$existing['id'];
        $items = $db->fetchAll(
            "SELECT *
             FROM consulting_project_checklist_items
             WHERE checklist_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$cid]
        ) ?: [];
        if (!empty($items)) {
            // Best-effort: if objective_text is supported and empty, allow setting it via this idempotent call.
            if ($objectiveText !== '' && cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text')) {
                $curObj = trim((string)($existing['objective_text'] ?? ''));
                if ($curObj === '') {
                    try {
                        $db->update('consulting_project_checklists', ['objective_text' => $objectiveText, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $cid]);
                        $existing = $db->fetchOne("SELECT * FROM consulting_project_checklists WHERE id = ? LIMIT 1", [$cid]) ?: $existing;
                    } catch (Throwable $e) {}
                }
            }
            api_success([
                'created' => false,
                'checklist' => $existing,
                'items' => $items,
            ]);
        }
        // Rare case: checklist exists but items missing -> reuse the same checklist id and (re)create items.
        $reuseChecklistId = $cid;
        $reuseChecklistRow = $existing;
    }

    $title = trim((string)($template['title'] ?? 'Checklist'));
    if ($title === '') $title = 'Checklist';
    if (mb_strlen($title, 'UTF-8') > 255) $title = mb_substr($title, 0, 255, 'UTF-8');

    $now = date('Y-m-d H:i:s');

    // objective_text is mandatory: require DB support (migration 65).
    if (!cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text')) {
        api_error(
            'Checklist non aggiornata (manca objective_text)',
            503,
            ['migration' => CNX_CHECKLISTS_MIGRATION_HINT_V2]
        );
    }
    if (trim($objectiveText) === '') {
        api_error('objective_text obbligatorio', 400);
    }

    $db->beginTransaction();
    try {
        $checklistId = $reuseChecklistId;
        if ($checklistId > 0) {
            // Best-effort: keep checklist header coherent with current request/template
            try {
                $db->update('consulting_project_checklists', [
                    'company_id' => $companyId,
                    'client_tenant_id' => $clientTenantId,
                    'title' => $title,
                    'objective_text' => (cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text') ? $objectiveText : null),
                    'updated_at' => $now,
                ], ['id' => $checklistId]);
            } catch (Throwable $e) {}
        } else {
            $checklistId = (int)$db->insert('consulting_project_checklists', [
                'tenant_id' => CNX_VENDOR_TENANT_ID,
                'plan_id' => $planId,
                'company_id' => $companyId,
                'client_tenant_id' => $clientTenantId,
                'template_key' => $templateKey,
                'title' => $title,
                'status' => 'draft',
                'objective_text' => (cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text') ? $objectiveText : null),
                'last_docs_snapshot_at' => null,
                'last_docs_analyze_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($checklistId <= 0) throw new Exception('Insert checklist failed');
        }

        $allowedQ = cnx_checklists_allowed_question_types();
        $sections = is_array($template['sections'] ?? null) ? $template['sections'] : [];
        $sort = 0;
        $inserted = 0;

        foreach ($sections as $s) {
            if (!is_array($s)) continue;
            $sectionKey = trim((string)($s['section_key'] ?? ''));
            if ($sectionKey === '') continue;
            if (strlen($sectionKey) > 80) $sectionKey = substr($sectionKey, 0, 80);
            $items = is_array($s['items'] ?? null) ? $s['items'] : [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $itemKey = trim((string)($it['item_key'] ?? ''));
                if ($itemKey === '') continue;
                if (strlen($itemKey) > 120) $itemKey = substr($itemKey, 0, 120);
                $itTitle = trim((string)($it['title'] ?? $itemKey));
                if ($itTitle === '') $itTitle = $itemKey;
                if (mb_strlen($itTitle, 'UTF-8') > 255) $itTitle = mb_substr($itTitle, 0, 255, 'UTF-8');
                $desc = trim((string)($it['description'] ?? ''));
                if ($desc === '') $desc = null;
                $q = strtolower(trim((string)($it['question_type'] ?? 'text')));
                if (!in_array($q, $allowedQ, true)) $q = 'text';
                $required = !empty($it['required']) ? 1 : 0;

                // Store linked artifacts hints (best-effort, not enforced)
                $linkedHints = null;
                if (isset($it['linked_artifacts_hints'])) {
                    $linkedHints = cnx_checklists_json_stringify($it['linked_artifacts_hints'], 6000);
                }

                $db->insert('consulting_project_checklist_items', [
                    'checklist_id' => $checklistId,
                    'section_key' => $sectionKey,
                    'item_key' => $itemKey,
                    'title' => $itTitle,
                    'description' => $desc,
                    'question_type' => $q,
                    'required' => $required,
                    'status' => 'missing',
                    'answer_text' => null,
                    'answer_json' => null,
                    'evidence_json' => null,
                    'linked_artifacts_json' => $linkedHints,
                    'assigned_to_user_id' => null,
                    'due_date' => null,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $sort++;
                $inserted++;
            }
        }

        $db->commit();

        $chk = $db->fetchOne("SELECT * FROM consulting_project_checklists WHERE id = ? LIMIT 1", [$checklistId]);
        $rows = $db->fetchAll(
            "SELECT *
             FROM consulting_project_checklist_items
             WHERE checklist_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$checklistId]
        ) ?: [];

        api_success([
            'created' => true,
            'checklist' => $chk,
            'items' => $rows,
            'counts' => ['items_created' => $inserted],
        ]);
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }
} catch (Throwable $e) {
    $errId = 'chk_create_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_CREATE][{$errId}] " . $e->getMessage());
    api_error('Errore creazione checklist', 500, ['error_id' => $errId]);
}

