<?php
/**
 * Read-only debug script: inspect tenants + tasks visibility.
 *
 * Usage (CLI):
 *   php tools/debug_tasks_visibility.php
 *
 * NOTE: This script does NOT modify the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::getInstance()->getConnection();

    $tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    echo "TENANTS:\n";
    foreach ($tenants as $t) {
        echo sprintf("- %s: %s\n", (string)($t['id'] ?? ''), (string)($t['name'] ?? ''));
    }

    // Tasks table schema
    $cols = $pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_ASSOC);
    echo "\nTASKS COLUMNS:\n";
    foreach ($cols as $c) {
        echo "- " . ($c['Field'] ?? '') . "\n";
    }

    // Fetch task(s) titled "m"
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE title = ?');
    $stmt->execute(['m']);
    $tasksM = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\nTASKS WHERE title='m':\n";
    echo json_encode($tasksM, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    // Count tasks by tenant_id (if present)
    $hasTenantId = false;
    foreach ($cols as $c) {
        if (($c['Field'] ?? '') === 'tenant_id') {
            $hasTenantId = true;
            break;
        }
    }
    if ($hasTenantId) {
        $counts = $pdo->query('SELECT tenant_id, COUNT(*) as cnt FROM tasks GROUP BY tenant_id ORDER BY tenant_id ASC')->fetchAll(PDO::FETCH_ASSOC);
        echo "\nTASK COUNTS BY tenant_id:\n";
        foreach ($counts as $row) {
            echo sprintf("- tenant_id=%s cnt=%s\n", (string)($row['tenant_id'] ?? ''), (string)($row['cnt'] ?? ''));
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


