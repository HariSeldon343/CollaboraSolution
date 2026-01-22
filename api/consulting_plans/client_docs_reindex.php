<?php
// Consulting Planning: trigger (best-effort) delta reindex for client tenant docs (IMS/Knowledge)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/ai/knowledge_indexer.php';

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

/**
 * Best-effort resolve knowledge folder under tenant root.
 *
 * @return array{ok:bool,folder_id?:int,folder_name?:string,error?:string}
 */
function cnx_resolve_knowledge_folder(Database $db, int $tenantId): array {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0) return ['ok' => false, 'error' => 'tenant_id non valido'];

    $root = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND folder_id IS NULL
           AND is_folder = 1
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT 1",
        [$tenantId]
    );
    $rootId = $root ? (int)($root['id'] ?? 0) : 0;
    if ($rootId <= 0) return ['ok' => false, 'error' => 'Cartella root tenant non trovata'];

    // Prefer IMS for Consulting/Planning (fallback to Knowledge if IMS is missing).
    foreach (['IMS', 'Knowledge'] as $name) {
        $row = $db->fetchOne(
            "SELECT id, name
             FROM files
             WHERE tenant_id = ?
               AND folder_id = ?
               AND is_folder = 1
               AND deleted_at IS NULL
               AND name = ?
             LIMIT 1",
            [$tenantId, $rootId, $name]
        );
        if ($row && !empty($row['id'])) {
            return ['ok' => true, 'folder_id' => (int)$row['id'], 'folder_name' => (string)($row['name'] ?? $name)];
        }
    }
    return ['ok' => false, 'error' => 'Cartella Knowledge/IMS non trovata (crea /Knowledge o /IMS nel File Manager)'];
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('client_tenant_id obbligatorio', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);
    $forceRaw = $payload['force'] ?? null;
    $force = ($forceRaw === true || $forceRaw === 1 || $forceRaw === '1' || $forceRaw === 'true');

    // Storage check (schema drift safe)
    $hasKnowledge = cnx_has_table($db, 'ai_knowledge_sources')
        && cnx_has_table($db, 'ai_knowledge_chunks')
        && cnx_has_table($db, 'ai_knowledge_index_state')
        && cnx_has_table($db, 'files');
    if (!$hasKnowledge) {
        api_success([
            'supported' => false,
            'status' => 'not_supported',
            'reason' => 'missing_tables',
            'migration' => 'database/migrations/48_ai_knowledge_index.sql',
        ]);
    }

    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

    // Resolve active source (or create it)
    $source = $db->fetchOne(
        "SELECT id, folder_id, folder_label
         FROM ai_knowledge_sources
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY id DESC
         LIMIT 1",
        [$clientTenantId]
    );
    $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
    $folderId = $source ? (int)($source['folder_id'] ?? 0) : 0;
    $folderName = $source ? (string)($source['folder_label'] ?? '') : '';

    if ($folderId <= 0) {
        $folder = cnx_resolve_knowledge_folder($db, $clientTenantId);
        if (!$folder['ok']) {
            api_success([
                'supported' => false,
                'status' => 'not_supported',
                'reason' => (string)($folder['error'] ?? 'folder_missing'),
            ]);
        }
        $folderId = (int)($folder['folder_id'] ?? 0);
        $folderName = (string)($folder['folder_name'] ?? '');
    }

    if ($sourceId > 0) {
        // Ensure folder matches resolved folder (best-effort)
        if ($folderId > 0 && (int)($source['folder_id'] ?? 0) !== $folderId) {
            try {
                $db->update('ai_knowledge_sources', [
                    'folder_id' => $folderId,
                    'folder_label' => $folderName,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $sourceId]);
            } catch (Throwable $e) {}
        }
    } else {
        $sourceId = (int)$db->insert('ai_knowledge_sources', [
            'tenant_id' => $clientTenantId,
            'folder_id' => $folderId,
            'folder_label' => $folderName,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($sourceId <= 0) api_error('Impossibile creare source knowledge', 500);
    }

    // Throttle: if already indexed in the last 10 minutes, skip unless forced.
    $freshnessSeconds = null;
    if (!$force && $sourceId > 0) {
        try {
            $state = $db->fetchOne(
                "SELECT last_indexed_at
                 FROM ai_knowledge_index_state
                 WHERE tenant_id = ? AND source_id = ?
                 LIMIT 1",
                [$clientTenantId, $sourceId]
            );
            $last = $state ? (string)($state['last_indexed_at'] ?? '') : '';
            if ($last !== '') {
                $ts = strtotime($last);
                if ($ts !== false) {
                    $freshnessSeconds = max(0, time() - (int)$ts);
                    if ($freshnessSeconds <= 600) {
                        api_success([
                            'supported' => true,
                            'status' => 'fresh_skip',
                            'skipped' => true,
                            'client_tenant_id' => $clientTenantId,
                            'source_id' => $sourceId,
                            'folder_id' => $folderId,
                            'folder_name' => $folderName,
                            'last_indexed_at' => $last,
                            'freshness_seconds' => $freshnessSeconds,
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore, continue
        }
    }

    // Run delta indexing with strict caps (fast/best-effort)
    $delta = cnx_ai_index_folder_delta($db, $clientTenantId, $sourceId, $folderId, [
        'max_indexed_files' => 14,
        'max_checked_files' => 800,
        'max_seconds' => 7,
    ]);

    $locked = !empty($delta['locked']);
    if (!$locked) {
        // Update index state best-effort
        try {
            $state = $db->fetchOne(
                "SELECT id
                 FROM ai_knowledge_index_state
                 WHERE tenant_id = ? AND source_id = ?
                 LIMIT 1",
                [$clientTenantId, $sourceId]
            );
            $data = [
                'tenant_id' => $clientTenantId,
                'source_id' => $sourceId,
                'last_indexed_at' => date('Y-m-d H:i:s'),
                'last_file_count' => (int)($delta['scanned_files'] ?? 0),
                'last_error' => null,
                'updated_by' => ($actorUserId > 0 ? $actorUserId : null),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($state && !empty($state['id'])) {
                $db->update('ai_knowledge_index_state', $data, ['id' => (int)$state['id']]);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $db->insert('ai_knowledge_index_state', $data);
            }
        } catch (Throwable $e) {
            // non-blocking
        }
    }

    api_success([
        'supported' => true,
        'status' => $locked ? 'locked' : 'started',
        'client_tenant_id' => $clientTenantId,
        'source_id' => $sourceId,
        'folder_id' => $folderId,
        'folder_name' => $folderName,
        'force' => $force,
        'delta' => $delta,
    ]);
} catch (Throwable $e) {
    $errId = 'cdr_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CLIENT_DOCS_REINDEX][{$errId}] " . $e->getMessage());
    api_error('Errore reindicizzazione documenti cliente', 500, ['error_id' => $errId]);
}

