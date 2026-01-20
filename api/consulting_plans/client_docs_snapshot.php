<?php
// Consulting Planning: client tenant documents snapshot (IMS/Knowledge) for wizard (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

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

try {
    $clientTenantId = isset($_GET['client_tenant_id']) ? (int)$_GET['client_tenant_id'] : 0;
    if ($clientTenantId <= 0) api_error('client_tenant_id obbligatorio', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    // Knowledge index status (schema drift safe)
    $hasKnowledge = cnx_has_table($db, 'ai_knowledge_sources')
        && cnx_has_table($db, 'ai_knowledge_chunks')
        && cnx_has_table($db, 'ai_knowledge_index_state');

    $knowledge = [
        'storage_available' => $hasKnowledge,
        'configured' => false,
        'source_id' => 0,
        'folder_id' => 0,
        'folder_name' => '',
        'last_indexed_at' => '',
        'last_file_count' => 0,
        'last_error' => '',
        'file_count_indexed' => 0,
        'chunk_count_indexed' => 0,
    ];

    if ($hasKnowledge) {
        $source = $db->fetchOne(
            "SELECT id, folder_id, folder_label
             FROM ai_knowledge_sources
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY id DESC
             LIMIT 1",
            [$clientTenantId]
        );
        if ($source && !empty($source['id'])) {
            $knowledge['configured'] = true;
            $knowledge['source_id'] = (int)$source['id'];
            $knowledge['folder_id'] = (int)($source['folder_id'] ?? 0);
            $knowledge['folder_name'] = (string)($source['folder_label'] ?? '');

            $state = $db->fetchOne(
                "SELECT last_indexed_at, last_file_count, last_error
                 FROM ai_knowledge_index_state
                 WHERE tenant_id = ? AND source_id = ?
                 LIMIT 1",
                [$clientTenantId, (int)$knowledge['source_id']]
            );
            if ($state) {
                $knowledge['last_indexed_at'] = (string)($state['last_indexed_at'] ?? '');
                $knowledge['last_file_count'] = (int)($state['last_file_count'] ?? 0);
                $knowledge['last_error'] = (string)($state['last_error'] ?? '');
            }

            $c = $db->fetchOne(
                "SELECT COUNT(DISTINCT file_id) AS files, COUNT(*) AS chunks
                 FROM ai_knowledge_chunks
                 WHERE tenant_id = ? AND source_id = ?",
                [$clientTenantId, (int)$knowledge['source_id']]
            );
            if ($c) {
                $knowledge['file_count_indexed'] = (int)($c['files'] ?? 0);
                $knowledge['chunk_count_indexed'] = (int)($c['chunks'] ?? 0);
            }
        }
    }

    // IMS folder presence (best-effort, metadata only)
    $imsFolderPresent = false;
    $knowledgeFolderPresent = false;
    try {
        $root = $db->fetchOne(
            "SELECT id
             FROM files
             WHERE tenant_id = ?
               AND folder_id IS NULL
               AND is_folder = 1
               AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1",
            [$clientTenantId]
        );
        $rootId = $root ? (int)($root['id'] ?? 0) : 0;
        if ($rootId > 0) {
            $ims = $db->fetchOne(
                "SELECT id FROM files
                 WHERE tenant_id = ?
                   AND folder_id = ?
                   AND is_folder = 1
                   AND deleted_at IS NULL
                   AND name = 'IMS'
                 LIMIT 1",
                [$clientTenantId, $rootId]
            );
            $kn = $db->fetchOne(
                "SELECT id FROM files
                 WHERE tenant_id = ?
                   AND folder_id = ?
                   AND is_folder = 1
                   AND deleted_at IS NULL
                   AND name = 'Knowledge'
                 LIMIT 1",
                [$clientTenantId, $rootId]
            );
            $imsFolderPresent = !empty($ims['id']);
            $knowledgeFolderPresent = !empty($kn['id']);
        }
    } catch (Throwable $e) {
        $imsFolderPresent = false;
        $knowledgeFolderPresent = false;
    }

    // Freshness (based on knowledge last_indexed_at, if any)
    $freshnessSeconds = null;
    $stale = true;
    if ($knowledge['last_indexed_at'] !== '') {
        $ts = strtotime($knowledge['last_indexed_at']);
        if ($ts !== false) {
            $freshnessSeconds = max(0, time() - (int)$ts);
            $stale = ($freshnessSeconds > 600);
        }
    }

    // Compliance artifacts stats (best-effort, metadata only)
    $complianceStats = null;
    try {
        $hasPrograms = cnx_has_table($db, 'compliance_programs');
        $hasArtifacts = cnx_has_table($db, 'compliance_artifacts');
        if ($hasPrograms && $hasArtifacts) {
            $rows = $db->fetchAll(
                "SELECT p.standard_code,
                        COUNT(a.id) AS artifacts_total,
                        SUM(CASE WHEN a.status IN ('approved','in_review') THEN 1 ELSE 0 END) AS artifacts_done
                 FROM compliance_programs p
                 LEFT JOIN compliance_artifacts a ON a.program_id = p.id
                 WHERE p.tenant_id = ?
                 GROUP BY p.standard_code
                 ORDER BY p.standard_code ASC",
                [$clientTenantId]
            ) ?: [];
            $complianceStats = array_map(static fn($r) => [
                'standard_code' => (string)($r['standard_code'] ?? ''),
                'artifacts_total' => (int)($r['artifacts_total'] ?? 0),
                'artifacts_done' => (int)($r['artifacts_done'] ?? 0),
            ], $rows);
        }
    } catch (Throwable $e) {
        $complianceStats = null;
    }

    api_success([
        'client_tenant_id' => $clientTenantId,
        'knowledge' => $knowledge,
        'ims_folder_present' => $imsFolderPresent,
        'knowledge_folder_present' => $knowledgeFolderPresent,
        'freshness_seconds' => $freshnessSeconds,
        'stale' => $stale,
        'compliance_artifacts_stats' => $complianceStats,
    ]);
} catch (Throwable $e) {
    $errId = 'cds_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CLIENT_DOCS_SNAPSHOT][{$errId}] " . $e->getMessage());
    api_error('Errore snapshot documenti cliente', 500, ['error_id' => $errId]);
}

