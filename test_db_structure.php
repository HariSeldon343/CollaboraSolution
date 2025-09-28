<?php
require_once 'config.php';
require_once 'includes/db.php';

header('Content-Type: text/plain');

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "=== FILES TABLE STRUCTURE ===\n";
    $stmt = $pdo->query("DESCRIBE files");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $column) {
        echo sprintf("%-20s %-20s %-10s %-10s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Default'] ?? 'NULL'
        );
    }

    echo "\n=== FOLDERS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query("DESCRIBE folders");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $column) {
        echo sprintf("%-20s %-20s %-10s %-10s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Default'] ?? 'NULL'
        );
    }

    echo "\n=== USERS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $column) {
        echo sprintf("%-20s %-20s %-10s %-10s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Default'] ?? 'NULL'
        );
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>