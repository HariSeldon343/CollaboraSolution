<?php
/**
 * Deployment self-check for Planning (tenant 28) + Migration 35 features.
 *
 * Web: requires authenticated super_admin (safe to expose file paths only to SA)
 * CLI: allowed.
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
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

    if (!$isCli) {
        require_once $root . '/includes/session_init.php';
        require_once $root . '/includes/auth_simple.php';
        $auth = new Auth();
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo "Unauthorized\n";
            exit;
        }
        $u = $auth->getCurrentUser();
        if ((string)($u['role'] ?? '') !== 'super_admin') {
            http_response_code(403);
            echo "Forbidden: super_admin required\n";
            exit;
        }
    }

    $required = [
        'planning.php',
        'assets/js/planning.js',
        'assets/css/planning.css',
        'database/migrations/34_consulting_plans_tenant28.sql',
        'database/migrations/35_consulting_activity_catalog_and_schedule.sql',
        'tools/apply_migration_34_consulting_plans.php',
        'tools/apply_migration_35_consulting_activity_catalog_and_schedule.php',
        'api/consulting_plans/_common.php',
        'api/consulting_plans/activity_types.php',
        'api/consulting_plans/consultants.php',
        'api/consulting_plans/schedule_generate.php',
        'api/consulting_plans/schedule_list.php',
        'api/consulting_plans/schedule_update.php',
        'api/consulting_plans/schedule_suggest.php',
        'api/consulting_plans/schedule_confirm.php',
    ];

    echo "Planning35 Deploy Check\n";
    echo "Root: {$root}\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "SAPI: " . PHP_SAPI . "\n";
    echo "\n";

    $missing = 0;
    foreach ($required as $rel) {
        $abs = $root . '/' . str_replace('\\', '/', $rel);
        $ok = is_file($abs) && is_readable($abs);
        if (!$ok) $missing++;
        $status = $ok ? 'OK' : 'MISSING';
        $meta = '';
        if ($ok) {
            $meta = 'mtime=' . @date('Y-m-d H:i:s', (int)@filemtime($abs)) . ' size=' . (string)@filesize($abs);
        }
        echo sprintf("[%s] %s %s\n", $status, $rel, $meta);
    }

    echo "\n";
    if ($missing > 0) {
        http_response_code(500);
        echo "RESULT: FAIL ({$missing} missing/unreadable)\n";
        exit(1);
    }
    echo "RESULT: OK (all files present)\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


