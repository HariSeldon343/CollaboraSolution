<?php
declare(strict_types=1);

/**
 * AI Knowledge: search endpoint
 *
 * GET /api/ai/knowledge_search.php?q=...&tenant_id=...&limit=...
 *
 * Returns top text chunks to be used as context for AI chat (RAG).
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/file_access.php';

$chk = cnx_ai_check_tables($db, ['ai_knowledge_sources', 'ai_knowledge_chunks'], 'database/migrations/48_ai_knowledge_index.sql');
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/48_ai_knowledge_index.sql',
        'results' => [],
    ]);
}

$tenantId = cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
    api_success(['storage_available' => true, 'results' => []]);
}
$limit = (int)($_GET['limit'] ?? 8);
$limit = max(1, min(20, $limit));

$source = $db->fetchOne(
    "SELECT id, folder_id, folder_label
     FROM ai_knowledge_sources
     WHERE tenant_id = ? AND is_active = 1
     ORDER BY id DESC
     LIMIT 1",
    [$tenantId]
);
if (!$source) {
    api_success([
        'storage_available' => true,
        'results' => [],
        'warning' => 'Knowledge non configurata: crea /Knowledge o /IMS e avvia indicizzazione',
    ]);
}

$sourceId = (int)($source['id'] ?? 0);
if ($sourceId <= 0) api_success(['storage_available' => true, 'results' => []]);

// LIKE-based search (max compatibility)
$like = '%' . $q . '%';
$rows = $db->fetchAll(
    "SELECT file_id, file_name, logical_path, chunk_index, chunk_text
     FROM ai_knowledge_chunks
     WHERE tenant_id = ?
       AND source_id = ?
       AND chunk_text LIKE ?
     ORDER BY updated_at DESC, id DESC
     LIMIT {$limit}",
    [$tenantId, $sourceId, $like]
) ?: [];

// Build short snippets
$results = [];
$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
$userRole = (string)($userInfo['role'] ?? 'user');
foreach ($rows as $r) {
    $fid = (int)($r['file_id'] ?? 0);
    if ($fid > 0) {
        try {
            $acc = hasFileOrFolderAccess($db, $fid, $userId, $userRole, $tenantId);
            if (empty($acc['has_access'])) continue;
        } catch (Throwable $e) {
            continue;
        }
    }
    $txt = (string)($r['chunk_text'] ?? '');
    $pos = mb_stripos($txt, $q, 0, 'UTF-8');
    if ($pos === false) $pos = 0;
    $start = max(0, (int)$pos - 120);
    $snippet = mb_substr($txt, $start, 360, 'UTF-8');
    $snippet = trim($snippet);
    $results[] = [
        'file_id' => $fid,
        'file_name' => (string)($r['file_name'] ?? ''),
        'logical_path' => (string)($r['logical_path'] ?? ''),
        'chunk_index' => (int)($r['chunk_index'] ?? 0),
        'snippet' => $snippet,
    ];
}

api_success([
    'storage_available' => true,
    'tenant_id' => $tenantId,
    'source_id' => $sourceId,
    'results' => $results,
]);

