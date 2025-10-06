<?php
// Database Structure Checker
// This script examines the current database structure to understand what exists

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$username = 'root';
$password = ''; // Empty password for XAMPP default
$database = 'collabora';

try {
    // Connect to MySQL
    $mysqli = new mysqli($host, $username, $password, $database);

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    echo "<h2>Database Structure Analysis</h2>";
    echo "<style>
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .box { background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 5px; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>";

    // Get all tables
    echo "<div class='box'>";
    echo "<h3>1. Existing Tables in Database</h3>";
    $result = $mysqli->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    echo "<p>Total tables: <b>" . count($tables) . "</b></p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    echo "</div>";

    // Check for missing Phase 5 tables
    echo "<div class='box'>";
    echo "<h3>2. Missing Tables from Error Messages</h3>";
    $required_tables = [
        'project_milestones',
        'event_attendees',
        'sessions',
        'rate_limits',
        'system_settings'
    ];

    $missing = [];
    foreach ($required_tables as $table) {
        if (!in_array($table, $tables)) {
            $missing[] = $table;
            echo "<span class='error'>✗ $table - MISSING</span><br>";
        } else {
            echo "<span class='success'>✓ $table - EXISTS</span><br>";
        }
    }
    echo "</div>";

    // Check critical table structures
    echo "<div class='box'>";
    echo "<h3>3. Critical Table Structures</h3>";

    // Check tenants table structure
    if (in_array('tenants', $tables)) {
        echo "<h4>tenants table:</h4>";
        $result = $mysqli->query("DESCRIBE tenants");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check users table structure
    if (in_array('users', $tables)) {
        echo "<h4>users table:</h4>";
        $result = $mysqli->query("DESCRIBE users");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check projects table structure
    if (in_array('projects', $tables)) {
        echo "<h4>projects table:</h4>";
        $result = $mysqli->query("DESCRIBE projects");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check events table structure
    if (in_array('events', $tables)) {
        echo "<h4>events table:</h4>";
        $result = $mysqli->query("DESCRIBE events");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check task_comments table structure
    if (in_array('task_comments', $tables)) {
        echo "<h4>task_comments table:</h4>";
        $result = $mysqli->query("DESCRIBE task_comments");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check file_shares table structure
    if (in_array('file_shares', $tables)) {
        echo "<h4>file_shares table:</h4>";
        $result = $mysqli->query("DESCRIBE file_shares");
        echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "</div>";

    // Check for sample data
    echo "<div class='box'>";
    echo "<h3>4. Sample Data Check</h3>";

    if (in_array('tenants', $tables)) {
        $result = $mysqli->query("SELECT id, name FROM tenants LIMIT 5");
        echo "<h4>Tenants:</h4>";
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "ID: {$row['id']}, Name: {$row['name']}<br>";
            }
        } else {
            echo "<span class='warning'>No tenants found</span><br>";
        }
    }

    if (in_array('users', $tables)) {
        $result = $mysqli->query("SELECT id, username, email FROM users LIMIT 5");
        echo "<h4>Users:</h4>";
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "ID: {$row['id']}, Username: {$row['username']}, Email: {$row['email']}<br>";
            }
        } else {
            echo "<span class='warning'>No users found</span><br>";
        }
    }

    if (in_array('projects', $tables)) {
        $result = $mysqli->query("SELECT id, name FROM projects LIMIT 5");
        echo "<h4>Projects:</h4>";
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "ID: {$row['id']}, Name: {$row['name']}<br>";
            }
        } else {
            echo "<span class='warning'>No projects found</span><br>";
        }
    }

    echo "</div>";

    $mysqli->close();

} catch (Exception $e) {
    echo "<div class='box'>";
    echo "<span class='error'>Error: " . $e->getMessage() . "</span>";
    echo "</div>";
}
?>