<?php
/**
 * Database Connection Test Page
 *
 * Use this page to test database connectivity and troubleshoot issues
 */

// Suppress PHP errors from displaying directly
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Output as HTML for better formatting
header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Database Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 800px;
            width: 100%;
            padding: 30px;
        }

        h1 {
            color: #333;
            margin-top: 0;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }

        .test-section {
            margin: 20px 0;
            padding: 15px;
            border-left: 4px solid #e0e0e0;
            background: #f9f9f9;
        }

        .success {
            border-left-color: #4caf50;
            background: #e8f5e9;
        }

        .error {
            border-left-color: #f44336;
            background: #ffebee;
        }

        .warning {
            border-left-color: #ff9800;
            background: #fff3e0;
        }

        .info {
            border-left-color: #2196f3;
            background: #e3f2fd;
        }

        .test-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .test-result {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #555;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
            transition: background 0.3s;
        }

        .button:hover {
            background: #5a67d8;
        }

        .button.secondary {
            background: #48bb78;
        }

        .button.secondary:hover {
            background: #38a169;
        }

        code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f0f0f0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>CollaboraNexio - Database Connection Test</h1>

        <?php
        // Load configuration
        $configFile = __DIR__ . '/config.php';

        echo '<div class="test-section">';
        echo '<div class="test-title">1. Configuration File Check</div>';
        echo '<div class="test-result">';

        if (file_exists($configFile)) {
            require_once $configFile;
            echo '✅ Configuration file found: <code>' . $configFile . '</code>';

            // Display connection parameters (hide password)
            echo '<table>';
            echo '<tr><th>Parameter</th><th>Value</th></tr>';
            echo '<tr><td>DB_HOST</td><td>' . (defined('DB_HOST') ? DB_HOST : 'Not defined') . '</td></tr>';
            echo '<tr><td>DB_PORT</td><td>' . (defined('DB_PORT') ? DB_PORT : 'Not defined') . '</td></tr>';
            echo '<tr><td>DB_NAME</td><td>' . (defined('DB_NAME') ? DB_NAME : 'Not defined') . '</td></tr>';
            echo '<tr><td>DB_USER</td><td>' . (defined('DB_USER') ? DB_USER : 'Not defined') . '</td></tr>';
            echo '<tr><td>DB_PASS</td><td>' . (defined('DB_PASS') ? (DB_PASS ? '****** (hidden)' : 'Empty') : 'Not defined') . '</td></tr>';
            echo '<tr><td>DEBUG_MODE</td><td>' . (defined('DEBUG_MODE') ? (DEBUG_MODE ? 'ON' : 'OFF') : 'Not defined') . '</td></tr>';
            echo '</table>';

            $configOk = true;
        } else {
            echo '❌ Configuration file not found: <code>' . $configFile . '</code>';
            echo '<br><br>Please create the config.php file with your database settings.';
            $configOk = false;
        }

        echo '</div>';
        echo '</div>';

        if ($configOk) {
            // Test 2: MySQL Connection
            echo '<div class="test-section">';
            echo '<div class="test-title">2. MySQL Server Connection</div>';
            echo '<div class="test-result">';

            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                echo '✅ Successfully connected to MySQL server<br>';

                // Get MySQL version
                $version = $pdo->query("SELECT VERSION()")->fetchColumn();
                echo '📊 MySQL Version: <code>' . $version . '</code>';

                $mysqlOk = true;
            } catch (PDOException $e) {
                echo '❌ Failed to connect to MySQL server<br>';
                echo '🔍 Error: <code>' . htmlspecialchars($e->getMessage()) . '</code><br><br>';

                echo '<strong>Possible solutions:</strong><br>';
                echo '• Ensure MySQL/MariaDB is running<br>';
                echo '• Check hostname and port are correct<br>';
                echo '• Verify username and password<br>';

                $mysqlOk = false;
            }

            echo '</div>';
            echo '</div>';

            if ($mysqlOk) {
                // Test 3: Database Check
                echo '<div class="test-section">';
                echo '<div class="test-title">3. Database Check</div>';
                echo '<div class="test-result">';

                try {
                    // Check if database exists
                    $dbName = DB_NAME;
                    $result = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbName'")->fetch();

                    if ($result) {
                        echo '✅ Database <code>' . $dbName . '</code> exists<br>';

                        // Connect to the database
                        $pdo->exec("USE `$dbName`");

                        // Check tables
                        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                        echo '📁 Tables found: <code>' . count($tables) . '</code><br>';

                        if (count($tables) > 0) {
                            echo '<br><strong>Existing tables:</strong><br>';
                            foreach ($tables as $table) {
                                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                                echo '• <code>' . $table . '</code> (' . $count . ' records)<br>';
                            }
                            $dbReady = true;
                        } else {
                            echo '<br>⚠️ Database exists but has no tables';
                            $dbReady = false;
                        }

                    } else {
                        echo '⚠️ Database <code>' . $dbName . '</code> does not exist<br>';
                        echo 'The database will be created automatically on first use.';
                        $dbReady = false;
                    }

                } catch (PDOException $e) {
                    echo '❌ Error checking database: <code>' . htmlspecialchars($e->getMessage()) . '</code>';
                    $dbReady = false;
                }

                echo '</div>';
                echo '</div>';

                // Test 4: Database Class
                echo '<div class="test-section">';
                echo '<div class="test-title">4. Database Class Test</div>';
                echo '<div class="test-result">';

                try {
                    require_once __DIR__ . '/includes/db.php';

                    $db = Database::getInstance();
                    echo '✅ Database class loaded successfully<br>';

                    $conn = $db->getConnection();
                    echo '✅ Database connection established<br>';

                    // Test a simple query if tables exist
                    if ($dbReady && in_array('users', $tables)) {
                        $userCount = $db->count('users');
                        echo '📊 User count: <code>' . $userCount . '</code><br>';

                        if ($userCount > 0) {
                            $testUser = $db->fetchOne("SELECT email, role FROM users LIMIT 1");
                            echo '👤 Sample user: <code>' . $testUser['email'] . '</code> (Role: ' . $testUser['role'] . ')';
                        }
                    }

                    $classOk = true;

                } catch (Exception $e) {
                    echo '❌ Database class error: <code>' . htmlspecialchars($e->getMessage()) . '</code>';
                    $classOk = false;
                }

                echo '</div>';
                echo '</div>';

                // Test 5: API Response Handler
                echo '<div class="test-section">';
                echo '<div class="test-title">5. API Response Handler Test</div>';
                echo '<div class="test-result">';

                if (file_exists(__DIR__ . '/includes/api_response.php')) {
                    echo '✅ API Response handler found<br>';

                    // Test that it doesn't output anything immediately
                    ob_start();
                    require_once __DIR__ . '/includes/api_response.php';
                    $output = ob_get_clean();

                    if (empty($output)) {
                        echo '✅ API handler loads without output<br>';
                        echo '✅ Functions available: <code>api_response()</code>, <code>api_error()</code>, <code>api_success()</code>';
                    } else {
                        echo '⚠️ API handler produces unexpected output';
                    }
                } else {
                    echo '❌ API Response handler not found';
                }

                echo '</div>';
                echo '</div>';
            }
        }

        // Summary and Actions
        echo '<div class="test-section ' . ($dbReady && $classOk ? 'success' : 'info') . '">';
        echo '<div class="test-title">📋 Summary</div>';
        echo '<div class="test-result">';

        if (isset($dbReady) && isset($classOk) && $dbReady && $classOk) {
            echo '<strong>✅ System is ready!</strong><br>';
            echo 'Your database is configured and working correctly.';
        } else {
            echo '<strong>⚠️ Setup Required</strong><br>';
            echo 'Your system needs some configuration before it can be used.';
        }

        echo '</div>';
        echo '</div>';

        // Action buttons
        echo '<div style="text-align: center; margin-top: 30px;">';

        if (!isset($dbReady) || !$dbReady) {
            echo '<a href="/CollaboraNexio/setup/init_database.php" class="button secondary">Initialize Database</a>';
        }

        echo '<a href="/CollaboraNexio/login.php" class="button">Go to Login</a>';
        echo '<a href="/CollaboraNexio/pages/users.php" class="button">User Management</a>';

        echo '</div>';

        // Instructions
        echo '<div class="test-section info" style="margin-top: 30px;">';
        echo '<div class="test-title">📖 Setup Instructions</div>';
        echo '<div class="test-result">';
        echo '<ol>';
        echo '<li>Ensure MySQL/MariaDB is running on your system</li>';
        echo '<li>Verify database credentials in <code>/config.php</code></li>';
        echo '<li>Run <a href="/CollaboraNexio/setup/init_database.php">Database Initialization</a> to create tables and test data</li>';
        echo '<li>Login with test credentials:<br>';
        echo '   • Email: <code>admin@demo.local</code><br>';
        echo '   • Password: <code>password123</code></li>';
        echo '<li>Access the <a href="/CollaboraNexio/pages/users.php">User Management</a> page to manage users</li>';
        echo '</ol>';
        echo '</div>';
        echo '</div>';

        ?>
    </div>
</body>
</html>