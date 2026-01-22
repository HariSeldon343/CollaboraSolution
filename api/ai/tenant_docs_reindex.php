<?php
/**
 * Tenant Docs Reindex (multi-tenant)
 * POST /api/ai/tenant_docs_reindex.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/ai/tenant_docs_indexer.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_doc_index_runs',
    'tenant_doc_chunks',
    'tenant_doc_files_state',
    'tenant_ai_settings',
], 'database/migrations/67_tenant_ai_onboarding.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
}

$force = !empty($payload['force']);
$maxFiles = (int)($payload['max_files'] ?? 80);
$maxFiles = max(10, min(400, $maxFiles));

$settings = $db->fetchOne(
    "SELECT allowed_index_paths_json, exclude_patterns_json
     FROM tenant_ai_settings
     WHERE tenant_id = ?
     LIMIT 1",
    [$tenantId]
);
$allowedPaths = ['/IMS'];
if ($settings && !empty($settings['allowed_index_paths_json'])) {
    $dec = json_decode((string)$settings['allowed_index_paths_json'], true);
    if (is_array($dec)) {
        $allowedPaths = array_values(array_filter(array_map('strval', $dec)));
    }
}
$excludePatterns = [];
if ($settings && !empty($settings['exclude_patterns_json'])) {
    $dec = json_decode((string)$settings['exclude_patterns_json'], true);
    if (is_array($dec)) {
        $excludePatterns = array_values(array_filter(array_map('strval', $dec)));
    }
}

// Throttle to 10 minutes (best-effort)
$lastIndexed = $db->fetchOne(
    "SELECT MAX(last_indexed_at) AS last_indexed_at
     FROM tenant_doc_files_state
     WHERE tenant_id = ?",
    [$tenantId]
);
$lastIndexedAt = (string)($lastIndexed['last_indexed_at'] ?? '');
$lastTs = $lastIndexedAt ? @strtotime($lastIndexedAt) : 0;
if (!$force && $lastTs && (time() - $lastTs) < 600) {
    api_success(['status' => 'fresh_skip', 'last_indexed_at' => $lastIndexedAt]);
}

// Lock per tenant
$lockName = 'tenant_docs_index_' . $tenantId;
if (!cnx_tenant_docs_try_lock($db, $lockName, 0)) {
    api_success(['status' => 'locked']);
}

$runId = (int)$db->insert('tenant_doc_index_runs', [
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

    foreach ($files as $f) {
        $stats['scanned_files']++;
        $fileId = (int)($f['id'] ?? 0);
        $name = (string)($f['name'] ?? '');
        $filePath = (string)($f['file_path'] ?? '');
        $updatedAt = (string)($f['updated_at'] ?? '');
        $size = (int)($f['file_size'] ?? 0);

        $logicalPath = cnx_tenant_docs_build_logical_path($db, $tenantId, $fileId);
        $hay = strtolower(trim($name . ' ' . $logicalPath));
        $excluded = false;
        foreach ($excludePatterns as $pat) {
            $pat = trim((string)$pat);
            if ($pat === '') continue;
            if (@preg_match('/' . $pat . '/i', $hay)) {
                $excluded = true;
                break;
            }
        }
        if ($excluded) {
            $stats['skipped_files']++;
            continue;
        }

        $absPath = cnx_tenant_docs_resolve_abs_path($tenantId, $filePath);
        if ($absPath === '' || !is_file($absPath)) {
            $stats['error_files']++;
            $db->insert('tenant_doc_files_state', [
                'tenant_id' => $tenantId,
                'file_id' => $fileId,
                'last_hash' => null,
                'last_indexed_at' => date('Y-m-d H:i:s'),
                'status' => 'error',
                'error_id' => 'file_missing',
            ]);
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $warnings = [];
        $text = cnx_tenant_docs_extract_text($absPath, $ext, $warnings);
        if ($text === '') {
            $stats['unsupported_files']++;
            $db->insert('tenant_doc_files_state', [
                'tenant_id' => $tenantId,
                'file_id' => $fileId,
                'last_hash' => null,
                'last_indexed_at' => date('Y-m-d H:i:s'),
                'status' => 'unsupported',
                'error_id' => null,
            ]);
            continue;
        }

        $text = cnx_tenant_docs_redact_text($text);
        $chunks = cnx_tenant_docs_chunk_text($text, 1200, 200);
        if (empty($chunks)) {
            $stats['skipped_files']++;
            continue;
        }
        $hash = cnx_tenant_docs_compute_file_hash($absPath, $fileId, $updatedAt . '_' . $size);
        $meta = [
            'path' => $logicalPath,
            'name' => $name,
            'mime' => (string)($f['mime_type'] ?? ''),
            'warnings' => $warnings,
        ];
        $res = cnx_tenant_docs_upsert_chunks($db, $tenantId, $fileId, $hash, $chunks, $meta);
        if (!empty($res['updated'])) {
            $stats['indexed_files']++;
            $stats['chunks_total'] += (int)($res['chunk_count'] ?? 0);
        } else {
            $stats['skipped_files']++;
        }
    }

    $db->update('tenant_doc_index_runs', [
        'finished_at' => date('Y-m-d H:i:s'),
        'status' => 'ok',
        'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['id' => $runId]);

    api_success([
        'status' => 'ok',
        'run_id' => $runId,
        'stats' => $stats,
    ]);
} catch (Throwable $e) {
    $errId = 'doc_idx_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[TENANT_DOCS_REINDEX][{$errId}] " . $e->getMessage());
    $db->update('tenant_doc_index_runs', [
        'finished_at' => date('Y-m-d H:i:s'),
        'status' => 'error',
        'error_id' => $errId,
        'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['id' => $runId]);
    api_error('Errore reindicizzazione', 500, ['error_id' => $errId]);
} finally {
    cnx_tenant_docs_release_lock($db, $lockName);
}

