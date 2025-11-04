<?php
/**
 * Execute Workflow Activation System Migration
 *
 * This script executes the workflow_activation_system.sql migration
 * Run from browser: http://localhost:8888/CollaboraNexio/run_workflow_activation_migration.php
 */

require_once __DIR__ . '/includes/db.php';

// Security: Only allow execution from localhost
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
    die('❌ Migration can only be run from localhost');
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Workflow Activation Migration</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4}";
echo ".success{color:#4ec9b0}.error{color:#f48771}.warning{color:#ce9178}</style></head><body>";

echo "<h1>🚀 Workflow Activation System Migration</h1>\n";
echo "<p>Reading migration file...</p>\n";

// Read migration SQL file
$migrationFile = __DIR__ . '/database/migrations/workflow_activation_system.sql';

if (!file_exists($migrationFile)) {
    echo "<p class='error'>❌ Migration file not found: $migrationFile</p>";
    die();
}

$sql = file_get_contents($migrationFile);

if (!$sql) {
    echo "<p class='error'>❌ Failed to read migration file</p>";
    die();
}

echo "<p class='success'>✅ Migration file loaded (" . strlen($sql) . " bytes)</p>\n";

// Get database connection
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "<p class='success'>✅ Database connection established</p>\n";

    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            // Remove comments and empty lines
            $lines = array_filter(
                array_map('trim', explode("\n", $stmt)),
                function($line) {
                    return $line && !str_starts_with($line, '--') && !str_starts_with($line, '/*');
                }
            );
            return !empty($lines);
        }
    );

    echo "<p>Found " . count($statements) . " SQL statements to execute</p>\n";

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    // Execute each statement
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;

        try {
            // Show what we're executing (first 100 chars)
            $preview = substr($statement, 0, 100);
            echo "<p>Executing statement " . ($index + 1) . ": " . htmlspecialchars($preview) . "...</p>\n";
            flush();

            $pdo->exec($statement);
            $successCount++;
            echo "<p class='success'>  ✅ Success</p>\n";

        } catch (PDOException $e) {
            $errorCount++;
            $errors[] = [
                'statement' => $index + 1,
                'preview' => $preview,
                'error' => $e->getMessage()
            ];
            echo "<p class='error'>  ❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";

            // Continue with other statements (some errors might be acceptable, like "table already exists")
            if (str_contains($e->getMessage(), 'already exists')) {
                echo "<p class='warning'>  ⚠️  Continuing anyway (object already exists)</p>\n";
            }
        }
        flush();
    }

    echo "\n<hr>\n<h2>📊 Migration Summary</h2>\n";
    echo "<p class='success'>✅ Successful statements: $successCount</p>\n";

    if ($errorCount > 0) {
        echo "<p class='error'>❌ Failed statements: $errorCount</p>\n";
        echo "<h3>Error Details:</h3>\n";
        foreach ($errors as $error) {
            echo "<p class='error'>";
            echo "Statement " . $error['statement'] . ": " . htmlspecialchars($error['preview']) . "...<br>";
            echo "Error: " . htmlspecialchars($error['error']);
            echo "</p>\n";
        }
    } else {
        echo "<p class='success'>✅ No errors!</p>\n";
    }

    // Verify migration
    echo "\n<hr>\n<h2>🔍 Verification</h2>\n";

    // Check if table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'workflow_settings'")->fetch();
    if ($tableCheck) {
        echo "<p class='success'>✅ Table 'workflow_settings' created successfully</p>\n";

        // Get column count
        $columns = $pdo->query("DESCRIBE workflow_settings")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p class='success'>✅ Table has " . count($columns) . " columns</p>\n";

        // Check function
        try {
            $funcCheck = $pdo->query("SHOW FUNCTION STATUS WHERE Name = 'get_workflow_enabled_for_folder'")->fetch();
            if ($funcCheck) {
                echo "<p class='success'>✅ Function 'get_workflow_enabled_for_folder' created successfully</p>\n";
            } else {
                echo "<p class='warning'>⚠️  Function 'get_workflow_enabled_for_folder' not found</p>\n";
            }
        } catch (PDOException $e) {
            echo "<p class='warning'>⚠️  Could not check function: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }

        // Check indexes
        $indexes = $pdo->query("SHOW INDEX FROM workflow_settings")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p class='success'>✅ Table has " . count($indexes) . " indexes</p>\n";

    } else {
        echo "<p class='error'>❌ Table 'workflow_settings' NOT found</p>\n";
    }

    echo "\n<hr>\n<h2>✅ Migration Complete</h2>\n";
    echo "<p>You can now use the workflow activation system.</p>\n";
    echo "<p><a href='files.php'>Go to File Manager</a></p>\n";

} catch (Exception $e) {
    echo "<p class='error'>❌ Fatal error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "</body></html>";
