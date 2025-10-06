<?php
// Check Files and Folders Tables Structure
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'includes/db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "<!DOCTYPE html><html><head><title>Files Tables Check</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .box { background: #f9f9f9; padding: 15px; margin: 20px 0; border-radius: 5px; border: 1px solid #ddd; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; border: 1px solid #ddd; }
    </style></head><body>";

    echo "<h1>Files and Folders Tables Structure Check</h1>";

    // 1. Check if tables exist
    echo "<div class='box'>";
    echo "<h2>1. Tables Existence Check</h2>";

    $tables_to_check = ['files', 'folders', 'tenants', 'users', 'file_shares', 'file_versions'];
    $existing_tables = [];

    foreach ($tables_to_check as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "<span class='success'>✓</span> Table '$table' EXISTS<br>";
            $existing_tables[] = $table;
        } else {
            echo "<span class='error'>✗</span> Table '$table' is MISSING<br>";
        }
    }
    echo "</div>";

    // 2. Check FILES table structure
    if (in_array('files', $existing_tables)) {
        echo "<div class='box'>";
        echo "<h2>2. FILES Table Structure</h2>";

        $stmt = $pdo->query("SHOW COLUMNS FROM files");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<table>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "<td>{$col['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Check for important columns
        $column_names = array_column($columns, 'Field');
        echo "<h3>Column Analysis:</h3>";

        // Name columns
        $name_columns = ['name', 'file_name', 'filename'];
        $found_name = null;
        foreach ($name_columns as $nc) {
            if (in_array($nc, $column_names)) {
                $found_name = $nc;
                echo "<span class='success'>✓</span> Name column found: '$nc'<br>";
                break;
            }
        }
        if (!$found_name) {
            echo "<span class='error'>✗</span> No name column found (expected: name, file_name, or filename)<br>";
        }

        // Size columns
        $size_columns = ['size', 'file_size', 'size_bytes'];
        $found_size = null;
        foreach ($size_columns as $sc) {
            if (in_array($sc, $column_names)) {
                $found_size = $sc;
                echo "<span class='success'>✓</span> Size column found: '$sc'<br>";
                break;
            }
        }
        if (!$found_size) {
            echo "<span class='error'>✗</span> No size column found (expected: size, file_size, or size_bytes)<br>";
        }

        // Path columns
        $path_columns = ['path', 'file_path', 'storage_path'];
        $found_path = null;
        foreach ($path_columns as $pc) {
            if (in_array($pc, $column_names)) {
                $found_path = $pc;
                echo "<span class='success'>✓</span> Path column found: '$pc'<br>";
                break;
            }
        }
        if (!$found_path) {
            echo "<span class='error'>✗</span> No path column found (expected: path, file_path, or storage_path)<br>";
        }

        // Other important columns
        if (in_array('folder_id', $column_names)) {
            echo "<span class='success'>✓</span> folder_id column exists<br>";
        } else {
            echo "<span class='error'>✗</span> folder_id column missing<br>";
        }

        if (in_array('tenant_id', $column_names)) {
            echo "<span class='success'>✓</span> tenant_id column exists<br>";
        } else {
            echo "<span class='error'>✗</span> tenant_id column missing<br>";
        }

        if (in_array('deleted_at', $column_names)) {
            echo "<span class='success'>✓</span> deleted_at column exists (soft deletes)<br>";
        } else {
            echo "<span class='warning'>⚠</span> deleted_at column missing (no soft deletes)<br>";
        }

        echo "</div>";
    }

    // 3. Check FOLDERS table structure
    if (in_array('folders', $existing_tables)) {
        echo "<div class='box'>";
        echo "<h2>3. FOLDERS Table Structure</h2>";

        $stmt = $pdo->query("SHOW COLUMNS FROM folders");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<table>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "<td>{$col['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Check for important columns
        $column_names = array_column($columns, 'Field');
        echo "<h3>Column Analysis:</h3>";

        if (in_array('name', $column_names)) {
            echo "<span class='success'>✓</span> name column exists<br>";
        } else {
            echo "<span class='error'>✗</span> name column missing<br>";
        }

        if (in_array('parent_id', $column_names)) {
            echo "<span class='success'>✓</span> parent_id column exists<br>";
        } else {
            echo "<span class='error'>✗</span> parent_id column missing<br>";
        }

        if (in_array('tenant_id', $column_names)) {
            echo "<span class='success'>✓</span> tenant_id column exists<br>";
        } else {
            echo "<span class='error'>✗</span> tenant_id column missing<br>";
        }

        if (in_array('deleted_at', $column_names)) {
            echo "<span class='success'>✓</span> deleted_at column exists (soft deletes)<br>";
        } else {
            echo "<span class='warning'>⚠</span> deleted_at column missing (no soft deletes)<br>";
        }

        echo "</div>";
    }

    // 4. Test sample queries
    echo "<div class='box'>";
    echo "<h2>4. Test Queries</h2>";

    // Test simple folder query
    try {
        $query = "SELECT * FROM folders WHERE deleted_at IS NULL LIMIT 1";
        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<span class='success'>✓</span> Simple folder query works<br>";
        if ($result) {
            echo "Sample folder data found<br>";
        } else {
            echo "<span class='warning'>⚠</span> No folders in database<br>";
        }
    } catch (PDOException $e) {
        echo "<span class='error'>✗</span> Folder query failed: " . $e->getMessage() . "<br>";
    }

    // Test simple file query
    try {
        $query = "SELECT * FROM files WHERE deleted_at IS NULL LIMIT 1";
        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<span class='success'>✓</span> Simple file query works<br>";
        if ($result) {
            echo "Sample file data found<br>";
        } else {
            echo "<span class='warning'>⚠</span> No files in database<br>";
        }
    } catch (PDOException $e) {
        echo "<span class='error'>✗</span> File query failed: " . $e->getMessage() . "<br>";
    }

    // Test join query
    try {
        $query = "
            SELECT f.*, t.name as tenant_name
            FROM folders f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.deleted_at IS NULL
            LIMIT 1
        ";
        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<span class='success'>✓</span> Join query with tenants works<br>";
    } catch (PDOException $e) {
        echo "<span class='error'>✗</span> Join query failed: " . $e->getMessage() . "<br>";
    }

    echo "</div>";

    // 5. Check for data
    echo "<div class='box'>";
    echo "<h2>5. Data Summary</h2>";

    try {
        // Count folders
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM folders WHERE deleted_at IS NULL");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "Active folders: <strong>$count</strong><br>";

        // Count files
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM files WHERE deleted_at IS NULL");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "Active files: <strong>$count</strong><br>";

        // Count tenants
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "Total tenants: <strong>$count</strong><br>";

        // List tenants
        $stmt = $pdo->query("SELECT id, name FROM tenants LIMIT 5");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($tenants) {
            echo "<br>Sample tenants:<br>";
            foreach ($tenants as $tenant) {
                echo "- ID: {$tenant['id']}, Name: {$tenant['name']}<br>";
            }
        }

    } catch (PDOException $e) {
        echo "<span class='error'>Error counting data: " . $e->getMessage() . "</span><br>";
    }

    echo "</div>";

    // 6. Session info
    session_start();
    echo "<div class='box'>";
    echo "<h2>6. Current Session Info</h2>";
    echo "Session ID: " . session_id() . "<br>";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";
    echo "Tenant ID: " . ($_SESSION['tenant_id'] ?? 'NOT SET') . "<br>";
    echo "Role: " . ($_SESSION['role'] ?? 'NOT SET') . "<br>";
    echo "</div>";

    echo "</body></html>";

} catch (Exception $e) {
    echo "<div class='box'>";
    echo "<span class='error'>Fatal Error: " . $e->getMessage() . "</span><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?>