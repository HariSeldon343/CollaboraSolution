<?php
/**
 * Leva 4: Compliance artifacts API
 *
 * GET  /api/compliance/artifacts.php?action=list&program_id=...
 * POST /api/compliance/artifacts.php?action=update
 *
 * Update supports: status, owner_label, owner_user_id, due_date, file_id, folder_id
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/compliance/template_schema_defaults.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

// Storage check (schema drift safe)
$chk = cnx_compliance_check_tables($db, ['compliance_programs', 'compliance_artifacts']);
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'artifacts' => [],
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/41_compliance_programs.sql',
    ]);
}

try {
    if ($action === 'list') {
        $programId = (int)($_GET['program_id'] ?? 0);
        if ($programId <= 0) api_error('program_id richiesto', 400);

        $program = $db->fetchOne("SELECT tenant_id FROM compliance_programs WHERE id = ? LIMIT 1", [$programId]);
        if (!$program) api_error('Programma non trovato', 404);
        $tenantId = (int)($program['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant del programma', 403);
        }

        $hasTasks = false;
        try { $hasTasks = (bool)$db->fetchOne("SHOW TABLES LIKE 'tasks'"); } catch (Throwable $e) { $hasTasks = false; }
        $hasTemplates = false;
        try { $hasTemplates = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_artifact_templates'"); } catch (Throwable $e) { $hasTemplates = false; }
        $hasInputs = false;
        try { $hasInputs = (bool)$db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_artifact_inputs' LIMIT 1"); } catch (Throwable $e) { $hasInputs = false; }
        $hasVersions = false;
        try { $hasVersions = (bool)$db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_file_versions' LIMIT 1"); } catch (Throwable $e) { $hasVersions = false; }
        $tplHasSchema = false;
        $tplHasAiHint = false;
        if ($hasTemplates) {
            try {
                $tplHasSchema = (bool)$db->fetchOne(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'compliance_artifact_templates'
                       AND COLUMN_NAME = 'input_schema_json'
                     LIMIT 1"
                );
                $tplHasAiHint = (bool)$db->fetchOne(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'compliance_artifact_templates'
                       AND COLUMN_NAME = 'ai_hint'
                     LIMIT 1"
                );
            } catch (Throwable $e) {
                $tplHasSchema = false;
                $tplHasAiHint = false;
            }
        }

        $tplSchemaSelect = $tplHasSchema ? "tpl.input_schema_json AS tpl_input_schema_json" : "'' AS tpl_input_schema_json";
        $tplAiSelect = $tplHasAiHint ? "tpl.ai_hint AS tpl_ai_hint" : "'' AS tpl_ai_hint";

        if ($hasTasks) {
            if ($hasTemplates) {
                $rows = $db->fetchAll(
                    "SELECT a.id, a.program_id, a.artifact_key, a.title, a.artifact_type, a.clause_refs_json,
                            a.owner_label, a.owner_user_id, a.due_date, a.status, a.file_id, a.folder_id, a.updated_at,
                            tl.task_id AS task_id,
                            t.status AS task_status,
                            tpl.template_key AS tpl_template_key,
                            tpl.doc_type AS tpl_doc_type,
                            tpl.file_kind AS tpl_file_kind,
                            tpl.folder_path AS tpl_folder_path,
                            tpl.filename_template AS tpl_filename_template,
                            tpl.tags_json AS tpl_tags_json,
                            tpl.is_common_hls AS tpl_is_common_hls,
                            {$tplSchemaSelect},
                            {$tplAiSelect}
                     FROM compliance_artifacts a
                     LEFT JOIN compliance_task_links tl ON tl.artifact_id = a.id
                     LEFT JOIN tasks t ON t.id = tl.task_id
                     LEFT JOIN compliance_artifact_templates tpl ON tpl.template_key = a.artifact_key
                     WHERE a.program_id = ?
                     ORDER BY a.id ASC",
                    [$programId]
                ) ?: [];
            } else {
                $rows = $db->fetchAll(
                    "SELECT a.id, a.program_id, a.artifact_key, a.title, a.artifact_type, a.clause_refs_json,
                            a.owner_label, a.owner_user_id, a.due_date, a.status, a.file_id, a.folder_id, a.updated_at,
                            tl.task_id AS task_id,
                            t.status AS task_status
                     FROM compliance_artifacts a
                     LEFT JOIN compliance_task_links tl ON tl.artifact_id = a.id
                     LEFT JOIN tasks t ON t.id = tl.task_id
                     WHERE a.program_id = ?
                     ORDER BY a.id ASC",
                    [$programId]
                ) ?: [];
            }
        } else {
            if ($hasTemplates) {
                $rows = $db->fetchAll(
                    "SELECT a.id, a.program_id, a.artifact_key, a.title, a.artifact_type, a.clause_refs_json,
                            a.owner_label, a.owner_user_id, a.due_date, a.status, a.file_id, a.folder_id, a.updated_at,
                            tpl.template_key AS tpl_template_key,
                            tpl.doc_type AS tpl_doc_type,
                            tpl.file_kind AS tpl_file_kind,
                            tpl.folder_path AS tpl_folder_path,
                            tpl.filename_template AS tpl_filename_template,
                            tpl.tags_json AS tpl_tags_json,
                            tpl.is_common_hls AS tpl_is_common_hls,
                            {$tplSchemaSelect},
                            {$tplAiSelect}
                     FROM compliance_artifacts a
                     LEFT JOIN compliance_artifact_templates tpl ON tpl.template_key = a.artifact_key
                     WHERE a.program_id = ?
                     ORDER BY a.id ASC",
                    [$programId]
                ) ?: [];
            } else {
                $rows = $db->fetchAll(
                    "SELECT id, program_id, artifact_key, title, artifact_type, clause_refs_json,
                            owner_label, owner_user_id, due_date, status, file_id, folder_id, updated_at
                     FROM compliance_artifacts
                     WHERE program_id = ?
                     ORDER BY id ASC",
                    [$programId]
                ) ?: [];
            }
        }

        // Optional: enrich with inputs/versions info for compilation status (wizard)
        $inputByArtifactId = [];
        if ($hasInputs && !empty($rows)) {
            $ids = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows)));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$tenantId], $ids);
                try {
                    $inpRows = $db->fetchAll(
                        "SELECT artifact_id, updated_at
                         FROM compliance_artifact_inputs
                         WHERE tenant_id = ?
                           AND artifact_id IN ($placeholders)",
                        $params
                    ) ?: [];
                    foreach ($inpRows as $ir) {
                        $aid = (int)($ir['artifact_id'] ?? 0);
                        if ($aid > 0) $inputByArtifactId[$aid] = (string)($ir['updated_at'] ?? '');
                    }
                } catch (Throwable $e) {
                    $inputByArtifactId = [];
                }
            }
        }

        $versionsByFileId = [];
        if ($hasVersions && !empty($rows)) {
            $fileIds = [];
            foreach ($rows as $r) {
                $fid = (int)($r['file_id'] ?? 0);
                if ($fid > 0) $fileIds[$fid] = true;
            }
            $fileIds = array_keys($fileIds);
            if (!empty($fileIds)) {
                $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
                $params = array_merge([$tenantId], $fileIds);
                try {
                    $vRows = $db->fetchAll(
                        "SELECT file_id, COUNT(*) AS cnt
                         FROM compliance_file_versions
                         WHERE tenant_id = ?
                           AND file_id IN ($placeholders)
                         GROUP BY file_id",
                        $params
                    ) ?: [];
                    foreach ($vRows as $vr) {
                        $fid = (int)($vr['file_id'] ?? 0);
                        $cnt = (int)($vr['cnt'] ?? 0);
                        if ($fid > 0) $versionsByFileId[$fid] = $cnt;
                    }
                } catch (Throwable $e) {
                    $versionsByFileId = [];
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $clauseRefs = null;
            try { $clauseRefs = $r['clause_refs_json'] ? (json_decode((string)$r['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $clauseRefs = null; }
            $standards = [];
            $flat = [];
            if (is_array($clauseRefs)) {
                $isAssoc = array_keys($clauseRefs) !== range(0, count($clauseRefs) - 1);
                if ($isAssoc) {
                    $standards = array_values(array_filter(array_map('strval', array_keys($clauseRefs))));
                    foreach ($clauseRefs as $k => $arr) {
                        if (!is_array($arr)) continue;
                        foreach ($arr as $c) $flat[] = (string)$c;
                    }
                } else {
                    $flat = array_values(array_map('strval', $clauseRefs));
                }
            }
            $flat = array_values(array_unique(array_filter($flat)));
            sort($flat);

            $tags = [];
            if (isset($r['tpl_tags_json'])) {
                try { $tags = $r['tpl_tags_json'] ? (json_decode((string)$r['tpl_tags_json'], true) ?: []) : []; } catch (Throwable $e) { $tags = []; }
            }
            $artifactId = (int)$r['id'];
            $fileId = isset($r['file_id']) ? (int)$r['file_id'] : 0;
            // "Compila" availability: if a deliverable has an associated document, we allow compilation via
            // - stored template schema (if present), OR
            // - runtime fallback schema (even when template row/schema is missing).
            $hasSchema = $fileId > 0;
            $inputsUpdatedAt = $inputByArtifactId[$artifactId] ?? null;
            $versionsCount = $fileId > 0 ? (int)($versionsByFileId[$fileId] ?? 0) : 0;
            $out[] = [
                'id' => $artifactId,
                'program_id' => (int)$r['program_id'],
                'artifact_key' => (string)$r['artifact_key'],
                'title' => (string)$r['title'],
                'artifact_type' => (string)$r['artifact_type'],
                'clause_refs' => $clauseRefs,               // array legacy OR object per standard (preferred)
                'clause_refs_flat' => $flat,                // convenience for UI
                'standards' => $standards,                  // derived from clause refs object keys
                'owner_label' => (string)($r['owner_label'] ?? ''),
                'owner_user_id' => isset($r['owner_user_id']) ? (int)$r['owner_user_id'] : null,
                'due_date' => $r['due_date'] ?? null,
                'status' => (string)$r['status'],
                'file_id' => $fileId > 0 ? $fileId : null,
                'folder_id' => isset($r['folder_id']) ? (int)$r['folder_id'] : null,
                'task_id' => isset($r['task_id']) ? (int)$r['task_id'] : null,
                'task_status' => isset($r['task_status']) ? (string)$r['task_status'] : null,
                'wizard' => [
                    'has_schema' => $hasSchema ? 1 : 0,
                    'inputs_updated_at' => $inputsUpdatedAt,
                    'versions_count' => $versionsCount,
                ],
                'template' => $hasTemplates ? [
                    'doc_type' => (string)($r['tpl_doc_type'] ?? ''),
                    'file_kind' => (string)($r['tpl_file_kind'] ?? ''),
                    'folder_path' => (string)($r['tpl_folder_path'] ?? ''),
                    'filename_template' => (string)($r['tpl_filename_template'] ?? ''),
                    'tags' => is_array($tags) ? array_values($tags) : [],
                    'is_common_hls' => isset($r['tpl_is_common_hls']) ? ((int)$r['tpl_is_common_hls'] ? 1 : 0) : 0,
                ] : null,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }

        api_success([
            'storage_available' => true,
            'program_id' => $programId,
            'artifacts' => $out,
        ]);
    }

    if ($action === 'update') {
        cnx_compliance_require_csrf_for_write();

        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $artifactId = (int)($payload['artifact_id'] ?? 0);
        if ($artifactId <= 0) api_error('artifact_id richiesto', 400);

        $artifact = $db->fetchOne(
            "SELECT a.id, a.program_id, p.tenant_id
             FROM compliance_artifacts a
             JOIN compliance_programs p ON p.id = a.program_id
             WHERE a.id = ?
             LIMIT 1",
            [$artifactId]
        );
        if (!$artifact) api_error('Artifact non trovato', 404);
        $tenantId = (int)($artifact['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant del programma', 403);
        }

        $allowedStatus = ['todo','draft','in_review','approved','obsolete'];
        $upd = [];

        if (isset($payload['status'])) {
            $st = (string)$payload['status'];
            if (!in_array($st, $allowedStatus, true)) api_error('status non valido', 400);
            $upd['status'] = $st;
        }
        if (array_key_exists('owner_label', $payload)) {
            $upd['owner_label'] = trim((string)$payload['owner_label']);
        }
        if (array_key_exists('owner_user_id', $payload)) {
            $v = $payload['owner_user_id'];
            $upd['owner_user_id'] = ($v === null || $v === '') ? null : (int)$v;
        }
        if (array_key_exists('due_date', $payload)) {
            $v = $payload['due_date'];
            $upd['due_date'] = ($v === null || $v === '') ? null : (string)$v;
        }
        if (array_key_exists('file_id', $payload)) {
            $v = $payload['file_id'];
            $upd['file_id'] = ($v === null || $v === '') ? null : (int)$v;
        }
        if (array_key_exists('folder_id', $payload)) {
            $v = $payload['folder_id'];
            $upd['folder_id'] = ($v === null || $v === '') ? null : (int)$v;
        }

        if (empty($upd)) {
            api_error('Nessun campo da aggiornare', 400);
        }
        $upd['updated_at'] = date('Y-m-d H:i:s');

        $db->update('compliance_artifacts', $upd, ['id' => $artifactId]);
        api_success(['storage_available' => true, 'artifact_id' => $artifactId], 'Aggiornato');
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_ARTIFACTS] ' . $e->getMessage());
    api_error('Errore compliance artifacts', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

