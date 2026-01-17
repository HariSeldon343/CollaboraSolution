<?php
/**
 * SQL Migration Runner (best-effort, schema-drift safe)
 *
 * Executes a migration .sql file by splitting into statements on semicolons,
 * ignoring semicolons inside quotes/backticks and skipping comments.
 *
 * IMPORTANT:
 * - Only use for idempotent migrations (CREATE TABLE IF NOT EXISTS, etc.)
 * - Callers must enforce auth/RBAC/CSRF before using this.
 */
declare(strict_types=1);

/**
 * Split a SQL file content into statements.
 *
 * @return string[]
 */
function cnx_split_sql_statements(string $sql): array
{
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

    $stmt = trim($buf);
    if ($stmt !== '') $out[] = $stmt;
    return $out;
}

/**
 * Execute a migration file.
 *
 * @throws RuntimeException
 */
function cnx_apply_sql_migration_file(PDO $pdo, string $sqlFile): void
{
    if (!is_file($sqlFile)) {
        throw new RuntimeException("migration file not found: {$sqlFile}");
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("could not read migration file: {$sqlFile}");
    }
    $stmts = cnx_split_sql_statements($sql);

    // Ensure failures are never silent. Some environments can run PDO with ERRMODE_SILENT
    // (or a driver may not throw on exec()) which would produce false "OK" migrations.
    $oldErrMode = null;
    try {
        $oldErrMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    } catch (Throwable $e) {
        $oldErrMode = null;
    }
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        // If we can't set, we still run, but we'll check exec() return value below.
    }

    foreach ($stmts as $stmt) {
        try {
            $res = $pdo->exec($stmt);
            if ($res === false) {
                $info = $pdo->errorInfo();
                $sqlState = (string)($info[0] ?? '');
                $driverCode = (string)($info[1] ?? '');
                $driverMsg = (string)($info[2] ?? 'Unknown SQL error');
                $snippet = substr(trim($stmt), 0, 280);
                throw new RuntimeException("SQL exec failed ({$sqlState}/{$driverCode}): {$driverMsg}. Statement: {$snippet}");
            }
        } catch (Throwable $e) {
            $snippet = substr(trim($stmt), 0, 280);
            throw new RuntimeException("Migration exec failed for {$sqlFile}: {$e->getMessage()}. Statement: {$snippet}", 0, $e);
        }
    }

    // Restore old mode if possible.
    if ($oldErrMode !== null) {
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $oldErrMode);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

