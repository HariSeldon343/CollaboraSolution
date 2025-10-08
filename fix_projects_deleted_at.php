<?php
/**
 * Quick fix: Add deleted_at column to projects table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';

echo "Adding deleted_at column to projects table...\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

try {
    // Check if column exists
    $stmt = $conn->query("SHOW COLUMNS FROM projects LIKE 'deleted_at'");
    $exists = $stmt->fetch();

    if ($exists) {
        echo "✅ Column deleted_at already exists in projects table\n";
    } else {
        // Add the column
        $sql = "ALTER TABLE projects ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at";
        $conn->exec($sql);
        echo "✅ Successfully added deleted_at column to projects table\n";

        // Add index for performance
        $indexSql = "ALTER TABLE projects ADD INDEX idx_projects_deleted_at (deleted_at)";
        $conn->exec($indexSql);
        echo "✅ Added index on deleted_at column\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Fix completed successfully!\n";
