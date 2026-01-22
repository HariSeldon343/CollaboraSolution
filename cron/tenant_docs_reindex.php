<?php
/**
 * Cron: Tenant Docs Reindex (multi-tenant)
 *
 * Usage:
 *   php cron/tenant_docs_reindex.php [--min-interval-seconds=600] [--max-files=80]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai/tenant_docs_indexer.php';

$minInterval = 600;
$maxFiles = 80;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--min-interval-seconds=')) {
        $minInterval = max(60, (int)substr($arg, strlen('--min-interval-seconds=')));
    } elseif (str_starts_with($arg, '--max-files=')) {
        $maxFiles = max(10, min(400, (int)substr($arg, strlen('--max-files='))));
    }
}

$db = Database::getInstance();

// Storage check
$chk = $db->fetchOne("SHOW TABLES LIKE 'tenant_doc_chunks'");
if (!$chk) {
    echo "Storage not available: apply migration 67\n";
    exit(1);
}

$tenants = $db->fetchAll(
    "SELECT id
     FROM tenants
     WHERE deleted_at IS NULL
       AND status = 'active'
     ORDER BY id ASC"
) ?: [];

foreach ($tenants as $t) {
    $tenantId = (int)($t['id'] ?? 0);
    if ($tenantId <= 0) continue;

    $lockName = 'tenant_docs_index_' . $tenantId;
    if (!cnx_tenant_docs_try_lock($db, $lockName, 0)) {
        continue;
    }

    try {
        $lastIndexed = $db->fetchOne(
            "SELECT MAX(last_indexed_at) AS last_indexed_at
             FROM tenant_doc_files_state
             WHERE tenant_id = ?",
            [$tenantId]
        );
        $lastIndexedAt = (string)($lastIndexed['last_indexed_at'] ?? '');
        $lastTs = $lastIndexedAt ? @strtotime($lastIndexedAt) : 0;
        if ($lastTs && (time() - $lastTs) < $minInterval) {
            continue;
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
            if (is_array($dec)) $allowedPaths = array_values(array_filter(array_map('strval', $dec)));
        }
        $excludePatterns = [];
        if ($settings && !empty($settings['exclude_patterns_json'])) {
            $dec = json_decode((string)$settings['exclude_patterns_json'], true);
            if (is_array($dec)) $excludePatterns = array_values(array_filter(array_map('strval', $dec)));
        }

        $runId = (int)$db->insert('tenant_doc_index_runs', [
            'tenant_id' => $tenantId,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'stats_json' => null,
        ]);

        $stats = [
            'scanned_files' => 0,
            'indexed_files' => 0,
            'skipped_files' => 0,
            'unsupported_files' => 0,
            'error_files' => 0,
            'chunks_total' => 0,
        ];

        $files = cnx_tenant_docs_list_files($db, $tenantId, $allowedPaths);
        $files = array_slice($files, 0, $maxFiles);
        foreach ($files as $f) {
            $stats['scanned_files']++;
            $fileId = (int)($f['id'] ?? 0);
            $name = (string)($f['name'] ?? '');
            $filePath = (string)($f['file_path'] ?? '');
            $updatedAt = (string)($f['updated_at'] ?? '');
            $size = (int)($f['file_size'] ?? 0);
            $logicalPath = cnx_tenant_docs_build_logical_path($db, $tenantId, $fileId);
            $hay = strtolower(trim($name . ' ' . $logicalPath));
            $excluded = false;
            foreach ($excludePatterns as $pat) {
                $pat = trim((string)$pat);
                if ($pat === '') continue;
                if (@preg_match('/' . $pat . '/i', $hay)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                $stats['skipped_files']++;
                continue;
            }

            $absPath = cnx_tenant_docs_resolve_abs_path($tenantId, $filePath);
            if ($absPath === '' || !is_file($absPath)) {
                $stats['error_files']++;
                $db->insert('tenant_doc_files_state', [
                    'tenant_id' => $tenantId,
                    'file_id' => $fileId,
                    'last_hash' => null,
                    'last_indexed_at' => date('Y-m-d H:i:s'),
                    'status' => 'error',
                    'error_id' => 'file_missing',
                ]);
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $warnings = [];
            $text = cnx_tenant_docs_extract_text($absPath, $ext, $warnings);
            if ($text === '') {
                $stats['unsupported_files']++;
                $db->insert('tenant_doc_files_state', [
                    'tenant_id' => $tenantId,
                    'file_id' => $fileId,
                    'last_hash' => null,
                    'last_indexed_at' => date('Y-m-d H:i:s'),
                    'status' => 'unsupported',
                    'error_id' => null,
                ]);
                continue;
            }
            $text = cnx_tenant_docs_redact_text($text);
            $chunks = cnx_tenant_docs_chunk_text($text, 1200, 200);
            if (empty($chunks)) {
                $stats['skipped_files']++;
                continue;
            }
            $hash = cnx_tenant_docs_compute_file_hash($absPath, $fileId, $updatedAt . '_' . $size);
            $meta = [
                'path' => $logicalPath,
                'name' => $name,
                'mime' => (string)($f['mime_type'] ?? ''),
                'warnings' => $warnings,
            ];
            $res = cnx_tenant_docs_upsert_chunks($db, $tenantId, $fileId, $hash, $chunks, $meta);
            if (!empty($res['updated'])) {
                $stats['indexed_files']++;
                $stats['chunks_total'] += (int)($res['chunk_count'] ?? 0);
            } else {
                $stats['skipped_files']++;
            }
        }

        $db->update('tenant_doc_index_runs', [
            'finished_at' => date('Y-m-d H:i:s'),
            'status' => 'ok',
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id' => $runId]);
    } catch (Throwable $e) {
        $errId = 'doc_idx_' . substr(bin2hex(random_bytes(6)), 0, 12);
        error_log("[TENANT_DOCS_REINDEX][{$errId}] " . $e->getMessage());
        $db->update('tenant_doc_index_runs', [
            'finished_at' => date('Y-m-d H:i:s'),
            'status' => 'error',
            'error_id' => $errId,
        ], ['tenant_id' => $tenantId]);
    } finally {
        cnx_tenant_docs_release_lock($db, $lockName);
    }
}

echo "OK\n";

