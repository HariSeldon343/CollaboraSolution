<?php
/**
 * One-click Fix: Folder ZIP approval system
 *
 * What it does (single button):
 * - Creates DB table `folder_zip_download_requests` if missing (Migration 24)
 * - Patches `api/files_tenant.php` cnx_table_exists() to avoid false negatives
 * - Resets OPcache (best-effort)
 *
 * Security:
 * - Auth required
 * - super_admin only
 * - CSRF required
 *
 * This tool is meant for environments where phpMyAdmin/panel access is not available.
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/api_auth.php'; // verifyApiCsrfToken()
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: ../index.php');
    exit;
}
$currentUser = $auth->getCurrentUser();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$csrfToken = $auth->generateCSRFToken();
$db = Database::getInstance();
$pdo = $db->getConnection();

function cnx_table_ready(PDO $pdo): array {
    $table = 'folder_zip_download_requests';
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return ['ready' => true, 'missing' => false];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        $missing = (stripos($msg, "doesn't exist") !== false)
            || (stripos($msg, 'Base table or view not found') !== false)
            || ($code === '42S02');
        return [
            'ready' => false,
            'missing' => $missing,
            'error' => $msg,
            'code' => $code,
        ];
    }
}

function cnx_apply_migration_24(PDO $pdo): array {
    $statusBefore = cnx_table_ready($pdo);
    if (($statusBefore['ready'] ?? false) === true) {
        return ['ok' => true, 'already_existed' => true, 'status_before' => $statusBefore];
    }

    if (($statusBefore['missing'] ?? false) !== true) {
        // Not missing, but not usable: permissions/config issue
        return ['ok' => false, 'error' => 'DB table check failed (not missing).', 'details' => $statusBefore];
    }

    // DDL: do NOT wrap in transaction (MySQL/MariaDB implicit commit)
    $sql = "CREATE TABLE IF NOT EXISTS folder_zip_download_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                folder_id INT NOT NULL,
                requester_id INT NOT NULL,
                status ENUM('pending','approved','rejected','consumed') NOT NULL DEFAULT 'pending',
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME NULL,
                decided_by INT NULL,
                used_at DATETIME NULL,
                note TEXT NULL,
                INDEX idx_fzdr_tenant_folder (tenant_id, folder_id),
                INDEX idx_fzdr_status (status),
                INDEX idx_fzdr_requester_status (requester_id, status),
                INDEX idx_fzdr_requested_at (requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $res = $pdo->exec($sql);
    if ($res === false) {
        $info = $pdo->errorInfo();
        return ['ok' => false, 'error' => 'CREATE TABLE failed', 'details' => $info];
    }

    $statusAfter = cnx_table_ready($pdo);
    return [
        'ok' => (bool)($statusAfter['ready'] ?? false),
        'already_existed' => false,
        'status_before' => $statusBefore,
        'status_after' => $statusAfter,
    ];
}

function cnx_patch_api_files_tenant(string $path): array {
    if (!file_exists($path)) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'file_not_found', 'path' => $path];
    }
    if (!is_readable($path)) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'not_readable', 'path' => $path];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'not_writable', 'path' => $path];
    }

    $src = file_get_contents($path);
    if ($src === false) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'read_failed', 'path' => $path];
    }

    // If already patched, skip successfully
    if (strpos($src, 'Preferred: try a lightweight query against the table') !== false) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_patched', 'path' => $path];
    }

    $newFn = <<<'PHP'
function cnx_table_exists(PDO $pdo, string $table): bool {
    try {
        // Preferred: try a lightweight query against the table.
        // This avoids false negatives when SHOW TABLES is restricted on some hosts.
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->query("SELECT 1 FROM $quoted LIMIT 1");
        return true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();

        // MySQL "table doesn't exist" (SQLSTATE 42S02)
        $isMissing = (stripos($msg, 'doesn\\'t exist') !== false)
            || (stripos($msg, 'Base table or view not found') !== false)
            || ($code === '42S02');
        if ($isMissing) {
            return false;
        }

        // Fallback: SHOW TABLES LIKE (some setups allow it even if SELECT fails)
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return ($stmt->fetch(PDO::FETCH_NUM) !== false);
        } catch (Throwable $e2) {
            error_log('[cnx_table_exists] failed for ' . $table . ': ' . $msg . ' | fallback: ' . $e2->getMessage());
            return false;
        }
    }
}
PHP;

    /**
     * Robust replacement of a function body, tolerant to different signatures/formatting.
     */
    $findRange = function (string $code, string $fnName): ?array {
        $m = [];
        if (!preg_match('/function\s+' . preg_quote($fnName, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = (int)$m[0][1];

        $len = strlen($code);
        $i = $start;

        // Find first opening brace after function(...)
        $bracePos = -1;
        for (; $i < $len; $i++) {
            $ch = $code[$i];
            if ($ch === '{') {
                $bracePos = $i;
                break;
            }
        }
        if ($bracePos < 0) return null;

        // Walk forward and find matching closing brace.
        $depth = 0;
        $inS = false; $inD = false;
        $inLineComment = false; $inBlockComment = false;

        for ($i = $bracePos; $i < $len; $i++) {
            $ch = $code[$i];
            $n1 = ($i + 1 < $len) ? $code[$i + 1] : "\0";

            if ($inLineComment) {
                if ($ch === "\n") $inLineComment = false;
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $n1 === '/') { $inBlockComment = false; $i++; }
                continue;
            }
            if ($inS) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === "'") { $inS = false; }
                continue;
            }
            if ($inD) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === '"') { $inD = false; }
                continue;
            }

            // Enter comments
            if ($ch === '/' && $n1 === '/') { $inLineComment = true; $i++; continue; }
            if ($ch === '#' ) { $inLineComment = true; continue; }
            if ($ch === '/' && $n1 === '*') { $inBlockComment = true; $i++; continue; }

            // Enter strings
            if ($ch === "'") { $inS = true; continue; }
            if ($ch === '"') { $inD = true; continue; }

            if ($ch === '{') { $depth++; continue; }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    // Extend to include trailing whitespace/newline
                    while ($end < $len && ($code[$end] === "\r" || $code[$end] === "\n" || $code[$end] === ' ' || $code[$end] === "\t")) {
                        $end++;
                    }
                    return ['start' => $start, 'end' => $end];
                }
                continue;
            }
        }
        return null;
    };

    $range = $findRange($src, 'cnx_table_exists');
    if ($range === null) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'function_not_found', 'path' => $path];
    }

    $patched = substr($src, 0, (int)$range['start']) . $newFn . "\n\n" . substr($src, (int)$range['end']);

    $backup = $path . '.bak_' . date('Ymd_His');
    if (!@copy($path, $backup)) {
        // continue anyway, but report
        $backup = null;
    }

    $ok = (file_put_contents($path, $patched) !== false);
    return ['ok' => $ok, 'backup' => $backup, 'path' => $path];
}

function cnx_try_opcache_reset(): array {
    try {
        if (function_exists('opcache_reset')) {
            $r = @opcache_reset();
            return ['ok' => (bool)$r, 'supported' => true];
        }
        return ['ok' => false, 'supported' => false];
    } catch (Throwable $e) {
        return ['ok' => false, 'supported' => true, 'error' => $e->getMessage()];
    }
}

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$result = null;
$error = null;

// Pre-checks for UI
$statusNow = cnx_table_ready($pdo);
$apiFile = realpath(__DIR__ . '/../api/files_tenant.php') ?: (__DIR__ . '/../api/files_tenant.php');
$apiWritable = is_writable($apiFile);

if ($isPost) {
    verifyApiCsrfToken(true);
    try {
        $steps = [];
        $steps['migration_24'] = cnx_apply_migration_24($pdo);
        $steps['patch_api_files_tenant'] = cnx_patch_api_files_tenant($apiFile);
        $steps['opcache_reset'] = cnx_try_opcache_reset();

        $result = [
            'ok' => (bool)(($steps['migration_24']['ok'] ?? false) && ($steps['patch_api_files_tenant']['ok'] ?? false)),
            'steps' => $steps,
            'table_status_after' => cnx_table_ready($pdo),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[one_click_fix_zip_approval] failed: ' . $error);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>One-click Fix - ZIP Approval</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .wrap { max-width: 860px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; }
        .muted { color:#6b7280; font-size: 13px; }
        pre { background:#0b1020; color:#e5e7eb; padding: 12px; border-radius: 10px; overflow:auto; }
        .k { display:inline-block; min-width: 210px; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2 style="margin-top:0;">One-click Fix: ZIP Approval (Migration 24 + Patch)</h2>
        <p class="muted" style="margin-top:6px;">
            Questo tool prova a sistemare tutto con un click (solo <strong>super_admin</strong>).
        </p>

        <div style="margin:12px 0; padding:12px; border:1px solid #eef0f3; border-radius:10px;">
            <div><span class="k">Tabella DB pronta:</span> <strong><?php echo ($statusNow['ready'] ? 'SI' : 'NO'); ?></strong></div>
            <div><span class="k">Tabella DB mancante:</span> <?php echo (($statusNow['missing'] ?? false) ? 'SI' : 'NO'); ?></div>
            <div><span class="k">api/files_tenant.php scrivibile:</span> <?php echo ($apiWritable ? 'SI' : 'NO'); ?></div>
        </div>

        <?php if ($isPost): ?>
            <?php if ($result && ($result['ok'] ?? false)): ?>
                <div class="alert alert-success" style="margin:12px 0;">
                    Fix completato.
                </div>
            <?php else: ?>
                <div class="alert alert-danger" style="margin:12px 0;">
                    Fix non completato. <?php echo htmlspecialchars((string)$error); ?>
                </div>
            <?php endif; ?>
            <pre><?php echo htmlspecialchars(json_encode($result ?: ['ok' => false, 'error' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        <?php endif; ?>

        <form method="POST" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <button type="submit" class="btn btn-primary">Esegui Fix (DB + Patch + OPcache)</button>
            <a class="btn btn-secondary" href="../files.php" style="margin-left:8px;">Vai a File Manager</a>
        </form>

        <p class="muted" style="margin-top:14px;">
            Nota: se <code>api/files_tenant.php</code> non è scrivibile, il tool può comunque creare la tabella,
            ma non può patchare il check tabella (in quel caso serve permesso di scrittura o deploy file).
        </p>
    </div>
</div>
</body>
</html>

