<?php
/**
 * Audit Logs Migration Script
 *
 * This script creates the audit_logs table for the CollaboraNexio system
 * following the multi-tenant architecture pattern.
 *
 * @author Database Architect
 * @version 2025-09-29
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Include database configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Color codes for terminal output
$colors = [
    'green' => "\033[0;32m",
    'red' => "\033[0;31m",
    'yellow' => "\033[1;33m",
    'blue' => "\033[0;34m",
    'reset' => "\033[0m"
];

function printMessage($message, $type = 'info') {
    global $colors;
    $timestamp = date('Y-m-d H:i:s');

    switch($type) {
        case 'success':
            echo $colors['green'] . "✓ [$timestamp] $message" . $colors['reset'] . PHP_EOL;
            break;
        case 'error':
            echo $colors['red'] . "✗ [$timestamp] $message" . $colors['reset'] . PHP_EOL;
            break;
        case 'warning':
            echo $colors['yellow'] . "⚠ [$timestamp] $message" . $colors['reset'] . PHP_EOL;
            break;
        case 'info':
            echo $colors['blue'] . "ℹ [$timestamp] $message" . $colors['reset'] . PHP_EOL;
            break;
        default:
            echo "[$timestamp] $message" . PHP_EOL;
    }
}

function runMigration() {
    try {
        printMessage("Starting audit_logs table migration...", 'info');

        // Get database connection
        $db = Database::getInstance();
        $conn = $db->getConnection();

        if (!$conn) {
            throw new Exception("Failed to connect to database");
        }

        printMessage("Database connection established", 'success');

        // Check if table already exists
        $checkQuery = "SHOW TABLES LIKE 'audit_logs'";
        $stmt = $conn->query($checkQuery);
        $result = $stmt->fetchAll();

        if ($result && count($result) > 0) {
            printMessage("Table 'audit_logs' already exists", 'warning');

            echo PHP_EOL . "Do you want to drop and recreate the table? This will delete all existing audit logs! (yes/no): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            $answer = trim(strtolower($line));
            fclose($handle);

            if ($answer !== 'yes' && $answer !== 'y') {
                printMessage("Migration cancelled by user", 'warning');
                return false;
            }

            printMessage("Dropping existing audit_logs table...", 'info');
        }

        // Read SQL file
        $sqlFile = __DIR__ . '/database/06_audit_logs.sql';

        if (!file_exists($sqlFile)) {
            throw new Exception("SQL file not found: $sqlFile");
        }

        printMessage("Reading SQL file: $sqlFile", 'info');
        $sql = file_get_contents($sqlFile);

        if (empty($sql)) {
            throw new Exception("SQL file is empty");
        }

        // Execute SQL statements
        printMessage("Executing migration...", 'info');

        // Execute statements line by line
        $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Remove comments and split by semicolon
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Handle DELIMITER changes for stored procedures
        if (strpos($sql, 'DELIMITER') !== false) {
            // Split on DELIMITER commands
            $sections = preg_split('/DELIMITER\s+/i', $sql);

            foreach ($sections as $section) {
                $section = trim($section);
                if (empty($section)) continue;

                // Check if this section uses special delimiter
                if (strpos($section, '$$') === 0) {
                    // Remove the $$ delimiters and execute
                    $section = trim($section, '$$');
                    $procedures = explode('$$', $section);
                    foreach ($procedures as $proc) {
                        $proc = trim($proc);
                        if (!empty($proc)) {
                            executeStatement($conn, $proc);
                        }
                    }
                } else {
                    // Regular statements with semicolon delimiter
                    $statements = array_filter(array_map('trim', explode(';', $section)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && stripos($statement, 'USE ') === false) {
                            executeStatement($conn, $statement);
                        }
                    }
                }
            }
        } else {
            // No DELIMITER changes, process normally
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement) && stripos($statement, 'USE ') === false) {
                    executeStatement($conn, $statement);
                }
            }
        }

        $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Verify table creation
        printMessage("Verifying table creation...", 'info');

        $verifyQuery = "SELECT COUNT(*) as count FROM information_schema.tables
                       WHERE table_schema = DATABASE()
                       AND table_name = 'audit_logs'";
        $result = $conn->query($verifyQuery);

        if (!$result) {
            throw new Exception("Failed to verify table creation");
        }

        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            throw new Exception("Table was not created successfully");
        }

        printMessage("Table 'audit_logs' created successfully", 'success');

        // Count demo records
        $countQuery = "SELECT COUNT(*) as count FROM audit_logs";
        $result = $conn->query($countQuery);

        if ($result) {
            $row = $result->fetch_assoc();
            printMessage("Inserted {$row['count']} demo records", 'success');
        }

        // Show table structure
        printMessage("Table structure:", 'info');
        $structureQuery = "DESCRIBE audit_logs";
        $result = $conn->query($structureQuery);

        if ($result) {
            echo PHP_EOL;
            printf("%-25s %-30s %-10s %-10s %-10s %-20s\n",
                   "Field", "Type", "Null", "Key", "Default", "Extra");
            echo str_repeat("-", 110) . PHP_EOL;

            while ($row = $result->fetch_assoc()) {
                printf("%-25s %-30s %-10s %-10s %-10s %-20s\n",
                       $row['Field'],
                       $row['Type'],
                       $row['Null'],
                       $row['Key'],
                       $row['Default'] ?? 'NULL',
                       $row['Extra']);
            }
            echo PHP_EOL;
        }

        // Show indexes
        printMessage("Table indexes:", 'info');
        $indexQuery = "SHOW INDEX FROM audit_logs";
        $result = $conn->query($indexQuery);

        if ($result) {
            echo PHP_EOL;
            $indexes = [];
            while ($row = $result->fetch_assoc()) {
                $indexes[$row['Key_name']][] = $row['Column_name'];
            }

            foreach ($indexes as $indexName => $columns) {
                echo "  • $indexName: " . implode(', ', $columns) . PHP_EOL;
            }
            echo PHP_EOL;
        }

        // Update migration history if table exists
        $migrationQuery = "SELECT COUNT(*) as count FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'migration_history'";
        $result = $conn->query($migrationQuery);

        if ($result) {
            $row = $result->fetch_assoc();
            if ($row['count'] > 0) {
                $insertMigration = "INSERT INTO migration_history (migration_name, executed_at, success)
                                   VALUES ('06_audit_logs.sql', NOW(), 1)
                                   ON DUPLICATE KEY UPDATE
                                   executed_at = NOW(),
                                   success = 1";
                $conn->query($insertMigration);
                printMessage("Migration history updated", 'success');
            }
        }

        printMessage("Migration completed successfully!", 'success');

        // Log the migration in the new audit_logs table
        $logQuery = "INSERT INTO audit_logs
                    (tenant_id, user_id, action, entity_type, description, severity, status, created_at)
                    VALUES
                    (1, NULL, 'system_update', 'system_setting',
                     'Audit logs table created via migration script', 'info', 'success', NOW())";

        if ($conn->query($logQuery)) {
            printMessage("Migration logged in audit_logs table", 'success');
        }

        return true;

    } catch (Exception $e) {
        printMessage("Migration failed: " . $e->getMessage(), 'error');

        // Log error if possible
        if (isset($conn) && $conn) {
            $errorLog = "INSERT IGNORE INTO migration_history (migration_name, executed_at, success, error_message)
                        VALUES ('06_audit_logs.sql', NOW(), 0, " . $conn->quote($e->getMessage()) . ")";
            @$conn->query($errorLog);
        }

        return false;
    }
}

function executeStatement($conn, $query) {
    $query = trim($query);

    // Skip empty queries and USE statements
    if (empty($query) || stripos($query, 'USE ') === 0) {
        return true;
    }

    // Remove SQL comments
    $query = preg_replace('/^--.*$/m', '', $query);
    $query = preg_replace('/\/\*.*?\*\//s', '', $query);
    $query = trim($query);

    if (empty($query)) {
        return true;
    }

    // Determine query type for logging
    $queryType = strtoupper(substr($query, 0, 6));

    try {
        $conn->exec($query);
    } catch (PDOException $e) {
        // Don't fail on DROP statements if table doesn't exist
        if (strpos($query, 'DROP') === 0 && strpos($e->getMessage(), "Unknown table") !== false) {
            printMessage("Table does not exist (skipping drop)", 'warning');
            return true;
        }

        // Don't fail on duplicate key for procedures/functions
        if ((strpos($query, 'CREATE PROCEDURE') !== false ||
             strpos($query, 'CREATE FUNCTION') !== false) &&
            strpos($e->getMessage(), "already exists") !== false) {
            printMessage("Routine already exists (skipping)", 'warning');
            return true;
        }

        throw new Exception("SQL Error: " . $e->getMessage() . "\nQuery: " . substr($query, 0, 100) . "...");
    }

    // Log success for important operations
    if (in_array($queryType, ['CREATE', 'INSERT', 'ALTER'])) {
        // PDO doesn't have affected_rows for exec, skip this
        if ($queryType === 'CREATE') {
            if (strpos($query, 'TABLE') !== false) {
                printMessage("Table created successfully", 'success');
            } elseif (strpos($query, 'INDEX') !== false) {
                printMessage("Index created successfully", 'success');
            } elseif (strpos($query, 'VIEW') !== false) {
                printMessage("View created successfully", 'success');
            } elseif (strpos($query, 'PROCEDURE') !== false) {
                printMessage("Stored procedure created successfully", 'success');
            } elseif (strpos($query, 'FUNCTION') !== false) {
                printMessage("Function created successfully", 'success');
            }
        }
    }

    return true;
}

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script must be run from the command line.\n";
    echo "Usage: php " . basename(__FILE__) . "\n";
    exit(1);
}

// Display header
echo PHP_EOL;
echo $colors['blue'] . "========================================" . $colors['reset'] . PHP_EOL;
echo $colors['blue'] . "  CollaboraNexio Audit Logs Migration  " . $colors['reset'] . PHP_EOL;
echo $colors['blue'] . "========================================" . $colors['reset'] . PHP_EOL;
echo PHP_EOL;

// Run migration
$success = runMigration();

// Display footer
echo PHP_EOL;
if ($success) {
    echo $colors['green'] . "========================================" . $colors['reset'] . PHP_EOL;
    echo $colors['green'] . "     Migration Completed Successfully   " . $colors['reset'] . PHP_EOL;
    echo $colors['green'] . "========================================" . $colors['reset'] . PHP_EOL;
} else {
    echo $colors['red'] . "========================================" . $colors['reset'] . PHP_EOL;
    echo $colors['red'] . "        Migration Failed                " . $colors['reset'] . PHP_EOL;
    echo $colors['red'] . "========================================" . $colors['reset'] . PHP_EOL;
}
echo PHP_EOL;

exit($success ? 0 : 1);