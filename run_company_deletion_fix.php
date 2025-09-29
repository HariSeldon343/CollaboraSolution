<?php
/**
 * Script to apply company deletion fix migration
 * Run this from command line: php run_company_deletion_fix.php
 */

// Configuration
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Colors for console output
$red = "\033[0;31m";
$green = "\033[0;32m";
$yellow = "\033[1;33m";
$blue = "\033[0;34m";
$reset = "\033[0m";

echo "\n{$blue}==================================={$reset}\n";
echo "{$blue}Company Deletion Fix Migration{$reset}\n";
echo "{$blue}==================================={$reset}\n\n";

try {
    // Get database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Check if migration has already been applied
    echo "{$yellow}Checking migration status...{$reset}\n";

    $checkMigration = $pdo->prepare("SELECT * FROM migration_history WHERE migration_name = '07_company_deletion_fix.sql'");
    $checkMigration->execute();
    $migrationExists = $checkMigration->fetch(PDO::FETCH_ASSOC);

    if ($migrationExists) {
        echo "{$yellow}Migration already applied on: {$migrationExists['executed_at']}{$reset}\n";
        echo "{$yellow}Do you want to re-apply the migration? (y/n): {$reset}";
        $answer = trim(fgets(STDIN));
        if (strtolower($answer) !== 'y') {
            echo "{$blue}Migration cancelled.{$reset}\n";
            exit(0);
        }
    }

    // Read migration file
    $migrationFile = __DIR__ . '/database/07_company_deletion_fix.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    echo "{$green}Reading migration file...{$reset}\n";
    $sql = file_get_contents($migrationFile);

    // Split SQL into individual statements
    // Remove comments and split by semicolon
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Handle DELIMITER changes for stored procedures
    $delimiter = ';';
    $statements = [];
    $currentStatement = '';
    $inDelimiterBlock = false;

    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line)) continue;

        if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
            if ($currentStatement) {
                $statements[] = $currentStatement;
                $currentStatement = '';
            }
            $delimiter = trim($matches[1]);
            $inDelimiterBlock = ($delimiter !== ';');
            continue;
        }

        $currentStatement .= $line . "\n";

        if (!$inDelimiterBlock && substr($line, -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        } elseif ($inDelimiterBlock && substr($line, -strlen($delimiter)) === $delimiter) {
            $statements[] = trim(substr($currentStatement, 0, -strlen($delimiter)));
            $currentStatement = '';
        }
    }

    if ($currentStatement) {
        $statements[] = trim($currentStatement);
    }

    // Execute migration
    echo "{$green}Executing migration...{$reset}\n\n";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $totalStatements = count($statements);
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;

    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;

        // Display progress
        $progress = $index + 1;

        // Determine statement type for display
        $statementType = 'Unknown';
        if (preg_match('/^ALTER\s+TABLE\s+(\w+)/i', $statement, $matches)) {
            $statementType = "ALTER TABLE {$matches[1]}";
        } elseif (preg_match('/^CREATE\s+PROCEDURE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $statement, $matches)) {
            $statementType = "CREATE PROCEDURE {$matches[1]}";
        } elseif (preg_match('/^DROP\s+PROCEDURE\s+(?:IF\s+EXISTS\s+)?(\w+)/i', $statement, $matches)) {
            $statementType = "DROP PROCEDURE {$matches[1]}";
        } elseif (preg_match('/^INSERT\s+INTO\s+(\w+)/i', $statement, $matches)) {
            $statementType = "INSERT INTO {$matches[1]}";
        } elseif (preg_match('/^CREATE\s+TABLE/i', $statement)) {
            $statementType = "CREATE TABLE";
        } elseif (preg_match('/^SELECT/i', $statement)) {
            $statementType = "SELECT";
        }

        echo "[{$progress}/{$totalStatements}] {$statementType}... ";

        try {
            // Skip SELECT statements (they're just for verification)
            if (preg_match('/^SELECT/i', $statement) && !preg_match('/^SELECT.*INTO/i', $statement)) {
                echo "{$blue}SKIPPED{$reset}\n";
                $skipCount++;
                continue;
            }

            $pdo->exec($statement);
            echo "{$green}OK{$reset}\n";
            $successCount++;

        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();

            // Check for common ignorable errors
            if (strpos($errorMsg, 'Duplicate column name') !== false ||
                strpos($errorMsg, 'already exists') !== false) {
                echo "{$yellow}ALREADY EXISTS{$reset}\n";
                $skipCount++;
            } else {
                echo "{$red}ERROR: {$errorMsg}{$reset}\n";
                $errorCount++;

                // Ask whether to continue
                if ($errorCount > 5) {
                    echo "{$red}Too many errors. Continue? (y/n): {$reset}";
                    $answer = trim(fgets(STDIN));
                    if (strtolower($answer) !== 'y') {
                        throw new Exception("Migration aborted by user");
                    }
                    $errorCount = 0;
                }
            }
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Display summary
    echo "\n{$blue}==================================={$reset}\n";
    echo "{$green}Migration Summary:{$reset}\n";
    echo "  Successful: {$green}{$successCount}{$reset}\n";
    echo "  Skipped: {$yellow}{$skipCount}{$reset}\n";
    echo "  Errors: " . ($errorCount > 0 ? "{$red}{$errorCount}{$reset}" : "{$green}0{$reset}") . "\n";

    // Verify the changes
    echo "\n{$yellow}Verifying changes...{$reset}\n";

    // Check if columns are nullable
    $checks = [
        ['table' => 'users', 'column' => 'tenant_id'],
        ['table' => 'folders', 'column' => 'tenant_id'],
        ['table' => 'files', 'column' => 'tenant_id']
    ];

    foreach ($checks as $check) {
        $stmt = $pdo->prepare("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = :table
            AND COLUMN_NAME = :column
        ");
        $stmt->execute(['table' => $check['table'], 'column' => $check['column']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['IS_NULLABLE'] === 'YES') {
            echo "  ✓ {$check['table']}.{$check['column']} is nullable{$green} OK{$reset}\n";
        } else {
            echo "  ✗ {$check['table']}.{$check['column']} is NOT nullable{$red} FAILED{$reset}\n";
        }
    }

    // Check for new columns
    $newColumns = [
        ['table' => 'users', 'column' => 'original_tenant_id'],
        ['table' => 'folders', 'column' => 'original_tenant_id'],
        ['table' => 'files', 'column' => 'original_tenant_id']
    ];

    foreach ($newColumns as $check) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = :table
            AND COLUMN_NAME = :column
        ");
        $stmt->execute(['table' => $check['table'], 'column' => $check['column']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['count'] > 0) {
            echo "  ✓ {$check['table']}.{$check['column']} exists{$green} OK{$reset}\n";
        } else {
            echo "  ✗ {$check['table']}.{$check['column']} missing{$yellow} WARNING{$reset}\n";
        }
    }

    // Check for stored procedures
    $procedures = ['SafeDeleteCompany', 'CheckUserLoginAccess', 'GetAccessibleFolders'];
    foreach ($procedures as $proc) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = 'collaboranexio'
            AND ROUTINE_NAME = :name
        ");
        $stmt->execute(['name' => $proc]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['count'] > 0) {
            echo "  ✓ Procedure {$proc} exists{$green} OK{$reset}\n";
        } else {
            echo "  ✗ Procedure {$proc} missing{$yellow} WARNING{$reset}\n";
        }
    }

    // Check for Super Admin root folder
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM folders WHERE tenant_id IS NULL AND parent_id IS NULL AND name = 'Super Admin Files'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['count'] > 0) {
        echo "  ✓ Super Admin root folder exists{$green} OK{$reset}\n";
    } else {
        echo "  ✗ Super Admin root folder missing{$yellow} WARNING{$reset}\n";
    }

    echo "\n{$green}==================================={$reset}\n";
    echo "{$green}Migration completed successfully!{$reset}\n";
    echo "{$green}==================================={$reset}\n\n";

    echo "{$yellow}Important Notes:{$reset}\n";
    echo "1. Company deletion will now reassign folders to Super Admin\n";
    echo "2. Regular users without a company cannot login\n";
    echo "3. Admin users can login even without a company\n";
    echo "4. Test the delete functionality before using in production\n\n";

} catch (Exception $e) {
    echo "\n{$red}Error: " . $e->getMessage() . "{$reset}\n\n";
    exit(1);
}
?>