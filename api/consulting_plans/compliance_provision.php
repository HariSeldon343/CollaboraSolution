<?php
/**
 * Leva 2/3/4 (ISO 9001 pilot) — Provisioning IMS + Action Plan tasks + milestone events
 *
 * POST JSON: { plan_id, client_tenant_id?, blueprint_json?, company_profile?, options:{create_documents,create_tasks,create_milestones} }
 *
 * Schema drift safe:
 * - If optional storage tables are missing, operation still proceeds best-effort and returns storage_available=false + warnings
 * - Never 500 due to missing tables/columns (except unexpected server faults)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

require_once __DIR__ . '/../../includes/tenant_folder_helper.php';
require_once __DIR__ . '/../../includes/compliance/provisioning_files_helper.php';
require_once __DIR__ . '/../../includes/calendar.php';
require_once __DIR__ . '/../../includes/sql_migration_runner.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/compliance/doc_template_engine.php';

/**
 * @return array<string,bool>
 */
function cnx_cols(Database $db, string $table): array {
    $cols = [];
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM `$table`") ?: [];
        foreach ($rows as $r) {
            if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function cnx_filter_cols(array $data, array $cols): array {
    if (empty($cols)) return $data;
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $out[$k] = $v;
    }
    return $out;
}

/**
 * Flatten company profile into placeholder replacements (best-effort).
 * @param array<string,mixed> $profile
 * @return array<string,string>
 */
function cnx_profile_to_placeholders(array $profile): array {
    $out = [];
    $company = trim((string)($profile['company_name'] ?? ''));
    if ($company !== '') $out['company_name'] = $company;
    $out['doc_version'] = '0.1';
    $out['doc_date'] = date('Y-m-d');
    $out['today_date'] = $out['doc_date']; // legacy placeholder alias

    foreach (['sites','products_services','processes','roles'] as $k) {
        if (!isset($profile[$k]) || !is_array($profile[$k])) continue;
        $lines = [];
        foreach ($profile[$k] as $it) {
            $s = trim((string)$it);
            if ($s === '') continue;
            $lines[] = '- ' . $s;
        }
        if (!empty($lines)) $out[$k] = implode("\n", $lines);
    }
    $notes = trim((string)($profile['notes'] ?? ''));
    if ($notes !== '') $out['notes'] = $notes;
    return $out;
}

function cnx_norm_key(string $s): string {
    $s = strtoupper(trim($s));
    $s = preg_replace('/[^A-Z0-9_\\-]+/', '_', $s) ?: $s;
    $s = preg_replace('/_+/', '_', $s) ?: $s;
    return trim($s, '_');
}

function cnx_table_exists(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Normalize standard code from various UI/generator forms.
 * Examples:
 * - "ISO 9001" -> ISO9001
 * - "ISO/IEC 27001" -> ISO27001
 * - "UNI 10881" -> UNI10881
 */
function cnx_norm_standard_code(string $raw): string {
    $s = strtoupper(trim($raw));
    $s = preg_replace('/\\s+/', ' ', $s) ?: $s;
    if ($s === '') return '';
    if (strpos($s, '9001') !== false) return 'ISO9001';
    if (strpos($s, '14001') !== false) return 'ISO14001';
    if (strpos($s, '45001') !== false) return 'ISO45001';
    if (strpos($s, '27001') !== false) return 'ISO27001';
    if (strpos($s, '13485') !== false) return 'ISO13485';
    if (strpos($s, '42001') !== false) return 'ISO42001';
    if (strpos($s, '7101') !== false) return 'ISO7101';
    if (strpos($s, '10881') !== false) return 'UNI10881';
    // fallback: strip separators
    $s = preg_replace('/[^A-Z0-9]/', '', $s) ?: $s;
    return $s;
}

/**
 * Extract standards from blueprint JSON in multiple supported shapes.
 *
 * @return array<int,array{code:string,edition:string}>
 */
function cnx_extract_standards_from_blueprint(?array $blueprint, array $fallback = []): array {
    $out = [];
    $tryLists = [];
    if (is_array($blueprint)) {
        if (isset($blueprint['meta']['standards']) && is_array($blueprint['meta']['standards'])) $tryLists[] = $blueprint['meta']['standards'];
        if (isset($blueprint['compliance_blueprint']['standards']) && is_array($blueprint['compliance_blueprint']['standards'])) $tryLists[] = $blueprint['compliance_blueprint']['standards'];
        if (isset($blueprint['standards']) && is_array($blueprint['standards'])) $tryLists[] = $blueprint['standards'];
    }
    foreach ($tryLists as $list) {
        foreach ($list as $s) {
            if (is_string($s)) {
                $code = cnx_norm_standard_code($s);
                if ($code !== '') $out[] = ['code' => $code, 'edition' => ''];
                continue;
            }
            if (is_array($s)) {
                $codeRaw = (string)($s['code'] ?? $s['name'] ?? '');
                $edition = (string)($s['edition'] ?? $s['edition_label'] ?? '');
                $code = cnx_norm_standard_code($codeRaw);
                if ($code !== '') $out[] = ['code' => $code, 'edition' => $edition];
            }
        }
        if (!empty($out)) break;
    }
    if (empty($out) && !empty($fallback)) {
        foreach ($fallback as $s) {
            $code = cnx_norm_standard_code((string)$s);
            if ($code !== '') $out[] = ['code' => $code, 'edition' => ''];
        }
    }
    if (empty($out)) {
        $out[] = ['code' => 'ISO9001', 'edition' => ''];
    }
    // unique by code, keep first edition if present
    $uniq = [];
    foreach ($out as $r) {
        $c = (string)$r['code'];
        if ($c === '') continue;
        if (!isset($uniq[$c])) $uniq[$c] = ['code' => $c, 'edition' => (string)($r['edition'] ?? '')];
        if ($uniq[$c]['edition'] === '' && !empty($r['edition'])) $uniq[$c]['edition'] = (string)$r['edition'];
    }
    return array_values($uniq);
}

/**
 * Normalize an IMS folder path so that the first level is always one of:
 * - /IMS/Manuale
 * - /IMS/Procedure
 * - /IMS/Moduli
 * - /IMS/Allegati
 *
 * Legacy paths (HLS taxonomy) are mapped best-effort to the new structure.
 */
function cnx_normalize_ims_folder_path(string $path, string $docType = '', string $templateKey = ''): string {
    $p = rtrim(trim($path), '/');
    if ($p === '' || $p === '/IMS') {
        $p = '';
    }

    // Already normalized (or subfolder of normalized root)
    foreach (['/IMS/Manuale', '/IMS/Procedure', '/IMS/Moduli', '/IMS/Allegati'] as $root) {
        if ($p === $root || strpos($p, $root . '/') === 0) {
            return $p;
        }
    }

    // Legacy: /IMS/00-IMS/<X>
    $legacyMap = [
        '/IMS/00-IMS/Manuale' => '/IMS/Manuale',
        '/IMS/00-IMS/Procedure' => '/IMS/Procedure',
        '/IMS/00-IMS/Moduli' => '/IMS/Moduli',
        '/IMS/00-IMS/Allegati' => '/IMS/Allegati',
    ];
    foreach ($legacyMap as $from => $to) {
        if ($p === $from || strpos($p, $from . '/') === 0) {
            $suffix = substr($p, strlen($from));
            $out = rtrim($to . $suffix, '/');
            return $out !== '' ? $out : $to;
        }
    }

    // Best-effort inference from legacy segments
    $segToRoot = [
        '/Manuale' => '/IMS/Manuale',
        '/Procedure' => '/IMS/Procedure',
        '/Allegati' => '/IMS/Allegati',
        '/Moduli' => '/IMS/Moduli',
    ];
    foreach ($segToRoot as $seg => $root) {
        $pos = strpos($p, $seg);
        if ($pos !== false) {
            $suffix = substr($p, $pos + strlen($seg));
            $out = rtrim($root . $suffix, '/');
            return $out !== '' ? $out : $root;
        }
    }
    if (strpos($p, '/03-Records') !== false || strpos($p, '/Records') !== false) {
        return '/IMS/Moduli';
    }

    // Fallback by doc_type
    $dt = strtolower(trim($docType));
    if ($dt === 'manual') return '/IMS/Manuale';
    if ($dt === 'procedure' || $dt === 'instruction') return '/IMS/Procedure';
    if ($dt === 'policy') return '/IMS/Allegati';
    if (in_array($dt, ['register', 'record', 'form', 'plan'], true)) return '/IMS/Moduli';

    // Last resort: try to infer from template_key
    $k = strtoupper(trim($templateKey));
    if ($k !== '') {
        if (strpos($k, 'MANUALE') !== false || strpos($k, 'MANUAL') !== false) return '/IMS/Manuale';
        if (strpos($k, 'ALLEG') !== false || strpos($k, 'ANNEX') !== false) return '/IMS/Allegati';
        if (strpos($k, 'PROC') !== false || preg_match('/\\bPO_\\d+/i', $k)) return '/IMS/Procedure';
        if (strpos($k, 'MOD') !== false || strpos($k, 'REG') !== false) return '/IMS/Moduli';
    }

    return '/IMS/Moduli';
}

/**
 * Best-effort: find an existing file by exact `name` inside the IMS subtree.
 * Used only when `consulting_plan_compliance_artifacts` mapping storage is missing.
 *
 * @param string $imsFolderFilePath The folder `file_path` for IMS root (e.g. "/TenantName/IMS")
 */
function cnx_find_existing_ims_file_by_name(Database $db, int $tenantId, string $imsFolderFilePath, string $name): int {
    $tenantId = (int)$tenantId;
    $imsFolderFilePath = trim((string)$imsFolderFilePath);
    $name = trim((string)$name);
    if ($tenantId <= 0 || $imsFolderFilePath === '' || $name === '') return 0;

    $like = rtrim($imsFolderFilePath, '/') . '%';
    $row = $db->fetchOne(
        "SELECT f.id
         FROM files f
         JOIN files dir
           ON dir.id = f.folder_id
          AND dir.tenant_id = f.tenant_id
          AND dir.is_folder = 1
          AND dir.deleted_at IS NULL
         WHERE f.tenant_id = ?
           AND f.deleted_at IS NULL
           AND f.is_folder = 0
           AND f.name = ?
           AND dir.file_path LIKE ?
         ORDER BY f.id ASC
         LIMIT 1",
        [$tenantId, $name, $like]
    );
    return (int)($row['id'] ?? 0);
}

/**
 * Ensure a folder path exists under /IMS (idempotent) and return updated path map.
 *
 * @param array<string,int> $pathToFolderId
 */
function cnx_ensure_ims_path(Database $db, int $clientTenantId, int $imsFolderId, int $actorUserId, string $path, array &$pathToFolderId, callable $upsertMap, array &$created, array &$reused): void {
    $path = rtrim(trim($path), '/');
    if ($path === '' || $path === '/IMS') return;
    if (strpos($path, '/IMS/') !== 0) return;
    if (isset($pathToFolderId[$path])) return;

    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    if (empty($parts) || $parts[0] !== 'IMS') return;
    array_shift($parts);
    $currentPath = '/IMS';
    $parentId = $imsFolderId;
    foreach ($parts as $seg) {
        $seg = trim((string)$seg);
        if ($seg === '') continue;
        $currentPath .= '/' . $seg;
        if (isset($pathToFolderId[$currentPath])) {
            $parentId = (int)$pathToFolderId[$currentPath];
            continue;
        }
        $res = cnx_ensure_folder($db, $clientTenantId, $parentId, $seg, $actorUserId);
        $res['created'] ? $created['folders']++ : $reused['folders']++;
        $pathToFolderId[$currentPath] = (int)$res['folder_id'];
        $parentId = (int)$res['folder_id'];
        $upsertMap('folder', 'PATH_' . cnx_norm_key($currentPath), (int)$res['folder_id']);
    }
}

try {
    $raw = cnx_get_raw_request_body();
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Dati non validi', 400);
    }

    // Backward/forward compatibility: accept multiple keys
    $planId = (int)($data['plan_id'] ?? $data['consulting_plan_id'] ?? $data['id'] ?? 0);
    if ($planId <= 0) {
        api_error('plan_id richiesto', 400);
    }

    $options = is_array($data['options'] ?? null) ? $data['options'] : [];
    $createDocuments = (bool)($options['create_documents'] ?? true);
    $createTasks = (bool)($options['create_tasks'] ?? true);
    $createMilestones = (bool)($options['create_milestones'] ?? false);
    $tasksRequested = $createTasks;
    $tasksSkippedReason = null;
    $planItemsCount = null;

    // Optional: provision only selected modules (tenant 28 UI)
    $moduleKeys = [];
    if (isset($data['module_keys']) && is_array($data['module_keys'])) {
        foreach ($data['module_keys'] as $k) {
            if (!is_string($k)) continue;
            $k = strtoupper(trim($k));
            if ($k === '' || strlen($k) > 80) continue;
            if (!preg_match('/^[A-Z0-9_]+$/', $k)) continue;
            $moduleKeys[] = $k;
        }
        $moduleKeys = array_values(array_unique($moduleKeys));
    }

    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($actorUserId <= 0) {
        api_error('Utente non valido', 401);
    }

    // Optional: company profile draft coming from planning UI (used to prefill Document Wizard; NO ISO/UNI text)
    $companyProfile = null;
    if (isset($data['company_profile']) && $data['company_profile'] !== null) {
        if (!is_array($data['company_profile'])) {
            api_error('company_profile deve essere un oggetto JSON', 400);
        }
        $companyProfile = $data['company_profile'];
        // Normalize legacy keys (planning UI may send products instead of products_services)
        if (is_array($companyProfile)) {
            if (!isset($companyProfile['products_services']) && isset($companyProfile['products']) && is_array($companyProfile['products'])) {
                $companyProfile['products_services'] = $companyProfile['products'];
            }
        }
    }

    // Load plan -> client tenant (schema-drift safe)
    // Some installations use start_date/end_date instead of period_start/period_end.
    $cpCols = cnx_cols($db, 'consulting_plans');
    $startExpr = isset($cpCols['period_start']) ? 'period_start' : (isset($cpCols['start_date']) ? 'start_date' : null);
    $endExpr = isset($cpCols['period_end']) ? 'period_end' : (isset($cpCols['end_date']) ? 'end_date' : null);
    $deletedWhere = isset($cpCols['deleted_at']) ? ' AND deleted_at IS NULL' : '';

    $planSql = "SELECT id, client_tenant_id"
        . (isset($cpCols['title']) ? ", title" : ", '' AS title")
        . (isset($cpCols['created_by']) ? ", created_by" : (isset($cpCols['created_by_user_id']) ? ", created_by_user_id AS created_by" : ", NULL AS created_by"))
        . ($startExpr ? ", {$startExpr} AS period_start" : ", NULL AS period_start")
        . ($endExpr ? ", {$endExpr} AS period_end" : ", NULL AS period_end")
        . (isset($cpCols['blueprint_standards_json']) ? ", blueprint_standards_json" : "")
        . (isset($cpCols['estimate_json']) ? ", estimate_json" : "")
        . " FROM consulting_plans WHERE id = ?{$deletedWhere} LIMIT 1";

    $plan = $db->fetchOne($planSql, [$planId]);
    if (!$plan) {
        api_error('Piano non trovato', 404, ['plan_id' => $planId]);
    }
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) {
        api_error('Piano non valido (client_tenant_id mancante)', 400);
    }
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato al tenant cliente', 403);
    }

    // Planning/Wizard coherence:
    // If the plan already has consulting_plan_items, do NOT create additional tasks from provisioning options.
    // (Keeps idempotence and avoids confusing “double planning” across modules.)
    try {
        $hasPlanItems = cnx_table_exists($db, 'consulting_plan_items');
        if ($hasPlanItems) {
            $itCols = cnx_cols($db, 'consulting_plan_items');
            $where = "plan_id = ?";
            $params = [$planId];
            if (!empty($itCols['deleted_at'])) $where .= " AND deleted_at IS NULL";
            $r = $db->fetchOne("SELECT COUNT(*) AS c FROM consulting_plan_items WHERE {$where}", $params);
            $planItemsCount = (int)($r['c'] ?? 0);
        }
    } catch (Throwable $e) {
        $planItemsCount = null;
    }
    if ($tasksRequested && $planItemsCount !== null && $planItemsCount > 0) {
        $createTasks = false;
        $tasksSkippedReason = 'already_present';
    }

    // Storage availability (optional)
    $warnings = [];
    $hasArtifactMap = false;
    try { $hasArtifactMap = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_compliance_artifacts'"); } catch (Throwable $e) { $hasArtifactMap = false; }
    $storageAvailable = $hasArtifactMap;
    if (!$hasArtifactMap) {
        $warnings[] = 'Storage mapping non disponibile: applica migrazione 44 (consulting_plan_compliance_artifacts).';
    }

    $upsertMap = function (string $type, string $key, int $targetId) use ($db, $planId, $clientTenantId, $hasArtifactMap): void {
        if (!$hasArtifactMap) return;
        $db->query(
            "INSERT INTO consulting_plan_compliance_artifacts (plan_id, client_tenant_id, artifact_type, artifact_key, target_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE target_id = VALUES(target_id), updated_at = NOW()",
            [$planId, $clientTenantId, $type, $key, $targetId]
        );
    };

    $getMappedTarget = function (string $type, string $key) use ($db, $planId, $clientTenantId, $hasArtifactMap): int {
        if (!$hasArtifactMap) return 0;
        $row = $db->fetchOne(
            "SELECT target_id
             FROM consulting_plan_compliance_artifacts
             WHERE plan_id = ?
               AND client_tenant_id = ?
               AND artifact_type = ?
               AND artifact_key = ?
             LIMIT 1",
            [$planId, $clientTenantId, $type, $key]
        );
        return (int)($row['target_id'] ?? 0);
    };

    // Determine selected standards (best-effort):
    // - explicit payload standards[]
    // - plan.blueprint_standards_json (legacy)
    // - plan.estimate_json (new flow: services/norms selection)
    $fallbackStandards = [];
    if (isset($data['standards']) && is_array($data['standards'])) {
        foreach ($data['standards'] as $s) {
            if (!is_string($s)) continue;
            $code = cnx_norm_standard_code($s);
            if ($code !== '') $fallbackStandards[] = $code;
        }
    }
    if (isset($plan['blueprint_standards_json']) && is_string($plan['blueprint_standards_json']) && trim($plan['blueprint_standards_json']) !== '') {
        try {
            $arr = json_decode((string)$plan['blueprint_standards_json'], true);
            if (is_array($arr)) {
                foreach ($arr as $s) {
                    $code = cnx_norm_standard_code((string)$s);
                    if ($code !== '') $fallbackStandards[] = $code;
                }
            }
        } catch (Throwable $e) {
            // best-effort
        }
    }
    if (isset($plan['estimate_json']) && $plan['estimate_json'] !== null && $plan['estimate_json'] !== '') {
        try {
            $est = json_decode((string)$plan['estimate_json'], true);
            if (is_array($est)) {
                $estimates = $est['estimates'] ?? null;
                if (is_array($estimates)) {
                    foreach ($estimates as $e) {
                        if (!is_array($e)) continue;
                        $sc = trim((string)($e['service_code'] ?? ''));
                        if ($sc === '') continue;
                        $code = cnx_norm_standard_code($sc);
                        if ($code !== '') $fallbackStandards[] = $code;
                    }
                }
            }
        } catch (Throwable $e) {
            // best-effort
        }
    }
    $fallbackStandards = array_values(array_unique(array_filter($fallbackStandards, static fn($s) => is_string($s) && $s !== '')));

    // Load blueprint from DB if available; else payload; else allow minimal /IMS provisioning
    $bpJson = null;
    $bpTable = false;
    try { $bpTable = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_blueprints'"); } catch (Throwable $e) { $bpTable = false; }
    if ($bpTable) {
        $row = $db->fetchOne(
            "SELECT blueprint_json
             FROM consulting_plan_blueprints
             WHERE plan_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$planId]
        );
        if ($row && !empty($row['blueprint_json'])) {
            $bpJson = (string)$row['blueprint_json'];
        }
    }
    if (!$bpJson && isset($data['blueprint_json'])) {
        $bpJson = is_string($data['blueprint_json'])
            ? (string)$data['blueprint_json']
            : json_encode($data['blueprint_json'], JSON_UNESCAPED_UNICODE);
    }

    $blueprint = null;
    if ($bpJson) {
        $blueprint = json_decode((string)$bpJson, true);
        if (!is_array($blueprint)) {
            $warnings[] = 'Blueprint JSON non valido: continuo con provisioning basato su moduli/template (best-effort).';
            $blueprint = null;
        }
    } else {
        $warnings[] = $bpTable
            ? 'Blueprint non trovato per il piano: continuo con provisioning basato su moduli/template (best-effort).'
            : 'Storage blueprint non disponibile: continuo con provisioning basato su moduli/template (best-effort).';
    }

    $bp = is_array($blueprint) ? (array)(($blueprint['compliance_blueprint'] ?? null) ?: $blueprint) : [];
    $repo = isset($bp['repository_structure']) && is_array($bp['repository_structure']) ? $bp['repository_structure'] : [];
    $docs = isset($bp['documents']) && is_array($bp['documents']) ? $bp['documents'] : [];
    $recs = isset($bp['records_register']) && is_array($bp['records_register']) ? $bp['records_register'] : [];

    // IMS v2: try to use template catalog (migration 45) when available.
    // If missing and user is super_admin, attempt best-effort auto-apply (same pattern used for migrations 41/42 in legacy endpoints).
    $role = (string)($userInfo['role'] ?? 'user');
    $mode = strtolower(trim((string)($options['mode'] ?? 'full')));
    if ($mode !== 'minimal') $mode = 'full';

    $hasStd = cnx_table_exists($db, 'compliance_standards');
    $hasTpl = cnx_table_exists($db, 'compliance_artifact_templates');
    $hasProgStd = cnx_table_exists($db, 'compliance_program_standards');
    if ((!$hasStd || !$hasTpl || !$hasProgStd) && $role === 'super_admin') {
        try {
            cnx_apply_sql_migration_file($db->getConnection(), __DIR__ . '/../../database/migrations/45_compliance_templates_and_standards.sql');
            $hasStd = cnx_table_exists($db, 'compliance_standards');
            $hasTpl = cnx_table_exists($db, 'compliance_artifact_templates');
            $hasProgStd = cnx_table_exists($db, 'compliance_program_standards');
        } catch (Throwable $e) {
            $warnings[] = 'Impossibile applicare migrazione 45 automaticamente: ' . $e->getMessage();
        }
    }

    $selectedStandards = cnx_extract_standards_from_blueprint($blueprint, $fallbackStandards);
    $selectedCodes = array_map(static fn($s) => (string)$s['code'], $selectedStandards);
    if (empty($selectedCodes)) $selectedCodes = ['ISO9001'];

    // Resolve family/edition via standards catalog when available
    $standardMeta = []; // code => ['edition_label'=>..., 'family'=>...]
    if ($hasStd && !empty($selectedCodes)) {
        try {
            $in = implode(',', array_fill(0, count($selectedCodes), '?'));
            $rows = $db->fetchAll(
                "SELECT code, edition_label, family
                 FROM compliance_standards
                 WHERE code IN ($in) AND is_active = 1",
                $selectedCodes
            ) ?: [];
            foreach ($rows as $r) {
                $c = (string)($r['code'] ?? '');
                if ($c === '') continue;
                $standardMeta[$c] = [
                    'edition_label' => (string)($r['edition_label'] ?? ''),
                    'family' => (string)($r['family'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // non-blocking
        }
    }
    $hasHlsSelected = false;
    foreach ($selectedCodes as $c) {
        $fam = (string)($standardMeta[$c]['family'] ?? '');
        if ($fam === 'HLS') { $hasHlsSelected = true; break; }
    }
    if (!$hasHlsSelected) {
        // heuristic default: ISO9/14/45/27/42 are HLS
        foreach ($selectedCodes as $c) {
            if (in_array($c, ['ISO9001','ISO14001','ISO45001','ISO27001','ISO42001'], true)) { $hasHlsSelected = true; break; }
        }
    }

    $useTemplates = false;
    $templates = [];
    if ($hasTpl) {
        try {
            // Feature-detect optional columns from migration 46 (schema drift safe)
            $tplCols = [];
            try {
                $crows = $db->fetchAll(
                    "SELECT COLUMN_NAME AS c
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'compliance_artifact_templates'"
                ) ?: [];
                foreach ($crows as $cr) {
                    if (!empty($cr['c'])) $tplCols[(string)$cr['c']] = true;
                }
            } catch (Throwable $e) { $tplCols = []; }

            $selExtra = '';
            if (!empty($tplCols)) {
                if (isset($tplCols['source_file_id'])) $selExtra .= ", source_file_id";
                if (isset($tplCols['source_tenant_id'])) $selExtra .= ", source_tenant_id";
                if (isset($tplCols['content_mode'])) $selExtra .= ", content_mode";
                if (isset($tplCols['doc_code_template'])) $selExtra .= ", doc_code_template";
            }

            // If module_keys provided and module tables are available (migration 46), resolve templates via module items
            $hasModules = cnx_table_exists($db, 'compliance_template_modules') && cnx_table_exists($db, 'compliance_template_module_items');

            // Default for ISO9001: prefer the full pack module when available (prevents partial deliverables set).
            if (empty($moduleKeys) && $hasModules && in_array('ISO9001', $selectedCodes, true)) {
                try {
                    $hasSgq = (bool)$db->fetchOne(
                        "SELECT 1 AS ok FROM compliance_template_modules WHERE module_key = 'ISO9001_SGQ_COMPLETO' AND is_active = 1 LIMIT 1"
                    );
                    if ($hasSgq) {
                        $moduleKeys = ['ISO9001_SGQ_COMPLETO'];
                    }
                } catch (Throwable $e) {
                    // non-blocking
                }
            }
            if (!empty($moduleKeys) && $hasModules) {
                $in = implode(',', array_fill(0, count($moduleKeys), '?'));
                $params = $moduleKeys;
                $rows = $db->fetchAll(
                    "SELECT t.template_key, t.title, t.doc_type, t.file_kind, t.folder_path, t.filename_template,
                            t.tags_json, t.clause_refs_json, t.is_common_hls, t.is_active
                            $selExtra,
                            MIN(i.sort_order) AS sort_order
                     FROM compliance_template_modules m
                     JOIN compliance_template_module_items i ON i.module_id = m.id
                     JOIN compliance_artifact_templates t ON t.template_key = i.template_key
                     WHERE m.module_key IN ($in)
                       AND m.is_active = 1
                       AND t.is_active = 1
                     GROUP BY t.template_key, t.title, t.doc_type, t.file_kind, t.folder_path, t.filename_template,
                              t.tags_json, t.clause_refs_json, t.is_common_hls, t.is_active" . (!empty($selExtra) ? ", " . trim($selExtra, ", ") : "") . "
                     ORDER BY sort_order ASC, t.is_common_hls DESC, t.template_key ASC",
                    $params
                ) ?: [];
                if (empty($rows)) {
                    $warnings[] = 'Moduli selezionati senza template: fallback su selezione per standard.';
                }
            }

            // Default selection by standard/is_common_hls
            if (!isset($rows)) {
                $rows = $db->fetchAll(
                    "SELECT template_key, title, doc_type, file_kind, folder_path, filename_template,
                            tags_json, clause_refs_json, is_common_hls, is_active
                            $selExtra
                     FROM compliance_artifact_templates
                     WHERE is_active = 1
                     ORDER BY is_common_hls DESC, template_key ASC"
                ) ?: [];
            }

            foreach ($rows as $r) {
                $tKey = trim((string)($r['template_key'] ?? ''));
                if ($tKey === '') continue;
                $isCommon = (int)($r['is_common_hls'] ?? 0) ? true : false;
                $refs = null;
                try { $refs = $r['clause_refs_json'] ? (json_decode((string)$r['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $refs = null; }

                // When using modules, we already filtered. Otherwise filter by standard coverage.
                if (empty($moduleKeys)) {
                    $covers = false;
                    if (is_array($refs)) {
                        foreach ($selectedCodes as $c) {
                            if (array_key_exists($c, $refs)) { $covers = true; break; }
                        }
                    }
                    $include = ($isCommon && $hasHlsSelected) || $covers;
                    if ($mode === 'minimal') {
                        // Minimal: keep only core HLS templates + ISO9001 refs if present
                        $include = ($isCommon && $hasHlsSelected) || (is_array($refs) && array_key_exists('ISO9001', $refs));
                    }
                    if (!$include) continue;
                }

                $contentMode = (string)($r['content_mode'] ?? 'placeholder');
                if (!in_array($contentMode, ['placeholder', 'copy_source'], true)) $contentMode = 'placeholder';
                $sourceTenantId = (int)($r['source_tenant_id'] ?? 28);
                if ($sourceTenantId <= 0) $sourceTenantId = 28;
                $sourceFileId = (int)($r['source_file_id'] ?? 0);
                $docCodeTpl = (string)($r['doc_code_template'] ?? '');

                $templates[] = [
                    'template_key' => $tKey,
                    'title' => (string)($r['title'] ?? ''),
                    'doc_type' => (string)($r['doc_type'] ?? 'document'),
                    'file_kind' => (string)($r['file_kind'] ?? 'docx'),
                    'folder_path' => (string)($r['folder_path'] ?? '/IMS'),
                    'filename_template' => (string)($r['filename_template'] ?? ''),
                    'clause_refs' => $refs,
                    'is_common_hls' => $isCommon,
                    'content_mode' => $contentMode,
                    'source_tenant_id' => $sourceTenantId,
                    'source_file_id' => $sourceFileId > 0 ? $sourceFileId : null,
                    'doc_code_template' => $docCodeTpl,
                ];
            }
            if (!empty($templates)) $useTemplates = true;
        } catch (Throwable $e) {
            $warnings[] = 'Catalogo template IMS non disponibile: ' . $e->getMessage();
        }
    } else {
        // Not fatal: keep legacy blueprint-driven provisioning
        $warnings[] = 'Catalogo template IMS non disponibile: applica migrazione 45 (templates + standards).';
    }

    // Ensure tenant root folder exists
    $tenantRow = $db->fetchOne("SELECT name, COALESCE(denominazione, name) AS denominazione FROM tenants WHERE id = ? LIMIT 1", [$clientTenantId]);
    $tenantName = (string)($tenantRow['denominazione'] ?? $tenantRow['name'] ?? ('Tenant ' . $clientTenantId));
    $root = cnx_ensure_tenant_root_folder($db, $clientTenantId, $tenantName);
    $rootFolderId = (int)($root['folder_id'] ?? 0);
    if ($rootFolderId <= 0) {
        api_error('Impossibile creare/rilevare la cartella root del tenant cliente', 503);
    }

    // Ensure IMS folder under tenant root
    $imsKey = 'IMS_ROOT';
    $imsMapped = $getMappedTarget('folder', $imsKey);
    $imsRes = null;
    if ($imsMapped > 0) {
        // verify exists and is folder in this tenant
        $row = $db->fetchOne("SELECT id FROM files WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL LIMIT 1", [$imsMapped, $clientTenantId]);
        if ($row) {
            $imsRes = ['folder_id' => $imsMapped, 'created' => false];
        }
    }
    if (!$imsRes) {
        $imsRes = cnx_ensure_folder($db, $clientTenantId, $rootFolderId, 'IMS', $actorUserId);
        $upsertMap('folder', $imsKey, (int)$imsRes['folder_id']);
    }
    $imsFolderId = (int)($imsRes['folder_id'] ?? 0);
    if ($imsFolderId <= 0) {
        api_error('Impossibile creare/rilevare la cartella IMS', 503);
    }

    // Best-effort IMS subtree lookup (used only when mapping storage is missing)
    $imsFolderFilePath = '';
    if (!$hasArtifactMap) {
        try {
            $row = $db->fetchOne(
                "SELECT file_path
                 FROM files
                 WHERE id = ?
                   AND tenant_id = ?
                   AND is_folder = 1
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$imsFolderId, $clientTenantId]
            );
            if ($row && !empty($row['file_path'])) {
                $imsFolderFilePath = (string)$row['file_path'];
            }
        } catch (Throwable $e) {
            $imsFolderFilePath = '';
        }
    }

    $created = ['folders' => 0, 'documents' => 0, 'tasks' => 0, 'events' => 0];
    $reused  = ['folders' => 0, 'documents' => 0, 'tasks' => 0, 'events' => 0];
    $imsRes['created'] ? $created['folders']++ : $reused['folders']++;

    // IMS v2 stable storage (client tenant): compliance_programs + program_standards + artifacts
    $complianceStorageAvailable = false;
    $programId = 0;
    $hasPrograms = cnx_table_exists($db, 'compliance_programs');
    $hasArtifacts = cnx_table_exists($db, 'compliance_artifacts');
    $hasRuns = cnx_table_exists($db, 'compliance_provisioning_runs');
    if ((!$hasPrograms || !$hasArtifacts || !$hasRuns) && $role === 'super_admin') {
        try {
            cnx_apply_sql_migration_file($db->getConnection(), __DIR__ . '/../../database/migrations/41_compliance_programs.sql');
            $hasPrograms = cnx_table_exists($db, 'compliance_programs');
            $hasArtifacts = cnx_table_exists($db, 'compliance_artifacts');
            $hasRuns = cnx_table_exists($db, 'compliance_provisioning_runs');
        } catch (Throwable $e) {
            $warnings[] = 'Impossibile applicare migrazione 41 automaticamente: ' . $e->getMessage();
        }
    }
    $complianceStorageAvailable = ($hasPrograms && $hasArtifacts);
    if (!$complianceStorageAvailable) {
        $warnings[] = 'Storage compliance non disponibile: applica migrazione 41 (compliance programs/artifacts).';
    } else {
        // determine primary standard
        $primaryCode = in_array('ISO9001', $selectedCodes, true) ? 'ISO9001' : (string)($selectedCodes[0] ?? 'ISO9001');
        $editionFromBlueprint = '';
        foreach ($selectedStandards as $s) {
            if ((string)$s['code'] === $primaryCode && !empty($s['edition'])) { $editionFromBlueprint = (string)$s['edition']; break; }
        }
        $primaryEdition = (string)($standardMeta[$primaryCode]['edition_label'] ?? $editionFromBlueprint);
        if ($primaryEdition === '') $primaryEdition = $editionFromBlueprint;
        if ($primaryEdition === '') $primaryEdition = $primaryCode;

        $existingProgram = $db->fetchOne(
            "SELECT id, standard_edition
             FROM compliance_programs
             WHERE tenant_id = ?
               AND standard_code = ?
               AND source_consulting_plan_id = ?
             LIMIT 1",
            [$clientTenantId, $primaryCode, $planId]
        );
        if ($existingProgram && !empty($existingProgram['id'])) {
            $programId = (int)$existingProgram['id'];
            if (empty($existingProgram['standard_edition']) && $primaryEdition !== '') {
                try {
                    $db->update('compliance_programs', [
                        'standard_edition' => $primaryEdition,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $programId]);
                } catch (Throwable $e) {}
            }
        } else {
            $programId = (int)$db->insert('compliance_programs', [
                'tenant_id' => $clientTenantId,
                'standard_code' => $primaryCode,
                'standard_edition' => $primaryEdition,
                'scope_text' => null,
                'sector_text' => null,
                'size_text' => null,
                'source_consulting_plan_id' => $planId,
                'created_by_user_id' => $actorUserId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // program standards join (migration 45)
        if ($programId > 0 && $hasProgStd) {
            foreach ($selectedCodes as $c) {
                $ed = (string)($standardMeta[$c]['edition_label'] ?? '');
                if ($ed === '') {
                    foreach ($selectedStandards as $s) { if ((string)$s['code'] === $c && !empty($s['edition'])) { $ed = (string)$s['edition']; break; } }
                }
                if ($ed === '') $ed = $c;
                try {
                    $db->query(
                        "INSERT INTO compliance_program_standards (program_id, standard_code, edition_label, created_at)
                         VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE edition_label = VALUES(edition_label)",
                        [$programId, $c, $ed]
                    );
                } catch (Throwable $e) {
                    // non-blocking
                }
            }
        }

        // Persist company profile (migration 47) once we have a program_id (best-effort; schema-drift safe)
        if ($programId > 0 && is_array($companyProfile) && !empty($companyProfile)) {
            $hasProfiles = cnx_table_exists($db, 'compliance_program_profiles');
            if ($hasProfiles) {
                try {
                    $profileJson = json_encode($companyProfile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $db->query(
                        "INSERT INTO compliance_program_profiles (tenant_id, program_id, profile_json, updated_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE profile_json = VALUES(profile_json), updated_by = VALUES(updated_by), updated_at = NOW()",
                        [$clientTenantId, $programId, $profileJson, $actorUserId]
                    );
                } catch (Throwable $e) {
                    $warnings[] = 'Profilo azienda non salvato (migrazione 47): ' . $e->getMessage();
                }
            } else {
                $warnings[] = 'Profilo azienda non salvato: applica migrazione 47 (compliance_program_profiles).';
            }
        }
    }

    // Ensure IMS subfolders:
    // - Always create required /IMS structure (product UX: stable /IMS taxonomy)
    // - Merge blueprint repository_structure (if any)
    // - Merge template folder paths (if catalog is available)
    $pathToFolderId = ['/IMS' => $imsFolderId];

    $baseImsPaths = [
        '/IMS/Manuale',
        '/IMS/Procedure',
        '/IMS/Moduli',
        '/IMS/Allegati',
    ];

    $allPaths = [];
    foreach ($baseImsPaths as $p) $allPaths[] = (string)$p;
    foreach ($repo as $p) {
        if (!is_array($p)) continue;
        $path = trim((string)($p['path'] ?? ''));
        if ($path !== '') $allPaths[] = cnx_normalize_ims_folder_path($path);
    }
    if ($useTemplates) {
        foreach ($templates as $t) {
            $fp = trim((string)($t['folder_path'] ?? ''));
            if ($fp !== '') $allPaths[] = cnx_normalize_ims_folder_path($fp, (string)($t['doc_type'] ?? ''), (string)($t['template_key'] ?? ''));
        }
    }

    // Ensure unique paths, stable order (base first)
    $seen = [];
    $uniqPaths = [];
    foreach ($allPaths as $p) {
        $p = cnx_normalize_ims_folder_path((string)$p);
        $p = rtrim(trim((string)$p), '/');
        if ($p === '' || $p === '/IMS') continue;
        if (strpos($p, '/IMS') !== 0) continue;
        if (isset($seen[$p])) continue;
        $seen[$p] = true;
        $uniqPaths[] = $p;
    }
    foreach ($uniqPaths as $p) {
        cnx_ensure_ims_path($db, $clientTenantId, $imsFolderId, $actorUserId, $p, $pathToFolderId, $upsertMap, $created, $reused);
    }

    // Documents placeholders (no ISO text)
    $artifactToFileId = [];
    if ($createDocuments) {
        if ($useTemplates) {
            foreach ($templates as $t) {
                $tKey = (string)($t['template_key'] ?? '');
                if ($tKey === '') continue;
                $title = trim((string)($t['title'] ?? 'Documento'));
                $fileKind = strtolower(trim((string)($t['file_kind'] ?? 'docx')));
                $folderPathRaw = rtrim(trim((string)($t['folder_path'] ?? '/IMS')), '/');
                $folderPath = cnx_normalize_ims_folder_path($folderPathRaw, (string)($t['doc_type'] ?? ''), $tKey);
                $filename = trim((string)($t['filename_template'] ?? $title));
                $targetName = $filename;
                if ($fileKind !== '' && !str_ends_with(strtolower($targetName), '.' . $fileKind)) {
                    $targetName .= '.' . $fileKind;
                }

                $folderId = $imsFolderId;
                if ($folderPath !== '' && isset($pathToFolderId[$folderPath])) {
                    $folderId = (int)$pathToFolderId[$folderPath];
                }

                // Stable artifact key: template_key
                $artifactKey = $tKey;

                $mapped = $getMappedTarget('document', $artifactKey);
                $docRes = null;
                if ($mapped > 0) {
                    $row = $db->fetchOne("SELECT id FROM files WHERE id = ? AND tenant_id = ? AND is_folder = 0 AND deleted_at IS NULL LIMIT 1", [$mapped, $clientTenantId]);
                    if ($row) $docRes = ['file_id' => $mapped, 'created' => false];
                }

                // Best-effort reuse (when mapping storage is missing): find by name anywhere under /IMS
                if (
                    !$docRes
                    && !$hasArtifactMap
                    && $imsFolderFilePath !== ''
                    && $targetName !== ''
                ) {
                    try {
                        $existingId = cnx_find_existing_ims_file_by_name($db, $clientTenantId, $imsFolderFilePath, $targetName);
                        if ($existingId > 0) {
                            $docRes = ['file_id' => $existingId, 'created' => false];
                        }
                    } catch (Throwable $e) {
                        // non-blocking
                    }
                }

                if (!$docRes) {
                    try {
                        $contentMode = strtolower(trim((string)($t['content_mode'] ?? 'placeholder')));
                        $sourceTenantId = (int)($t['source_tenant_id'] ?? 28);
                        $sourceFileId = (int)($t['source_file_id'] ?? 0);
                        if ($sourceTenantId <= 0) $sourceTenantId = 28;

                        if ($contentMode === 'copy_source' && $sourceFileId > 0) {
                            // Copy master template file from tenant 28 (or configured source tenant)
                            $docRes = cnx_copy_file_between_tenants($db, $sourceTenantId, $sourceFileId, $clientTenantId, $folderId, $actorUserId, $targetName);
                        } else {
                            // Default: create minimal placeholder (current behavior)
                            $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, $fileKind, $targetName);
                        }

                        // Apply a minimal prefill on newly created documents:
                        // - Ensures DOCX header/footer (page numbers) are present by default
                        // - Optionally fills placeholders from company_profile (if provided)
                        if (!empty($docRes['created']) && !empty($docRes['file_id']) && in_array($fileKind, ['docx','xlsx'], true)) {
                            try {
                                $rowF = $db->fetchOne("SELECT file_path, name FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1", [(int)$docRes['file_id'], $clientTenantId]);
                                if ($rowF && !empty($rowF['file_path'])) {
                                    $abs = rtrim(FileHelper::getTenantUploadPath($clientTenantId), '/\\') . '/' . ltrim((string)$rowF['file_path'], '/\\');
                                    $repl = (!empty($companyProfile) && is_array($companyProfile)) ? cnx_profile_to_placeholders($companyProfile) : [];
                                    if (empty($repl['company_name'])) $repl['company_name'] = (string)$tenantName;
                                    $repl['doc_title'] = $title;
                                    if (empty($repl['doc_version'])) {
                                        $repl['doc_version'] = '0.1';
                                    }
                                    $docCodeTpl = trim((string)($t['doc_code_template'] ?? ''));
                                    $repl['doc_code'] = $docCodeTpl !== '' ? $docCodeTpl : $artifactKey;
                                    // Header friendly date (italian format)
                                    $repl['doc_date'] = date('d/m/Y');
                                    $applyWarnings = [];
                                    if ($fileKind === 'docx') {
                                        cnx_apply_placeholders_to_docx($abs, $repl, $applyWarnings, ['header_enabled' => true]);
                                    } else {
                                        cnx_apply_placeholders_to_xlsx($abs, $repl, $applyWarnings);
                                    }
                                    foreach ($applyWarnings as $w) {
                                        $warnings[] = "Prefill ({$artifactKey}): " . (string)$w;
                                    }
                                }
                            } catch (Throwable $e) {
                                $warnings[] = "Prefill ({$artifactKey}) fallito: " . $e->getMessage();
                            }
                        }

                        $upsertMap('document', $artifactKey, (int)$docRes['file_id']);
                    } catch (Throwable $e) {
                        $warnings[] = "Documento placeholder non creato ({$artifactKey}): " . $e->getMessage();
                        continue;
                    }
                }

                // Best-effort: keep file placed under normalized IMS folder (no disk move, only folder_id)
                if (!empty($docRes['file_id']) && $folderId > 0) {
                    try {
                        $db->query(
                            "UPDATE files
                             SET folder_id = ?, updated_at = NOW()
                             WHERE id = ?
                               AND tenant_id = ?
                               AND is_folder = 0
                               AND deleted_at IS NULL
                             LIMIT 1",
                            [$folderId, (int)$docRes['file_id'], $clientTenantId]
                        );
                    } catch (Throwable $e) {
                        // non-blocking
                    }
                }

                $docRes['created'] ? $created['documents']++ : $reused['documents']++;
                $artifactToFileId[$artifactKey] = (int)($docRes['file_id'] ?? 0);
            }

            // Persist artifacts mapping in compliance tables (stable, idempotent)
            if ($complianceStorageAvailable && $programId > 0) {
                foreach ($templates as $t) {
                    $tKey = (string)($t['template_key'] ?? '');
                    if ($tKey === '') continue;
                    $title = trim((string)($t['title'] ?? 'Documento'));
                    $docType = strtolower(trim((string)($t['doc_type'] ?? 'document')));
                    $folderPathRaw = rtrim(trim((string)($t['folder_path'] ?? '/IMS')), '/');
                    $folderPath = cnx_normalize_ims_folder_path($folderPathRaw, $docType, $tKey);
                    $folderId = ($folderPath !== '' && isset($pathToFolderId[$folderPath])) ? (int)$pathToFolderId[$folderPath] : $imsFolderId;
                    $fileId = (int)($artifactToFileId[$tKey] ?? 0);

                    // Filter clause refs to selected standards (multi-standard object)
                    $refsObj = $t['clause_refs'] ?? null;
                    $filteredRefs = null;
                    if (is_array($refsObj)) {
                        $filteredRefs = [];
                        foreach ($selectedCodes as $c) {
                            if (isset($refsObj[$c])) $filteredRefs[$c] = $refsObj[$c];
                        }
                    }
                    $refsJson = $filteredRefs ? json_encode($filteredRefs, JSON_UNESCAPED_UNICODE) : null;

                    try {
                        $existing = $db->fetchOne(
                            "SELECT id, file_id
                             FROM compliance_artifacts
                             WHERE program_id = ? AND artifact_key = ?
                             LIMIT 1",
                            [$programId, $tKey]
                        );
                        if ($existing && !empty($existing['id'])) {
                            $updates = [
                                'title' => $title,
                                'artifact_type' => $docType,
                                'clause_refs_json' => $refsJson,
                                'folder_id' => $folderId,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ];
                            if ($fileId > 0 && (int)($existing['file_id'] ?? 0) <= 0) {
                                $updates['file_id'] = $fileId;
                            } elseif ($fileId > 0) {
                                // keep in sync if file changed (rare)
                                $updates['file_id'] = $fileId;
                            }
                            $db->update('compliance_artifacts', $updates, ['id' => (int)$existing['id']]);
                        } else {
                            // Default status:
                            // - "se applicabile" deliverables start as 'draft' (Da valutare)
                            // - others start as 'todo' (Applicabile)
                            $defaultStatus = (stripos($title, 'se applicabile') !== false) ? 'draft' : 'todo';
                            $db->insert('compliance_artifacts', [
                                'program_id' => $programId,
                                'artifact_key' => $tKey,
                                'title' => $title,
                                'artifact_type' => $docType,
                                'clause_refs_json' => $refsJson,
                                'owner_label' => null,
                                'owner_user_id' => null,
                                'due_date' => null,
                                'status' => $defaultStatus,
                                'file_id' => ($fileId > 0 ? $fileId : null),
                                'folder_id' => $folderId,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Artifact mapping non salvato ({$tKey}): " . $e->getMessage();
                    }
                }
            }
        } else {
        foreach ($docs as $d) {
            if (!is_array($d)) continue;
            $docCode = trim((string)($d['doc_code'] ?? ''));
            $title = trim((string)($d['title'] ?? 'Documento'));
            $docType = strtolower(trim((string)($d['doc_type'] ?? 'docx')));
            if ($docType === '') $docType = 'docx';

            $repoPath = trim((string)($d['repository_path'] ?? ''));
            $folderId = $imsFolderId;
            if ($repoPath !== '' && strpos($repoPath, '/IMS') === 0) {
                $repoPath = cnx_normalize_ims_folder_path(rtrim($repoPath, '/'));
                if (isset($pathToFolderId[$repoPath])) $folderId = (int)$pathToFolderId[$repoPath];
            }

            $artifactKey = $docCode !== '' ? ('DOC_' . cnx_norm_key($docCode)) : ('DOC_' . cnx_norm_key($title));
            if (strlen($artifactKey) > 180) $artifactKey = substr($artifactKey, 0, 180);

            $mapped = $getMappedTarget('document', $artifactKey);
            $docRes = null;
            if ($mapped > 0) {
                $row = $db->fetchOne("SELECT id FROM files WHERE id = ? AND tenant_id = ? AND is_folder = 0 AND deleted_at IS NULL LIMIT 1", [$mapped, $clientTenantId]);
                if ($row) {
                    $docRes = ['file_id' => $mapped, 'created' => false];
                }
            }
            if (!$docRes) {
                $filename = ($docCode !== '' ? ($docCode . ' - ' . $title) : $title);
                // Match placeholder helper sanitization so name-based reuse works reliably
                $targetName = preg_replace('/[^a-zA-Z0-9\\s\\-_\\.]/', '', $filename) ?: $filename;
                if (strlen($targetName) > 160) $targetName = substr($targetName, 0, 160);
                if (!str_ends_with(strtolower($targetName), '.' . $docType)) {
                    $targetName .= '.' . $docType;
                }

                // Best-effort reuse (when mapping storage is missing): find by name anywhere under /IMS
                if (!$hasArtifactMap && $imsFolderFilePath !== '' && $targetName !== '') {
                    try {
                        $existingId = cnx_find_existing_ims_file_by_name($db, $clientTenantId, $imsFolderFilePath, $targetName);
                        if ($existingId > 0) {
                            $docRes = ['file_id' => $existingId, 'created' => false];
                        }
                    } catch (Throwable $e) {
                        // non-blocking
                    }
                }
                if ($docRes) {
                    // continue (we reused an existing file)
                } else {
                try {
                    $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, $docType, $targetName);
                    $upsertMap('document', $artifactKey, (int)$docRes['file_id']);
                } catch (Throwable $e) {
                    $warnings[] = "Documento placeholder non creato ({$artifactKey}): " . $e->getMessage();
                    continue; // non-blocking: folders/tasks/milestones can still proceed
                }
                }
            }

            // Best-effort: keep file placed under normalized IMS folder (no disk move, only folder_id)
            if (!empty($docRes['file_id']) && $folderId > 0) {
                try {
                    $db->query(
                        "UPDATE files
                         SET folder_id = ?, updated_at = NOW()
                         WHERE id = ?
                           AND tenant_id = ?
                           AND is_folder = 0
                           AND deleted_at IS NULL
                         LIMIT 1",
                        [$folderId, (int)$docRes['file_id'], $clientTenantId]
                    );
                } catch (Throwable $e) {
                    // non-blocking
                }
            }

            $docRes['created'] ? $created['documents']++ : $reused['documents']++;
            $artifactToFileId[$artifactKey] = (int)($docRes['file_id'] ?? 0);
        }

        foreach ($recs as $r) {
            if (!is_array($r)) continue;
            $recordName = trim((string)($r['record_name'] ?? 'Registro'));
            $artifactKey = 'REC_' . cnx_norm_key($recordName);
            if (strlen($artifactKey) > 180) $artifactKey = substr($artifactKey, 0, 180);

            // Default records path if present in blueprint; else under /IMS
            $folderId = $imsFolderId;
            $repoPath = trim((string)($r['repository_path'] ?? ''));
            if ($repoPath !== '' && strpos($repoPath, '/IMS') === 0) {
                $repoPath = rtrim($repoPath, '/');
                if (isset($pathToFolderId[$repoPath])) $folderId = (int)$pathToFolderId[$repoPath];
            }

            $mapped = $getMappedTarget('document', $artifactKey);
            $docRes = null;
            if ($mapped > 0) {
                $row = $db->fetchOne("SELECT id FROM files WHERE id = ? AND tenant_id = ? AND is_folder = 0 AND deleted_at IS NULL LIMIT 1", [$mapped, $clientTenantId]);
                if ($row) $docRes = ['file_id' => $mapped, 'created' => false];
            }
            if (!$docRes) {
                try {
                    $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, 'txt', $recordName);
                    $upsertMap('document', $artifactKey, (int)$docRes['file_id']);
                } catch (Throwable $e) {
                    $warnings[] = "Registro placeholder non creato ({$artifactKey}): " . $e->getMessage();
                    continue; // non-blocking
                }
            }

            $docRes['created'] ? $created['documents']++ : $reused['documents']++;
            $artifactToFileId[$artifactKey] = (int)($docRes['file_id'] ?? 0);
        }
        }
    }

    // Tasks (Action Plan) — best-effort
    if ($createTasks) {
        $hasTasks = false;
        try { $hasTasks = (bool)$db->fetchOne("SHOW TABLES LIKE 'tasks'"); } catch (Throwable $e) { $hasTasks = false; }
        if (!$hasTasks) {
            $warnings[] = 'Modulo task non disponibile: task non creati.';
        } else {
            $tasksCols = cnx_cols($db, 'tasks');
            $hasTaskHistory = false;
            try { $hasTaskHistory = (bool)$db->fetchOne("SHOW TABLES LIKE 'task_history'"); } catch (Throwable $e) { $hasTaskHistory = false; }
            $histCols = $hasTaskHistory ? cnx_cols($db, 'task_history') : [];

            // Find a default assignee (manager/admin of client tenant) — best-effort
            $assigneeId = null;
            try {
                $u = $db->fetchOne(
                    "SELECT id FROM users
                     WHERE tenant_id = ?
                       AND deleted_at IS NULL
                       AND role IN ('manager','admin')
                     ORDER BY FIELD(role,'manager','admin'), id ASC
                     LIMIT 1",
                    [$clientTenantId]
                );
                $assigneeId = $u ? (int)$u['id'] : null;
            } catch (Throwable $e) {
                $assigneeId = null;
            }
            if (!$assigneeId) {
                $warnings[] = 'Nessun manager/admin trovato nel tenant cliente: task creati non assegnati.';
            }

            $deliverables = [];
            if ($useTemplates) {
                foreach ($templates as $t) {
                    $k = (string)($t['template_key'] ?? '');
                    if ($k === '') continue;
                    $title = trim((string)($t['title'] ?? 'Deliverable'));
                    $refsObj = $t['clause_refs'] ?? null;
                    $refsFlat = [];
                    if (is_array($refsObj)) {
                        foreach ($selectedCodes as $sc) {
                            if (isset($refsObj[$sc]) && is_array($refsObj[$sc])) {
                                foreach ($refsObj[$sc] as $cr) $refsFlat[] = (string)$cr;
                            }
                        }
                        $refsFlat = array_values(array_unique(array_filter($refsFlat)));
                    }
                    $deliverables[] = ['artifact_key' => $k, 'title' => $title, 'clause_refs' => $refsFlat];
                }
            } else {
                foreach ($docs as $d) {
                    if (!is_array($d)) continue;
                    $docCode = trim((string)($d['doc_code'] ?? ''));
                    $title = trim((string)($d['title'] ?? 'Deliverable'));
                    $clauseRefs = (isset($d['clause_refs']) && is_array($d['clause_refs'])) ? array_values($d['clause_refs']) : [];
                    $artifactKey = $docCode !== '' ? ('DOC_' . cnx_norm_key($docCode)) : ('DOC_' . cnx_norm_key($title));
                    if (strlen($artifactKey) > 180) $artifactKey = substr($artifactKey, 0, 180);
                    $deliverables[] = ['artifact_key' => $artifactKey, 'title' => $title, 'clause_refs' => $clauseRefs];
                }
                foreach ($recs as $r) {
                    if (!is_array($r)) continue;
                    $recordName = trim((string)($r['record_name'] ?? 'Registro'));
                    $clauseRefs = (isset($r['clause_refs']) && is_array($r['clause_refs'])) ? array_values($r['clause_refs']) : [];
                    $artifactKey = 'REC_' . cnx_norm_key($recordName);
                    if (strlen($artifactKey) > 180) $artifactKey = substr($artifactKey, 0, 180);
                    $deliverables[] = ['artifact_key' => $artifactKey, 'title' => $recordName, 'clause_refs' => $clauseRefs];
                }
            }

            $tag = "[CNX_PLAN_ID:$planId]";
            foreach ($deliverables as $dv) {
                $akey = (string)$dv['artifact_key'];
                $existingTaskId = $getMappedTarget('task', $akey);
                if ($existingTaskId <= 0) {
                    // best-effort dedup (no storage): title + tag in description
                    if (!$hasArtifactMap) {
                        $row = $db->fetchOne(
                            "SELECT id
                             FROM tasks
                             WHERE tenant_id = ?
                               AND title = ?
                               AND description LIKE ?
                             ORDER BY id DESC
                             LIMIT 1",
                            [$clientTenantId, '[IMS] ' . (string)$dv['title'], '%' . $tag . '%']
                        );
                        $existingTaskId = (int)($row['id'] ?? 0);
                    }
                }
                if ($existingTaskId > 0) {
                    $reused['tasks']++;
                    $upsertMap('task', $akey, $existingTaskId);
                    continue;
                }

                $fileId = (int)($artifactToFileId[$akey] ?? 0);
                $descLines = [];
                $descLines[] = $tag;
                $descLines[] = "Deliverable IMS (document-first): " . (string)$dv['title'];
                if (!empty($dv['clause_refs'])) {
                    $descLines[] = "Clause refs: " . implode(', ', array_map('strval', (array)$dv['clause_refs']));
                }
                if ($fileId > 0) {
                    $descLines[] = "Documento: files.php?open_file_id={$fileId}&open_mode=edit";
                }
                $descLines[] = "";
                $descLines[] = "Nota: documento placeholder (senza testo norma).";
                $description = implode("\n", $descLines);

                $db->beginTransaction();
                try {
                    $insert = [
                        'tenant_id' => $clientTenantId,
                        'title' => '[IMS] ' . (string)$dv['title'],
                        'description' => $description,
                        'status' => 'todo',
                        'priority' => 'medium',
                        'due_date' => null,
                        'assigned_to' => $assigneeId,
                        'created_by' => $actorUserId,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                    $taskId = (int)$db->insert('tasks', cnx_filter_cols($insert, $tasksCols));
                    if ($taskId <= 0) throw new RuntimeException('Insert task failed');

                    if ($hasTaskHistory) {
                        $hist = [
                            'tenant_id' => $clientTenantId,
                            'task_id' => $taskId,
                            'user_id' => $actorUserId,
                            'action' => 'created',
                            'field_name' => null,
                            'old_value' => null,
                            'new_value' => json_encode(['title' => $insert['title'], 'status' => 'todo'], JSON_UNESCAPED_UNICODE),
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                        try { $db->insert('task_history', cnx_filter_cols($hist, $histCols)); } catch (Throwable $e) {}
                    }

                    $db->commit();
                    $created['tasks']++;
                    $upsertMap('task', $akey, $taskId);
                } catch (Throwable $e) {
                    $db->rollback();
                    $warnings[] = "Task non creato ({$akey}): " . $e->getMessage();
                }
            }
        }
    }

    // Milestone events (best-effort)
    if ($createMilestones) {
        $hasEvents = false;
        try { $hasEvents = (bool)$db->fetchOne("SHOW TABLES LIKE 'events'"); } catch (Throwable $e) { $hasEvents = false; }
        if (!$hasEvents) {
            $warnings[] = 'Modulo calendario non disponibile: milestone non create.';
        } else {
            $pdo = $db->getConnection();
            $cal = new Calendar($pdo, $clientTenantId, $actorUserId);

            $baseStart = !empty($plan['period_start']) ? new DateTime((string)$plan['period_start']) : new DateTime('now');
            $baseEnd = !empty($plan['period_end']) ? new DateTime((string)$plan['period_end']) : (new DateTime('now +90 days'));

            $milestones = [
                ['key' => 'IMS_MILESTONE_KICKOFF', 'title' => 'Kickoff IMS', 'when' => clone $baseStart],
                ['key' => 'IMS_MILESTONE_INTERNAL_AUDIT', 'title' => 'Audit interno (milestone)', 'when' => (clone $baseStart)->modify('+60 days')],
                ['key' => 'IMS_MILESTONE_MGMT_REVIEW', 'title' => 'Riesame di direzione (milestone)', 'when' => (clone $baseEnd)->modify('-15 days')],
                ['key' => 'IMS_MILESTONE_CERTIFICATION', 'title' => 'Audit esterno / certificazione (milestone)', 'when' => clone $baseEnd],
            ];

            foreach ($milestones as $m) {
                $eKey = (string)$m['key'];
                $mapped = $getMappedTarget('event', $eKey);
                if ($mapped > 0) {
                    $reused['events']++;
                    continue;
                }
                if (!$hasArtifactMap) {
                    // best-effort dedup by title + date
                    $startDay = (clone $m['when'])->format('Y-m-d');
                    $row = $db->fetchOne(
                        "SELECT id
                         FROM events
                         WHERE tenant_id = ?
                           AND title = ?
                           AND start_datetime LIKE ?
                         ORDER BY id DESC
                         LIMIT 1",
                        [$clientTenantId, '[IMS] ' . (string)$m['title'], $startDay . '%']
                    );
                    $mapped = (int)($row['id'] ?? 0);
                    if ($mapped > 0) {
                        $reused['events']++;
                        continue;
                    }
                }

                try {
                    $start = $m['when'];
                    $start->setTime(9, 0, 0);
                    $end = (clone $start)->modify('+60 minutes');

                    $eventId = $cal->createEvent([
                        'title' => '[IMS] ' . (string)$m['title'],
                        'start_datetime' => $start->format('Y-m-d H:i:s'),
                        'end_datetime' => $end->format('Y-m-d H:i:s'),
                        'description' => "[CNX_PLAN_ID:$planId]\nMilestone generata (IMS).\nEvent key: {$eKey}",
                        'metadata' => [
                            'cnx_plan_id' => $planId,
                            'event_key' => $eKey,
                            'standard_code' => 'IMS',
                        ],
                        'check_conflicts' => false,
                        'visibility' => 'private',
                        'status' => 'confirmed',
                    ]);

                    $created['events']++;
                    $upsertMap('event', $eKey, (int)$eventId);
                } catch (Throwable $e) {
                    $warnings[] = "Milestone non creata ({$eKey}): " . $e->getMessage();
                }
            }
        }
    }

    api_success([
        'storage_available' => $storageAvailable,
        'data' => [
            'client_tenant_id' => $clientTenantId,
            'program_id' => $programId,
            'root_folder' => ['id' => $imsFolderId, 'name' => 'IMS'],
            'created' => $created,
            'reused' => $reused,
            'warnings' => $warnings,
            'tasks_requested' => $tasksRequested,
            'tasks_skipped_reason' => $tasksSkippedReason,
            'plan_items_count' => $planItemsCount,
            'links' => [
                'open_files' => 'files.php',
                'open_compliance' => 'compliance.php',
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_COMPLIANCE_PROVISION] ' . $e->getMessage());
    api_error('Errore provisioning compliance', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

