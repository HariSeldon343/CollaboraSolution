<?php
/**
 * One-click migration: Consulting plans - plan scopes (Migration 56).
 *
 * Creates consulting_plan_scopes table used to store multi-service/multi-standard scope per plan.
 *
 * Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
 * - Web: requires authenticated super_admin
 * - CLI: allowed
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';

    if (!$isCli) {
        require_once __DIR__ . '/../includes/session_init.php';
        require_once __DIR__ . '/../includes/auth_simple.php';
        $auth = new Auth();
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo "Unauthorized\n";
            exit(1);
        }
        $u = $auth->getCurrentUser();
        $role = (string)($u['role'] ?? '');
        if ($role !== 'super_admin') {
            http_response_code(403);
            echo "Forbidden: super_admin required\n";
            exit(1);
        }
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $sqlFile = __DIR__ . '/../database/migrations/56_consulting_plan_scopes.sql';
    if (!is_file($sqlFile)) {
        http_response_code(500);
        echo "ERROR: migration file not found: $sqlFile\n";
        exit(1);
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        http_response_code(500);
        echo "ERROR: could not read migration file\n";
        exit(1);
    }

    // Simple split by semicolon (same approach used by other tools in repo)
    $splitSqlStatements = static function (string $sql): array {
        $out = [];
        $buf = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = ($i + 1) < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") { $inLineComment = false; $buf .= "\n"; }
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') { $inBlockComment = false; $i++; }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') {
                    $after = ($i + 2) < $len ? $sql[$i + 2] : '';
                    if ($after === ' ' || $after === "\t" || $after === "\r" || $after === "\n" || $after === '') {
                        $inLineComment = true; $i++; continue;
                    }
                }
                if ($ch === '#') { $inLineComment = true; continue; }
                if ($ch === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") { $buf .= "''"; $i++; continue; }
                $inSingle = !$inSingle; $buf .= $ch; continue;
            }
            if ($ch === '"' && !$inSingle && !$inBacktick) { $inDouble = !$inDouble; $buf .= $ch; continue; }
            if ($ch === '`' && !$inSingle && !$inDouble) { $inBacktick = !$inBacktick; $buf .= $ch; continue; }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $stmt = trim($buf);
                if ($stmt !== '') $out[] = $stmt;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $stmt = trim($buf);
        if ($stmt !== '') $out[] = $stmt;
        return $out;
    };

    foreach ($splitSqlStatements($sql) as $stmt) {
        $pdo->exec($stmt);
    }

    echo "OK: migration 56 applied (consulting_plan_scopes)\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR applying migration 56: " . $e->getMessage() . "\n";
    exit(1);
}

