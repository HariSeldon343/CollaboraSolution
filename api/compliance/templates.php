<?php
/**
 * IMS v2: Document/Artifact template catalog API (tenant 28 managed).
 *
 * GET  /api/compliance/templates.php?action=list
 * GET  /api/compliance/templates.php?action=detail&template_key=...
 * GET  /api/compliance/templates.php?action=diag_storage
 * POST /api/compliance/templates.php?action=upsert
 * POST /api/compliance/templates.php?action=toggle_active
 * POST /api/compliance/templates.php?action=init_storage
 *
 * Security:
 * - Auth required
 * - Tenant 28 gate required (vendor-only admin tool)
 * - CSRF required for POST
 *
 * Schema drift safe:
 * - If migration 45 missing -> 503 with migration hint
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/compliance/template_schema_defaults.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

// Public schema read for client tenant UI (no tenant28 gate)
if ($action === 'public_get_schema') {
    $key = trim((string)($_GET['template_key'] ?? ''));
    if ($key === '') api_error('template_key richiesto', 400);

    $fallbackTitle = trim((string)($_GET['title'] ?? ''));
    $fallbackDocType = trim((string)($_GET['doc_type'] ?? ''));

    $has = cnx_compliance_check_tables($db, ['compliance_artifact_templates'], 'database/migrations/45_compliance_templates_and_standards.sql');
    if (!$has['ok']) {
        // Even if the template catalog is not initialized, return a runtime fallback schema
        // so the client wizard can still work (schema drift safe).
        $fallback = cnx_compliance_fallback_schema($key, $fallbackTitle, $fallbackDocType);
        $schema = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hint = cnx_compliance_fallback_ai_hint($key, $fallbackTitle, $fallbackDocType);
        api_success([
            'storage_available' => false,
            'missing' => $has['missing'] ?? [],
            'migration' => $has['migration'] ?? 'database/migrations/45_compliance_templates_and_standards.sql',
            'template' => [
                'template_key' => $key,
                'title' => $fallbackTitle,
                'doc_type' => $fallbackDocType,
                'input_schema_json' => $schema,
                'ai_hint' => $hint,
            ],
        ]);
    }

    $tplCols = cnx_tpl_cols($db);
    $hasSchema = isset($tplCols['input_schema_json']);
    $hasHint = isset($tplCols['ai_hint']);

    $row = $db->fetchOne(
        "SELECT template_key, title, doc_type, file_kind"
        . ($hasSchema ? ", input_schema_json" : "")
        . ($hasHint ? ", ai_hint" : "")
        . " FROM compliance_artifact_templates WHERE template_key = ? LIMIT 1",
        [$key]
    );
    if (!$row) {
        // Template not present in catalog: still allow wizard using runtime fallback schema/hint.
        $fallback = cnx_compliance_fallback_schema($key, $fallbackTitle, $fallbackDocType);
        $schema = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hint = cnx_compliance_fallback_ai_hint($key, $fallbackTitle, $fallbackDocType);
        api_success([
            'storage_available' => true,
            'template' => [
                'template_key' => $key,
                'title' => $fallbackTitle,
                'doc_type' => $fallbackDocType,
                'input_schema_json' => $schema,
                'ai_hint' => $hint,
            ],
        ]);
    }

    $schema = null;
    if ($hasSchema && !empty($row['input_schema_json'])) {
        // allow returning raw json string for client; client will parse
        $schema = (string)$row['input_schema_json'];
    }
    $hint = $hasHint ? (string)($row['ai_hint'] ?? '') : '';

    // Runtime fallback: if schema/hint are missing, return a minimal schema so the client can still compile.
    if (!$schema || trim((string)$schema) === '') {
        $fallback = cnx_compliance_fallback_schema((string)$row['template_key'], (string)($row['title'] ?? ''), (string)($row['doc_type'] ?? ''));
        $schema = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (trim($hint) === '') {
        $hint = cnx_compliance_fallback_ai_hint((string)$row['template_key'], (string)($row['title'] ?? ''), (string)($row['doc_type'] ?? ''));
    }

    api_success([
        'storage_available' => true,
        'template' => [
            'template_key' => (string)$row['template_key'],
            'title' => (string)($row['title'] ?? ''),
            'doc_type' => (string)($row['doc_type'] ?? ''),
            'input_schema_json' => $schema,
            'ai_hint' => $hint,
        ],
    ]);
}

// Tenant 28 gate for the managed catalog actions
cnx_compliance_require_tenant28_planning($userInfo);

if ($action === 'diag_storage') {
    $needed = ['compliance_standards', 'compliance_artifact_templates', 'compliance_program_standards'];
    $chk2 = cnx_compliance_check_tables($db, $needed);
    $dbName = '';
    $hostName = '';
    try { $dbName = (string)($db->fetchOne("SELECT DATABASE() AS db")['db'] ?? ''); } catch (Throwable $e) {}
    try { $hostName = (string)($db->fetchOne("SELECT @@hostname AS h")['h'] ?? ''); } catch (Throwable $e) {}
    api_success([
        'storage_available' => $chk2['ok'],
        'missing' => $chk2['missing'] ?? [],
        'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
        'db_name' => $dbName,
        'db_host' => $hostName,
    ]);
}

// Storage init (explicit POST, CSRF-protected) — avoids side-effects on GET.
if ($action === 'init_storage') {
    cnx_compliance_require_csrf_for_write();
    $role = (string)($userInfo['role'] ?? 'user');
    if ($role !== 'super_admin') {
        api_error('Solo super_admin può inizializzare le migrazioni', 403);
    }
    require_once __DIR__ . '/../../includes/sql_migration_runner.php';
    try {
        cnx_apply_sql_migration_file($db->getConnection(), __DIR__ . '/../../database/migrations/45_compliance_templates_and_standards.sql');

        // Verify that the critical table is actually visible to THIS connection.
        // This prevents false positives when the migration fails silently (or when CREATE is denied).
        $hasAfter = cnx_compliance_check_tables($db, ['compliance_artifact_templates'], 'database/migrations/45_compliance_templates_and_standards.sql');
        if (!$hasAfter['ok']) {
            // Best-effort diagnostics (super_admin only, safe):
            // - Does information_schema see the table?
            // - What happens if we try selecting from it?
            $isInInformationSchema = null;
            $selectError = null;
            try {
                $row = $db->fetchOne(
                    "SELECT 1 AS ok
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'compliance_artifact_templates'
                     LIMIT 1"
                );
                $isInInformationSchema = (bool)$row;
            } catch (Throwable $e) {
                $isInInformationSchema = null;
            }
            try {
                // If table exists but is empty, this returns false; that's fine.
                $db->fetchOne("SELECT 1 AS ok FROM compliance_artifact_templates LIMIT 1");
            } catch (Throwable $e) {
                $selectError = substr((string)$e->getMessage(), 0, 900);
            }

            api_error(
                'Migrazione 45 eseguita ma storage non disponibile (tabella mancante). Verificare permessi DB e compatibilità schema.',
                500,
                [
                    'storage_available' => false,
                    'missing' => $hasAfter['missing'] ?? [],
                    'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
                    'debug' => [
                        'information_schema_has_table' => $isInInformationSchema,
                        'select_error' => $selectError,
                    ],
                ]
            );
        }

        api_success([
            'storage_available' => true,
            'ok' => true,
        ], 'Migrazione 45 applicata');
    } catch (Throwable $e) {
        // Provide safe debugging info for super_admin to unblock environments where DEBUG_MODE is off.
        // This may include SQLSTATE/driver messages and statement snippets (no secrets expected).
        $missingNow = cnx_compliance_check_tables(
            $db,
            ['compliance_standards', 'compliance_artifact_templates', 'compliance_program_standards'],
            'database/migrations/45_compliance_templates_and_standards.sql'
        );

        api_error(
            'Impossibile applicare migrazione 45',
            500,
            [
                'storage_available' => false,
                'missing' => $missingNow['missing'] ?? [],
                'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
                'debug' => substr((string)$e->getMessage(), 0, 900),
            ]
        );
    }
}

// Storage check (schema drift safe)
$has = cnx_compliance_check_tables($db, ['compliance_artifact_templates'], 'database/migrations/45_compliance_templates_and_standards.sql');
if (!$has['ok']) {
    api_error(
        'Modulo template IMS non inizializzato: applica la migrazione database 45 (templates + standards)',
        503,
        [
            'storage_available' => false,
            'missing' => $has['missing'] ?? [],
            'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
        ]
    );
}

/**
 * Feature-detect optional columns from migration 46 (schema drift safe).
 *
 * @return array<string,bool>
 */
function cnx_tpl_cols(Database $db): array {
    $cols = [];
    try {
        $rows = $db->fetchAll("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_artifact_templates'") ?: [];
        foreach ($rows as $r) {
            if (!empty($r['c'])) $cols[(string)$r['c']] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

$tplCols = cnx_tpl_cols($db);

try {
    if ($action === 'list') {
        $onlyActive = isset($_GET['active_only']) ? (int)($_GET['active_only']) : 0;
        $where = $onlyActive ? "WHERE is_active = 1" : "";

        $selExtra = '';
        if (!empty($tplCols)) {
            if (isset($tplCols['source_file_id'])) $selExtra .= ", source_file_id";
            if (isset($tplCols['source_tenant_id'])) $selExtra .= ", source_tenant_id";
            if (isset($tplCols['content_mode'])) $selExtra .= ", content_mode";
            if (isset($tplCols['doc_code_template'])) $selExtra .= ", doc_code_template";
            if (isset($tplCols['input_schema_json'])) $selExtra .= ", input_schema_json";
            if (isset($tplCols['ai_hint'])) $selExtra .= ", ai_hint";
        }
        $rows = $db->fetchAll(
            "SELECT template_key, title, doc_type, file_kind, folder_path, filename_template,
                    tags_json, clause_refs_json, is_common_hls, is_active, updated_at
                    $selExtra
             FROM compliance_artifact_templates
             $where
             ORDER BY is_active DESC, is_common_hls DESC, doc_type ASC, template_key ASC"
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $tags = [];
            $refs = null;
            try { $tags = $r['tags_json'] ? (json_decode((string)$r['tags_json'], true) ?: []) : []; } catch (Throwable $e) { $tags = []; }
            try { $refs = $r['clause_refs_json'] ? (json_decode((string)$r['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $refs = null; }

            $contentMode = isset($tplCols['content_mode']) ? (string)($r['content_mode'] ?? 'placeholder') : 'placeholder';
            if (!in_array($contentMode, ['placeholder', 'copy_source'], true)) $contentMode = 'placeholder';
            $sourceTenantId = isset($tplCols['source_tenant_id']) ? (int)($r['source_tenant_id'] ?? 28) : 28;
            $sourceFileId = isset($tplCols['source_file_id']) ? (int)($r['source_file_id'] ?? 0) : 0;
            $docCodeTpl = isset($tplCols['doc_code_template']) ? (string)($r['doc_code_template'] ?? '') : '';
            $schemaJson = isset($tplCols['input_schema_json']) ? (string)($r['input_schema_json'] ?? '') : '';
            $aiHint = isset($tplCols['ai_hint']) ? (string)($r['ai_hint'] ?? '') : '';
            $out[] = [
                'template_key' => (string)$r['template_key'],
                'title' => (string)$r['title'],
                'doc_type' => (string)$r['doc_type'],
                'file_kind' => (string)$r['file_kind'],
                'folder_path' => (string)$r['folder_path'],
                'filename_template' => (string)$r['filename_template'],
                'tags' => is_array($tags) ? array_values($tags) : [],
                'clause_refs' => $refs, // object per standard (preferred)
                'is_common_hls' => (int)($r['is_common_hls'] ?? 0) ? 1 : 0,
                'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                'content_mode' => $contentMode,
                'source_tenant_id' => $sourceTenantId,
                'source_file_id' => $sourceFileId > 0 ? $sourceFileId : null,
                'doc_code_template' => $docCodeTpl,
                'input_schema_json' => $schemaJson !== '' ? $schemaJson : null,
                'ai_hint' => $aiHint !== '' ? $aiHint : null,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }

        api_success([
            'storage_available' => true,
            'templates' => $out,
            'storage_v46_available' => (bool)(isset($tplCols['content_mode']) || isset($tplCols['source_file_id']) || isset($tplCols['doc_code_template'])),
            'storage_v47_available' => (bool)(isset($tplCols['input_schema_json']) || isset($tplCols['ai_hint'])),
        ]);
    }

    if ($action === 'detail') {
        $key = trim((string)($_GET['template_key'] ?? ''));
        if ($key === '') api_error('template_key richiesto', 400);
        $r = $db->fetchOne(
            "SELECT *
             FROM compliance_artifact_templates
             WHERE template_key = ?
             LIMIT 1",
            [$key]
        );
        if (!$r) api_error('Template non trovato', 404);
        $tags = [];
        $refs = null;
        try { $tags = $r['tags_json'] ? (json_decode((string)$r['tags_json'], true) ?: []) : []; } catch (Throwable $e) { $tags = []; }
        try { $refs = $r['clause_refs_json'] ? (json_decode((string)$r['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $refs = null; }
        api_success([
            'storage_available' => true,
            'template' => [
                'template_key' => (string)$r['template_key'],
                'title' => (string)$r['title'],
                'doc_type' => (string)$r['doc_type'],
                'file_kind' => (string)$r['file_kind'],
                'folder_path' => (string)$r['folder_path'],
                'filename_template' => (string)$r['filename_template'],
                'tags' => is_array($tags) ? array_values($tags) : [],
                'clause_refs' => $refs,
                'is_common_hls' => (int)($r['is_common_hls'] ?? 0) ? 1 : 0,
                'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                'input_schema_json' => isset($tplCols['input_schema_json']) ? ((string)($r['input_schema_json'] ?? '') ?: null) : null,
                'ai_hint' => isset($tplCols['ai_hint']) ? ((string)($r['ai_hint'] ?? '') ?: null) : null,
                'created_at' => (string)($r['created_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ],
        ]);
    }

    if ($action === 'upsert') {
        cnx_compliance_require_csrf_for_write();
        $p = json_decode(file_get_contents('php://input'), true) ?: [];

        $key = strtoupper(trim((string)($p['template_key'] ?? '')));
        $title = trim((string)($p['title'] ?? ''));
        $docType = strtolower(trim((string)($p['doc_type'] ?? 'document')));
        $fileKind = strtolower(trim((string)($p['file_kind'] ?? 'docx')));
        $folderPath = trim((string)($p['folder_path'] ?? ''));
        $filenameTpl = trim((string)($p['filename_template'] ?? ''));
        $tags = is_array($p['tags'] ?? null) ? $p['tags'] : [];
        $clauseRefs = $p['clause_refs'] ?? null; // object (preferred), can be null
        $isCommon = isset($p['is_common_hls']) ? (int)((bool)$p['is_common_hls']) : 0;
        $isActive = isset($p['is_active']) ? (int)((bool)$p['is_active']) : 1;

        // Optional fields (migration 46)
        $contentMode = strtolower(trim((string)($p['content_mode'] ?? 'placeholder')));
        if (!in_array($contentMode, ['placeholder', 'copy_source'], true)) $contentMode = 'placeholder';
        $sourceTenantId = isset($p['source_tenant_id']) ? (int)$p['source_tenant_id'] : 28;
        $sourceFileId = isset($p['source_file_id']) ? (int)$p['source_file_id'] : 0;
        $docCodeTpl = trim((string)($p['doc_code_template'] ?? ''));

        // Optional fields (migration 47)
        $inputSchemaRaw = $p['input_schema_json'] ?? null; // string JSON (object) or null
        $aiHint = trim((string)($p['ai_hint'] ?? ''));

        if ($key === '' || strlen($key) > 80) api_error('template_key non valido', 400);
        if (!preg_match('/^[A-Z0-9_]+$/', $key)) api_error('template_key deve essere uppercase e underscore (A-Z0-9_)', 400);
        if ($title === '' || strlen($title) > 255) api_error('title non valido', 400);
        if (!in_array($docType, ['manual','policy','procedure','instruction','form','register','record','plan','document'], true)) api_error('doc_type non valido', 400);
        if (!in_array($fileKind, ['docx','xlsx','pptx','txt'], true)) api_error('file_kind non valido', 400);
        if ($folderPath === '' || strlen($folderPath) > 255) api_error('folder_path non valido', 400);
        if (!($folderPath === '/IMS' || strpos($folderPath, '/IMS/') === 0)) api_error('folder_path deve iniziare con /IMS/', 400);
        if ($filenameTpl === '' || strlen($filenameTpl) > 255) api_error('filename_template non valido', 400);

        if ($docCodeTpl !== '' && strlen($docCodeTpl) > 64) api_error('doc_code_template troppo lungo', 400);
        if ($docCodeTpl !== '' && !preg_match('/^[A-Z0-9_\\-\\{\\}]+$/', $docCodeTpl)) api_error('doc_code_template non valido', 400);
        if ($sourceTenantId <= 0) $sourceTenantId = 28;
        if ($sourceFileId <= 0) $sourceFileId = 0;
        if ($sourceFileId === 0) $contentMode = 'placeholder';

        // Validate clause_refs object shape (references only, no free text)
        if ($clauseRefs !== null) {
            if (!is_array($clauseRefs)) api_error('clause_refs deve essere un oggetto JSON', 400);
            foreach ($clauseRefs as $std => $refs) {
                if (!is_string($std) || trim($std) === '' || strlen($std) > 32) api_error('clause_refs standard non valido', 400);
                if (!is_array($refs)) api_error('clause_refs per standard deve essere array', 400);
                foreach ($refs as $ref) {
                    if (!is_string($ref)) api_error('clause_refs deve contenere stringhe', 400);
                    $ref = trim($ref);
                    if ($ref === '' || strlen($ref) > 24) api_error('clause_ref non valido', 400);
                    if (preg_match('/\\s/', $ref)) api_error('clause_ref non deve contenere spazi', 400);
                    if (!preg_match('/^[A-Za-z0-9\\.\\-]+$/', $ref)) api_error('clause_ref non valido', 400);
                }
            }
        }

        // Validate input_schema_json if provided (must be JSON object; no free text validation here)
        $inputSchemaJson = null;
        if ($inputSchemaRaw !== null && $inputSchemaRaw !== '') {
            if (!is_string($inputSchemaRaw)) api_error('input_schema_json deve essere una stringa JSON', 400);
            $decoded = json_decode($inputSchemaRaw, true);
            if (!is_array($decoded)) api_error('input_schema_json non è JSON valido', 400);
            // Must be object-like (associative)
            $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
            if (!$isAssoc) api_error('input_schema_json deve essere un oggetto JSON', 400);
            $inputSchemaJson = $inputSchemaRaw;
        }
        if ($aiHint !== '' && strlen($aiHint) > 5000) api_error('ai_hint troppo lungo', 400);

        $tagsJson = json_encode(array_values(array_filter(array_map('strval', $tags))), JSON_UNESCAPED_UNICODE);
        $refsJson = ($clauseRefs === null) ? null : json_encode($clauseRefs, JSON_UNESCAPED_UNICODE);

        // Insert or update
        $existing = $db->fetchOne("SELECT id FROM compliance_artifact_templates WHERE template_key = ? LIMIT 1", [$key]);
        $extraUpdate = [];
        $extraInsert = [];
        if (!empty($tplCols)) {
            if (isset($tplCols['source_file_id'])) $extraUpdate['source_file_id'] = ($sourceFileId > 0 ? $sourceFileId : null);
            if (isset($tplCols['source_tenant_id'])) $extraUpdate['source_tenant_id'] = $sourceTenantId;
            if (isset($tplCols['content_mode'])) $extraUpdate['content_mode'] = $contentMode;
            if (isset($tplCols['doc_code_template'])) $extraUpdate['doc_code_template'] = ($docCodeTpl !== '' ? $docCodeTpl : null);
            if (isset($tplCols['input_schema_json'])) $extraUpdate['input_schema_json'] = $inputSchemaJson;
            if (isset($tplCols['ai_hint'])) $extraUpdate['ai_hint'] = ($aiHint !== '' ? $aiHint : null);
            $extraInsert = $extraUpdate;
        }
        if ($existing && !empty($existing['id'])) {
            $db->update('compliance_artifact_templates', [
                'title' => $title,
                'doc_type' => $docType,
                'file_kind' => $fileKind,
                'folder_path' => $folderPath,
                'filename_template' => $filenameTpl,
                'tags_json' => $tagsJson,
                'clause_refs_json' => $refsJson,
                'is_common_hls' => $isCommon,
                'is_active' => $isActive,
                'updated_at' => date('Y-m-d H:i:s'),
            ] + $extraUpdate, ['template_key' => $key]);
        } else {
            $db->insert('compliance_artifact_templates', [
                'template_key' => $key,
                'title' => $title,
                'doc_type' => $docType,
                'file_kind' => $fileKind,
                'folder_path' => $folderPath,
                'filename_template' => $filenameTpl,
                'tags_json' => $tagsJson,
                'clause_refs_json' => $refsJson,
                'is_common_hls' => $isCommon,
                'is_active' => $isActive,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ] + $extraInsert);
        }

        api_success(['storage_available' => true, 'template_key' => $key], 'Salvato');
    }

    if ($action === 'toggle_active') {
        cnx_compliance_require_csrf_for_write();
        $p = json_decode(file_get_contents('php://input'), true) ?: [];
        $key = strtoupper(trim((string)($p['template_key'] ?? '')));
        $isActive = isset($p['is_active']) ? (int)((bool)$p['is_active']) : null;
        if ($key === '') api_error('template_key richiesto', 400);
        if ($isActive === null) api_error('is_active richiesto', 400);
        $row = $db->fetchOne("SELECT template_key FROM compliance_artifact_templates WHERE template_key = ? LIMIT 1", [$key]);
        if (!$row) api_error('Template non trovato', 404);
        $db->update('compliance_artifact_templates', [
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['template_key' => $key]);
        api_success(['storage_available' => true, 'template_key' => $key, 'is_active' => $isActive], 'Aggiornato');
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_TEMPLATES] ' . $e->getMessage());
    api_error('Errore templates', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

