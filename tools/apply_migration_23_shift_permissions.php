<?php
/**
 * One-click migration: add per-tenant shift permissions column.
 *
 * Adds `tenants.shift_permissions` if missing (Migration 23).
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

    // Detect column existence
    $hasCol = $db->fetchOne(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'shift_permissions'
         LIMIT 1"
    );

    if ($hasCol) {
        echo "OK: column tenants.shift_permissions already exists\n";
        exit;
    }

    // Apply migration
    $sql = "ALTER TABLE tenants
  ADD COLUMN shift_permissions TEXT NULL DEFAULT NULL
  AFTER tenant_role_assignment_roles";

    // Database wrapper doesn't expose execute(); use query() for raw SQL.
    $db->query($sql);

    // Verify
    $hasColAfter = $db->fetchOne(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'shift_permissions'
         LIMIT 1"
    );

    if (!$hasColAfter) {
        http_response_code(500);
        echo "ERROR: migration executed but column not found after\n";
        exit;
    }

    echo "OK: added column tenants.shift_permissions\n";
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit;
}

