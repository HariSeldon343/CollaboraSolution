<?php
/**
 * Database Table Structure Analyzer
 * Examines the exact structure of files, folders, and users tables
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "DATABASE TABLE STRUCTURE ANALYSIS\n";
echo "==================================\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Tables to analyze
    $tables = ['files', 'folders', 'users'];

    foreach ($tables as $tableName) {
        echo "TABLE: $tableName\n";
        echo str_repeat('-', 80) . "\n";

        // Get table structure using DESCRIBE
        $stmt = $conn->prepare("DESCRIBE $tableName");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "Columns:\n";
        foreach ($columns as $col) {
            echo sprintf(
                "  %-25s %-20s %-10s %-10s %s\n",
                $col['Field'],
                $col['Type'],
                $col['Null'],
                $col['Key'],
                $col['Default'] ?? 'NULL'
            );
        }

        // Get indexes
        echo "\nIndexes:\n";
        $stmt = $conn->prepare("SHOW INDEX FROM $tableName");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexGroups = [];
        foreach ($indexes as $idx) {
            $indexGroups[$idx['Key_name']][] = $idx['Column_name'];
        }

        foreach ($indexGroups as $indexName => $columns) {
            echo "  $indexName: " . implode(', ', $columns) . "\n";
        }

        // Get foreign keys
        echo "\nForeign Keys:\n";
        $stmt = $conn->prepare("
            SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :db_name
                AND TABLE_NAME = :table_name
                AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([
            ':db_name' => DB_NAME,
            ':table_name' => $tableName
        ]);
        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($fks) {
            foreach ($fks as $fk) {
                echo sprintf(
                    "  %s: %s -> %s.%s\n",
                    $fk['CONSTRAINT_NAME'],
                    $fk['COLUMN_NAME'],
                    $fk['REFERENCED_TABLE_NAME'],
                    $fk['REFERENCED_COLUMN_NAME']
                );
            }
        } else {
            echo "  None\n";
        }

        // Get sample data (first row)
        echo "\nSample Data (first row):\n";
        $stmt = $conn->prepare("SELECT * FROM $tableName LIMIT 1");
        $stmt->execute();
        $sample = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sample) {
            foreach ($sample as $field => $value) {
                $displayValue = $value;
                if ($value === null) {
                    $displayValue = 'NULL';
                } elseif (strlen($value) > 50) {
                    $displayValue = substr($value, 0, 47) . '...';
                }
                echo sprintf("  %-25s: %s\n", $field, $displayValue);
            }
        } else {
            echo "  No data in table\n";
        }

        echo "\n\n";
    }

    // Additional check: Show CREATE TABLE statements
    echo "CREATE TABLE STATEMENTS\n";
    echo "=======================\n\n";

    foreach ($tables as $tableName) {
        $stmt = $conn->prepare("SHOW CREATE TABLE $tableName");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "-- $tableName\n";
        echo $result['Create Table'] . ";\n\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

ob_end_flush();