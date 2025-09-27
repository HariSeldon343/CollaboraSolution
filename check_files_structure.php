<?php
require_once 'includes/db_connection.php';

try {
    // Check if files table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'files'");
    if ($checkTable->rowCount() > 0) {
        echo "Table 'files' exists. Current structure:\n";
        echo str_repeat("=", 50) . "\n";

        // Get current structure
        $structure = $pdo->query("DESCRIBE files");
        $columns = $structure->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $column) {
            echo sprintf("%-20s %-20s %-10s %-10s %s\n",
                $column['Field'],
                $column['Type'],
                $column['Null'],
                $column['Key'],
                $column['Default'] ?? 'NULL'
            );
        }

        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Sample data (first 5 records):\n";

        // Get sample data
        $data = $pdo->query("SELECT * FROM files LIMIT 5");
        $records = $data->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            echo "No data in the table.\n";
        } else {
            foreach ($records as $record) {
                print_r($record);
            }
        }

        // Count total records
        $count = $pdo->query("SELECT COUNT(*) as total FROM files")->fetch();
        echo "\nTotal records: " . $count['total'] . "\n";

    } else {
        echo "Table 'files' does not exist.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}