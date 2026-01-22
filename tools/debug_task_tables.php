<?php
/**
 * Read-only debug script: inspect task_history/task_comments schema + recent rows.
 *
 * Usage:
 *   php tools/debug_task_tables.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

function showColumns(PDO $pdo, string $table): array {
    echo "\n=== SHOW COLUMNS FROM {$table} ===\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "- " . ($c['Field'] ?? '') . " (" . ($c['Type'] ?? '') . ")\n";
        }
        return $cols;
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return [];
    }
}

try {
    $pdo = Database::getInstance()->getConnection();

    showColumns($pdo, 'task_history');
    showColumns($pdo, 'task_comments');
    showColumns($pdo, 'task_assignments');

    echo "\n=== Recent task_history (limit 5) ===\n";
    try {
        $rows = $pdo->query("SELECT * FROM task_history ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }

    echo "\n=== Recent task_comments (limit 5) ===\n";
    try {
        $rows = $pdo->query("SELECT * FROM task_comments ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}


