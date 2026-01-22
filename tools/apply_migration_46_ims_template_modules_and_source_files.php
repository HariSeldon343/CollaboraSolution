<?php
/**
 * One-click migration: IMS Template Modules + Source Master Files (Migration 46).
 *
 * Safe to run multiple times (idempotent SQL + conditional ALTERs).
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
    require_once __DIR__ . '/../includes/sql_migration_runner.php';

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

    $sqlFile = __DIR__ . '/../database/migrations/46_ims_template_modules_and_source_files.sql';
    if (!is_file($sqlFile)) {
        http_response_code(500);
        echo "ERROR: migration file not found: $sqlFile\n";
        exit(1);
    }

    cnx_apply_sql_migration_file($pdo, $sqlFile);

    echo "OK: migration 46 applied (IMS template modules + source master fields)\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

