<?php
/**
 * IMS v2: Template Modules API (tenant 28 managed).
 *
 * GET  /api/compliance/template_modules.php?action=list
 * GET  /api/compliance/template_modules.php?action=items_list&module_key=...
 * GET  /api/compliance/template_modules.php?action=diag_storage
 *
 * POST /api/compliance/template_modules.php?action=upsert
 * POST /api/compliance/template_modules.php?action=items_set
 *
 * Security:
 * - Auth required
 * - Tenant 28 gate required
 * - CSRF required for POST
 *
 * Schema drift safe:
 * - If migration 46 missing -> 503 with migration hint
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

cnx_compliance_require_tenant28_planning($userInfo);

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

// Storage check (migration 46)
$need = ['compliance_template_modules', 'compliance_template_module_items'];
$has = cnx_compliance_check_tables($db, $need, 'database/migrations/46_ims_template_modules_and_source_files.sql');
if (!$has['ok'] && $action !== 'diag_storage') {
    api_error(
        'Modulo moduli template IMS non inizializzato: applica la migrazione database 46 (modules + source files)',
        503,
        [
            'storage_available' => false,
            'missing' => $has['missing'] ?? [],
            'migration' => 'database/migrations/46_ims_template_modules_and_source_files.sql',
        ]
    );
}

if ($action === 'diag_storage') {
    $chk = cnx_compliance_check_tables($db, $need, 'database/migrations/46_ims_template_modules_and_source_files.sql');
    api_success([
        'storage_available' => $chk['ok'],
        'missing' => $chk['missing'] ?? [],
        'migration' => 'database/migrations/46_ims_template_modules_and_source_files.sql',
    ]);
}

/**
 * @return array<int,string>
 */
function cnx_decode_json_list(?string $s): array {
    $s = $s === null ? '' : trim($s);
    if ($s === '') return [];
    $v = json_decode($s, true);
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        if (is_string($x)) {
            $x = trim($x);
            if ($x !== '') $out[] = $x;
        }
    }
    return array_values(array_unique($out));
}

function cnx_validate_module_key(string $key): string {
    $key = strtoupper(trim($key));
    if ($key === '' || strlen($key) > 80) api_error('module_key non valido', 400);
    if (!preg_match('/^[A-Z0-9_]+$/', $key)) api_error('module_key deve essere uppercase e underscore (A-Z0-9_)', 400);
    return $key;
}

try {
    if ($action === 'list') {
        $activeOnly = isset($_GET['active_only']) ? (int)$_GET['active_only'] : 0;
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        $rows = $db->fetchAll(
            "SELECT id, module_key, title, description, standards_json, tags_json, is_active, updated_at
             FROM compliance_template_modules
             $where
             ORDER BY is_active DESC, module_key ASC"
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'module_key' => (string)($r['module_key'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'description' => (string)($r['description'] ?? ''),
                'standards' => cnx_decode_json_list($r['standards_json'] ?? null),
                'tags' => cnx_decode_json_list($r['tags_json'] ?? null),
                'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }
        api_success(['storage_available' => true, 'modules' => $out]);
    }

    if ($action === 'items_list') {
        $moduleKey = cnx_validate_module_key((string)($_GET['module_key'] ?? ''));
        $m = $db->fetchOne("SELECT id, module_key, title FROM compliance_template_modules WHERE module_key = ? LIMIT 1", [$moduleKey]);
        if (!$m) api_error('Modulo non trovato', 404);

        $rows = $db->fetchAll(
            "SELECT i.template_key, i.is_required, i.sort_order,
                    t.title, t.doc_type, t.file_kind, t.folder_path, t.filename_template, t.is_common_hls, t.is_active
             FROM compliance_template_module_items i
             LEFT JOIN compliance_artifact_templates t ON t.template_key = i.template_key
             WHERE i.module_id = ?
             ORDER BY i.sort_order ASC, i.template_key ASC",
            [(int)$m['id']]
        ) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'template_key' => (string)($r['template_key'] ?? ''),
                'is_required' => (int)($r['is_required'] ?? 0) ? 1 : 0,
                'sort_order' => (int)($r['sort_order'] ?? 0),
                'template' => [
                    'title' => (string)($r['title'] ?? ''),
                    'doc_type' => (string)($r['doc_type'] ?? ''),
                    'file_kind' => (string)($r['file_kind'] ?? ''),
                    'folder_path' => (string)($r['folder_path'] ?? ''),
                    'filename_template' => (string)($r['filename_template'] ?? ''),
                    'is_common_hls' => (int)($r['is_common_hls'] ?? 0) ? 1 : 0,
                    'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                ],
            ];
        }

        api_success([
            'storage_available' => true,
            'module' => [
                'id' => (int)($m['id'] ?? 0),
                'module_key' => (string)($m['module_key'] ?? ''),
                'title' => (string)($m['title'] ?? ''),
            ],
            'items' => $items,
        ]);
    }

    if ($action === 'upsert') {
        cnx_compliance_require_csrf_for_write();
        $p = json_decode(file_get_contents('php://input'), true) ?: [];

        $moduleKey = cnx_validate_module_key((string)($p['module_key'] ?? ''));
        $title = trim((string)($p['title'] ?? ''));
        $desc = trim((string)($p['description'] ?? ''));
        $standards = is_array($p['standards'] ?? null) ? $p['standards'] : [];
        $tags = is_array($p['tags'] ?? null) ? $p['tags'] : [];
        $isActive = isset($p['is_active']) ? (int)((bool)$p['is_active']) : 1;

        if ($title === '' || strlen($title) > 255) api_error('title non valido', 400);
        if ($desc !== '' && strlen($desc) > 4000) api_error('description troppo lunga', 400);

        $stdOut = [];
        foreach ($standards as $s) {
            if (!is_string($s)) continue;
            $s = strtoupper(trim($s));
            if ($s === '' || strlen($s) > 32) continue;
            if (!preg_match('/^[A-Z0-9]+$/', $s)) continue;
            $stdOut[] = $s;
        }
        $stdOut = array_values(array_unique($stdOut));

        $tagOut = [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t === '' || strlen($t) > 40) continue;
            $tagOut[] = $t;
        }
        $tagOut = array_values(array_unique($tagOut));

        $stdJson = !empty($stdOut) ? json_encode($stdOut, JSON_UNESCAPED_UNICODE) : null;
        $tagsJson = !empty($tagOut) ? json_encode($tagOut, JSON_UNESCAPED_UNICODE) : null;

        $existing = $db->fetchOne("SELECT id FROM compliance_template_modules WHERE module_key = ? LIMIT 1", [$moduleKey]);
        if ($existing && !empty($existing['id'])) {
            $db->update('compliance_template_modules', [
                'title' => $title,
                'description' => ($desc !== '' ? $desc : null),
                'standards_json' => $stdJson,
                'tags_json' => $tagsJson,
                'is_active' => $isActive,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['module_key' => $moduleKey]);
        } else {
            $db->insert('compliance_template_modules', [
                'module_key' => $moduleKey,
                'title' => $title,
                'description' => ($desc !== '' ? $desc : null),
                'standards_json' => $stdJson,
                'tags_json' => $tagsJson,
                'is_active' => $isActive,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        api_success(['storage_available' => true, 'module_key' => $moduleKey], 'Salvato');
    }

    if ($action === 'items_set') {
        cnx_compliance_require_csrf_for_write();
        $p = json_decode(file_get_contents('php://input'), true) ?: [];

        $moduleKey = cnx_validate_module_key((string)($p['module_key'] ?? ''));
        $items = is_array($p['items'] ?? null) ? $p['items'] : [];
        if (count($items) > 300) api_error('Troppe righe', 400);

        $m = $db->fetchOne("SELECT id FROM compliance_template_modules WHERE module_key = ? LIMIT 1", [$moduleKey]);
        if (!$m) api_error('Modulo non trovato', 404);
        $moduleId = (int)($m['id'] ?? 0);
        if ($moduleId <= 0) api_error('Modulo non valido', 500);

        // Normalize and validate item list
        $norm = [];
        $seen = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;
            $tk = strtoupper(trim((string)($it['template_key'] ?? '')));
            if ($tk === '' || strlen($tk) > 80) continue;
            if (!preg_match('/^[A-Z0-9_]+$/', $tk)) continue;
            if (isset($seen[$tk])) continue;
            $seen[$tk] = true;
            $isReq = isset($it['is_required']) ? (int)((bool)$it['is_required']) : 1;
            $sort = isset($it['sort_order']) ? (int)$it['sort_order'] : ((int)$idx * 10);
            $norm[] = ['template_key' => $tk, 'is_required' => $isReq ? 1 : 0, 'sort_order' => $sort];
        }

        $pdo = $db->getConnection();
        $pdo->beginTransaction();
        try {
            // Replace list (idempotent)
            $db->query("DELETE FROM compliance_template_module_items WHERE module_id = ?", [$moduleId]);
            foreach ($norm as $it) {
                $db->insert('compliance_template_module_items', [
                    'module_id' => $moduleId,
                    'template_key' => $it['template_key'],
                    'is_required' => $it['is_required'],
                    'sort_order' => $it['sort_order'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        api_success(['storage_available' => true, 'module_key' => $moduleKey, 'count' => count($norm)], 'Salvato');
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_TEMPLATE_MODULES] ' . $e->getMessage());
    api_error('Errore moduli template', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

