<?php
// Common helpers for SGQ Project Checklists (tenant 28 planning add-on)
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

const CNX_CHECKLISTS_MIGRATION_HINT = 'database/migrations/64_consulting_project_checklists.sql';
const CNX_CHECKLISTS_MIGRATION_HINT_V2 = 'database/migrations/65_consulting_project_checklists_objective_evidence.sql';

function cnx_checklists_templates_dir(): string {
    return __DIR__ . '/../../../configs/checklists';
}

function cnx_checklists_has_table(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
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

function cnx_checklists_has_col(Database $db, string $table, string $col): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{ok:bool,missing?:string[],migration?:string}
 */
function cnx_checklists_check_storage(Database $db): array {
    $req = ['consulting_project_checklists', 'consulting_project_checklist_items'];
    $missing = [];
    foreach ($req as $t) {
        if (!cnx_checklists_has_table($db, $t)) $missing[] = $t;
    }
    if (!empty($missing)) {
        return ['ok' => false, 'missing' => $missing, 'migration' => CNX_CHECKLISTS_MIGRATION_HINT];
    }
    return ['ok' => true];
}

function cnx_checklists_norm_template_key(string $k): string {
    $k = strtoupper(trim($k));
    if ($k === '') return '';
    if (strlen($k) > 80) $k = substr($k, 0, 80);
    // Allow A-Z0-9_
    if (!preg_match('/^[A-Z0-9_]+$/', $k)) return '';
    return $k;
}

/**
 * Load a checklist template JSON from configs/checklists.
 *
 * @return array{ok:bool,template?:array,error?:string}
 */
function cnx_checklists_load_template(string $templateKey): array {
    $k = cnx_checklists_norm_template_key($templateKey);
    if ($k === '') return ['ok' => false, 'error' => 'template_key non valido'];

    $idx = cnx_checklists_template_index();
    $path = $idx[$k] ?? null;
    if (!$path || !is_file($path)) return ['ok' => false, 'error' => 'Template non trovato'];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'error' => 'Template vuoto/non leggibile'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Template JSON non valido'];
    }
    return ['ok' => true, 'template' => $decoded];
}

/**
 * Index templates by template_key, scanning configs/checklists/*.json.
 *
 * @return array<string,string> template_key => fullpath
 */
function cnx_checklists_template_index(): array {
    static $idx = null;
    if (is_array($idx)) return $idx;
    $idx = [];
    $dir = cnx_checklists_templates_dir();
    $paths = @glob($dir . '/*.json') ?: [];
    foreach ($paths as $p) {
        if (!is_string($p) || !is_file($p)) continue;
        $raw = @file_get_contents($p);
        if (!is_string($raw) || trim($raw) === '') continue;
        $dec = json_decode($raw, true);
        if (!is_array($dec)) continue;
        $k = cnx_checklists_norm_template_key((string)($dec['template_key'] ?? ''));
        if ($k === '') continue;
        $idx[$k] = $p;
    }
    ksort($idx);
    return $idx;
}

/**
 * List template metadata (best-effort) for UI selection.
 *
 * @return array<int,array{template_key:string,title:string,version:string,standards:array<int,string>,intervention_types:array<int,string>}>
 */
function cnx_checklists_list_templates_meta(): array {
    $out = [];
    foreach (cnx_checklists_template_index() as $k => $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') continue;
        $tpl = json_decode($raw, true);
        if (!is_array($tpl)) continue;
        $tKey = cnx_checklists_norm_template_key((string)($tpl['template_key'] ?? $k));
        if ($tKey === '') continue;
        $title = (string)($tpl['title'] ?? $tKey);
        $version = (string)($tpl['version'] ?? '');
        $standards = (is_array($tpl['standards'] ?? null) ? array_values(array_map('strval', $tpl['standards'])) : []);
        $it = (is_array($tpl['intervention_types'] ?? null) ? array_values(array_map('strval', $tpl['intervention_types'])) : []);
        $out[] = [
            'template_key' => $tKey,
            'title' => $title,
            'version' => $version,
            'standards' => $standards,
            'intervention_types' => $it,
        ];
    }
    return $out;
}

function cnx_checklists_allowed_question_types(): array {
    return ['bool','text','multiline','select','file','multi_file'];
}

function cnx_checklists_allowed_statuses(): array {
    return ['missing','present','to_review','done','not_applicable'];
}

function cnx_checklists_json_stringify($v, int $maxLen = 20000): ?string {
    if ($v === null) return null;
    if (is_string($v)) {
        $s = trim($v);
        if ($s === '') return null;
        if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
        return $s;
    }
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($s) || trim($s) === '') return null;
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

