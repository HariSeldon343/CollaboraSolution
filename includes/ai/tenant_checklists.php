<?php
/**
 * Tenant checklist helpers (multi-tenant, compliance/onboarding).
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function cnx_tenant_checklists_templates_dir(): string {
    return dirname(__DIR__, 2) . '/configs/checklists';
}

function cnx_tenant_checklists_norm_key(string $k): string {
    $k = strtoupper(trim($k));
    if ($k === '') return '';
    if (strlen($k) > 80) $k = substr($k, 0, 80);
    if (!preg_match('/^[A-Z0-9_]+$/', $k)) return '';
    return $k;
}

/**
 * @return array{ok:bool,template?:array,error?:string}
 */
function cnx_tenant_checklists_load_template(string $templateKey): array {
    $k = cnx_tenant_checklists_norm_key($templateKey);
    if ($k === '') return ['ok' => false, 'error' => 'template_key non valido'];
    $paths = @glob(cnx_tenant_checklists_templates_dir() . '/*.json') ?: [];
    foreach ($paths as $p) {
        if (!is_string($p) || !is_file($p)) continue;
        $raw = @file_get_contents($p);
        if (!is_string($raw) || trim($raw) === '') continue;
        $tpl = json_decode($raw, true);
        if (!is_array($tpl)) continue;
        $key = cnx_tenant_checklists_norm_key((string)($tpl['template_key'] ?? ''));
        if ($key === $k) return ['ok' => true, 'template' => $tpl];
    }
    return ['ok' => false, 'error' => 'Template non trovato'];
}

/**
 * @return array<int,array{template_key:string,title:string,version:string,standards:array<int,string>,intervention_types:array<int,string>}>
 */
function cnx_tenant_checklists_list_templates(): array {
    $out = [];
    $paths = @glob(cnx_tenant_checklists_templates_dir() . '/*.json') ?: [];
    foreach ($paths as $p) {
        if (!is_string($p) || !is_file($p)) continue;
        $raw = @file_get_contents($p);
        if (!is_string($raw) || trim($raw) === '') continue;
        $tpl = json_decode($raw, true);
        if (!is_array($tpl)) continue;
        $key = cnx_tenant_checklists_norm_key((string)($tpl['template_key'] ?? ''));
        if ($key === '') continue;
        $out[] = [
            'template_key' => $key,
            'title' => (string)($tpl['title'] ?? $key),
            'version' => (string)($tpl['version'] ?? ''),
            'standards' => is_array($tpl['standards'] ?? null) ? array_values(array_map('strval', $tpl['standards'])) : [],
            'intervention_types' => is_array($tpl['intervention_types'] ?? null) ? array_values(array_map('strval', $tpl['intervention_types'])) : [],
        ];
    }
    return $out;
}

/**
 * Ensure checklist + items exist for tenant.
 *
 * @return array{checklist_id:int,created:bool,items_created:int}
 */
function cnx_tenant_checklists_get_or_create(Database $db, int $tenantId, string $scopeType, ?int $scopeId, string $templateKey): array {
    $tenantId = (int)$tenantId;
    $scopeType = trim($scopeType) ?: 'company';
    $scopeId = $scopeId ? (int)$scopeId : null;
    $templateKey = cnx_tenant_checklists_norm_key($templateKey);
    if ($tenantId <= 0 || $templateKey === '') {
        throw new Exception('Invalid checklist params');
    }

    $row = $db->fetchOne(
        "SELECT id
         FROM tenant_checklists
         WHERE tenant_id = ?
           AND scope_type = ?
           AND scope_id <=> ?
           AND template_key = ?
         LIMIT 1",
        [$tenantId, $scopeType, $scopeId, $templateKey]
    );
    if ($row && !empty($row['id'])) {
        return ['checklist_id' => (int)$row['id'], 'created' => false, 'items_created' => 0];
    }

    $id = (int)$db->insert('tenant_checklists', [
        'tenant_id' => $tenantId,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'template_key' => $templateKey,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    if ($id <= 0) throw new Exception('Insert checklist failed');

    $tpl = cnx_tenant_checklists_load_template($templateKey);
    $itemsCreated = 0;
    if (!empty($tpl['ok']) && is_array($tpl['template'])) {
        $sections = is_array($tpl['template']['sections'] ?? null) ? $tpl['template']['sections'] : [];
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
                $title = trim((string)($it['title'] ?? $itemKey));
                if ($title === '') $title = $itemKey;
                if (strlen($title) > 255) $title = substr($title, 0, 255);
                $desc = trim((string)($it['description'] ?? ''));
                if ($desc === '') $desc = null;
                $inputType = strtolower(trim((string)($it['question_type'] ?? 'text')));
                if (!in_array($inputType, ['none','text','multi_file','file','boolean','select','multiline'], true)) $inputType = 'text';
                $req = !empty($it['required']) ? 1 : 0;

                $db->insert('tenant_checklist_items', [
                    'tenant_id' => $tenantId,
                    'checklist_id' => $id,
                    'item_key' => $itemKey,
                    'section_key' => $sectionKey,
                    'title' => $title,
                    'description' => $desc,
                    'required_flag' => $req,
                    'input_type' => $inputType,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $itemsCreated++;
            }
        }
    }

    return ['checklist_id' => $id, 'created' => true, 'items_created' => $itemsCreated];
}

