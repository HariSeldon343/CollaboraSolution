<?php
// Consulting Planning: analyze client tenant documents (IMS/Knowledge) with cached AI profile
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

function cnx_has_table(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_now(): string {
    return date('Y-m-d H:i:s');
}

function cnx_safe_trunc(string $s, int $maxLen): string {
    $s = preg_replace("/\\r\\n?/", "\n", $s) ?: $s;
    $s = trim($s);
    if ($s === '') return '';
    if (mb_strlen($s, 'UTF-8') <= $maxLen) return $s;
    return trim(mb_substr($s, 0, $maxLen, 'UTF-8'));
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('client_tenant_id obbligatorio', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    // Optional: allow disabling analysis via config constant (keeps snapshot usable)
    if (defined('CNX_DISABLE_TENANT_DOC_ANALYSIS') && CNX_DISABLE_TENANT_DOC_ANALYSIS) {
        api_error('Analisi documenti disabilitata (config)', 503, ['ai_disabled' => true]);
    }

    $scope = 'IMS_PLANNING';
    $standardCodes = [];
    if (isset($payload['standard_codes']) && is_array($payload['standard_codes'])) {
        foreach ($payload['standard_codes'] as $s) {
            $v = strtoupper(trim((string)$s));
            if ($v === '' || strlen($v) > 32) continue;
            if (!preg_match('/^[A-Z0-9]+$/', $v)) continue;
            $standardCodes[] = $v;
        }
        $standardCodes = array_values(array_unique($standardCodes));
    }
    if (empty($standardCodes)) $standardCodes = ['ISO9001'];

    $interventionType = strtolower(trim((string)($payload['intervention_type'] ?? '')));
    if ($interventionType === '') api_error('intervention_type obbligatorio', 400);

    // Storage checks
    $hasProfiles = cnx_has_table($db, 'ai_tenant_doc_profiles');
    if (!$hasProfiles) {
        api_success([
            'supported' => false,
            'storage_available' => false,
            'migration' => 'database/migrations/63_ai_tenant_doc_profiles.sql',
        ]);
    }

    $hasKnowledge = cnx_has_table($db, 'ai_knowledge_sources')
        && cnx_has_table($db, 'ai_knowledge_chunks')
        && cnx_has_table($db, 'ai_knowledge_index_state');

    // Basic knowledge status (used for fingerprint)
    $sourceId = 0;
    $lastIndexedAt = '';
    $indexedFiles = 0;
    $indexedChunks = 0;
    if ($hasKnowledge) {
        $source = $db->fetchOne(
            "SELECT id
             FROM ai_knowledge_sources
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY id DESC
             LIMIT 1",
            [$clientTenantId]
        );
        $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
        if ($sourceId > 0) {
            $state = $db->fetchOne(
                "SELECT last_indexed_at
                 FROM ai_knowledge_index_state
                 WHERE tenant_id = ? AND source_id = ?
                 LIMIT 1",
                [$clientTenantId, $sourceId]
            );
            $lastIndexedAt = $state ? (string)($state['last_indexed_at'] ?? '') : '';

            $c = $db->fetchOne(
                "SELECT COUNT(DISTINCT file_id) AS files, COUNT(*) AS chunks
                 FROM ai_knowledge_chunks
                 WHERE tenant_id = ? AND source_id = ?",
                [$clientTenantId, $sourceId]
            );
            if ($c) {
                $indexedFiles = (int)($c['files'] ?? 0);
                $indexedChunks = (int)($c['chunks'] ?? 0);
            }
        }
    }

    // Fingerprint (stable enough for cache reuse)
    $fingerprint = sha1(json_encode([
        'tenant_id' => $clientTenantId,
        'scope' => $scope,
        'source_id' => $sourceId,
        'last_indexed_at' => $lastIndexedAt,
        'indexed_files' => $indexedFiles,
        'indexed_chunks' => $indexedChunks,
        'standard_codes' => $standardCodes,
        'intervention_type' => $interventionType,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $now = cnx_now();
    $row = $db->fetchOne(
        "SELECT *
         FROM ai_tenant_doc_profiles
         WHERE tenant_id = ? AND scope = ?
         LIMIT 1",
        [$clientTenantId, $scope]
    );

    $hasCached = false;
    if ($row) {
        $expiresAt = (string)($row['expires_at'] ?? '');
        $cachedFp = (string)($row['source_fingerprint'] ?? '');
        $payloadJson = (string)($row['payload_json'] ?? '');
        $notExpired = ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) > time());
        if ($notExpired && $cachedFp !== '' && $cachedFp === $fingerprint && trim($payloadJson) !== '') {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) {
                api_success([
                    'supported' => true,
                    'cached' => true,
                    'client_tenant_id' => $clientTenantId,
                    'scope' => $scope,
                    'doc_profile_id' => (int)($row['id'] ?? 0),
                    'source_fingerprint' => $fingerprint,
                    'computed_at' => (string)($row['computed_at'] ?? ''),
                    'expires_at' => $expiresAt,
                    'payload' => $decoded,
                ]);
            }
        }
    }

    // Build signals from knowledge index (metadata + minimal excerpts, NOT returned to UI)
    $filesMeta = [];
    $snippets = [];
    $warnings = [];
    if ($hasKnowledge && $sourceId > 0 && $indexedFiles > 0) {
        $filesMeta = $db->fetchAll(
            "SELECT file_id,
                    MAX(file_name) AS file_name,
                    MAX(logical_path) AS logical_path,
                    MAX(updated_at) AS indexed_at,
                    COUNT(*) AS chunks
             FROM ai_knowledge_chunks
             WHERE tenant_id = ? AND source_id = ?
             GROUP BY file_id
             ORDER BY MAX(updated_at) DESC, file_id DESC
             LIMIT 50",
            [$clientTenantId, $sourceId]
        ) ?: [];

        // Pick candidate docs for tiny excerpts (best-effort, avoid long content)
        $candIds = [];
        foreach ($filesMeta as $r) {
            $name = strtolower((string)($r['file_name'] ?? ''));
            if ($name === '') continue;
            if (preg_match('/manual|manuale|proced|policy|audit|riesame|review|gap|process|registro|record|modul|form|kpi/i', $name)) {
                $candIds[] = (int)($r['file_id'] ?? 0);
            }
            if (count($candIds) >= 12) break;
        }
        if (empty($candIds)) {
            foreach ($filesMeta as $r) {
                $candIds[] = (int)($r['file_id'] ?? 0);
                if (count($candIds) >= 8) break;
            }
        }
        $candIds = array_values(array_unique(array_filter($candIds, static fn($v) => (int)$v > 0)));
        if (!empty($candIds)) {
            $in = implode(',', array_fill(0, count($candIds), '?'));
            $rows = $db->fetchAll(
                "SELECT file_id, chunk_text
                 FROM ai_knowledge_chunks
                 WHERE tenant_id = ?
                   AND source_id = ?
                   AND file_id IN ($in)
                   AND chunk_index = 0
                 ORDER BY file_id ASC",
                array_merge([$clientTenantId, $sourceId], $candIds)
            ) ?: [];
            $budget = 6500; // max chars total for AI context (best-effort)
            foreach ($rows as $rr) {
                $fid = (int)($rr['file_id'] ?? 0);
                if ($fid <= 0) continue;
                $txt = cnx_safe_trunc((string)($rr['chunk_text'] ?? ''), 700);
                if ($txt === '') continue;
                if ($budget <= 0) break;
                $txt = mb_substr($txt, 0, min($budget, 700), 'UTF-8');
                $budget -= mb_strlen($txt, 'UTF-8');
                $snippets[] = [
                    'file_id' => $fid,
                    'excerpt' => $txt,
                ];
            }
        }
    } else {
        $warnings[] = 'Knowledge index non disponibile o vuoto: analisi basata solo su metadati minimi.';
    }

    // Call AI with strict schema (store only structured output)
    $schema = [
        'name' => 'tenant_doc_profile_v1',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'maturity_suggested' => [
                    'type' => 'string',
                    'enum' => ['none','partial','structured_non_certified','already_certified','integrated_existing'],
                ],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'detected' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'manual' => ['type' => 'boolean'],
                        'procedures_count_est' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                        'records_count_est' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                        'evidence_of_certification' => ['type' => 'boolean'],
                        'key_documents' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['manual','procedures_count_est','records_count_est','evidence_of_certification','key_documents'],
                ],
                'gaps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'area' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['low','medium','high']],
                        ],
                        'required' => ['area','detail','severity'],
                    ],
                ],
                'planning_adjustments' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'documentation_factor' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                        'focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['documentation_factor','focus','notes'],
                ],
            ],
            'required' => ['maturity_suggested','confidence','detected','gaps','planning_adjustments'],
        ],
    ];

    $system = [
        'role' => 'system',
        'content' => "Analizzi SOLO metadati ed estratti minimi di documenti aziendali per supportare la pianificazione. Regole: NON copiare/incollare testo ISO/UNI; non citare frasi della norma; non riportare estratti testuali nel risultato. Fornisci SOLO JSON conforme allo schema, con valutazioni best-effort e motivazioni sintetiche.",
    ];
    $user = [
        'role' => 'user',
        'content' => json_encode([
            'client_tenant_id' => $clientTenantId,
            'standard_codes' => $standardCodes,
            'intervention_type' => $interventionType,
            'knowledge' => [
                'source_id' => $sourceId,
                'last_indexed_at' => ($lastIndexedAt !== '' ? $lastIndexedAt : null),
                'indexed_files' => $indexedFiles,
                'indexed_chunks' => $indexedChunks,
                'files' => array_map(static fn($r) => [
                    'file_name' => (string)($r['file_name'] ?? ''),
                    'logical_path' => (string)($r['logical_path'] ?? ''),
                    'indexed_at' => (string)($r['indexed_at'] ?? ''),
                    'chunks' => (int)($r['chunks'] ?? 0),
                ], $filesMeta),
                // Excerpts are provided ONLY for analysis, will not be stored nor returned.
                'snippets' => array_map(static fn($s) => [
                    'file_id' => (int)($s['file_id'] ?? 0),
                    'excerpt' => (string)($s['excerpt'] ?? ''),
                ], $snippets),
                'warnings' => $warnings,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $ai = cnx_openai_chat_json([$system, $user], $schema, [
        'temperature' => 0.2,
        'max_tokens' => 900,
        'timeout_seconds' => 25,
        'max_retries' => 1,
    ]);
    if (empty($ai['ok']) || !isset($ai['data']) || !is_array($ai['data'])) {
        $err = trim((string)($ai['error'] ?? 'AI non disponibile'));
        $errId = 'cda_' . substr(bin2hex(random_bytes(6)), 0, 12);
        error_log("[CONSULTING_CLIENT_DOCS_ANALYZE][{$errId}] " . $err);

        // Persist last error (best-effort upsert)
        try {
            $db->query(
                "INSERT INTO ai_tenant_doc_profiles (tenant_id, scope, computed_at, expires_at, source_fingerprint, payload_json, created_by_user_id, error_last, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE computed_at = VALUES(computed_at), expires_at = VALUES(expires_at), source_fingerprint = VALUES(source_fingerprint), error_last = VALUES(error_last), updated_at = NOW()",
                [$clientTenantId, $scope, $now, date('Y-m-d H:i:s', time() + 3600), $fingerprint, (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0), $err]
            );
        } catch (Throwable $e) {}

        api_error('Analisi AI non disponibile', 503, ['error_id' => $errId]);
    }

    $docProfile = $ai['data'];
    // Clamp/normalize best-effort
    $docProfile['confidence'] = max(0, min(100, (int)($docProfile['confidence'] ?? 0)));
    if (!isset($docProfile['planning_adjustments']['documentation_factor'])) {
        $docProfile['planning_adjustments']['documentation_factor'] = 1.0;
    }
    $docProfile['planning_adjustments']['documentation_factor'] = max(0.0, min(1.0, (float)$docProfile['planning_adjustments']['documentation_factor']));

    $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24h
    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

    // Upsert cache row (unique tenant_id+scope)
    $db->query(
        "INSERT INTO ai_tenant_doc_profiles (tenant_id, scope, computed_at, expires_at, source_fingerprint, payload_json, created_by_user_id, error_last, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE computed_at = VALUES(computed_at), expires_at = VALUES(expires_at), source_fingerprint = VALUES(source_fingerprint), payload_json = VALUES(payload_json), created_by_user_id = VALUES(created_by_user_id), error_last = NULL, updated_at = NOW()",
        [
            $clientTenantId,
            $scope,
            $now,
            $expiresAt,
            $fingerprint,
            json_encode($docProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ($actorUserId > 0 ? $actorUserId : null),
        ]
    );

    $saved = $db->fetchOne(
        "SELECT id, computed_at, expires_at
         FROM ai_tenant_doc_profiles
         WHERE tenant_id = ? AND scope = ?
         LIMIT 1",
        [$clientTenantId, $scope]
    );

    api_success([
        'supported' => true,
        'cached' => false,
        'client_tenant_id' => $clientTenantId,
        'scope' => $scope,
        'doc_profile_id' => (int)($saved['id'] ?? 0),
        'source_fingerprint' => $fingerprint,
        'computed_at' => (string)($saved['computed_at'] ?? $now),
        'expires_at' => (string)($saved['expires_at'] ?? $expiresAt),
        'payload' => $docProfile,
    ]);
} catch (Throwable $e) {
    $errId = 'cda_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CLIENT_DOCS_ANALYZE][{$errId}] " . $e->getMessage());
    api_error('Errore analisi documenti cliente', 500, ['error_id' => $errId]);
}

