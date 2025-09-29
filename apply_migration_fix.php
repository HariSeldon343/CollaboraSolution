<?php
/**
 * Apply Company Deletion Fix Migration
 *
 * This script can be run from browser or command line
 * It applies the database migration to fix company deletion issues
 */

// Allow execution from browser
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if running from browser
$isBrowser = php_sapi_name() !== 'cli';

// Output header for browser
if ($isBrowser) {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Apply Migration Fix</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f7fa;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .success {
            color: #27ae60;
            padding: 10px;
            background: #d4edda;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            color: #e74c3c;
            padding: 10px;
            background: #f8d7da;
            border-radius: 4px;
            margin: 10px 0;
        }
        .info {
            color: #3498db;
            padding: 10px;
            background: #d1ecf1;
            border-radius: 4px;
            margin: 10px 0;
        }
        .warning {
            color: #f39c12;
            padding: 10px;
            background: #fff3cd;
            border-radius: 4px;
            margin: 10px 0;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .button {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 10px 0;
        }
        .button:hover {
            background: #2980b9;
        }
        .button.danger {
            background: #e74c3c;
        }
        .button.danger:hover {
            background: #c0392b;
        }
        .sql-statement {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
            overflow-x: auto;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Apply Company Deletion Fix Migration</h1>";
}

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

function output($message, $type = 'info') {
    global $isBrowser;

    if ($isBrowser) {
        echo "<div class='$type'>$message</div>";
    } else {
        // Terminal colors
        $colors = [
            'success' => "\033[92m",
            'error' => "\033[91m",
            'warning' => "\033[93m",
            'info' => "\033[94m",
            'reset' => "\033[0m"
        ];

        $color = $colors[$type] ?? $colors['info'];
        echo $color . $message . $colors['reset'] . "\n";
    }

    flush();
}

try {
    output("Starting migration process...", "info");

    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if migration was already applied
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'original_tenant_id'");
    if ($stmt->rowCount() > 0) {
        output("⚠️ Migration appears to be already applied (original_tenant_id column exists)", "warning");

        if ($isBrowser && !isset($_GET['force'])) {
            echo "<p><a href='?force=1' class='button danger'>Force Re-apply Migration</a></p>";
            echo "<p><a href='test_company_deletion.php' class='button'>Go to Test Page</a></p>";
            echo "</div></body></html>";
            exit;
        }
    }

    // Read migration file
    $migrationFile = __DIR__ . '/database/07_company_deletion_fix.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    output("Reading migration file...", "info");
    $sql = file_get_contents($migrationFile);

    // Split into individual statements
    $statements = preg_split('/;\s*$/m', $sql);

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    // Execute each statement
    foreach ($statements as $statement) {
        $statement = trim($statement);

        if (empty($statement)) {
            continue;
        }

        // Skip comments and USE statements
        if (strpos($statement, '--') === 0 ||
            strpos($statement, '/*') === 0 ||
            stripos($statement, 'USE ') === 0) {
            continue;
        }

        try {
            // Handle DELIMITER statements specially
            if (stripos($statement, 'DELIMITER') === 0) {
                continue;
            }

            // Show what we're executing (truncated for display)
            $displayStmt = substr($statement, 0, 100);
            if (strlen($statement) > 100) {
                $displayStmt .= '...';
            }

            if ($isBrowser) {
                echo "<div class='sql-statement'>Executing: $displayStmt</div>";
            }

            $conn->exec($statement);
            $successCount++;

        } catch (PDOException $e) {
            $errorCount++;
            $errorMsg = "Error: " . $e->getMessage() . "\nStatement: " . substr($statement, 0, 200);
            $errors[] = $errorMsg;

            // Some errors are expected (like "already exists")
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate column') !== false) {
                output("⚠️ Skipping (already exists): " . $displayStmt, "warning");
            } else {
                output("❌ Failed: " . $e->getMessage(), "error");
            }
        }
    }

    // Verify the migration
    output("\n📊 Verifying migration results...", "info");

    // Check if key changes were applied
    $checks = [
        "SHOW COLUMNS FROM users LIKE 'original_tenant_id'" => "Users table modification",
        "SHOW COLUMNS FROM folders LIKE 'original_tenant_id'" => "Folders table modification",
        "SHOW COLUMNS FROM files LIKE 'original_tenant_id'" => "Files table modification",
        "SELECT * FROM folders WHERE name = 'Orphaned Companies' AND tenant_id IS NULL LIMIT 1" => "Super Admin orphaned folder"
    ];

    $verificationSuccess = true;
    foreach ($checks as $query => $description) {
        try {
            $stmt = $conn->query($query);
            if ($stmt->rowCount() > 0) {
                output("✅ $description - OK", "success");
            } else {
                output("⚠️ $description - Not found", "warning");
                $verificationSuccess = false;
            }
        } catch (PDOException $e) {
            output("❌ $description - Failed: " . $e->getMessage(), "error");
            $verificationSuccess = false;
        }
    }

    // Summary
    output("\n📈 Migration Summary", "info");
    output("✅ Successful statements: $successCount", "success");

    if ($errorCount > 0) {
        output("❌ Failed statements: $errorCount", "error");
        if (!empty($errors) && $isBrowser) {
            echo "<details><summary>View Errors</summary><pre>";
            foreach ($errors as $error) {
                echo htmlspecialchars($error) . "\n\n";
            }
            echo "</pre></details>";
        }
    }

    if ($verificationSuccess) {
        output("\n🎉 Migration completed successfully!", "success");
        output("The company deletion fix has been applied to your database.", "success");
    } else {
        output("\n⚠️ Migration completed with warnings", "warning");
        output("Some features may not work as expected. Please review the warnings above.", "warning");
    }

    if ($isBrowser) {
        echo "<h2>Next Steps:</h2>";
        echo "<ul>";
        echo "<li>Test company deletion functionality</li>";
        echo "<li>Verify that folders are reassigned correctly</li>";
        echo "<li>Check user authentication behavior</li>";
        echo "</ul>";
        echo "<p><a href='test_company_deletion.php' class='button'>Go to Test Page</a></p>";
        echo "<p><a href='aziende.php' class='button'>Go to Companies Page</a></p>";
    }

} catch (Exception $e) {
    output("❌ Critical Error: " . $e->getMessage(), "error");

    if ($isBrowser) {
        echo "<p>Please check your database configuration and try again.</p>";
    }
}

if ($isBrowser) {
    echo "</div></body></html>";
}
?>