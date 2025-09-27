<?php
/**
 * Database Connection Test Script
 *
 * Tests database connectivity and displays current status
 */

// Suppress PHP errors from displaying directly
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start output buffering to catch any errors
ob_start();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e9; border-radius: 5px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #e3f2fd; border-radius: 5px; margin: 10px 0; }
        .warning { color: orange; padding: 10px; background: #fff3e0; border-radius: 5px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>CollaboraNexio - Database Connection Test</h1>

<?php

try {
    // Include configuration
    require_once __DIR__ . '/config.php';

    echo "<div class='info'>Configuration loaded successfully</div>";

    // Display configuration (without password)
    echo "<h2>Database Configuration:</h2>";
    echo "<table>";
    echo "<tr><th>Setting</th><th>Value</th></tr>";
    echo "<tr><td>Host</td><td>" . DB_HOST . "</td></tr>";
    echo "<tr><td>Port</td><td>" . DB_PORT . "</td></tr>";
    echo "<tr><td>Database</td><td>" . DB_NAME . "</td></tr>";
    echo "<tr><td>User</td><td>" . DB_USER . "</td></tr>";
    echo "<tr><td>Password</td><td>" . (empty(DB_PASS) ? '(empty)' : '***') . "</td></tr>";
    echo "<tr><td>Charset</td><td>" . DB_CHARSET . "</td></tr>";
    echo "</table>";

    // Test 1: Try to connect to MySQL server (without database)
    echo "<h2>Connection Tests:</h2>";

    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        echo "<div class='success'>✓ Connected to MySQL server successfully</div>";

        // Get MySQL version
        $version = $pdo->query('SELECT VERSION() as version')->fetch();
        echo "<div class='info'>MySQL Version: " . $version['version'] . "</div>";

    } catch (PDOException $e) {
        echo "<div class='error'>✗ Failed to connect to MySQL server: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<div class='warning'>Make sure XAMPP MySQL service is running</div>";
        throw $e;
    }

    // Test 2: Check if database exists
    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([DB_NAME]);
    $dbExists = $stmt->fetch();

    if ($dbExists) {
        echo "<div class='success'>✓ Database '" . DB_NAME . "' exists</div>";

        // Use the database
        $pdo->exec("USE `" . DB_NAME . "`");

        // Test 3: Check tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($tables) > 0) {
            echo "<div class='success'>✓ Found " . count($tables) . " tables in database</div>";

            echo "<h3>Existing Tables:</h3>";
            echo "<ul>";
            foreach ($tables as $table) {
                // Get row count for each table
                try {
                    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                    echo "<li>$table (" . $count . " rows)</li>";
                } catch (Exception $e) {
                    echo "<li>$table (error counting rows)</li>";
                }
            }
            echo "</ul>";

            // Check specific required tables
            $requiredTables = ['users', 'tenants', 'audit_logs'];
            $missingTables = array_diff($requiredTables, $tables);

            if (empty($missingTables)) {
                echo "<div class='success'>✓ All required tables present</div>";

                // Test user table
                try {
                    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                    echo "<div class='info'>Users in database: $userCount</div>";

                    if ($userCount > 0) {
                        echo "<h3>Sample Users:</h3>";
                        $users = $pdo->query("SELECT email, first_name, last_name, role, status FROM users LIMIT 5")->fetchAll();
                        echo "<table>";
                        echo "<tr><th>Email</th><th>Name</th><th>Role</th><th>Status</th></tr>";
                        foreach ($users as $user) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
                            echo "<td>" . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($user['role']) . "</td>";
                            echo "<td>" . htmlspecialchars($user['status']) . "</td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                    }
                } catch (Exception $e) {
                    echo "<div class='warning'>Could not query users table: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                echo "<div class='warning'>Missing required tables: " . implode(', ', $missingTables) . "</div>";
                echo "<div class='info'>Run <a href='setup_database.php'>setup_database.php</a> to create missing tables</div>";
            }
        } else {
            echo "<div class='warning'>Database exists but contains no tables</div>";
            echo "<div class='info'>Run <a href='setup_database.php'>setup_database.php</a> to create tables</div>";
        }
    } else {
        echo "<div class='warning'>Database '" . DB_NAME . "' does not exist</div>";
        echo "<div class='info'>Run <a href='setup_database.php'>setup_database.php</a> to create the database and tables</div>";
    }

    // Test 4: Test Database class
    echo "<h2>Testing Database Class:</h2>";

    try {
        require_once __DIR__ . '/includes/db.php';
        $db = Database::getInstance();
        echo "<div class='success'>✓ Database class loaded and instantiated successfully</div>";

        // Test a simple query
        $result = $db->fetchOne("SELECT 1 as test");
        if ($result && $result['test'] == 1) {
            echo "<div class='success'>✓ Database class query execution successful</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>✗ Database class error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    // Test 5: Test API response handler
    echo "<h2>Testing API Response Handler:</h2>";

    if (file_exists(__DIR__ . '/includes/api_response.php')) {
        echo "<div class='success'>✓ API response handler file exists</div>";
    } else {
        echo "<div class='error'>✗ API response handler file not found</div>";
    }

    // Final summary
    echo "<h2>Summary:</h2>";
    echo "<div class='success'>";
    echo "<strong>Database connectivity test completed!</strong><br>";
    echo "Your system is " . ($dbExists && count($tables ?? []) > 0 ? "ready" : "almost ready") . " to use.";
    echo "</div>";

    if (!$dbExists || count($tables ?? []) == 0) {
        echo "<div class='info'>";
        echo "<strong>Next Step:</strong> <a href='setup_database.php' class='button'>Run Database Setup</a>";
        echo "</div>";
    } else {
        echo "<div class='info'>";
        echo "<strong>Next Step:</strong> <a href='index.php' class='button'>Go to Login Page</a>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>Connection Failed!</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";

    echo "<div class='warning'>";
    echo "<h3>Troubleshooting Steps:</h3>";
    echo "<ol>";
    echo "<li>Make sure XAMPP is running</li>";
    echo "<li>Verify MySQL/MariaDB service is started in XAMPP Control Panel</li>";
    echo "<li>Check that MySQL is running on port " . DB_PORT . "</li>";
    echo "<li>Verify the database credentials in config.php</li>";
    echo "<li>Try accessing phpMyAdmin to verify MySQL is working</li>";
    echo "</ol>";
    echo "</div>";
}

// Clear output buffer and display
ob_end_flush();
?>

</body>
</html>