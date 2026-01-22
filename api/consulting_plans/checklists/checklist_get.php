<?php
// GET: get checklist instance + items for a plan (tenant 28 tool)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

try {
    $storage = cnx_checklists_check_storage($db);
    if (empty($storage['ok'])) {
        api_success([
            'storage_available' => false,
            'missing' => $storage['missing'] ?? [],
            'migration' => $storage['migration'] ?? CNX_CHECKLISTS_MIGRATION_HINT,
            'features' => [
                'objective_supported' => false,
                'evidence_table_supported' => cnx_checklists_has_table($db, 'consulting_project_checklist_evidence'),
            ],
            'checklist' => null,
            'items' => [],
        ]);
    }

    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $templateKeyIn = isset($_GET['template_key'])
        ? (string)$_GET['template_key']
        : (isset($_GET['template_code']) ? (string)$_GET['template_code'] : 'SGQ_ISO9001_BANDO');
    $templateKey = cnx_checklists_norm_template_key($templateKeyIn);
    if ($templateKey === '') $templateKey = 'SGQ_ISO9001_BANDO';

    // Load plan + access check (tenant 28 -> client tenant) (schema drift safe for deleted_at)
    $planHasDeletedAt = cnx_checklists_has_col($db, 'consulting_plans', 'deleted_at');
    $planWhere = "id = ?" . ($planHasDeletedAt ? " AND deleted_at IS NULL" : "");
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE {$planWhere} LIMIT 1", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Piano non valido (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    $chk = $db->fetchOne(
        "SELECT *
         FROM consulting_project_checklists
         WHERE tenant_id = ?
           AND plan_id = ?
           AND template_key = ?
         ORDER BY id DESC
         LIMIT 1",
        [CNX_VENDOR_TENANT_ID, $planId, $templateKey]
    );

    $items = [];
    if ($chk && !empty($chk['id'])) {
        $cid = (int)$chk['id'];
        $items = $db->fetchAll(
            "SELECT *
             FROM consulting_project_checklist_items
             WHERE checklist_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$cid]
        ) ?: [];
    }

    // Template meta (best-effort) to help UI suggest "create"
    $tplMeta = null;
    $tplFull = null;
    $tpl = cnx_checklists_load_template($templateKey);
    if (!empty($tpl['ok']) && is_array($tpl['template'] ?? null)) {
        $t = $tpl['template'];
        $tplFull = $t;
        $tplMeta = [
            'template_key' => cnx_checklists_norm_template_key((string)($t['template_key'] ?? $templateKey)),
            'title' => (string)($t['title'] ?? $templateKey),
            'version' => (string)($t['version'] ?? ''),
            'standards' => (is_array($t['standards'] ?? null) ? array_values(array_map('strval', $t['standards'])) : []),
        ];
    }

    $features = [
        'objective_supported' => cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text'),
        'evidence_table_supported' => cnx_checklists_has_table($db, 'consulting_project_checklist_evidence'),
    ];

    api_success([
        'storage_available' => true,
        'features' => $features,
        'plan_id' => $planId,
        'client_tenant_id' => $clientTenantId,
        'template' => $tplMeta,
        'template_full' => $tplFull,
        'checklist' => $chk ?: null,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    $errId = 'chk_get_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_GET][{$errId}] " . $e->getMessage());
    api_error('Errore caricamento checklist', 500, ['error_id' => $errId]);
}

