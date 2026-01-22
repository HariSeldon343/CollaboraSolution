<?php
/**
 * AI Copilot RAG Reindex (multi-tenant)
 * POST /api/ai_rag_reindex.php
 */
declare(strict_types=1);

require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/openai_client.php';
require_once __DIR__ . '/../includes/ai/embedding_utils.php';
require_once __DIR__ . '/../includes/ai/tenant_docs_indexer.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'ai_doc_chunks',
    'ai_doc_index_jobs',
    'ai_tenant_settings',
], 'database/migrations/68_ai_copilot_rag.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
}

$force = !empty($payload['force']);
$maxFiles = (int)($payload['max_files'] ?? 80);
$maxFiles = max(10, min(400, $maxFiles));

$settings = $db->fetchOne(
    "SELECT ai_indexing_enabled, ai_external_provider_enabled, allowed_index_paths_json, exclude_patterns_json
     FROM ai_tenant_settings
     WHERE tenant_id = ?
     LIMIT 1",
    [$tenantId]
);
$indexingEnabled = $settings ? ((int)($settings['ai_indexing_enabled'] ?? 1) === 1) : true;
$externalEnabled = $settings ? ((int)($settings['ai_external_provider_enabled'] ?? 1) === 1) : true;
if (!$indexingEnabled) {
    api_success(['status' => 'disabled']);
}

$allowedPaths = ['/IMS'];
if ($settings && !empty($settings['allowed_index_paths_json'])) {
    $dec = json_decode((string)$settings['allowed_index_paths_json'], true);
    if (is_array($dec)) $allowedPaths = array_values(array_filter(array_map('strval', $dec)));
}
$excludePatterns = [];
if ($settings && !empty($settings['exclude_patterns_json'])) {
    $dec = json_decode((string)$settings['exclude_patterns_json'], true);
    if (is_array($dec)) $excludePatterns = array_values(array_filter(array_map('strval', $dec)));
}

// Throttle 10 min
$lastJob = $db->fetchOne(
    "SELECT finished_at
     FROM ai_doc_index_jobs
     WHERE tenant_id = ?
       AND status = 'ok'
     ORDER BY id DESC
     LIMIT 1",
    [$tenantId]
);
$lastTs = $lastJob && !empty($lastJob['finished_at']) ? @strtotime((string)$lastJob['finished_at']) : 0;
if (!$force && $lastTs && (time() - $lastTs) < 600) {
    api_success(['status' => 'fresh_skip', 'last_finished_at' => (string)($lastJob['finished_at'] ?? '')]);
}

// Named lock
$lockName = 'ai_copilot_index_' . $tenantId;
if (!cnx_tenant_docs_try_lock($db, $lockName, 0)) {
    api_success(['status' => 'locked']);
}

$jobId = (int)$db->insert('ai_doc_index_jobs', [
    'tenant_id' => $tenantId,
    'started_at' => date('Y-m-d H:i:s'),
    'status' => 'running',
    'stats_json' => null,
]);

$stats = [
    'scanned_files' => 0,
    'indexed_files' => 0,
    'skipped_files' => 0,
    'unsupported_files' => 0,
    'error_files' => 0,
    'chunks_total' => 0,
];

try {
    $files = cnx_tenant_docs_list_files($db, $tenantId, $allowedPaths);
    $files = array_slice($files, 0, $maxFiles);
    $currentFileIds = [];

    foreach ($files as $f) {
        $stats['scanned_files']++;
        $fileId = (int)($f['id'] ?? 0);
        $name = (string)($f['name'] ?? '');
        $filePath = (string)($f['file_path'] ?? '');
        $updatedAt = (string)($f['updated_at'] ?? '');
        $size = (int)($f['file_size'] ?? 0);
        if ($fileId <= 0) continue;
        $currentFileIds[] = $fileId;

        $logicalPath = cnx_tenant_docs_build_logical_path($db, $tenantId, $fileId);
        $hay = strtolower(trim($name . ' ' . $logicalPath));
        $excluded = false;
        foreach ($excludePatterns as $pat) {
            $pat = trim((string)$pat);
            if ($pat === '') continue;
            if (@preg_match('/' . $pat . '/i', $hay)) { $excluded = true; break; }
        }
        if ($excluded) {
            $stats['skipped_files']++;
            continue;
        }

        $absPath = cnx_tenant_docs_resolve_abs_path($tenantId, $filePath);
        if ($absPath === '' || !is_file($absPath)) {
            $stats['error_files']++;
            continue;
        }

        $fileHash = cnx_tenant_docs_compute_file_hash($absPath, $fileId, $updatedAt . '_' . $size);
        $existing = $db->fetchOne(
            "SELECT 1 FROM ai_doc_chunks
             WHERE tenant_id = ? AND file_id = ? AND file_hash = ?
             LIMIT 1",
            [$tenantId, $fileId, $fileHash]
        );
        if ($existing) {
            $stats['skipped_files']++;
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $warnings = [];
        $text = cnx_tenant_docs_extract_text($absPath, $ext, $warnings);
        if ($text === '') {
            $stats['unsupported_files']++;
            continue;
        }
        $text = cnx_tenant_docs_redact_text($text);
        $chunks = cnx_tenant_docs_chunk_text($text, 1000, 200);
        if (empty($chunks)) {
            $stats['skipped_files']++;
            continue;
        }

        // Delete old chunks for file
        $db->query("DELETE FROM ai_doc_chunks WHERE tenant_id = ? AND file_id = ?", [$tenantId, $fileId]);

        $embedInputs = [];
        foreach ($chunks as $c) {
            $chunkText = trim((string)$c);
            if ($chunkText === '') continue;
            // Hard cap to avoid large provider payloads
            if (mb_strlen($chunkText, 'UTF-8') > 1800) {
                $chunkText = mb_substr($chunkText, 0, 1800, 'UTF-8');
            }
            $embedInputs[] = $chunkText;
        }

        $embeddings = [];
        if ($externalEnabled && !empty($embedInputs)) {
            $emb = cnx_openai_embed_texts($embedInputs, []);
            if ($emb['ok']) {
                $embeddings = $emb['embeddings'] ?? [];
            }
        }

        $idx = 0;
        foreach ($embedInputs as $chunkText) {
            $chunkHash = md5($chunkText);
            $embed = $embeddings[$idx] ?? null;
            $db->insert('ai_doc_chunks', [
                'tenant_id' => $tenantId,
                'file_id' => $fileId,
                'file_hash' => $fileHash,
                'chunk_index' => $idx,
                'chunk_text' => $chunkText,
                'chunk_hash' => $chunkHash,
                'embedding_json' => $embed ? json_encode($embed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'embedding_dim' => $embed ? count($embed) : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $idx++;
            if ($idx >= 500) break;
        }

        $stats['indexed_files']++;
        $stats['chunks_total'] += $idx;
    }

    // Cleanup obsolete chunks (best-effort)
    if (!empty($currentFileIds)) {
        $placeholders = implode(',', array_fill(0, count($currentFileIds), '?'));
        $params = array_merge([$tenantId], $currentFileIds);
        $db->query(
            "DELETE FROM ai_doc_chunks
             WHERE tenant_id = ?
               AND file_id NOT IN ({$placeholders})",
            $params
        );
    }

    $db->update('ai_doc_index_jobs', [
        'finished_at' => date('Y-m-d H:i:s'),
        'status' => 'ok',
        'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['id' => $jobId]);

    api_success(['status' => 'ok', 'job_id' => $jobId, 'stats' => $stats]);
} catch (Throwable $e) {
    $errId = 'rag_idx_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[AI_RAG_REINDEX][{$errId}] " . $e->getMessage());
    $db->update('ai_doc_index_jobs', [
        'finished_at' => date('Y-m-d H:i:s'),
        'status' => 'error',
        'error_id' => $errId,
        'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['id' => $jobId]);
    api_error('Errore reindicizzazione', 500, ['error_id' => $errId]);
} finally {
    cnx_tenant_docs_release_lock($db, $lockName);
}

