<?php
// Minimal diagnostic page - no dependencies
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start output
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - System Diagnostic</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .success {
            color: #27ae60;
            font-weight: bold;
        }
        .error {
            color: #e74c3c;
            font-weight: bold;
        }
        .warning {
            color: #f39c12;
            font-weight: bold;
        }
        .info {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 3px;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #34495e;
            color: white;
        }
        pre {
            background: #2c3e50;
            color: #1abc9c;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
        }
        .button:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>🔧 CollaboraNexio System Diagnostic</h1>
    <p>Complete system health check and troubleshooting</p>
</div>

<?php
// 1. PHP Environment Check
echo '<div class="section">';
echo '<h2>1. PHP Environment</h2>';

$php_ok = true;

// PHP Version
$php_version = phpversion();
$required_version = '7.4.0';
echo '<p>PHP Version: <strong>' . $php_version . '</strong> ';
if (version_compare($php_version, $required_version, '>=')) {
    echo '<span class="success">✓ OK</span>';
} else {
    echo '<span class="error">✗ Requires PHP ' . $required_version . ' or higher</span>';
    $php_ok = false;
}
echo '</p>';

// Server Software
echo '<p>Server Software: <strong>' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</strong></p>';

// Document Root
echo '<p>Document Root: <strong>' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . '</strong></p>';

// Current Directory
echo '<p>Current Directory: <strong>' . __DIR__ . '</strong></p>';

// Script Name
echo '<p>Script Path: <strong>' . ($_SERVER['SCRIPT_FILENAME'] ?? 'Unknown') . '</strong></p>';

echo '</div>';

// 2. Required PHP Extensions
echo '<div class="section">';
echo '<h2>2. PHP Extensions</h2>';

$required_extensions = [
    'pdo' => 'Database connectivity',
    'pdo_mysql' => 'MySQL database driver',
    'mysqli' => 'MySQL improved extension',
    'session' => 'Session management',
    'json' => 'JSON processing',
    'mbstring' => 'Multibyte string handling',
    'openssl' => 'SSL/TLS support',
    'curl' => 'HTTP requests',
    'gd' => 'Image processing',
    'fileinfo' => 'File type detection',
    'zip' => 'ZIP file handling'
];

echo '<table>';
echo '<tr><th>Extension</th><th>Purpose</th><th>Status</th></tr>';

foreach ($required_extensions as $ext => $purpose) {
    echo '<tr>';
    echo '<td><strong>' . $ext . '</strong></td>';
    echo '<td>' . $purpose . '</td>';
    echo '<td>';
    if (extension_loaded($ext)) {
        echo '<span class="success">✓ Installed</span>';
    } else {
        echo '<span class="error">✗ Missing</span>';
        $php_ok = false;
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table>';

echo '</div>';

// 3. File System Check
echo '<div class="section">';
echo '<h2>3. File System</h2>';

$directories_to_check = [
    'api' => 'API endpoints',
    'includes' => 'Include files',
    'assets' => 'Static assets',
    'uploads' => 'Upload directory',
    'logs' => 'Log files'
];

echo '<table>';
echo '<tr><th>Directory</th><th>Purpose</th><th>Exists</th><th>Writable</th></tr>';

foreach ($directories_to_check as $dir => $purpose) {
    $path = __DIR__ . '/' . $dir;
    echo '<tr>';
    echo '<td><strong>' . $dir . '/</strong></td>';
    echo '<td>' . $purpose . '</td>';
    echo '<td>';
    if (is_dir($path)) {
        echo '<span class="success">✓ Yes</span>';
    } else {
        echo '<span class="warning">✗ No</span>';
    }
    echo '</td>';
    echo '<td>';
    if (is_dir($path) && is_writable($path)) {
        echo '<span class="success">✓ Yes</span>';
    } elseif (is_dir($path)) {
        echo '<span class="error">✗ No</span>';
    } else {
        echo '-';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table>';

// Check critical files
echo '<h3>Critical Files</h3>';
$critical_files = [
    'config.php' => 'Configuration file',
    'index.php' => 'Main entry point',
    '.htaccess' => 'Apache configuration'
];

echo '<table>';
echo '<tr><th>File</th><th>Purpose</th><th>Status</th></tr>';
foreach ($critical_files as $file => $purpose) {
    $path = __DIR__ . '/' . $file;
    echo '<tr>';
    echo '<td><strong>' . $file . '</strong></td>';
    echo '<td>' . $purpose . '</td>';
    echo '<td>';
    if (file_exists($path)) {
        echo '<span class="success">✓ Found</span>';
        if (is_readable($path)) {
            echo ' <span class="success">(Readable)</span>';
        } else {
            echo ' <span class="error">(Not Readable)</span>';
        }
    } else {
        echo '<span class="warning">✗ Not Found</span>';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table>';

echo '</div>';

// 4. Database Connection Test
echo '<div class="section">';
echo '<h2>4. Database Connection</h2>';

$config_file = __DIR__ . '/config.php';
$db_connected = false;

if (file_exists($config_file)) {
    // Safely include config
    $old_error_reporting = error_reporting(0);
    @include $config_file;
    error_reporting($old_error_reporting);

    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        echo '<p>Configuration found. Testing connection...</p>';
        echo '<div class="info">';
        echo 'Host: <strong>' . DB_HOST . '</strong><br>';
        echo 'Database: <strong>' . DB_NAME . '</strong><br>';
        echo 'User: <strong>' . DB_USER . '</strong><br>';
        echo '</div>';

        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            echo '<p class="success">✓ Database connection successful!</p>';
            $db_connected = true;

            // Test query
            $stmt = $pdo->query("SELECT VERSION() as version");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo '<p>MySQL Version: <strong>' . $result['version'] . '</strong></p>';

            // Check tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo '<p>Found <strong>' . count($tables) . '</strong> tables in database</p>';

            if (!empty($tables)) {
                echo '<div class="info">';
                echo '<strong>Tables:</strong> ' . implode(', ', array_slice($tables, 0, 10));
                if (count($tables) > 10) {
                    echo ' ... and ' . (count($tables) - 10) . ' more';
                }
                echo '</div>';
            }

        } catch (PDOException $e) {
            echo '<p class="error">✗ Database connection failed!</p>';
            echo '<div class="info">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        echo '<p class="error">✗ Database configuration constants not defined</p>';
        echo '<p>Check your config.php file for DB_HOST, DB_NAME, DB_USER, DB_PASS</p>';
    }
} else {
    echo '<p class="error">✗ config.php not found!</p>';
    echo '<p>Create a config.php file with your database credentials.</p>';
}

echo '</div>';

// 5. Session Check
echo '<div class="section">';
echo '<h2>5. Session Management</h2>';

$session_ok = true;

// Session save path
$save_path = session_save_path();
echo '<p>Session Save Path: <strong>' . ($save_path ?: 'Default') . '</strong> ';
if (empty($save_path) || is_writable($save_path)) {
    echo '<span class="success">✓ Writable</span>';
} else {
    echo '<span class="error">✗ Not Writable</span>';
    $session_ok = false;
}
echo '</p>';

// Try to start session
if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

echo '<p>Session Status: ';
switch(session_status()) {
    case PHP_SESSION_DISABLED:
        echo '<span class="error">✗ Sessions Disabled</span>';
        $session_ok = false;
        break;
    case PHP_SESSION_NONE:
        echo '<span class="warning">⚠ No Session Started</span>';
        break;
    case PHP_SESSION_ACTIVE:
        echo '<span class="success">✓ Session Active</span>';
        echo '</p>';
        echo '<p>Session ID: <strong>' . session_id() . '</strong></p>';
        break;
}
echo '</p>';

echo '</div>';

// 6. Error Logs
echo '<div class="section">';
echo '<h2>6. Recent PHP Errors</h2>';

$error_log = ini_get('error_log');
echo '<p>Error Log Location: <strong>' . ($error_log ?: 'Not configured') . '</strong></p>';

// Check for Apache error log
$apache_log = 'C:/xampp/apache/logs/error.log';
if (file_exists($apache_log) && is_readable($apache_log)) {
    echo '<h3>Apache Error Log (Last 10 lines)</h3>';
    $lines = array_slice(file($apache_log), -10);
    if (!empty($lines)) {
        echo '<pre>';
        foreach ($lines as $line) {
            echo htmlspecialchars($line);
        }
        echo '</pre>';
    } else {
        echo '<p class="success">✓ No recent errors</p>';
    }
} else {
    echo '<p class="warning">⚠ Apache error log not accessible</p>';
}

echo '</div>';

// 7. Quick Actions
echo '<div class="section">';
echo '<h2>7. Quick Actions</h2>';

echo '<p>Test Pages:</p>';
echo '<a href="test.php" class="button">Test PHP Info</a>';
echo '<a href="emergency_access.php" class="button">Emergency Login</a>';
echo '<a href="/" class="button">Home Page</a>';

echo '<p style="margin-top: 20px;">If you see a 500 error:</p>';
echo '<ol>';
echo '<li>Run <strong>fix_500_error.bat</strong> as Administrator</li>';
echo '<li>Check if <strong>.htaccess</strong> has unsupported directives</li>';
echo '<li>Verify all PHP files have correct syntax</li>';
echo '<li>Ensure database connection is working</li>';
echo '<li>Check Apache and PHP error logs</li>';
echo '</ol>';

echo '</div>';

// 8. Overall Status
echo '<div class="section">';
echo '<h2>8. Overall Status</h2>';

$overall_status = $php_ok && $db_connected && $session_ok;

if ($overall_status) {
    echo '<div class="success" style="padding: 20px; background: #d4edda; border-radius: 5px;">';
    echo '<h3>✓ System appears to be functioning correctly</h3>';
    echo '<p>All critical components are operational.</p>';
    echo '</div>';
} else {
    echo '<div class="error" style="padding: 20px; background: #f8d7da; border-radius: 5px;">';
    echo '<h3>✗ Issues Detected</h3>';
    echo '<p>Please review the errors above and run fix_500_error.bat to resolve them.</p>';
    echo '</div>';
}

echo '</div>';

?>

<div class="section" style="text-align: center; background: #34495e; color: white;">
    <p>CollaboraNexio Diagnostic Tool v1.0 | Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
</div>

</body>
</html>