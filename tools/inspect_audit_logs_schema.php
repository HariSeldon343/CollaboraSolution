<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
header('Content-Type: text/plain; charset=utf-8');

try {
    $cols = $db->fetchAll('SHOW COLUMNS FROM audit_logs');
    foreach ($cols as $c) {
        echo ($c['Field'] ?? '?') . "\t" . ($c['Type'] ?? '?') . "\t" . ($c['Null'] ?? '?') . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}


