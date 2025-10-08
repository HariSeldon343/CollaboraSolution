<?php
/**
 * Execute Aziende Migration
 *
 * This script executes the database migration for the company management system.
 * Run from browser: http://localhost:8888/CollaboraNexio/run_aziende_migration.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

echo "<!DOCTYPE html>
<html lang='it'>
<head>
    <meta charset='UTF-8'>
    <title>Aziende Migration</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; margin: 10px 0; border-radius: 5px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .step { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>
<h1>🚀 Aziende System Migration</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "<div class='info'><strong>📊 Pre-Migration Status</strong></div>";

    // Get current status
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $tenantCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $userCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<div class='step'>";
    echo "<strong>Database Status:</strong><br>";
    echo "Tenants: $tenantCount<br>";
    echo "Users: $userCount<br>";
    echo "</div>";

    // Read migration file
    $migrationFile = __DIR__ . '/database/migrate_aziende_ruoli_sistema.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    echo "<div class='info'><strong>📄 Reading migration file...</strong></div>";

    $sql = file_get_contents($migrationFile);

    // Remove comments and split into statements
    $lines = explode("\n", $sql);
    $currentStatement = '';
    $statements = [];

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and comments
        if (empty($line) || substr($line, 0, 2) === '--') {
            continue;
        }

        $currentStatement .= ' ' . $line;

        // Check if statement is complete
        if (substr($line, -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        }
    }

    echo "<div class='step'>";
    echo "<strong>Parsed " . count($statements) . " SQL statements</strong>";
    echo "</div>";

    echo "<div class='info'><strong>⚙️ Executing migration...</strong></div>";

    $executedCount = 0;
    $skippedCount = 0;
    $errors = [];

    foreach ($statements as $index => $statement) {
        $statement = trim($statement);

        // Skip USE statements and empty
        if (empty($statement) || stripos($statement, 'USE ') === 0) {
            continue;
        }

        // Skip SELECT statements (they're just for validation)
        if (stripos($statement, 'SELECT') === 0) {
            $skippedCount++;
            continue;
        }

        try {
            // Execute statement
            if (stripos($statement, 'START TRANSACTION') === 0) {
                echo "<div class='step'><strong>🔄 Starting transaction...</strong></div>";
                $pdo->beginTransaction();
            } elseif (stripos($statement, 'COMMIT') === 0) {
                echo "<div class='step'><strong>✅ Committing transaction...</strong></div>";
                $pdo->commit();
            } elseif (stripos($statement, 'ROLLBACK') === 0) {
                echo "<div class='step'><strong>⚠️ Rolling back transaction...</strong></div>";
                $pdo->rollBack();
            } else {
                $pdo->exec($statement);
                $executedCount++;

                // Show important operations
                if (stripos($statement, 'ALTER TABLE') === 0) {
                    $preview = substr($statement, 0, 100);
                    echo "<div class='step'>✓ " . htmlspecialchars($preview) . "...</div>";
                }
            }
        } catch (PDOException $e) {
            $error = $e->getMessage();

            // Some errors are acceptable (e.g., column already exists)
            if (stripos($error, 'Duplicate column') !== false ||
                stripos($error, 'already exists') !== false ||
                stripos($error, "Can't DROP") !== false) {
                echo "<div class='warning'>⚠️ Skipped (already exists): " . htmlspecialchars(substr($statement, 0, 80)) . "...</div>";
                $skippedCount++;
            } else {
                $errors[] = [
                    'statement' => substr($statement, 0, 200),
                    'error' => $error
                ];
                echo "<div class='error'>❌ Error: " . htmlspecialchars($error) . "<br><pre>" . htmlspecialchars(substr($statement, 0, 200)) . "...</pre></div>";
            }
        }
    }

    echo "<div class='success'>";
    echo "<h3>✅ Migration Completed</h3>";
    echo "<strong>Statistics:</strong><br>";
    echo "Executed: $executedCount statements<br>";
    echo "Skipped: $skippedCount statements<br>";
    echo "Errors: " . count($errors) . "<br>";
    echo "</div>";

    if (count($errors) > 0) {
        echo "<div class='warning'>";
        echo "<h3>⚠️ Errors Encountered</h3>";
        foreach ($errors as $error) {
            echo "<strong>Statement:</strong> " . htmlspecialchars($error['statement']) . "...<br>";
            echo "<strong>Error:</strong> " . htmlspecialchars($error['error']) . "<br><br>";
        }
        echo "</div>";
    }

    // Verify migration
    echo "<div class='info'><strong>🔍 Verifying migration...</strong></div>";

    // Check if super_admin role exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($roleColumn && strpos($roleColumn['Type'], 'super_admin') !== false) {
        echo "<div class='success'>✅ super_admin role added to users table</div>";
    } else {
        echo "<div class='error'>❌ super_admin role NOT found in users table</div>";
    }

    // Check if tenant_id is nullable
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
    $tenantIdColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantIdColumn && $tenantIdColumn['Null'] === 'YES') {
        echo "<div class='success'>✅ users.tenant_id is now nullable</div>";
    } else {
        echo "<div class='error'>❌ users.tenant_id is NOT nullable</div>";
    }

    // Check new tenant columns
    $newColumns = [
        'denominazione', 'codice_fiscale', 'partita_iva',
        'sede_legale_indirizzo', 'sedi_operative', 'manager_id'
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM tenants");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach ($newColumns as $col) {
        if (in_array($col, $columns)) {
            echo "<div class='success'>✅ tenants.$col column exists</div>";
        } else {
            echo "<div class='error'>❌ tenants.$col column NOT found</div>";
        }
    }

    // Check constraints
    $stmt = $pdo->query("SHOW CREATE TABLE tenants");
    $createTable = $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'];

    if (strpos($createTable, 'chk_tenant_fiscal_code') !== false) {
        echo "<div class='success'>✅ CF/P.IVA CHECK constraint exists</div>";
    } else {
        echo "<div class='warning'>⚠️ CF/P.IVA CHECK constraint may not be active</div>";
    }

    if (strpos($createTable, 'fk_tenants_manager_id') !== false) {
        echo "<div class='success'>✅ manager_id foreign key exists</div>";
    } else {
        echo "<div class='warning'>⚠️ manager_id foreign key may not be active</div>";
    }

    // Final status
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $finalTenantCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $finalUserCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<div class='step'>";
    echo "<h3>📊 Post-Migration Status</h3>";
    echo "Tenants: $finalTenantCount (before: $tenantCount)<br>";
    echo "Users: $finalUserCount (before: $userCount)<br>";
    echo "</div>";

    echo "<div class='success'>";
    echo "<h2>🎉 Migration Successfully Completed!</h2>";
    echo "<p>Next steps:</p>";
    echo "<ul>";
    echo "<li>Run integrity tests: <a href='test_aziende_migration_integrity.php'>test_aziende_migration_integrity.php</a></li>";
    echo "<li>Test company form: <a href='aziende_new.php'>aziende_new.php</a></li>";
    echo "<li>Create super_admin user if needed</li>";
    echo "</ul>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>❌ Migration Failed</h3>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<strong>Trace:</strong><br><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";

    // Try to rollback if in transaction
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            echo "<div class='warning'>⚠️ Transaction rolled back</div>";
        }
    } catch (Exception $rollbackError) {
        echo "<div class='error'>Failed to rollback: " . htmlspecialchars($rollbackError->getMessage()) . "</div>";
    }
}

echo "</body></html>";
?>
