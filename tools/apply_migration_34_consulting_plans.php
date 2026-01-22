<?php
/**
 * One-click migration: create consulting planning tables (Migration 34).
 *
 * Creates:
 * - consulting_plans
 * - consulting_plan_items
 * - consulting_plan_task_links
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

    $existsPlans = $db->fetchOne("SHOW TABLES LIKE 'consulting_plans'");
    $existsItems = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_items'");
    $existsLinks = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_task_links'");

    if ($existsPlans && $existsItems && $existsLinks) {
        echo "OK: consulting planning tables already exist\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/../database/migrations/34_consulting_plans_tenant28.sql';
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

    // Execute as raw SQL (may contain START TRANSACTION / COMMIT)
    // Some DB drivers won't allow multi-statement by default; split on semicolons safely for our simple DDL.
    $statements = preg_split('/;\s*\r?\n/', $sql);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        // Skip USE db; in hosted env DB is already selected
        if (preg_match('/^USE\s+/i', $stmt)) continue;
        $db->query($stmt);
    }

    // Verify
    $existsPlans = $db->fetchOne("SHOW TABLES LIKE 'consulting_plans'");
    $existsItems = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_items'");
    $existsLinks = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_task_links'");

    if (!$existsPlans || !$existsItems || !$existsLinks) {
        http_response_code(500);
        echo "ERROR: migration executed but tables not found after\n";
        exit(1);
    }

    echo "OK: created consulting planning tables (migration 34)\n";
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


