<?php
/**
 * Database Setup Script for CollaboraNexio
 *
 * This script initializes the database with all required tables and demo data.
 * Run this script from the command line or browser to set up your database.
 *
 * Usage:
 *   Command line: php setup_database.php
 *   Browser: http://localhost:8888/CollaboraNexio/setup_database.php
 */

// Prevent timeout for large operations
set_time_limit(300);

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Output as plain text if run from CLI
if (PHP_SAPI === 'cli') {
    header('Content-Type: text/plain');
} else {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>CollaboraNexio - Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e9; border-radius: 5px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #e3f2fd; border-radius: 5px; margin: 10px 0; }
        .warning { color: orange; padding: 10px; background: #fff3e0; border-radius: 5px; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        h1 { color: #333; }
        button { padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #45a049; }
    </style>
</head>
<body>
<h1>CollaboraNexio Database Setup</h1>
";
}

function output($message, $type = 'info') {
    if (PHP_SAPI === 'cli') {
        $prefix = strtoupper($type) . ': ';
        echo $prefix . strip_tags($message) . PHP_EOL;
    } else {
        echo "<div class='$type'>$message</div>\n";
    }
}

// Include configuration
require_once __DIR__ . '/config.php';

output("Starting database setup...", "info");

try {
    // Step 1: Connect to MySQL without specifying database
    output("Connecting to MySQL server...", "info");

    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        output("Connected to MySQL successfully.", "success");
    } catch (PDOException $e) {
        throw new Exception("Failed to connect to MySQL: " . $e->getMessage());
    }

    // Step 2: Create database if it doesn't exist
    output("Creating database '" . DB_NAME . "' if it doesn't exist...", "info");

    $sql = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
            DEFAULT CHARACTER SET utf8mb4
            DEFAULT COLLATE utf8mb4_unicode_ci";

    $pdo->exec($sql);
    output("Database '" . DB_NAME . "' is ready.", "success");

    // Step 3: Select the database
    $pdo->exec("USE `" . DB_NAME . "`");

    // Step 4: Check if tables already exist
    $stmt = $pdo->query("SHOW TABLES");
    $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($existing_tables) > 0) {
        output("Found " . count($existing_tables) . " existing tables in the database.", "warning");
        output("Existing tables: " . implode(", ", $existing_tables), "info");

        if (PHP_SAPI !== 'cli') {
            echo "<div class='warning'>
                <strong>Warning:</strong> The database already contains tables.
                Running this script will DROP and RECREATE all tables, losing all existing data!
                <br><br>
                <form method='post' style='display: inline;'>
                    <input type='hidden' name='confirm_reset' value='1'>
                    <button type='submit' onclick='return confirm(\"Are you sure? This will delete all existing data!\")'>
                        Reset Database (Delete All Data)
                    </button>
                </form>
                <button onclick='window.location.href=\"index.php\"' style='margin-left: 10px; background: #666;'>
                    Cancel
                </button>
            </div>";

            if (!isset($_POST['confirm_reset'])) {
                exit;
            }
        }
    }

    // Step 5: Read and execute SQL file
    output("Reading SQL schema file...", "info");

    $sql_file = __DIR__ . '/database_schema.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("SQL schema file not found: $sql_file");
    }

    $sql_content = file_get_contents($sql_file);
    if (empty($sql_content)) {
        throw new Exception("SQL schema file is empty");
    }

    output("Executing SQL schema...", "info");

    // Split SQL content by semicolons but ignore those within strings
    $queries = preg_split('/;(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $sql_content);

    $success_count = 0;
    $error_count = 0;
    $table_count = 0;

    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) continue;

        // Skip comments and USE statements (we already selected the database)
        if (strpos($query, '--') === 0 || stripos($query, 'USE ') === 0) continue;

        // Skip CREATE DATABASE as we already did that
        if (stripos($query, 'CREATE DATABASE') !== false) continue;

        try {
            $pdo->exec($query);
            $success_count++;

            // Count table creations
            if (stripos($query, 'CREATE TABLE') !== false) {
                $table_count++;
                preg_match('/CREATE TABLE\s+`?(\w+)`?/i', $query, $matches);
                if (isset($matches[1])) {
                    output("Created table: " . $matches[1], "success");
                }
            }
        } catch (PDOException $e) {
            $error_count++;
            output("Error executing query: " . $e->getMessage(), "error");
            if (stripos($query, 'CREATE TABLE') !== false) {
                output("Failed query: " . substr($query, 0, 100) . "...", "error");
            }
        }
    }

    output("Execution completed: $success_count successful queries, $error_count errors",
           $error_count > 0 ? "warning" : "success");

    // Step 6: Verify tables were created
    output("Verifying database setup...", "info");

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    output("Tables created: " . count($tables), "success");

    $required_tables = ['tenants', 'users', 'projects', 'tasks', 'folders', 'files',
                       'chat_channels', 'chat_messages', 'notifications', 'audit_logs'];

    $missing_tables = array_diff($required_tables, $tables);

    if (empty($missing_tables)) {
        output("All required tables are present.", "success");
    } else {
        output("Missing tables: " . implode(", ", $missing_tables), "error");
    }

    // Step 7: Count demo data
    output("Verifying demo data...", "info");

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $user_count = $stmt->fetch()['count'];
    output("Users in database: $user_count", "info");

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $tenant_count = $stmt->fetch()['count'];
    output("Tenants in database: $tenant_count", "info");

    // Step 8: Create a real password hash for testing
    output("Generating proper password hash for demo users...", "info");
    $demo_password = 'Admin123!';
    $password_hash = password_hash($demo_password, PASSWORD_BCRYPT);

    // Update all demo users with proper password hash
    $update_sql = "UPDATE users SET password_hash = :hash WHERE password_hash NOT LIKE '$2y$%'";
    $stmt = $pdo->prepare($update_sql);
    $stmt->execute(['hash' => $password_hash]);
    $updated = $stmt->rowCount();

    if ($updated > 0) {
        output("Updated $updated user passwords with proper hash.", "success");
    }

    // Display login credentials
    output("<strong>Demo Login Credentials:</strong>", "success");
    output("Admin User: admin@demo.local / Admin123!", "info");
    output("Manager User: manager@demo.local / Admin123!", "info");
    output("Regular User: user1@demo.local / Admin123!", "info");

    // Final summary
    output("<h2>Setup Complete!</h2>", "success");
    output("Database '" . DB_NAME . "' has been successfully initialized with:", "success");
    output("- " . count($tables) . " tables", "info");
    output("- $tenant_count tenants", "info");
    output("- $user_count demo users", "info");

    if (PHP_SAPI !== 'cli') {
        echo "<br><div class='success'>
            <h3>Next Steps:</h3>
            <ol>
                <li>Navigate to <a href='index.php'>Login Page</a></li>
                <li>Use demo credentials: admin@demo.local / Admin123!</li>
                <li>Start using CollaboraNexio!</li>
            </ol>
        </div>";
    }

} catch (Exception $e) {
    output("Setup failed: " . $e->getMessage(), "error");

    if (PHP_SAPI !== 'cli') {
        echo "<div class='error'>
            <h3>Troubleshooting:</h3>
            <ol>
                <li>Ensure MySQL/MariaDB is running</li>
                <li>Check database credentials in config.php</li>
                <li>Verify user '" . DB_USER . "' has CREATE DATABASE privileges</li>
                <li>Check MySQL error log for more details</li>
            </ol>
        </div>";
    }
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    echo "</body></html>";
}
?>