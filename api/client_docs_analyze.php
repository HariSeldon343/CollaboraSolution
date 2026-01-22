<?php
/**
 * Client Docs Analyze (multi-tenant, Copilot)
 * POST /api/client_docs_analyze.php
 */
declare(strict_types=1);

require_once __DIR__ . '/ai/_common.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'ai_doc_chunks',
], 'database/migrations/68_ai_copilot_rag.sql');
if (!$storage['ok']) {
    api_success([
        'supported' => false,
        'storage_available' => false,
        'migration' => $storage['migration'] ?? null,
    ]);
}

$rules = [
    'manual' => ['manuale', 'manual', 'quality manual', 'sgq'],
    'procedures' => ['procedura', 'procedure', 'sop', 'istruzione operativa'],
    'records' => ['registro', 'registri', 'record', 'modulo', 'moduli'],
    'audit' => ['audit', 'verifica ispettiva', 'internal audit'],
    'management_review' => ['riesame', 'management review', 'riesame direzione'],
    'risk' => ['risch', 'risk', 'opportunit'],
    'doc_control' => ['gestione document', 'controllo document', 'version'],
];

$detected = [];
$gaps = [];
$foundCount = 0;
$requiredKeys = ['manual','procedures','records','audit','management_review'];

foreach ($rules as $tag => $kws) {
    $found = false;
    foreach ($kws as $kw) {
        $like = '%' . $kw . '%';
        $row = $db->fetchOne(
            "SELECT COUNT(DISTINCT file_id) AS cnt
             FROM ai_doc_chunks
             WHERE tenant_id = ?
               AND LOWER(chunk_text) LIKE ?",
            [$tenantId, strtolower($like)]
        );
        if ((int)($row['cnt'] ?? 0) > 0) {
            $found = true;
            break;
        }
    }
    $detected[$tag] = $found ? 1 : 0;
    if ($found) $foundCount++;
}

foreach ($requiredKeys as $rk) {
    if (empty($detected[$rk])) $gaps[] = $rk;
}

$totalRequired = count($requiredKeys);
$score = $totalRequired > 0 ? (int)round(($foundCount / max(1, $totalRequired)) * 100) : 0;
$maturity = ($score >= 75) ? 'high' : (($score >= 40) ? 'medium' : 'low');
$confidence = min(90, max(20, $score));

api_success([
    'supported' => true,
    'storage_available' => true,
    'maturity_score' => $score,
    'maturity_suggested' => $maturity,
    'confidence' => $confidence,
    'detected' => $detected,
    'gaps' => $gaps,
]);

