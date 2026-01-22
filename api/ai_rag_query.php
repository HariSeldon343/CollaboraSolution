<?php
/**
 * AI Copilot RAG Query (multi-tenant)
 * POST /api/ai_rag_query.php
 */
declare(strict_types=1);

require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/openai_client.php';
require_once __DIR__ . '/../includes/ai/embedding_utils.php';
require_once __DIR__ . '/../includes/file_access.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'ai_doc_chunks',
    'ai_tenant_settings',
], 'database/migrations/68_ai_copilot_rag.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
}

$query = trim((string)($payload['query_text'] ?? ''));
if ($query === '') api_error('query_text richiesto', 400);
$topK = (int)($payload['top_k'] ?? 8);
$topK = max(1, min(20, $topK));
$filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
$fileTypes = is_array($filters['file_types'] ?? null) ? $filters['file_types'] : [];
$fileTypes = array_values(array_filter(array_map('strtolower', array_map('strval', $fileTypes))));

$settings = $db->fetchOne(
    "SELECT ai_external_provider_enabled
     FROM ai_tenant_settings
     WHERE tenant_id = ?
     LIMIT 1",
    [$tenantId]
);
$externalEnabled = $settings ? ((int)($settings['ai_external_provider_enabled'] ?? 1) === 1) : true;

// Check FULLTEXT availability (best-effort)
$hasFulltext = false;
try {
    $row = $db->fetchOne(
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ai_doc_chunks'
           AND INDEX_TYPE = 'FULLTEXT'
         LIMIT 1"
    );
    $hasFulltext = (bool)$row;
} catch (Throwable $e) {
    $hasFulltext = false;
}

$limitCandidates = 200;
$queryLike = '%' . (mb_strlen($query, 'UTF-8') > 180 ? mb_substr($query, 0, 180, 'UTF-8') : $query) . '%';

$baseSql = "SELECT c.id, c.file_id, c.chunk_text, c.embedding_json, c.embedding_dim
            FROM ai_doc_chunks c
            WHERE c.tenant_id = ?";
$params = [$tenantId];

if (!empty($fileTypes)) {
    $placeholders = implode(',', array_fill(0, count($fileTypes), '?'));
    $baseSql .= " AND LOWER(SUBSTRING_INDEX((SELECT f.name FROM files f WHERE f.id = c.file_id LIMIT 1), '.', -1)) IN ({$placeholders})";
    $params = array_merge($params, $fileTypes);
}

if ($hasFulltext) {
    $baseSql .= " AND MATCH(c.chunk_text) AGAINST (? IN BOOLEAN MODE)";
    $params[] = $query;
    $baseSql .= " LIMIT {$limitCandidates}";
} else {
    $baseSql .= " AND c.chunk_text LIKE ?";
    $params[] = $queryLike;
    $baseSql .= " LIMIT {$limitCandidates}";
}

$rows = $db->fetchAll($baseSql, $params) ?: [];

// Compute query embedding if allowed and possible
$queryEmbedding = null;
if ($externalEnabled) {
    $emb = cnx_openai_embed_texts([$query], []);
    if ($emb['ok']) {
        $queryEmbedding = $emb['embeddings'][0] ?? null;
    }
}

$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
$userRole = (string)($userInfo['role'] ?? 'user');

$scored = [];
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
    if (mb_strlen($txt, 'UTF-8') > 500) $txt = mb_substr($txt, 0, 500, 'UTF-8') . '…';
    $score = 0.0;
    $embJson = (string)($r['embedding_json'] ?? '');
    if ($queryEmbedding && $embJson !== '') {
        $vec = json_decode($embJson, true);
        if (is_array($vec)) {
            $score = cnx_cosine_similarity($queryEmbedding, $vec);
        }
    } else {
        // fallback: naive term overlap
        $score = (float)substr_count(mb_strtolower($txt, 'UTF-8'), mb_strtolower($query, 'UTF-8'));
    }
    $scored[] = [
        'chunk_id' => (int)($r['id'] ?? 0),
        'file_id' => $fid,
        'excerpt' => $txt,
        'score' => $score,
    ];
}

usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']));
$scored = array_slice($scored, 0, $topK);

// Enrich with file name/path (best-effort)
foreach ($scored as &$s) {
    $file = $db->fetchOne(
        "SELECT name, file_path
         FROM files
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
         LIMIT 1",
        [(int)$s['file_id'], $tenantId]
    );
    $s['file_name'] = $file ? (string)($file['name'] ?? '') : '';
    $s['file_path'] = $file ? (string)($file['file_path'] ?? '') : '';
}
unset($s);

api_success(['query' => $query, 'results' => $scored]);

