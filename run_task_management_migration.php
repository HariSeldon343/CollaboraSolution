<?php
/**
 * Task Management Schema Migration Runner
 *
 * This script executes the task management database migration
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "========================================\n";
echo "Task Management Schema Migration\n";
echo "========================================\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Enable buffered queries to avoid "commands out of sync" error
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    // Read the migration file
    $migrationFile = __DIR__ . '/database/migrations/task_management_schema.sql';

    if (!file_exists($migrationFile)) {
        die("ERROR: Migration file not found at: $migrationFile\n");
    }

    echo "Reading migration file...\n";
    $sql = file_get_contents($migrationFile);

    if ($sql === false) {
        die("ERROR: Could not read migration file\n");
    }

    echo "Migration file loaded (" . strlen($sql) . " bytes)\n\n";

    // Split the SQL file into individual statements
    // We need to handle multi-line statements and delimiter changes
    $statements = [];
    $delimiter = ';';
    $currentStatement = '';
    $lines = explode("\n", $sql);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and comments
        if (empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 1) === '#') {
            continue;
        }

        // Check for delimiter change
        if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }

        $currentStatement .= $line . "\n";

        // Check if statement is complete
        if (substr(rtrim($line), -strlen($delimiter)) === $delimiter) {
            // Remove the delimiter
            $stmt = substr($currentStatement, 0, -strlen($delimiter));
            $stmt = trim($stmt);

            if (!empty($stmt)) {
                $statements[] = $stmt;
            }

            $currentStatement = '';

            // Reset delimiter if it was changed
            if ($delimiter !== ';') {
                $delimiter = ';';
            }
        }
    }

    // Add any remaining statement
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }

    echo "Executing " . count($statements) . " SQL statements...\n\n";

    $success = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($statements as $i => $statement) {
        $statementNum = $i + 1;

        // Get first 50 chars for display
        $preview = substr($statement, 0, 50);
        $preview = str_replace("\n", " ", $preview);

        echo "[$statementNum/" . count($statements) . "] Executing: $preview...\n";

        try {
            // Use query() for SELECT statements to properly free results
            if (stripos(trim($statement), 'SELECT') === 0) {
                $stmt = $pdo->query($statement);
                if ($stmt) {
                    $stmt->closeCursor(); // Free the result set
                }
            } else {
                $pdo->exec($statement);
            }
            echo "  ✓ Success\n";
            $success++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();

            // Check if it's a "table already exists" error - these are OK to skip
            if (strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, 'Duplicate') !== false) {
                echo "  ⚠ Skipped (already exists)\n";
                $skipped++;
            } else {
                echo "  ✗ ERROR: " . $errorMsg . "\n";
                $errors++;

                // For critical errors, we might want to stop
                if (strpos($statement, 'CREATE TABLE') === 0 && $errors > 5) {
                    echo "\nToo many errors, stopping migration.\n";
                    break;
                }
            }
        }

        echo "\n";
    }

    echo "========================================\n";
    echo "Migration Results:\n";
    echo "========================================\n";
    echo "✓ Successful: $success\n";
    echo "⚠ Skipped:    $skipped\n";
    echo "✗ Errors:     $errors\n";
    echo "Total:        " . count($statements) . "\n\n";

    if ($errors === 0) {
        echo "✅ Migration completed successfully!\n\n";

        // Verify tables exist
        echo "Verifying tables...\n";
        $tables = ['tasks', 'task_assignments', 'task_comments', 'task_history'];

        foreach ($tables as $table) {
            $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
            if ($result) {
                echo "  ✓ Table '$table' exists\n";
            } else {
                echo "  ✗ Table '$table' NOT FOUND\n";
            }
        }

        echo "\nVerifying views...\n";
        $views = ['view_orphaned_tasks', 'view_task_summary_by_status', 'view_my_tasks'];

        foreach ($views as $view) {
            $result = $pdo->query("SHOW TABLES LIKE '$view'")->fetch();
            if ($result) {
                echo "  ✓ View '$view' exists\n";
            } else {
                echo "  ✗ View '$view' NOT FOUND\n";
            }
        }

        echo "\nVerifying functions...\n";
        $functions = ['assign_task_to_user', 'get_orphaned_tasks_count'];

        foreach ($functions as $function) {
            $result = $pdo->query("SHOW FUNCTION STATUS WHERE Name = '$function'")->fetch();
            if ($result) {
                echo "  ✓ Function '$function' exists\n";
            } else {
                echo "  ✗ Function '$function' NOT FOUND\n";
            }
        }

    } else {
        echo "⚠ Migration completed with errors. Please review the output above.\n";
    }

} catch (Exception $e) {
    echo "\n========================================\n";
    echo "FATAL ERROR\n";
    echo "========================================\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
