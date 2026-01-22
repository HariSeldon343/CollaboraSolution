<?php
/**
 * One-click migration: consulting planning activity catalog + schedule drafts (Migration 35).
 *
 * Creates:
 * - consulting_activity_types
 * - consulting_activity_type_overrides
 * - consulting_plan_consultants
 * - consulting_plan_schedule_drafts
 * And adds columns to consulting_plan_items (schema-safe)
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
            exit;
        }
        $u = $auth->getCurrentUser();
        $role = (string)($u['role'] ?? '');
        if ($role !== 'super_admin') {
            http_response_code(403);
            echo "Forbidden: super_admin required\n";
            exit;
        }
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $existsTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
    $existsOverrides = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_type_overrides'");
    $existsConsultants = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
    $existsDrafts = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_schedule_drafts'");

    if ($existsTypes && $existsOverrides && $existsConsultants && $existsDrafts) {
        echo "OK: consulting planning catalog/schedule tables already exist\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/../database/migrations/35_consulting_activity_catalog_and_schedule.sql';
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

    /**
     * Split SQL into statements by semicolon, ignoring semicolons inside quotes.
     * This migration contains lines like: "PREPARE ...; EXECUTE ...; DEALLOCATE ...;"
     * so splitting only on ";\n" is not sufficient.
     *
     * Also strips SQL comments:
     * - line comments starting with "-- " or "#"
     * - block comments "/* ... *\/"
     *
     * Note: This is intentionally tailored for our DDL/DML migrations
     * (no custom DELIMITER blocks).
     *
     * @return string[]
     */
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

            // Handle end of line comment
            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    // Preserve newline as separator
                    $buf .= "\n";
                }
                continue;
            }

            // Handle end of block comment
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++; // skip '/'
                }
                continue;
            }

            // Start of line comment ("-- " or "#") only when not inside quotes
            if (!$inSingle && !$inDouble && !$inBacktick) {
                // "--" line comment (common MariaDB/MySQL style)
                if ($ch === '-' && $next === '-') {
                    $after = ($i + 2) < $len ? $sql[$i + 2] : '';
                    // Require whitespace or end after "--" to be a comment
                    if ($after === ' ' || $after === "\t" || $after === "\r" || $after === "\n" || $after === '') {
                        $inLineComment = true;
                        $i++; // skip second '-'
                        continue;
                    }
                }
                // "#" line comment
                if ($ch === '#') {
                    $inLineComment = true;
                    continue;
                }
                // "/* ... */" block comment
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++; // skip '*'
                    continue;
                }
            }

            // Quote handling (supports SQL '' escape)
            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    // Escaped single quote within string
                    $buf .= "''";
                    $i++; // skip next
                    continue;
                }
                $inSingle = !$inSingle;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle && !$inBacktick) {
                if ($inDouble && $next === '"') {
                    // Escaped double quote within string
                    $buf .= '""';
                    $i++; // skip next
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

            // Statement delimiter
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

    // Execute as raw SQL (may contain PREPARE / EXECUTE).
    $statements = $splitSqlStatements($sql);
    $idx = 0;
    foreach ($statements as $stmt) {
        $idx++;
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        // Skip USE db; in hosted env DB is already selected
        if (preg_match('/^USE\s+/i', $stmt)) continue;
        try {
            // Use PDO directly so we can show real errors even when DEBUG_MODE is off.
            // NOTE: $pdo is configured with ERRMODE_EXCEPTION in includes/db.php.
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            http_response_code(500);
            $preview = preg_replace('/\s+/', ' ', $stmt);
            $preview = mb_substr($preview, 0, 300);
            echo "ERROR: migration 35 failed at statement #{$idx}\n";
            echo "ERROR: " . $e->getMessage() . "\n";
            echo "SQL: {$preview}\n";
            exit(1);
        }
    }

    // Verify
    $existsTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
    $existsOverrides = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_type_overrides'");
    $existsConsultants = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
    $existsDrafts = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_schedule_drafts'");

    if (!$existsTypes || !$existsOverrides || !$existsConsultants || !$existsDrafts) {
        http_response_code(500);
        echo "ERROR: migration executed but tables not found after\n";
        exit(1);
    }

    echo "OK: created consulting activity catalog + schedule tables (migration 35)\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


