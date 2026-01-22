<?php
/**
 * Tenant Docs Analyze (multi-tenant)
 * POST /api/ai/tenant_docs_analyze.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_doc_chunks',
    'tenant_doc_files_state',
], 'database/migrations/67_tenant_ai_onboarding.sql');
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
             FROM tenant_doc_chunks
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
    if (empty($detected[$rk])) {
        $gaps[] = $rk;
    }
}

$totalRequired = count($requiredKeys);
$score = $totalRequired > 0 ? (int)round(($foundCount / max(1, $totalRequired)) * 100) : 0;
$maturity = ($score >= 75) ? 'high' : (($score >= 40) ? 'medium' : 'low');
$confidence = min(90, max(20, $score));

// Best-effort evidence list (top files)
$evidence = [];
try {
    $rows = $db->fetchAll(
        "SELECT file_id, MAX(meta_json) AS meta_json
         FROM tenant_doc_chunks
         WHERE tenant_id = ?
         GROUP BY file_id
         ORDER BY MAX(updated_at) DESC
         LIMIT 12",
        [$tenantId]
    ) ?: [];
    foreach ($rows as $r) {
        $fid = (int)($r['file_id'] ?? 0);
        if ($fid <= 0) continue;
        $file = $db->fetchOne(
            "SELECT id, name, file_path
             FROM files
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$fid, $tenantId]
        );
        if (!$file) continue;
        $evidence[] = [
            'file_id' => $fid,
            'name' => (string)($file['name'] ?? ''),
            'path' => (string)($file['file_path'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    // ignore
}

api_success([
    'supported' => true,
    'storage_available' => true,
    'maturity_suggested' => $maturity,
    'confidence' => $confidence,
    'detected' => $detected,
    'gaps' => $gaps,
    'doc_evidence' => $evidence,
    'planning_adjustments' => [
        'documentation_factor' => ($score >= 70 ? 0.8 : 1.0),
    ],
]);

