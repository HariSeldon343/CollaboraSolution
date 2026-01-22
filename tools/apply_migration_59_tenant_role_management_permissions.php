<?php
/**
 * One-click migration: Tenants - tenant_role_management_roles (Migration 59).
 *
 * Adds optional column to tenants:
 * - tenant_role_management_roles (TEXT JSON array): which system roles can manage tenant roles (create/update/delete).
 *
 * Safe to run multiple times (information_schema checks).
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

    $sqlFile = __DIR__ . '/../database/migrations/59_tenant_role_management_permissions.sql';
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
        // Use prepare/execute + consume all rowsets to avoid:
        // "Cannot execute queries while other unbuffered queries are active" (PDO/MySQL)
        $ps = $pdo->prepare($stmt);
        $ps->execute();

        // Consume any result sets (even if not expected) to keep connection clean.
        try { $ps->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { /* ignore */ }
        while (true) {
            try {
                if (!$ps->nextRowset()) break;
                try { $ps->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { /* ignore */ }
            } catch (Throwable $e) {
                break;
            }
        }
    }

    echo "OK: migration 59 applied (tenants tenant_role_management_roles)\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR applying migration 59: " . $e->getMessage() . "\n";
    exit(1);
}

