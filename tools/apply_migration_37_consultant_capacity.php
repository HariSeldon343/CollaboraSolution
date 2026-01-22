<?php
/**
 * One-click migration: consultant capacities (Migration 37).
 *
 * Creates:
 * - consultant_capacities (default available days per consultant user)
 *
 * Safe to run multiple times.
 * - Web: requires authenticated super_admin
 * - CLI: allowed (for local maintenance)
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

    // Web protection (Super Admin only)
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

    $exists = $db->fetchOne("SHOW TABLES LIKE 'consultant_capacities'");
    if ($exists) {
        echo "OK: consultant_capacities already exists\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/../database/migrations/37_consultant_capacity.sql';
    if (!is_file($sqlFile)) {
        http_response_code(500);
        echo "ERROR: migration file not found: $sqlFile\n";
        exit(1);
    }

    $sql = file_get_contents($sqlFile);
    if (!$sql) {
        http_response_code(500);
        echo "ERROR: could not read migration file\n";
        exit(1);
    }

    // Split SQL into statements by semicolon, ignoring semicolons inside quotes.
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
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buf .= "\n";
                }
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') {
                    $after = ($i + 2) < $len ? $sql[$i + 2] : '';
                    if ($after === ' ' || $after === "\t" || $after === "\r" || $after === "\n" || $after === '') {
                        $inLineComment = true;
                        $i++;
                        continue;
                    }
                }
                if ($ch === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle && !$inBacktick) {
                if ($inDouble && $next === '"') {
                    $buf .= '""';
                    $i++;
                    continue;
                }
                $inDouble = !$inDouble;
                $buf .= $ch;
                continue;
            }
            if ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $buf .= $ch;
                continue;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $stmt = trim($buf);
                if ($stmt !== '') $out[] = $stmt;
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }
        $tail = trim($buf);
        if ($tail !== '') $out[] = $tail;
        return $out;
    };

    $statements = $splitSqlStatements($sql);
    $idx = 0;
    foreach ($statements as $stmt) {
        $idx++;
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (preg_match('/^USE\s+/i', $stmt)) continue;
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            http_response_code(500);
            $preview = preg_replace('/\s+/', ' ', $stmt);
            $preview = mb_substr($preview, 0, 300);
            echo "ERROR: migration 37 failed at statement #{$idx}\n";
            echo "ERROR: " . $e->getMessage() . "\n";
            echo "SQL: {$preview}\n";
            exit(1);
        }
    }

    $exists = $db->fetchOne("SHOW TABLES LIKE 'consultant_capacities'");
    if (!$exists) {
        http_response_code(500);
        echo "ERROR: migration 37 did not create consultant_capacities\n";
        exit(1);
    }

    echo "OK: consultant_capacities created\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

