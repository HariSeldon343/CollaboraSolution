<?php
/**
 * Tenant Docs Snapshot (multi-tenant)
 * GET /api/ai/tenant_docs_snapshot.php?tenant_id=...
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/ai/tenant_docs_indexer.php';

cnx_ai_require_csrf_for_write();

$tenantId = cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_doc_index_runs',
    'tenant_doc_chunks',
    'tenant_doc_files_state',
    'tenant_ai_settings',
], 'database/migrations/67_tenant_ai_onboarding.sql');
if (!$storage['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $storage['missing'] ?? [],
        'migration' => $storage['migration'] ?? null,
    ]);
}

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

$latestRun = $db->fetchOne(
    "SELECT id, started_at, finished_at, status, error_id, stats_json
     FROM tenant_doc_index_runs
     WHERE tenant_id = ?
     ORDER BY id DESC
     LIMIT 1",
    [$tenantId]
);
$lastIndexed = $db->fetchOne(
    "SELECT MAX(last_indexed_at) AS last_indexed_at
     FROM tenant_doc_files_state
     WHERE tenant_id = ?",
    [$tenantId]
);
$lastIndexedAt = (string)($lastIndexed['last_indexed_at'] ?? '');
try { $lastTs = $lastIndexedAt ? strtotime($lastIndexedAt) : 0; } catch (Throwable $e) { $lastTs = 0; }
$stale = (!$lastTs || (time() - $lastTs) > 600);

$counts = $db->fetchOne(
    "SELECT COUNT(DISTINCT file_id) AS files, COUNT(*) AS chunks
     FROM tenant_doc_chunks
     WHERE tenant_id = ?",
    [$tenantId]
);

api_success([
    'storage_available' => true,
    'tenant_id' => $tenantId,
    'allowed_paths' => $allowedPaths,
    'exclude_patterns' => $excludePatterns,
    'last_indexed_at' => $lastIndexedAt,
    'stale' => $stale,
    'counts' => [
        'files' => (int)($counts['files'] ?? 0),
        'chunks' => (int)($counts['chunks'] ?? 0),
    ],
    'last_run' => $latestRun ?: null,
]);

