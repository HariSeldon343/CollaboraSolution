<?php
declare(strict_types=1);

require_once __DIR__ . '/../file_helper.php';

/**
 * Best-effort knowledge indexer:
 * - Extract text from tenant documents under a configured folder
 * - Chunk text and store into ai_knowledge_chunks
 */

/**
 * Acquire a per-tenant/source lock to avoid concurrent delta indexing on the same folder.
 *
 * Uses MySQL GET_LOCK (connection-scoped). If GET_LOCK is unavailable (or fails), we proceed without lock.
 */
function cnx_ai_try_lock(Database $db, string $lockName, int $timeoutSeconds = 0): bool {
    $lockName = substr(trim($lockName), 0, 64);
    if ($lockName === '') return true;
    try {
        $row = $db->fetchOne("SELECT GET_LOCK(?, ?) AS ok", [$lockName, max(0, (int)$timeoutSeconds)]);
        return ((int)($row['ok'] ?? 0) === 1);
    } catch (Throwable $e) {
        // Drift-safe fallback: do not block indexing if lock functions are unavailable.
        return true;
    }
}

/**
 * Release the MySQL named lock (best-effort).
 */
function cnx_ai_release_lock(Database $db, string $lockName): void {
    $lockName = substr(trim($lockName), 0, 64);
    if ($lockName === '') return;
    try {
        $db->fetchOne("SELECT RELEASE_LOCK(?) AS ok", [$lockName]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Compute logical path for a file (breadcrumb by folder names).
 */
function cnx_ai_build_logical_path(Database $db, int $tenantId, int $fileId): string {
    try {
        $row = $db->fetchOne(
            "SELECT id, name, folder_id, is_folder
             FROM files
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$fileId, $tenantId]
        );
        if (!$row) return '';
        $parts = [(string)($row['name'] ?? '')];
        $parent = (int)($row['folder_id'] ?? 0);
        $guard = 0;
        while ($parent > 0 && $guard < 50) {
            $guard++;
            $p = $db->fetchOne(
                "SELECT id, name, folder_id
                 FROM files
                 WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL
                 LIMIT 1",
                [$parent, $tenantId]
            );
            if (!$p) break;
            array_unshift($parts, (string)($p['name'] ?? ''));
            $parent = (int)($p['folder_id'] ?? 0);
        }
        $parts = array_values(array_filter(array_map('trim', $parts)));
        return '/' . implode('/', $parts);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * @return string
 */
function cnx_ai_compute_file_hash(string $absPath, int $fileId, string $updatedAt): string {
    if (is_file($absPath)) {
        $h = @md5_file($absPath);
        if (is_string($h) && $h !== '') return $h;
    }
    return md5($fileId . '_' . $updatedAt);
}

/**
 * Extract text from DOCX (best-effort).
 */
function cnx_ai_extract_docx_text(string $absPath, array &$warnings): string {
    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) {
        $warnings[] = 'DOCX: impossibile aprire zip';
        return '';
    }
    $xml = (string)($zip->getFromName('word/document.xml') ?: '');
    $zip->close();
    if ($xml === '') return '';

    // Keep paragraph boundaries as newlines (best-effort)
    $xml = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);
    $out = [];
    if (preg_match_all('/<w:t[^>]*>(.*?)<\\/w:t>/su', $xml, $m)) {
        foreach ($m[1] as $t) {
            $out[] = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $text = implode(' ', $out);
    $text = preg_replace('/\\s+/', ' ', $text) ?: $text;
    return trim($text);
}

/**
 * Extract text from XLSX (best-effort).
 */
function cnx_ai_extract_xlsx_text(string $absPath, array &$warnings): string {
    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) {
        $warnings[] = 'XLSX: impossibile aprire zip';
        return '';
    }
    $texts = [];

    // shared strings
    $ss = (string)($zip->getFromName('xl/sharedStrings.xml') ?: '');
    if ($ss !== '' && preg_match_all('/<t[^>]*>(.*?)<\\/t>/su', $ss, $m)) {
        foreach ($m[1] as $t) {
            $texts[] = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }

    // worksheets (inline strings)
    for ($i = 1; $i <= 50; $i++) {
        $sheet = (string)($zip->getFromName("xl/worksheets/sheet{$i}.xml") ?: '');
        if ($sheet === '') continue;
        if (preg_match_all('/<t[^>]*>(.*?)<\\/t>/su', $sheet, $m2)) {
            foreach ($m2[1] as $t) {
                $texts[] = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    $zip->close();

    $text = implode(' ', $texts);
    $text = preg_replace('/\\s+/', ' ', $text) ?: $text;
    return trim($text);
}

/**
 * Extract text for supported file types.
 */
function cnx_ai_extract_text_for_file(string $absPath, string $ext, array &$warnings): string {
    $ext = strtolower(trim($ext));
    if ($ext === 'txt' || $ext === 'md' || $ext === 'json' || $ext === 'xml') {
        $raw = @file_get_contents($absPath);
        if (!is_string($raw)) return '';
        $raw = preg_replace('/\\r\\n?/', "\n", $raw) ?: $raw;
        // Limit to keep DB sane (best-effort)
        if (mb_strlen($raw, 'UTF-8') > 200000) {
            $warnings[] = 'Testo troncato (max 200k char)';
            $raw = mb_substr($raw, 0, 200000, 'UTF-8');
        }
        return trim($raw);
    }
    if ($ext === 'docx') return cnx_ai_extract_docx_text($absPath, $warnings);
    if ($ext === 'xlsx') return cnx_ai_extract_xlsx_text($absPath, $warnings);
    return '';
}

/**
 * Chunk text into overlapping pieces for retrieval.
 *
 * @return string[]
 */
function cnx_ai_chunk_text(string $text, int $chunkSize = 1200, int $overlap = 200): array {
    $text = trim($text);
    if ($text === '') return [];
    $chunkSize = max(200, $chunkSize);
    $overlap = max(0, min($overlap, (int)floor($chunkSize / 2)));

    $chunks = [];
    $len = mb_strlen($text, 'UTF-8');
    $pos = 0;
    while ($pos < $len && count($chunks) < 500) { // hard cap
        $end = min($len, $pos + $chunkSize);
        $piece = mb_substr($text, $pos, $end - $pos, 'UTF-8');
        $piece = trim($piece);
        if ($piece !== '') $chunks[] = $piece;
        if ($end >= $len) break;
        $pos = max(0, $end - $overlap);
    }
    return $chunks;
}

/**
 * List files recursively under a folder.
 *
 * @return array<int,array<string,mixed>>
 */
function cnx_ai_list_files_under_folder(Database $db, int $tenantId, int $folderId): array {
    $tenantId = (int)$tenantId;
    $folderId = (int)$folderId;
    if ($tenantId <= 0 || $folderId <= 0) return [];

    $out = [];
    $queue = [$folderId];
    $seen = [];
    $guard = 0;

    while (!empty($queue) && $guard < 5000) {
        $guard++;
        $fid = array_shift($queue);
        if (isset($seen[$fid])) continue;
        $seen[$fid] = true;

        $rows = $db->fetchAll(
            "SELECT id, tenant_id, folder_id, is_folder, name, file_path, updated_at, mime_type
             FROM files
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND folder_id = ?",
            [$tenantId, $fid]
        ) ?: [];

        foreach ($rows as $r) {
            $isFolder = (int)($r['is_folder'] ?? 0) ? 1 : 0;
            if ($isFolder) {
                $cid = (int)($r['id'] ?? 0);
                if ($cid > 0) $queue[] = $cid;
            } else {
                $out[] = $r;
            }
        }
    }
    return $out;
}

/**
 * Index a single file into ai_knowledge_chunks (delete+insert, idempotent by file_hash).
 *
 * @return array{indexed:bool,chunks:int,warnings:array<int,string>}
 */
function cnx_ai_index_one_file(Database $db, int $tenantId, int $sourceId, array $fileRow): array {
    $warnings = [];
    $fileId = (int)($fileRow['id'] ?? 0);
    $name = (string)($fileRow['name'] ?? '');
    $rel = (string)($fileRow['file_path'] ?? '');
    $updatedAt = (string)($fileRow['updated_at'] ?? '');
    if ($fileId <= 0 || $rel === '') return ['indexed' => false, 'chunks' => 0, 'warnings' => ['File row non valido']];

    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt','md','json','xml','docx','xlsx'], true)) {
        return ['indexed' => false, 'chunks' => 0, 'warnings' => []];
    }

    $abs = rtrim(FileHelper::getTenantUploadPath($tenantId), '/\\') . '/' . ltrim($rel, '/\\');
    if (!is_file($abs)) return ['indexed' => false, 'chunks' => 0, 'warnings' => ['File fisico non trovato']];

    $fileHash = cnx_ai_compute_file_hash($abs, $fileId, $updatedAt);

    // Skip if already indexed at same hash
    $existing = $db->fetchOne(
        "SELECT file_hash FROM ai_knowledge_chunks WHERE tenant_id = ? AND source_id = ? AND file_id = ? LIMIT 1",
        [$tenantId, $sourceId, $fileId]
    );
    if ($existing && (string)($existing['file_hash'] ?? '') === $fileHash) {
        return ['indexed' => false, 'chunks' => 0, 'warnings' => []];
    }

    $text = cnx_ai_extract_text_for_file($abs, $ext, $warnings);
    if (trim($text) === '') {
        // Clear old chunks if any (file became empty)
        $db->query("DELETE FROM ai_knowledge_chunks WHERE tenant_id = ? AND source_id = ? AND file_id = ?", [$tenantId, $sourceId, $fileId]);
        return ['indexed' => true, 'chunks' => 0, 'warnings' => $warnings];
    }

    $logicalPath = cnx_ai_build_logical_path($db, $tenantId, $fileId);
    $chunks = cnx_ai_chunk_text($text);

    // Replace old chunks
    $db->query("DELETE FROM ai_knowledge_chunks WHERE tenant_id = ? AND source_id = ? AND file_id = ?", [$tenantId, $sourceId, $fileId]);

    $now = date('Y-m-d H:i:s');
    $i = 0;
    foreach ($chunks as $chunkText) {
        $db->insert('ai_knowledge_chunks', [
            'tenant_id' => $tenantId,
            'source_id' => $sourceId,
            'file_id' => $fileId,
            'file_hash' => $fileHash,
            'file_name' => $name,
            'logical_path' => $logicalPath,
            'chunk_index' => $i,
            'chunk_text' => $chunkText,
            'chunk_len' => mb_strlen($chunkText, 'UTF-8'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $i++;
        if ($i >= 500) break;
    }

    return ['indexed' => true, 'chunks' => $i, 'warnings' => $warnings];
}

/**
 * Delta index: scan a knowledge folder and index only new/changed files (idempotent by file_hash).
 * Uses strict caps to avoid timeouts; best-effort and safe to call frequently (cron / on-chat fallback).
 *
 * @param array<string,mixed> $opts
 * @return array{ok:bool,scanned_files:int,checked_files:int,indexed_files:int,indexed_chunks:int,partial:bool,warnings:array<int,string>}
 */
function cnx_ai_index_folder_delta(Database $db, int $tenantId, int $sourceId, int $folderId, array $opts = []): array {
    $tenantId = (int)$tenantId;
    $sourceId = (int)$sourceId;
    $folderId = (int)$folderId;
    if ($tenantId <= 0 || $sourceId <= 0 || $folderId <= 0) {
        return ['ok' => false, 'scanned_files' => 0, 'checked_files' => 0, 'indexed_files' => 0, 'indexed_chunks' => 0, 'partial' => false, 'warnings' => ['Parametri non validi']];
    }

    $lockName = "cnx_ai_delta_{$tenantId}_{$sourceId}";
    $gotLock = cnx_ai_try_lock($db, $lockName, 0);
    if (!$gotLock) {
        return [
            'ok' => true,
            'scanned_files' => 0,
            'checked_files' => 0,
            'indexed_files' => 0,
            'indexed_chunks' => 0,
            'partial' => false,
            'warnings' => ['Indicizzazione già in corso (lock attivo). Riprova tra qualche secondo.'],
            'locked' => true,
        ];
    }

    try {
    $maxIndexedFiles = (int)($opts['max_indexed_files'] ?? 12);
    $maxIndexedFiles = max(1, min(60, $maxIndexedFiles));

    $maxCheckedFiles = (int)($opts['max_checked_files'] ?? 500);
    $maxCheckedFiles = max(20, min(5000, $maxCheckedFiles));

    $maxSeconds = (int)($opts['max_seconds'] ?? 8);
    $maxSeconds = max(1, min(25, $maxSeconds));

    $warnings = [];
    $files = cnx_ai_list_files_under_folder($db, $tenantId, $folderId);
    $scanned = count($files);

    // Sort by updated_at desc to prioritize newest changes
    usort($files, function($a, $b) {
        $au = (string)($a['updated_at'] ?? '');
        $bu = (string)($b['updated_at'] ?? '');
        if ($au === $bu) {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        }
        return strcmp($bu, $au);
    });

    $t0 = microtime(true);
    $indexedFiles = 0;
    $indexedChunks = 0;
    $checked = 0;
    $partial = false;

    foreach ($files as $f) {
        $checked++;
        if ($checked > $maxCheckedFiles) {
            $warnings[] = "Indicizzazione delta limitata: raggiunto cap di {$maxCheckedFiles} file controllati. Ripeti per completare.";
            $partial = true;
            break;
        }
        if ((microtime(true) - $t0) > $maxSeconds) {
            $warnings[] = "Indicizzazione delta limitata: time budget ({$maxSeconds}s) raggiunto. Ripeti per completare.";
            $partial = true;
            break;
        }

        $res = cnx_ai_index_one_file($db, $tenantId, $sourceId, $f);
        if (!empty($res['warnings'])) {
            foreach ($res['warnings'] as $w) {
                $warnings[] = ((string)($f['name'] ?? 'file')) . ': ' . (string)$w;
            }
        }
        if (!empty($res['indexed'])) {
            $indexedFiles++;
            $indexedChunks += (int)($res['chunks'] ?? 0);
            if ($indexedFiles >= $maxIndexedFiles) {
                $warnings[] = "Indicizzazione delta limitata: raggiunto cap di {$maxIndexedFiles} file aggiornati. Ripeti per completare.";
                $partial = true;
                break;
            }
        }
    }

    if (!$partial && $checked < $scanned) {
        // This can happen if scanned_files=0 or other edge cases, but keep it explicit
        $partial = ($scanned > $checked);
    }

        return [
            'ok' => true,
            'scanned_files' => $scanned,
            'checked_files' => $checked,
            'indexed_files' => $indexedFiles,
            'indexed_chunks' => $indexedChunks,
            'partial' => $partial,
            'warnings' => array_slice(array_values(array_unique(array_filter($warnings))), 0, 30),
        ];
    } finally {
        cnx_ai_release_lock($db, $lockName);
    }
}
