<?php
/**
 * Tenant Docs Indexer (best-effort, no external deps required).
 *
 * - Lists tenant files under allowed paths (default: /IMS)
 * - Extracts text from supported formats
 * - Redacts PII from extracted text (best-effort) before RAG storage
 * - Chunks text and upserts into tenant_doc_chunks
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../file_helper.php';

function cnx_tenant_docs_try_lock(Database $db, string $lockName, int $timeoutSeconds = 0): bool {
    $lockName = substr(trim($lockName), 0, 64);
    if ($lockName === '') return true;
    try {
        $row = $db->fetchOne("SELECT GET_LOCK(?, ?) AS ok", [$lockName, max(0, (int)$timeoutSeconds)]);
        return ((int)($row['ok'] ?? 0) === 1);
    } catch (Throwable $e) {
        return true;
    }
}

function cnx_tenant_docs_release_lock(Database $db, string $lockName): void {
    $lockName = substr(trim($lockName), 0, 64);
    if ($lockName === '') return;
    try {
        $db->fetchOne("SELECT RELEASE_LOCK(?) AS ok", [$lockName]);
    } catch (Throwable $e) {
        // ignore
    }
}

function cnx_tenant_docs_root_folder_id(Database $db, int $tenantId): int {
    $row = $db->fetchOne(
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
    return (int)($row['id'] ?? 0);
}

function cnx_tenant_docs_find_folder_by_path(Database $db, int $tenantId, string $path): int {
    $path = trim($path);
    if ($path === '' || $path === '/') return 0;
    $path = trim($path, '/');
    $parts = array_values(array_filter(explode('/', $path)));
    if (empty($parts)) return 0;

    $rootId = cnx_tenant_docs_root_folder_id($db, $tenantId);
    if ($rootId <= 0) return 0;
    $parentId = $rootId;

    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $row = $db->fetchOne(
            "SELECT id
             FROM files
             WHERE tenant_id = ?
               AND is_folder = 1
               AND deleted_at IS NULL
               AND folder_id = ?
               AND name = ?
             ORDER BY id ASC
             LIMIT 1",
            [$tenantId, $parentId, $p]
        );
        if (!$row) return 0;
        $parentId = (int)($row['id'] ?? 0);
        if ($parentId <= 0) return 0;
    }

    return $parentId;
}

function cnx_tenant_docs_build_logical_path(Database $db, int $tenantId, int $fileId): string {
    try {
        $row = $db->fetchOne(
            "SELECT id, name, folder_id
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
 * @return array<int,array<string,mixed>>
 */
function cnx_tenant_docs_list_files_under_folder(Database $db, int $tenantId, int $folderId): array {
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
            "SELECT id, tenant_id, folder_id, is_folder, name, file_path, file_size, updated_at, mime_type
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
 * @return array<int,array<string,mixed>>
 */
function cnx_tenant_docs_list_files(Database $db, int $tenantId, array $rootPaths = ['/IMS']): array {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0) return [];
    $files = [];
    foreach ($rootPaths as $p) {
        $folderId = cnx_tenant_docs_find_folder_by_path($db, $tenantId, (string)$p);
        if ($folderId <= 0) continue;
        foreach (cnx_tenant_docs_list_files_under_folder($db, $tenantId, $folderId) as $row) {
            $files[] = $row;
        }
    }
    return $files;
}

function cnx_tenant_docs_compute_file_hash(string $absPath, int $fileId, string $updatedAt): string {
    if (is_file($absPath)) {
        $h = @md5_file($absPath);
        if (is_string($h) && $h !== '') return $h;
    }
    return md5($fileId . '_' . $updatedAt);
}

function cnx_tenant_docs_resolve_abs_path(int $tenantId, string $filePath): string {
    $tenantId = (int)$tenantId;
    $filePath = trim((string)$filePath);
    if ($tenantId <= 0 || $filePath === '') return '';

    // If stored as "uploads/{tenant}/file", use project root
    $fp = str_replace('\\', '/', $filePath);
    if (str_starts_with($fp, 'uploads/')) {
        return dirname(__DIR__, 2) . '/' . $fp;
    }
    if (str_starts_with($fp, '/uploads/')) {
        return dirname(__DIR__, 2) . $fp;
    }

    // Default: tenant upload path + file_path
    $base = FileHelper::getTenantUploadPath($tenantId);
    return rtrim($base, '\\/') . DIRECTORY_SEPARATOR . ltrim($filePath, '\\/');
}

function cnx_tenant_docs_extract_docx_text(string $absPath, array &$warnings): string {
    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) {
        $warnings[] = 'DOCX: impossibile aprire zip';
        return '';
    }
    $xml = (string)($zip->getFromName('word/document.xml') ?: '');
    $zip->close();
    if ($xml === '') return '';
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

function cnx_tenant_docs_extract_xlsx_text(string $absPath, array &$warnings): string {
    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) {
        $warnings[] = 'XLSX: impossibile aprire zip';
        return '';
    }
    $texts = [];
    $ss = (string)($zip->getFromName('xl/sharedStrings.xml') ?: '');
    if ($ss !== '' && preg_match_all('/<t[^>]*>(.*?)<\\/t>/su', $ss, $m)) {
        foreach ($m[1] as $t) {
            $texts[] = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
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

function cnx_tenant_docs_extract_pdf_text(string $absPath, array &$warnings): string {
    if (!function_exists('shell_exec')) {
        $warnings[] = 'PDF: shell_exec non disponibile';
        return '';
    }
    $cmd = 'pdftotext';
    $where = @shell_exec('where ' . $cmd . ' 2>NUL');
    if (!is_string($where) || trim($where) === '') {
        $warnings[] = 'PDF: pdftotext non disponibile';
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cnx_pdf_');
    if (!$tmp) {
        $warnings[] = 'PDF: temp file non disponibile';
        return '';
    }
    $outFile = $tmp . '.txt';
    @unlink($tmp);
    $argIn = escapeshellarg($absPath);
    $argOut = escapeshellarg($outFile);
    @shell_exec("pdftotext -layout $argIn $argOut");
    $raw = @file_get_contents($outFile);
    @unlink($outFile);
    if (!is_string($raw)) return '';
    $raw = preg_replace('/\\s+/', ' ', $raw) ?: $raw;
    return trim($raw);
}

function cnx_tenant_docs_extract_text(string $absPath, string $ext, array &$warnings): string {
    $ext = strtolower(trim($ext));
    if (in_array($ext, ['txt','md','json','xml'], true)) {
        $raw = @file_get_contents($absPath);
        if (!is_string($raw)) return '';
        $raw = preg_replace('/\\r\\n?/', "\n", $raw) ?: $raw;
        if (function_exists('mb_strlen') && mb_strlen($raw, 'UTF-8') > 200000) {
            $warnings[] = 'Testo troncato (max 200k char)';
            $raw = mb_substr($raw, 0, 200000, 'UTF-8');
        }
        return trim($raw);
    }
    if ($ext === 'docx') return cnx_tenant_docs_extract_docx_text($absPath, $warnings);
    if ($ext === 'xlsx') return cnx_tenant_docs_extract_xlsx_text($absPath, $warnings);
    if ($ext === 'pdf') return cnx_tenant_docs_extract_pdf_text($absPath, $warnings);
    return '';
}

function cnx_tenant_docs_redact_text(string $text): string {
    // Email
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', '[email]', $text) ?? $text;
    // Phone numbers (simple)
    $text = preg_replace('/\\b(?:\\+?\\d{1,3}[\\s.-]?)?(?:\\(?\\d{2,4}\\)?[\\s.-]?)?\\d{3,4}[\\s.-]?\\d{3,4}\\b/', '[phone]', $text) ?? $text;
    // Codice fiscale (Italy)
    $text = preg_replace('/\\b[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]\\b/i', '[cf]', $text) ?? $text;
    return $text;
}

/**
 * @return string[]
 */
function cnx_tenant_docs_chunk_text(string $text, int $maxLen = 1200, int $overlap = 200): array {
    $text = trim($text);
    if ($text === '') return [];
    $maxLen = max(200, $maxLen);
    $overlap = max(0, min($overlap, (int)floor($maxLen / 2)));
    $chunks = [];
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $pos = 0;
    while ($pos < $len && count($chunks) < 500) {
        $end = min($len, $pos + $maxLen);
        $piece = function_exists('mb_substr') ? mb_substr($text, $pos, $end - $pos, 'UTF-8') : substr($text, $pos, $end - $pos);
        $piece = trim((string)$piece);
        if ($piece !== '') $chunks[] = $piece;
        if ($end >= $len) break;
        $pos = max(0, $end - $overlap);
    }
    return $chunks;
}

/**
 * Upsert chunks for a file (idempotent by file_hash).
 *
 * @return array{updated:bool,chunk_count:int}
 */
function cnx_tenant_docs_upsert_chunks(Database $db, int $tenantId, int $fileId, string $fileHash, array $chunks, array $meta = []): array {
    $tenantId = (int)$tenantId;
    $fileId = (int)$fileId;
    $fileHash = trim($fileHash);
    if ($tenantId <= 0 || $fileId <= 0 || $fileHash === '') {
        return ['updated' => false, 'chunk_count' => 0];
    }

    $state = $db->fetchOne(
        "SELECT last_hash
         FROM tenant_doc_files_state
         WHERE tenant_id = ? AND file_id = ?
         LIMIT 1",
        [$tenantId, $fileId]
    );
    if ($state && (string)($state['last_hash'] ?? '') === $fileHash) {
        return ['updated' => false, 'chunk_count' => 0];
    }

    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $db->fetchOne(
            "DELETE FROM tenant_doc_chunks WHERE tenant_id = ? AND file_id = ?",
            [$tenantId, $fileId]
        );
        $idx = 0;
        foreach ($chunks as $c) {
            $chunkText = trim((string)$c);
            if ($chunkText === '') continue;
            $db->insert('tenant_doc_chunks', [
                'tenant_id' => $tenantId,
                'file_id' => $fileId,
                'file_hash' => $fileHash,
                'chunk_idx' => $idx,
                'chunk_text' => $chunkText,
                'chunk_tokens' => null,
                'meta_json' => cnx_ai_safe_json_stringify($meta, 6000),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $idx++;
            if ($idx >= 500) break;
        }

        $db->insert('tenant_doc_files_state', [
            'tenant_id' => $tenantId,
            'file_id' => $fileId,
            'last_hash' => $fileHash,
            'last_indexed_at' => date('Y-m-d H:i:s'),
            'status' => 'ok',
            'error_id' => null,
        ]);
        $pdo->commit();
        return ['updated' => true, 'chunk_count' => $idx];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Safe JSON stringify helper (local to this file).
 */
function cnx_ai_safe_json_stringify($v, int $maxLen = 20000): ?string {
    if ($v === null) return null;
    if (is_string($v)) {
        $s = trim($v);
        if ($s === '') return null;
        if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
        return $s;
    }
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($s) || trim($s) === '') return null;
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

