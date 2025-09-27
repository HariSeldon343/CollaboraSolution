<?php
/**
 * Database verification script
 * Checks if required tables exist and have proper structure
 */

// Start session
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    die('Access denied. Admin privileges required.');
}

// Include database configuration
require_once 'config.php';
require_once 'includes/db.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Verification</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .check-item {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .warning { background: #fff3cd; color: #856404; }
        .info { background: #d1ecf1; color: #0c5460; }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th { background: #f8f9fa; }
    </style>
</head>
<body>
    <h1>Database Verification</h1>

    <?php
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        echo '<div class="check-item success">✓ Database connection successful</div>';

        // Check required tables
        $requiredTables = [
            'users' => ['id', 'email', 'password_hash', 'first_name', 'last_name', 'role', 'tenant_id', 'status', 'deleted_at'],
            'tenants' => ['id', 'name', 'domain', 'status', 'plan_type', 'max_users', 'deleted_at'],
            'user_sessions' => ['id', 'user_id', 'session_token'],
            'audit_logs' => ['id', 'tenant_id', 'user_id', 'action', 'entity_type', 'entity_id', 'details', 'ip_address', 'created_at'],
            'activity_logs' => ['id', 'tenant_id', 'user_id', 'action', 'details', 'ip_address', 'user_agent', 'created_at'],
            'login_attempts' => ['id', 'email', 'user_id', 'tenant_id', 'success', 'ip_address', 'user_agent', 'attempted_at']
        ];

        foreach ($requiredTables as $tableName => $requiredColumns) {
            echo '<div class="check-item">';
            echo "<h3>Table: $tableName</h3>";

            // Check if table exists
            $stmt = $conn->prepare("SHOW TABLES LIKE :table");
            $stmt->execute([':table' => $tableName]);

            if ($stmt->fetch()) {
                echo '<div class="info">✓ Table exists</div>';

                // Get table structure
                $stmt = $conn->prepare("SHOW COLUMNS FROM $tableName");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo '<table>';
                echo '<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Status</th></tr>';

                $existingColumns = [];
                foreach ($columns as $column) {
                    $existingColumns[] = $column['Field'];
                    $status = in_array($column['Field'], $requiredColumns) ? '✓ Required' : 'Optional';
                    $statusClass = in_array($column['Field'], $requiredColumns) ? 'success' : '';

                    echo '<tr class="' . $statusClass . '">';
                    echo '<td>' . htmlspecialchars($column['Field']) . '</td>';
                    echo '<td>' . htmlspecialchars($column['Type']) . '</td>';
                    echo '<td>' . htmlspecialchars($column['Null']) . '</td>';
                    echo '<td>' . htmlspecialchars($column['Key']) . '</td>';
                    echo '<td>' . htmlspecialchars($column['Default'] ?? 'NULL') . '</td>';
                    echo '<td>' . $status . '</td>';
                    echo '</tr>';
                }
                echo '</table>';

                // Check for missing required columns
                $missingColumns = array_diff($requiredColumns, $existingColumns);
                if (!empty($missingColumns)) {
                    echo '<div class="warning">⚠ Missing columns: ' . implode(', ', $missingColumns) . '</div>';
                }

                // Show record count
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM $tableName");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo '<div class="info">Record count: ' . $count . '</div>';

            } else {
                echo '<div class="error">✗ Table does not exist</div>';

                // Provide CREATE TABLE statement
                if ($tableName === 'tenants') {
                    echo '<div class="warning">Create table with:</div>';
                    echo '<pre>CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    domain VARCHAR(100),
    status ENUM(\'active\', \'trial\', \'suspended\', \'inactive\') DEFAULT \'active\',
    plan_type VARCHAR(50) DEFAULT \'basic\',
    max_users INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_deleted (deleted_at)
);</pre>';
                }
            }

            echo '</div>';
        }

        // Check user session
        echo '<div class="check-item">';
        echo '<h3>Current Session</h3>';
        echo '<pre>';
        echo 'User ID: ' . ($_SESSION['user_id'] ?? 'Not set') . "\n";
        echo 'Tenant ID: ' . ($_SESSION['tenant_id'] ?? 'Not set') . "\n";
        echo 'Role: ' . ($_SESSION['role'] ?? 'Not set') . "\n";
        echo 'CSRF Token: ' . (isset($_SESSION['csrf_token']) ? 'Set (' . strlen($_SESSION['csrf_token']) . ' chars)' : 'Not set') . "\n";
        echo '</pre>';
        echo '</div>';

        // Check PHP configuration
        echo '<div class="check-item">';
        echo '<h3>PHP Configuration</h3>';
        echo '<pre>';
        echo 'PHP Version: ' . PHP_VERSION . "\n";
        echo 'Display Errors: ' . ini_get('display_errors') . "\n";
        echo 'Error Reporting: ' . error_reporting() . "\n";
        echo 'Session Status: ' . session_status() . "\n";
        echo 'Session Save Path: ' . session_save_path() . "\n";
        echo '</pre>';
        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="check-item error">✗ Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>

    <div class="check-item info">
        <h3>Next Steps</h3>
        <ol>
            <li>Ensure all required tables exist with proper structure</li>
            <li>Check that the session contains required authentication data</li>
            <li>Test the APIs using <a href="test_apis_browser.php">test_apis_browser.php</a></li>
            <li>Check browser console for any JavaScript errors</li>
            <li>Verify CSRF tokens are being sent correctly in API requests</li>
        </ol>
    </div>
</body>
</html>