<?php
/**
 * Client Docs Snapshot (multi-tenant, Copilot)
 * GET /api/client_docs_snapshot.php?tenant_id=...
 */
declare(strict_types=1);

require_once __DIR__ . '/ai/_common.php';

cnx_ai_require_csrf_for_write();

$tenantId = cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'ai_doc_chunks',
    'ai_doc_index_jobs',
    'ai_tenant_settings',
], 'database/migrations/68_ai_copilot_rag.sql');
if (!$storage['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $storage['missing'] ?? [],
        'migration' => $storage['migration'] ?? null,
    ]);
}

$settings = $db->fetchOne(
    "SELECT ai_indexing_enabled, ai_external_provider_enabled, allowed_index_paths_json, exclude_patterns_json
     FROM ai_tenant_settings
     WHERE tenant_id = ?
     LIMIT 1",
    [$tenantId]
);
$allowedPaths = ['/IMS'];
if ($settings && !empty($settings['allowed_index_paths_json'])) {
    $dec = json_decode((string)$settings['allowed_index_paths_json'], true);
    if (is_array($dec)) $allowedPaths = array_values(array_filter(array_map('strval', $dec)));
}

$lastJob = $db->fetchOne(
    "SELECT finished_at, status, error_id, stats_json
     FROM ai_doc_index_jobs
     WHERE tenant_id = ?
     ORDER BY id DESC
     LIMIT 1",
    [$tenantId]
);
$lastFinished = (string)($lastJob['finished_at'] ?? '');
$lastTs = $lastFinished ? @strtotime($lastFinished) : 0;
$stale = (!$lastTs || (time() - $lastTs) > 600);

$counts = $db->fetchOne(
    "SELECT COUNT(DISTINCT file_id) AS files, COUNT(*) AS chunks
     FROM ai_doc_chunks
     WHERE tenant_id = ?",
    [$tenantId]
);

api_success([
    'storage_available' => true,
    'tenant_id' => $tenantId,
    'allowed_paths' => $allowedPaths,
    'indexing_enabled' => $settings ? ((int)($settings['ai_indexing_enabled'] ?? 1) === 1) : true,
    'external_provider_enabled' => $settings ? ((int)($settings['ai_external_provider_enabled'] ?? 1) === 1) : true,
    'last_indexed_at' => $lastFinished,
    'stale' => $stale,
    'counts' => [
        'files' => (int)($counts['files'] ?? 0),
        'chunks' => (int)($counts['chunks'] ?? 0),
    ],
    'last_run' => $lastJob ?: null,
]);

