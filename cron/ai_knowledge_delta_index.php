<?php
/**
 * AI Knowledge Delta Index Runner (Cron)
 *
 * Keeps tenant knowledge up-to-date by indexing ONLY new/changed files under the configured knowledge folder.
 *
 * Usage (Windows Task Scheduler / cron):
 *   php cron/ai_knowledge_delta_index.php
 *
 * Options:
 *   --max-tenants=200
 *   --max-indexed-files=12
 *   --max-checked-files=500
 *   --max-seconds=8
 *   --min-interval-seconds=600   (skip tenants indexed more recently)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai/knowledge_indexer.php';

// Basic safety: ensure we run from CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

date_default_timezone_set(date_default_timezone_get());

/**
 * Read CLI option --name=value
 */
function cnx_cli_opt(string $name, $default = null) {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach (($argv ?: []) as $a) {
        if (strpos($a, $prefix) === 0) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
}

function cnx_has_table(Database $db, string $tableName): bool {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$tableName]
        );
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Storage check (schema drift safe)
    foreach (['ai_knowledge_sources', 'ai_knowledge_chunks', 'ai_knowledge_index_state', 'files'] as $t) {
        if (!cnx_has_table($db, $t)) {
            echo "AI Knowledge delta indexing skipped: missing table {$t}. Apply migration 48.\n";
            exit(0);
        }
    }

    $maxTenants = (int)cnx_cli_opt('max-tenants', 200);
    $maxTenants = max(1, min(5000, $maxTenants));

    $opts = [
        'max_indexed_files' => (int)cnx_cli_opt('max-indexed-files', 12),
        'max_checked_files' => (int)cnx_cli_opt('max-checked-files', 500),
        'max_seconds' => (int)cnx_cli_opt('max-seconds', 8),
    ];
    $minInterval = (int)cnx_cli_opt('min-interval-seconds', 600);
    $minInterval = max(0, min(86400, $minInterval));

    $rows = $db->fetchAll(
        "SELECT tenant_id, id AS source_id, folder_id, folder_label
         FROM ai_knowledge_sources
         WHERE is_active = 1
         ORDER BY tenant_id ASC, id DESC"
    ) ?: [];

    // Pick latest active source per tenant_id
    $byTenant = [];
    foreach ($rows as $r) {
        $tid = (int)($r['tenant_id'] ?? 0);
        if ($tid <= 0) continue;
        if (isset($byTenant[$tid])) continue;
        $byTenant[$tid] = [
            'tenant_id' => $tid,
            'source_id' => (int)($r['source_id'] ?? 0),
            'folder_id' => (int)($r['folder_id'] ?? 0),
            'folder_label' => (string)($r['folder_label'] ?? ''),
        ];
        if (count($byTenant) >= $maxTenants) break;
    }

    $totalTenants = 0;
    $totalIndexedFiles = 0;
    $totalIndexedChunks = 0;
    $withWork = 0;

    foreach ($byTenant as $tid => $s) {
        $totalTenants++;
        $sourceId = (int)($s['source_id'] ?? 0);
        $folderId = (int)($s['folder_id'] ?? 0);
        if ($sourceId <= 0 || $folderId <= 0) continue;

        try {
            // Throttle: skip if indexed recently (avoids redundant scans when scheduled frequently).
            if ($minInterval > 0) {
                try {
                    $st = $db->fetchOne(
                        "SELECT last_indexed_at
                         FROM ai_knowledge_index_state
                         WHERE tenant_id = ? AND source_id = ?
                         LIMIT 1",
                        [$tid, $sourceId]
                    );
                    $last = $st ? (string)($st['last_indexed_at'] ?? '') : '';
                    if ($last !== '') {
                        $ts = strtotime($last);
                        if ($ts !== false && (time() - (int)$ts) < $minInterval) {
                            continue;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore throttle errors
                }
            }

            $res = cnx_ai_index_folder_delta($db, $tid, $sourceId, $folderId, $opts);
            if (!empty($res['indexed_files'])) $withWork++;
            $totalIndexedFiles += (int)($res['indexed_files'] ?? 0);
            $totalIndexedChunks += (int)($res['indexed_chunks'] ?? 0);

            // Update index state (best-effort) — updated_by NULL (cron)
            try {
                $state = $db->fetchOne(
                    "SELECT id FROM ai_knowledge_index_state WHERE tenant_id = ? AND source_id = ? LIMIT 1",
                    [$tid, $sourceId]
                );
                $data = [
                    'tenant_id' => $tid,
                    'source_id' => $sourceId,
                    'last_indexed_at' => date('Y-m-d H:i:s'),
                    'last_file_count' => (int)($res['scanned_files'] ?? 0),
                    'last_error' => null,
                    'updated_by' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($state && !empty($state['id'])) {
                    $db->update('ai_knowledge_index_state', $data, ['id' => (int)$state['id']]);
                } else {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $db->insert('ai_knowledge_index_state', $data);
                }
            } catch (Throwable $e) {
                error_log("[CRON][ai_knowledge_delta_index] tenant={$tid} state update error: " . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log("[CRON][ai_knowledge_delta_index] tenant={$tid} error: " . $e->getMessage());
            // Continue other tenants
        }
    }

    echo "AI Knowledge delta indexing processed.\n";
    echo "Tenants: {$totalTenants}\n";
    echo "Tenants with updates: {$withWork}\n";
    echo "Indexed files: {$totalIndexedFiles}\n";
    echo "Indexed chunks: {$totalIndexedChunks}\n";
    exit(0);
} catch (Throwable $e) {
    error_log("[CRON][ai_knowledge_delta_index] fatal: " . $e->getMessage());
    fwrite(STDERR, "Fatal: " . $e->getMessage() . "\n");
    exit(2);
}

