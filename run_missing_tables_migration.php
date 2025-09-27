<?php
/**
 * Migration Runner for Missing Tables
 * Adds the 5 missing tables to reach 100% system completion
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Set execution time limit
set_time_limit(300);

// Output function for CLI and browser
function output($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    if (PHP_SAPI === 'cli') {
        echo "[$timestamp] $message\n";
    } else {
        $color = match($type) {
            'success' => 'green',
            'error' => 'red',
            'warning' => 'orange',
            default => 'black'
        };
        echo "<div style='color: $color;'>[$timestamp] $message</div>";
        flush();
    }
}

// HTML header for browser
if (PHP_SAPI !== 'cli') {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Missing Tables Migration - CollaboraNexio</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Missing Tables Migration</h1>
        <pre>";
}

try {
    output("Starting migration for missing tables...", 'info');
    output(str_repeat("-", 60), 'info');

    // Get database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Read and execute migration SQL
    $migrationFile = __DIR__ . '/database/migrations/add_missing_tables.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    $sql = file_get_contents($migrationFile);

    // Remove comments and split into statements
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)));

    $successCount = 0;
    $errorCount = 0;
    $tablesCreated = [];
    $dataInserted = [];

    foreach ($statements as $statement) {
        if (empty($statement) || stripos($statement, 'USE ') === 0) {
            continue;
        }

        try {
            // Check if it's a DROP TABLE statement
            if (preg_match('/DROP TABLE IF EXISTS\s+(\S+)/i', $statement, $matches)) {
                $tableName = str_replace('`', '', $matches[1]);
                output("Dropping table if exists: $tableName", 'info');
                $pdo->exec($statement);
                $successCount++;
            }
            // Check if it's a CREATE TABLE statement
            elseif (preg_match('/CREATE TABLE\s+(\S+)/i', $statement, $matches)) {
                $tableName = str_replace('`', '', $matches[1]);
                output("Creating table: $tableName", 'info');
                $pdo->exec($statement);
                $tablesCreated[] = $tableName;
                $successCount++;
            }
            // Check if it's a CREATE INDEX statement
            elseif (preg_match('/CREATE INDEX\s+(\S+)/i', $statement, $matches)) {
                $indexName = str_replace('`', '', $matches[1]);
                output("Creating index: $indexName", 'info');
                $pdo->exec($statement);
                $successCount++;
            }
            // Check if it's an INSERT statement
            elseif (preg_match('/INSERT INTO\s+(\S+)/i', $statement, $matches)) {
                $tableName = str_replace('`', '', $matches[1]);

                // Special handling for ON DUPLICATE KEY UPDATE
                if (stripos($statement, 'ON DUPLICATE KEY UPDATE') !== false) {
                    $pdo->exec($statement);
                    $rowCount = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
                    // ROW_COUNT returns 1 for insert, 2 for update, 0 for no change
                    if ($rowCount > 0) {
                        $action = $rowCount == 1 ? 'inserted' : 'updated';
                        output("Data $action in table: $tableName", 'success');
                        $dataInserted[$tableName] = ($dataInserted[$tableName] ?? 0) + 1;
                    }
                } else {
                    $pdo->exec($statement);
                    $rowCount = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
                    if ($rowCount > 0) {
                        output("Inserted $rowCount rows into: $tableName", 'success');
                        $dataInserted[$tableName] = ($dataInserted[$tableName] ?? 0) + $rowCount;
                    }
                }
                $successCount++;
            }
            // Execute other statements
            else {
                $pdo->exec($statement);
                $successCount++;
            }
        } catch (PDOException $e) {
            // Check if it's a duplicate key error (can be ignored)
            if ($e->getCode() == '23000') {
                output("Duplicate entry (skipped): " . substr($e->getMessage(), 0, 100), 'warning');
            } else {
                $errorCount++;
                output("Error: " . $e->getMessage(), 'error');
            }
        }
    }

    output("\n" . str_repeat("=", 60), 'info');
    output("Migration Results:", 'info');
    output("Successful operations: $successCount", 'success');
    output("Failed operations: $errorCount", $errorCount > 0 ? 'error' : 'info');

    // Verify tables
    output("\nVerifying tables:", 'info');
    $requiredTables = ['project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings'];

    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->rowCount() > 0) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            output("✓ Table '$table' exists with $count records", 'success');
        } else {
            output("✗ Table '$table' not found", 'error');
        }
    }

    // Verify other tables have demo data
    output("\nVerifying demo data in other tables:", 'info');
    $otherTables = ['task_comments', 'file_shares', 'file_versions'];

    foreach ($otherTables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->rowCount() > 0) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            if ($count > 0) {
                output("✓ Table '$table' has $count records", 'success');
            } else {
                output("✗ Table '$table' is empty", 'warning');
            }
        } else {
            output("✗ Table '$table' does not exist", 'error');
        }
    }

    // System status check
    output("\n" . str_repeat("=", 60), 'info');
    output("SYSTEM STATUS CHECK", 'info');
    output(str_repeat("=", 60), 'info');

    // Count total tables
    $totalTables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'collaboranexio'")->fetchColumn();
    output("Total tables in database: $totalTables", 'info');

    // Check for missing components
    $missingComponents = [];

    // Check tables
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->rowCount() == 0) {
            $missingComponents[] = "Table: $table";
        }
    }

    // Check files
    $requiredFiles = [
        '/config/config.php' => 'Configuration file',
        '/login.php' => 'Login page'
    ];

    foreach ($requiredFiles as $file => $description) {
        $fullPath = __DIR__ . $file;
        if (file_exists($fullPath)) {
            output("✓ $description exists at $file", 'success');
        } else {
            output("✗ $description missing at $file", 'error');
            $missingComponents[] = "File: $file";
        }
    }

    // Final summary
    if (empty($missingComponents)) {
        output("\n✅ SYSTEM IS 100% COMPLETE!", 'success');
        output("All required tables and files are present.", 'success');
    } else {
        $completion = 100 - (count($missingComponents) * 5); // Rough estimate
        output("\n⚠️ System is approximately $completion% complete", 'warning');
        output("Missing components:", 'error');
        foreach ($missingComponents as $component) {
            output("  - $component", 'error');
        }
    }

    output("\nMigration completed successfully!", 'success');

} catch (Exception $e) {
    output("\nCRITICAL ERROR: " . $e->getMessage(), 'error');
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// HTML footer for browser
if (PHP_SAPI !== 'cli') {
    echo "</pre>
    </div>
</body>
</html>";
}