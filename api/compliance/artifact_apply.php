<?php
declare(strict_types=1);

/**
 * Compliance Artifact Apply API (Document Wizard)
 *
 * POST /api/compliance/artifact_apply.php?action=apply_to_document   {artifact_id}
 * POST /api/compliance/artifact_apply.php?action=restore_version    {file_id, version_file_id}
 * GET  /api/compliance/artifact_apply.php?action=versions_list&file_id=... (manager/super_admin)
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/file_access.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/document_editor_helper.php';
require_once __DIR__ . '/../../includes/compliance/doc_template_engine.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action === '') api_error('action richiesto', 400);

verifyApiCsrfToken(true);

$need = [
    'compliance_artifacts',
    'compliance_programs',
    'compliance_program_profiles',
    'compliance_artifact_inputs',
    'compliance_file_versions',
    'files',
];
$chk = cnx_compliance_check_tables($db, $need, 'database/migrations/47_document_wizard.sql');
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/47_document_wizard.sql',
    ]);
}

function cnx_dw_role_can_manage_versions(array $userInfo): bool {
    $role = (string)($userInfo['role'] ?? 'user');
    return in_array($role, ['manager', 'super_admin'], true);
}

/**
 * @return array{file:array,tenant_id:int}
 */
function cnx_dw_load_file_any(Database $db, int $fileId): array {
    $file = $db->fetchOne(
        "SELECT id, tenant_id, name, folder_id, file_path, file_size, mime_type, uploaded_by, is_folder, updated_at
         FROM files
         WHERE id = ?
           AND deleted_at IS NULL",
        [$fileId]
    );
    if (!$file) api_error('File non trovato', 404);
    $tenantId = (int)($file['tenant_id'] ?? 0);
    return ['file' => $file, 'tenant_id' => $tenantId];
}

/**
 * Determine if a column exists in `files`.
 */
function cnx_dw_files_has_col(Database $db, string $col): bool {
    try {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'files'
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$col]
        );
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_dw_tenants_has_col(Database $db, string $col): bool {
    try {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenants'
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$col]
        );
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_dw_find_root_folder_id(Database $db, int $tenantId): int {
    $r = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND folder_id IS NULL
           AND is_folder = 1
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT 1",
        [$tenantId]
    );
    return $r ? (int)($r['id'] ?? 0) : 0;
}

function cnx_dw_find_child_folder_by_name(Database $db, int $tenantId, int $parentFolderId, string $name): int {
    $row = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND folder_id = ?
           AND is_folder = 1
           AND deleted_at IS NULL
           AND name = ?
         LIMIT 1",
        [$tenantId, $parentFolderId, $name]
    );
    return $row ? (int)($row['id'] ?? 0) : 0;
}

function cnx_dw_get_tenant_logo_file_id(Database $db, int $tenantId): int {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0) return 0;

    // Preferred: tenants.logo_file_id (optional migration)
    if (cnx_dw_tenants_has_col($db, 'logo_file_id')) {
        $t = $db->fetchOne("SELECT logo_file_id FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
        $fid = $t ? (int)($t['logo_file_id'] ?? 0) : 0;
        if ($fid > 0) return $fid;
    }

    // Fallback: try to locate a file under /IMS/Assets named logo.*
    $rootId = cnx_dw_find_root_folder_id($db, $tenantId);
    if ($rootId <= 0) return 0;
    $imsId = cnx_dw_find_child_folder_by_name($db, $tenantId, $rootId, 'IMS');
    if ($imsId > 0) {
        $assetsId = cnx_dw_find_child_folder_by_name($db, $tenantId, $imsId, 'Assets');
        if ($assetsId > 0) {
            $row = $db->fetchOne(
                "SELECT id
                 FROM files
                 WHERE tenant_id = ?
                   AND folder_id = ?
                   AND is_folder = 0
                   AND deleted_at IS NULL
                   AND (name IN ('logo.png','logo.jpg','logo.jpeg') OR original_name IN ('logo.png','logo.jpg','logo.jpeg'))
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                [$tenantId, $assetsId]
            );
            if ($row && !empty($row['id'])) return (int)$row['id'];
        }
    }

    return 0;
}

/**
 * Build options for DOCX header injection (logo + placeholders).
 *
 * @return array<string,mixed>
 */
function cnx_dw_build_docx_header_opts(Database $db, int $tenantId): array {
    $tenantId = (int)$tenantId;
    $opts = [
        'header_enabled' => true,
        'header_rel_id' => 'rIdCnxHeader1',
    ];

    $logoFileId = cnx_dw_get_tenant_logo_file_id($db, $tenantId);
    if ($logoFileId <= 0) return $opts;

    $row = $db->fetchOne(
        "SELECT id, tenant_id, name, file_path, mime_type
         FROM files
         WHERE id = ?
           AND deleted_at IS NULL
         LIMIT 1",
        [$logoFileId]
    );
    if (!$row) return $opts;
    if ((int)($row['tenant_id'] ?? 0) !== $tenantId) return $opts;

    $rel = (string)($row['file_path'] ?? '');
    if ($rel === '') return $opts;

    $abs = rtrim(FileHelper::getTenantUploadPath($tenantId), '/\\') . '/' . ltrim($rel, '/\\');
    if (!is_file($abs)) return $opts;

    $bytes = @file_get_contents($abs);
    if (!is_string($bytes) || $bytes === '') return $opts;
    if (strlen($bytes) > 3_000_000) {
        // Avoid huge images inside DOCX
        return $opts;
    }

    $ext = strtolower((string)pathinfo((string)($row['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        // Try to infer from mime
        $mime = strtolower((string)($row['mime_type'] ?? ''));
        if (str_contains($mime, 'jpeg')) $ext = 'jpg';
        else $ext = 'png';
    }

    $opts['logo_bytes'] = $bytes;
    $opts['logo_ext'] = $ext;
    return $opts;
}

/**
 * Create a snapshot copy of a file (visible file row) and link it to current file in compliance_file_versions.
 *
 * @return array{ok:bool,version_file_id?:int,warnings:array<int,string>}
 */
function cnx_dw_create_snapshot_version(Database $db, array $userInfo, int $fileId, string $reason): array {
    $warnings = [];

    $ctx = cnx_dw_load_file_any($db, $fileId);
    $file = $ctx['file'];
    $tenantId = (int)$ctx['tenant_id'];

    // AuthZ: must have access to tenant
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
        return ['ok' => false, 'warnings' => ['Accesso negato al tenant del file']];
    }

    // Only for real files
    if ((int)($file['is_folder'] ?? 0) === 1) {
        return ['ok' => false, 'warnings' => ['Impossibile versionare una cartella']];
    }

    // Physical paths
    $uploadPath = FileHelper::getTenantUploadPath($tenantId);
    $srcRel = (string)($file['file_path'] ?? '');
    $srcAbs = rtrim($uploadPath, '/\\') . '/' . ltrim($srcRel, '/\\');
    if (!is_file($srcAbs)) {
        return ['ok' => false, 'warnings' => ['File fisico non trovato: ' . $srcAbs]];
    }

    $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $baseName = (string)$file['name'];
    $baseNoExt = $ext ? preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $baseName) : $baseName;
    $stamp = date('Ymd_His');
    $versionName = trim((string)$baseNoExt) . '__v' . $stamp . ($ext ? ('.' . $ext) : '');
    if (strlen($versionName) > 180) $versionName = substr($versionName, 0, 180);

    // Create safe physical filename
    $safePhysical = FileHelper::generateSafeFilename($versionName, $uploadPath);
    $dstAbs = rtrim($uploadPath, '/\\') . '/' . $safePhysical;

    if (!@copy($srcAbs, $dstAbs)) {
        $warnings[] = "Snapshot copy fallita: {$dstAbs}";
        return ['ok' => false, 'warnings' => $warnings];
    }

    $mime = FileHelper::getMimeType($dstAbs);
    $size = @filesize($dstAbs);
    $size = is_int($size) ? $size : null;

    $now = date('Y-m-d H:i:s');
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

    $insert = [
        'tenant_id' => $tenantId,
        'name' => $versionName,
        'file_path' => $safePhysical,
        'file_size' => $size,
        'mime_type' => $mime,
        'folder_id' => $file['folder_id'] ?? null,
        'uploaded_by' => $userId > 0 ? $userId : ($file['uploaded_by'] ?? null),
        'is_folder' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    // optional columns
    if (cnx_dw_files_has_col($db, 'extension')) $insert['extension'] = $ext ?: null;
    if (cnx_dw_files_has_col($db, 'file_hash')) $insert['file_hash'] = @md5_file($dstAbs) ?: null;
    if (cnx_dw_files_has_col($db, 'status')) $insert['status'] = 'draft';
    if (cnx_dw_files_has_col($db, 'file_type')) $insert['file_type'] = $ext ?: null;
    if (cnx_dw_files_has_col($db, 'is_editable')) $insert['is_editable'] = 1;

    // Insert version file row
    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $versionFileId = (int)$db->insert('files', $insert);
        $db->insert('compliance_file_versions', [
            'tenant_id' => $tenantId,
            'file_id' => $fileId,
            'version_file_id' => $versionFileId,
            'reason' => $reason !== '' ? $reason : null,
            'created_by' => $userId > 0 ? $userId : null,
            'created_at' => $now,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $warnings[] = "Errore DB snapshot: " . $e->getMessage();
        // keep physical file; will be orphaned but safe
        return ['ok' => false, 'warnings' => $warnings];
    }

    return ['ok' => true, 'version_file_id' => $versionFileId, 'warnings' => $warnings];
}

/**
 * Keep only last N version files for a given file_id (soft-delete older version files).
 */
function cnx_dw_prune_versions(Database $db, int $tenantId, int $fileId, int $keepN, array &$warnings): void {
    $keepN = max(0, (int)$keepN);
    if ($keepN <= 0) return;

    $rows = $db->fetchAll(
        "SELECT id, version_file_id, created_at
         FROM compliance_file_versions
         WHERE tenant_id = ? AND file_id = ?
         ORDER BY created_at DESC, id DESC",
        [$tenantId, $fileId]
    ) ?: [];

    if (count($rows) <= $keepN) return;
    $toDelete = array_slice($rows, $keepN);
    foreach ($toDelete as $r) {
        $vid = (int)($r['version_file_id'] ?? 0);
        if ($vid <= 0) continue;
        // soft-delete file row
        try {
            $db->query("UPDATE files SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL", [$vid, $tenantId]);
            $db->query("DELETE FROM compliance_file_versions WHERE id = ? AND tenant_id = ?", [(int)$r['id'], $tenantId]);
        } catch (Throwable $e) {
            $warnings[] = "Prune version fallito (file_id={$fileId}, version_file_id={$vid}): " . $e->getMessage();
        }
    }
}

/**
 * Flatten associative arrays into string replacements.
 * - scalar => string
 * - list => newline bullet list
 * - object => key_subkey recursion
 *
 * @param array<string,mixed> $data
 * @return array<string,string>
 */
function cnx_dw_flatten(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $k => $v) {
        $key = trim((string)$k);
        if ($key === '') continue;
        $fullKey = $prefix !== '' ? ($prefix . '_' . $key) : $key;
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                $out += cnx_dw_flatten($v, $fullKey);
            } else {
                $lines = [];
                foreach ($v as $it) {
                    $s = trim((string)$it);
                    if ($s === '') continue;
                    $lines[] = '- ' . $s;
                }
                $out[$fullKey] = implode("\n", $lines);
            }
        } else {
            $out[$fullKey] = trim((string)$v);
        }
    }
    return $out;
}

/**
 * @return array{ok:bool,tenant_id?:int,warnings:array<int,string>}
 */
function cnx_dw_apply_placeholders(Database $db, array $userInfo, int $tenantId, int $fileId, array $replacements, array $docxOpts = []): array {
    $warnings = [];

    // Access: assignment-aware
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $userRole = (string)($userInfo['role'] ?? 'user');
    $acc = hasFileOrFolderAccess($db, $fileId, $userId, $userRole, $tenantId);
    if (!$acc['has_access']) {
        return ['ok' => false, 'warnings' => ['Accesso negato: documento assegnato']];
    }

    // Edit permission
    $perm = checkFileEditPermissions($fileId, $userId, $userRole);
    if (empty($perm['edit'])) {
        return ['ok' => false, 'warnings' => ['Non hai permessi di modifica per questo documento']];
    }

    // Workflow state: only draft
    try {
        $wf = $db->fetchOne(
            "SELECT current_state
             FROM document_workflow
             WHERE file_id = ?
               AND tenant_id = ?
               AND (deleted_at IS NULL OR deleted_at = '')
             LIMIT 1",
            [$fileId, $tenantId]
        );
        if ($wf && isset($wf['current_state']) && (string)$wf['current_state'] !== 'bozza') {
            return ['ok' => false, 'warnings' => ['Documento non in stato bozza: applicazione non consentita']];
        }
    } catch (Throwable $e) {
        // best-effort, ignore if workflow tables drift
    }

    $fi = getFileInfoForEditor($fileId, $tenantId);
    if (!$fi) {
        return ['ok' => false, 'warnings' => ['File non trovato o tenant mismatch']];
    }
    $abs = (string)($fi['physical_path'] ?? '');
    if ($abs === '' || !is_file($abs)) {
        return ['ok' => false, 'warnings' => ['File fisico non trovato']];
    }

    $ext = strtolower((string)pathinfo((string)$fi['name'], PATHINFO_EXTENSION));
    if ($ext === 'docx') {
        // Tenant header (logo + doc metadata) is injected at apply-time for consistent formatting.
        $hdrOpts = [];
        try {
            $hdrOpts = cnx_dw_build_docx_header_opts($db, $tenantId);
        } catch (Throwable $e) {
            $hdrOpts = [];
            $warnings[] = 'Header: ' . $e->getMessage();
        }
        // Optional DOCX formatting tweaks (set by apply_to_document)
        if (!empty($docxOpts) && is_array($docxOpts)) {
            $hdrOpts = array_merge($hdrOpts, $docxOpts);
        }
        // Optional debug hook (set by apply_to_document for super_admin + debug_apply=1)
        if (isset($replacements['_cnx_debug_apply']) && (string)$replacements['_cnx_debug_apply'] === '1') {
            $hdrOpts['debug_apply'] = true;
            $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG'] = [
                'dom_available' => class_exists('DOMDocument'),
                'fallback_mode' => null,
                'fallback_error' => null,
            ];
        }
        $ok = cnx_apply_placeholders_to_docx($abs, $replacements, $warnings, $hdrOpts);
    } elseif ($ext === 'xlsx') {
        $ok = cnx_apply_placeholders_to_xlsx($abs, $replacements, $warnings);
    } else {
        return ['ok' => false, 'warnings' => ['Formato non supportato per applicazione placeholder: ' . $ext]];
    }

    // Update file metadata best-effort
    try {
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        if (cnx_dw_files_has_col($db, 'file_hash')) {
            $upd['file_hash'] = @md5_file($abs) ?: null;
        }
        if (cnx_dw_files_has_col($db, 'file_size')) {
            $sz = @filesize($abs);
            if (is_int($sz)) $upd['file_size'] = $sz;
        }
        $db->update('files', $upd, ['id' => $fileId]);
    } catch (Throwable $e) {
        $warnings[] = 'Aggiornamento metadati file fallito (best-effort)';
    }

    return ['ok' => (bool)$ok, 'tenant_id' => $tenantId, 'warnings' => $warnings];
}

try {
    if ($action === 'versions_list') {
        if (!cnx_dw_role_can_manage_versions($userInfo)) api_error('Accesso negato', 403);
        $fileId = (int)($_GET['file_id'] ?? 0);
        if ($fileId <= 0) api_error('file_id richiesto', 400);
        $ctx = cnx_dw_load_file_any($db, $fileId);
        $tenantId = (int)$ctx['tenant_id'];
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

        $rows = $db->fetchAll(
            "SELECT v.id, v.version_file_id, v.reason, v.created_at,
                    f.name AS version_name
             FROM compliance_file_versions v
             LEFT JOIN files f ON f.id = v.version_file_id
             WHERE v.tenant_id = ? AND v.file_id = ?
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT 10",
            [$tenantId, $fileId]
        ) ?: [];

        api_success(['file_id' => $fileId, 'versions' => $rows]);
    }

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Body JSON non valido', 400);

    if ($action === 'apply_to_document') {
        $artifactId = (int)($payload['artifact_id'] ?? 0);
        if ($artifactId <= 0) api_error('artifact_id richiesto', 400);

        $artifact = $db->fetchOne(
            "SELECT a.id, a.program_id, a.artifact_key, a.title, a.file_id,
                    p.tenant_id
             FROM compliance_artifacts a
             JOIN compliance_programs p ON p.id = a.program_id
             WHERE a.id = ?
             LIMIT 1",
            [$artifactId]
        );
        if (!$artifact) api_error('Deliverable non trovato', 404);
        $tenantId = (int)($artifact['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

        $fileId = (int)($artifact['file_id'] ?? 0);
        if ($fileId <= 0) api_error('Deliverable senza documento associato', 400);

        // Snapshot before apply (visible version)
        $snap = cnx_dw_create_snapshot_version($db, $userInfo, $fileId, 'apply');
        $warnings = $snap['warnings'] ?? [];

        // Load profile + inputs
        $profileRow = $db->fetchOne(
            "SELECT profile_json FROM compliance_program_profiles WHERE tenant_id = ? AND program_id = ? LIMIT 1",
            [$tenantId, (int)$artifact['program_id']]
        );
        $profileObj = [];
        if ($profileRow && !empty($profileRow['profile_json'])) {
            try { $profileObj = json_decode((string)$profileRow['profile_json'], true) ?: []; } catch (Throwable $e) { $profileObj = []; }
        }
        $inputsRow = $db->fetchOne(
            "SELECT input_json FROM compliance_artifact_inputs WHERE tenant_id = ? AND artifact_id = ? LIMIT 1",
            [$tenantId, $artifactId]
        );
        $inputsObj = [];
        if ($inputsRow && !empty($inputsRow['input_json'])) {
            try { $inputsObj = json_decode((string)$inputsRow['input_json'], true) ?: []; } catch (Throwable $e) { $inputsObj = []; }
        }

        // Merge and flatten (inputs override profile)
        $repl = cnx_dw_flatten(is_array($profileObj) ? $profileObj : []);
        $replInputs = cnx_dw_flatten(is_array($inputsObj) ? $inputsObj : []);
        foreach ($replInputs as $k => $v) { $repl[$k] = $v; }

        // Common placeholders used by our placeholder docs (best-effort)
        // - Keep generic (NO ISO text)
        // Date placeholders (support both legacy today_date and newer doc_date)
        $repl['doc_date'] = date('d/m/Y');
        $repl['today_date'] = $repl['doc_date'];
        if (!isset($repl['doc_version']) || trim((string)$repl['doc_version']) === '') {
            $repl['doc_version'] = '0.1';
        }
        if (!isset($repl['doc_title']) || trim((string)$repl['doc_title']) === '') {
            $repl['doc_title'] = (string)($artifact['title'] ?? ($artifact['artifact_key'] ?? 'Documento'));
        }
        if (!isset($repl['doc_code']) || trim((string)$repl['doc_code']) === '') {
            $repl['doc_code'] = (string)($artifact['artifact_key'] ?? 'DOC');
        }

        // DOCX formatting helpers (best-effort):
        // - Remove redundant placeholder intro block from body
        // - Apply Heading1 style to section titles and field titles from template schema
        $headingTexts = [];
        try {
            $hasTpl = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'compliance_artifact_templates'
                 LIMIT 1"
            );
            if ($hasTpl) {
                $rowTpl = $db->fetchOne(
                    "SELECT input_schema_json
                     FROM compliance_artifact_templates
                     WHERE template_key = ?
                     LIMIT 1",
                    [(string)($artifact['artifact_key'] ?? '')]
                );
                $schemaRaw = $rowTpl ? (string)($rowTpl['input_schema_json'] ?? '') : '';
                if ($schemaRaw !== '') {
                    $schemaObj = json_decode($schemaRaw, true);
                    if (is_array($schemaObj) && is_array($schemaObj['sections'] ?? null)) {
                        foreach (($schemaObj['sections'] ?? []) as $sec) {
                            if (!is_array($sec)) continue;
                            $st = trim((string)($sec['title'] ?? ''));
                            if ($st !== '') $headingTexts[] = $st;
                            $fields = $sec['fields'] ?? null;
                            if (!is_array($fields)) continue;
                            foreach ($fields as $f) {
                                if (!is_array($f)) continue;
                                $lbl = trim((string)($f['label'] ?? ''));
                                if ($lbl !== '') $headingTexts[] = $lbl;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore (best-effort)
            $headingTexts = $headingTexts ?: [];
        }
        // De-dupe and cap to keep payload small
        $headingTexts = array_values(array_unique(array_filter(array_map('strval', $headingTexts))));
        if (count($headingTexts) > 120) $headingTexts = array_slice($headingTexts, 0, 120);
        $docxOpts = [
            'strip_intro_block' => true,
            'docx_heading1_texts' => $headingTexts,
        ];

        // Optional diagnostics (super_admin only) to troubleshoot placeholder replacement in DOCX
        $debugApply = ((string)($_GET['debug_apply'] ?? '') === '1') && ((string)($userInfo['role'] ?? '') === 'super_admin');
        if ($debugApply) {
            $repl['_cnx_debug_apply'] = '1';
        }
        $apply = cnx_dw_apply_placeholders($db, $userInfo, $tenantId, $fileId, $repl, $docxOpts);
        $warnings = array_values(array_filter(array_merge($warnings, $apply['warnings'] ?? [])));

        // prune old versions
        cnx_dw_prune_versions($db, $tenantId, $fileId, 3, $warnings);

        if (!$apply['ok']) {
            api_error('Applicazione placeholder fallita', 500, ['warnings' => array_slice($warnings, 0, 30)]);
        }

        api_success([
            'ok' => true,
            'artifact_id' => $artifactId,
            'file_id' => $fileId,
            'version_file_id' => $snap['version_file_id'] ?? null,
            'open_url' => 'files.php?open_file_id=' . $fileId . '&open_mode=edit',
            'warnings' => array_slice($warnings, 0, 30),
            'debug_apply' => $debugApply ? ($GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG'] ?? null) : null,
        ], 'Documento aggiornato');
    }

    if ($action === 'restore_version') {
        if (!cnx_dw_role_can_manage_versions($userInfo)) api_error('Accesso negato', 403);

        $fileId = (int)($payload['file_id'] ?? 0);
        $versionFileId = (int)($payload['version_file_id'] ?? 0);
        if ($fileId <= 0 || $versionFileId <= 0) api_error('file_id e version_file_id richiesti', 400);

        $ctx = cnx_dw_load_file_any($db, $fileId);
        $tenantId = (int)$ctx['tenant_id'];
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

        // Validate relationship exists
        $rel = $db->fetchOne(
            "SELECT 1
             FROM compliance_file_versions
             WHERE tenant_id = ? AND file_id = ? AND version_file_id = ?
             LIMIT 1",
            [$tenantId, $fileId, $versionFileId]
        );
        if (!$rel) api_error('Versione non trovata per questo file', 404);

        // Snapshot current before restore
        $warnings = [];
        $snap = cnx_dw_create_snapshot_version($db, $userInfo, $fileId, 'restore');
        $warnings = array_merge($warnings, $snap['warnings'] ?? []);

        $curr = getFileInfoForEditor($fileId, $tenantId);
        $ver = getFileInfoForEditor($versionFileId, $tenantId);
        if (!$curr || !$ver) api_error('File/versione non trovata', 404);
        $currAbs = (string)($curr['physical_path'] ?? '');
        $verAbs = (string)($ver['physical_path'] ?? '');
        if (!is_file($currAbs) || !is_file($verAbs)) api_error('File fisico non trovato', 404);

        if (!@copy($verAbs, $currAbs)) {
            api_error('Ripristino fallito (copy)', 500);
        }

        // Update current file metadata
        try {
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            if (cnx_dw_files_has_col($db, 'file_hash')) $upd['file_hash'] = @md5_file($currAbs) ?: null;
            if (cnx_dw_files_has_col($db, 'file_size')) {
                $sz = @filesize($currAbs);
                if (is_int($sz)) $upd['file_size'] = $sz;
            }
            $db->update('files', $upd, ['id' => $fileId]);
        } catch (Throwable $e) {
            $warnings[] = 'Aggiornamento metadati file fallito (best-effort)';
        }

        cnx_dw_prune_versions($db, $tenantId, $fileId, 3, $warnings);

        api_success([
            'ok' => true,
            'file_id' => $fileId,
            'restored_from' => $versionFileId,
            'version_file_id' => $snap['version_file_id'] ?? null,
            'warnings' => array_slice($warnings, 0, 30),
        ], 'Versione ripristinata');
    }

    api_error('Azione non supportata', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_ARTIFACT_APPLY] ' . $e->getMessage());
    api_error('Errore applicazione documento', 500);
}

