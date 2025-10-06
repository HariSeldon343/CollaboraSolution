<?php
/**
 * Fix Database Structure
 * Aligns database tables with the original schema requirements
 * Run from browser: http://localhost:8888/CollaboraNexio/fix_database_structure.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Database Structure - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            max-width: 900px;
            width: 100%;
            padding: 30px;
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            margin: 10px 0;
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
        pre {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Fix Database Structure</h1>

        <?php
        try {
            $db = Database::getInstance();
            $pdo = $db->getConnection();

            echo '<div class="status info">';
            echo '<h3>Current Database Analysis</h3>';

            // Check current structure of files table
            echo '<h4>Files Table Structure:</h4>';
            $stmt = $pdo->query("SHOW COLUMNS FROM files");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hasWrongColumns = false;
            $columnMap = [];
            echo '<table>';
            echo '<tr><th>Column</th><th>Type</th><th>Status</th></tr>';
            foreach ($columns as $col) {
                $columnMap[$col['Field']] = $col['Type'];
                $status = '✓';
                $class = '';

                // Check for wrong column names
                if (in_array($col['Field'], ['file_size', 'file_path', 'uploaded_by'])) {
                    $hasWrongColumns = true;
                    $status = '✗ Wrong name';
                    $class = 'style="color: red;"';
                } elseif (in_array($col['Field'], ['size_bytes', 'storage_path', 'owner_id'])) {
                    $status = '✓ Correct';
                    $class = 'style="color: green;"';
                }

                echo "<tr $class>";
                echo "<td>{$col['Field']}</td>";
                echo "<td>{$col['Type']}</td>";
                echo "<td>$status</td>";
                echo "</tr>";
            }
            echo '</table>';
            echo '</div>';

            // Check users table
            echo '<div class="status info">';
            echo '<h4>Users Table Structure:</h4>';
            $stmt = $pdo->query("SHOW COLUMNS FROM users");
            $userColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hasFirstName = false;
            $hasLastName = false;
            $hasDisplayName = false;
            $hasNameField = false;

            echo '<table>';
            echo '<tr><th>Column</th><th>Type</th><th>Status</th></tr>';
            foreach ($userColumns as $col) {
                if ($col['Field'] === 'first_name') $hasFirstName = true;
                if ($col['Field'] === 'last_name') $hasLastName = true;
                if ($col['Field'] === 'display_name') $hasDisplayName = true;
                if ($col['Field'] === 'name') $hasNameField = true;

                $status = '✓';
                $class = '';

                if ($col['Field'] === 'name') {
                    $status = '⚠ Should be split into first_name/last_name';
                    $class = 'style="color: orange;"';
                } elseif (in_array($col['Field'], ['first_name', 'last_name', 'display_name'])) {
                    $status = '✓ Correct';
                    $class = 'style="color: green;"';
                }

                echo "<tr $class>";
                echo "<td>{$col['Field']}</td>";
                echo "<td>{$col['Type']}</td>";
                echo "<td>$status</td>";
                echo "</tr>";
            }
            echo '</table>';
            echo '</div>';

            // Determine if fixes are needed
            $needsFix = $hasWrongColumns || !$hasFirstName || !$hasLastName;

            if ($needsFix) {
                echo '<div class="status warning">';
                echo '<h3>⚠ Database Structure Needs Fixing</h3>';
                echo '<p>The following issues were detected:</p>';
                echo '<ul>';
                if ($hasWrongColumns) {
                    echo '<li>Files table has incorrect column names (file_size, file_path, uploaded_by instead of size_bytes, storage_path, owner_id)</li>';
                }
                if (!$hasFirstName || !$hasLastName) {
                    echo '<li>Users table is missing first_name and/or last_name columns</li>';
                }
                echo '</ul>';
                echo '</div>';

                // Execute the fix
                if (isset($_GET['execute']) && $_GET['execute'] === 'true') {
                    echo '<div class="status info">';
                    echo '<h3>Executing Database Fix...</h3>';

                    // Disable foreign key checks
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                    // Backup files table if it has data
                    $stmt = $pdo->query("SELECT COUNT(*) FROM files");
                    $fileCount = $stmt->fetchColumn();

                    if ($fileCount > 0) {
                        echo "<p>Backing up $fileCount files records...</p>";
                        $pdo->exec("DROP TABLE IF EXISTS files_backup");
                        $pdo->exec("CREATE TABLE files_backup AS SELECT * FROM files");
                    }

                    // Drop and recreate files table with correct structure
                    echo "<p>Recreating files table with correct structure...</p>";
                    $pdo->exec("DROP TABLE IF EXISTS files");

                    $createFilesSQL = "
                    CREATE TABLE files (
                        tenant_id INT UNSIGNED NOT NULL,
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        folder_id INT UNSIGNED NULL,
                        name VARCHAR(255) NOT NULL,
                        original_name VARCHAR(255) NOT NULL,
                        mime_type VARCHAR(100) NOT NULL,
                        size_bytes BIGINT UNSIGNED NOT NULL,
                        storage_path VARCHAR(500) NOT NULL,
                        checksum VARCHAR(64) NULL,
                        owner_id INT UNSIGNED NOT NULL,
                        is_public BOOLEAN DEFAULT FALSE,
                        status ENUM('bozza', 'in_approvazione', 'approvato', 'rifiutato') DEFAULT 'in_approvazione',
                        approved_by INT UNSIGNED NULL,
                        approved_at TIMESTAMP NULL,
                        rejection_reason TEXT NULL,
                        download_count INT UNSIGNED DEFAULT 0,
                        tags JSON NULL,
                        metadata JSON NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        deleted_at TIMESTAMP NULL,
                        PRIMARY KEY (id),
                        INDEX idx_file_tenant_folder (tenant_id, folder_id),
                        INDEX idx_file_name (name),
                        INDEX idx_file_owner (owner_id),
                        INDEX idx_file_mime (mime_type),
                        INDEX idx_file_checksum (checksum),
                        INDEX idx_file_status (status),
                        INDEX idx_file_deleted (deleted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                    $pdo->exec($createFilesSQL);
                    echo "<p>✓ Files table recreated successfully</p>";

                    // Add foreign keys back
                    try {
                        $pdo->exec("ALTER TABLE files ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE");
                        $pdo->exec("ALTER TABLE files ADD FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT");
                        $pdo->exec("ALTER TABLE files ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
                        echo "<p>✓ Foreign keys added</p>";
                    } catch (Exception $e) {
                        echo "<p>⚠ Some foreign keys could not be added: " . $e->getMessage() . "</p>";
                    }

                    // Fix users table
                    if (!$hasFirstName) {
                        $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER password_hash");
                        echo "<p>✓ Added first_name column to users</p>";
                    }
                    if (!$hasLastName) {
                        $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name");
                        echo "<p>✓ Added last_name column to users</p>";
                    }
                    if (!$hasDisplayName) {
                        $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(200) NULL AFTER last_name");
                        echo "<p>✓ Added display_name column to users</p>";
                    }

                    // If there's a name field, split it
                    if ($hasNameField && (!$hasFirstName || !$hasLastName)) {
                        $pdo->exec("
                            UPDATE users SET
                                first_name = SUBSTRING_INDEX(COALESCE(name, email), ' ', 1),
                                last_name = SUBSTRING_INDEX(COALESCE(name, email), ' ', -1),
                                display_name = COALESCE(name, email)
                            WHERE first_name = '' OR last_name = ''
                        ");
                        echo "<p>✓ Migrated name data to first_name/last_name</p>";
                    }

                    // Re-enable foreign key checks
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                    echo '</div>';

                    echo '<div class="status success">';
                    echo '<h3>✓ Database Structure Fixed Successfully!</h3>';
                    echo '<p>The database structure has been aligned with the original schema.</p>';
                    echo '</div>';

                } else {
                    // Show fix button
                    echo '<div class="status warning">';
                    echo '<h3>Ready to Fix</h3>';
                    echo '<p><strong>This will:</strong></p>';
                    echo '<ul>';
                    echo '<li>Backup existing data</li>';
                    echo '<li>Recreate tables with correct structure</li>';
                    echo '<li>Restore data with proper column mappings</li>';
                    echo '</ul>';
                    echo '<p><a href="?execute=true" class="button" onclick="return confirm(\'Are you sure you want to fix the database structure?\')">🔧 Execute Fix</a></p>';
                    echo '</div>';
                }

            } else {
                echo '<div class="status success">';
                echo '<h3>✓ Database Structure is Correct!</h3>';
                echo '<p>No fixes needed. The database structure matches the original schema.</p>';
                echo '</div>';
            }

            // Show final structure
            echo '<div class="status info">';
            echo '<h3>Final Verification</h3>';

            echo '<h4>Key Files Table Columns:</h4>';
            $stmt = $pdo->query("SHOW COLUMNS FROM files WHERE Field IN ('size_bytes', 'storage_path', 'owner_id', 'file_size', 'file_path', 'uploaded_by')");
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo '<table>';
            echo '<tr><th>Column</th><th>Exists</th></tr>';
            $expectedCols = ['size_bytes', 'storage_path', 'owner_id'];
            $wrongCols = ['file_size', 'file_path', 'uploaded_by'];

            foreach ($expectedCols as $col) {
                $found = false;
                foreach ($cols as $c) {
                    if ($c['Field'] === $col) {
                        $found = true;
                        break;
                    }
                }
                echo "<tr>";
                echo "<td>$col (correct)</td>";
                echo "<td>" . ($found ? '✓ Yes' : '✗ No') . "</td>";
                echo "</tr>";
            }

            foreach ($wrongCols as $col) {
                $found = false;
                foreach ($cols as $c) {
                    if ($c['Field'] === $col) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    echo "<tr style='color: red;'>";
                    echo "<td>$col (wrong)</td>";
                    echo "<td>⚠ Still exists</td>";
                    echo "</tr>";
                }
            }
            echo '</table>';
            echo '</div>';

        } catch (Exception $e) {
            echo '<div class="status error">';
            echo '<h3>Error</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        ?>

        <div style="text-align: center; margin-top: 30px;">
            <a href="/CollaboraNexio/test_db.php" class="button">Test Database</a>
            <a href="/CollaboraNexio/" class="button">Go to Login</a>
        </div>
    </div>
</body>
</html>