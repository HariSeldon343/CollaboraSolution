<?php
/**
 * ISTAT Municipalities Import Script
 *
 * Imports Italian municipalities from ISTAT CSV file into the database.
 * Part of Phase 1 - Complete Italian Municipalities System
 *
 * CSV Format (semicolon delimited):
 * Column 5:  Codice Comune formato alfanumerico (ISTAT code)
 * Column 7:  Denominazione in italiano (Municipality name)
 * Column 15: Sigla automobilistica (Province code)
 * Column 11: Denominazione Regione (Region)
 * Column 20: Codice Catastale del comune (Cadastral code)
 *
 * Usage: php database/import_istat_municipalities.php
 */

declare(strict_types=1);

// CLI only execution
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

// ANSI color codes for CLI output
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_RED', "\033[31m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_RESET', "\033[0m");

/**
 * Remove Italian accents and normalize string for ASCII conversion
 *
 * @param string $str Input string with accents
 * @return string String with accents removed
 */
function removeAccents(string $str): string
{
    $accents = [
        'à' => 'a', 'À' => 'A',
        'è' => 'e', 'È' => 'E',
        'é' => 'e', 'É' => 'E',
        'ì' => 'i', 'Ì' => 'I',
        'ò' => 'o', 'Ò' => 'O',
        'ù' => 'u', 'Ù' => 'U',
        "'" => '', '-' => ' '
    ];

    $result = str_replace(array_keys($accents), array_values($accents), $str);
    return trim($result);
}

/**
 * Normalize municipality name for searching
 *
 * @param string $name Municipality name
 * @return string Normalized lowercase name
 */
function normalizeName(string $name): string
{
    return mb_strtolower(trim($name), 'UTF-8');
}

/**
 * Convert to ASCII-only string
 *
 * @param string $name Municipality name
 * @return string ASCII-only version
 */
function toAscii(string $name): string
{
    return removeAccents($name);
}

/**
 * Extract postal code prefix from ISTAT code
 *
 * @param string $istatCode ISTAT code (e.g., "001001")
 * @return string|null Postal code prefix (first 3 digits)
 */
function extractPostalPrefix(string $istatCode): ?string
{
    if (strlen($istatCode) >= 3) {
        return substr($istatCode, 0, 3);
    }
    return null;
}

/**
 * Print colored message to CLI
 *
 * @param string $message Message to print
 * @param string $color Color code
 */
function printColored(string $message, string $color = COLOR_RESET): void
{
    echo $color . $message . COLOR_RESET . PHP_EOL;
}

/**
 * Format duration in human-readable format
 *
 * @param float $seconds Duration in seconds
 * @return string Formatted duration
 */
function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.2f seconds', $seconds);
    } elseif ($seconds < 3600) {
        return sprintf('%.2f minutes', $seconds / 60);
    } else {
        return sprintf('%.2f hours', $seconds / 3600);
    }
}

// Main execution
try {
    printColored('=======================================================', COLOR_BLUE);
    printColored('  ISTAT Municipalities Import Script', COLOR_BLUE);
    printColored('  Phase 1 - Complete Italian Municipalities System', COLOR_BLUE);
    printColored('=======================================================', COLOR_BLUE);
    echo PHP_EOL;

    $startTime = microtime(true);

    // Define CSV file path
    $csvFile = __DIR__ . '/data/istat_comuni_italiani_2025.csv';

    // Verify file exists
    printColored('[1/6] Verifying CSV file...', COLOR_YELLOW);
    if (!file_exists($csvFile)) {
        throw new Exception("CSV file not found: {$csvFile}");
    }

    $fileSize = filesize($csvFile);
    printColored("      Found: {$csvFile} (" . number_format($fileSize) . " bytes)", COLOR_GREEN);
    echo PHP_EOL;

    // Initialize database connection
    printColored('[2/6] Connecting to database...', COLOR_YELLOW);
    $db = Database::getInstance();
    $conn = $db->getConnection();
    printColored('      Database connected: ' . DB_NAME, COLOR_GREEN);
    echo PHP_EOL;

    // Prepare database
    printColored('[3/6] Preparing database...', COLOR_YELLOW);

    // Disable foreign key checks
    $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
    printColored('      Foreign key checks disabled', COLOR_GREEN);

    // Truncate table (preserves structure, removes data)
    $conn->exec('TRUNCATE TABLE italian_municipalities');
    printColored('      Table truncated: italian_municipalities', COLOR_GREEN);
    echo PHP_EOL;

    // Open CSV file
    printColored('[4/6] Reading CSV file...', COLOR_YELLOW);
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        throw new Exception('Failed to open CSV file');
    }

    // Skip header row (first 2 rows are headers in ISTAT format)
    $header1 = fgetcsv($handle, 0, ';');
    $header2 = fgetcsv($handle, 0, ';');
    printColored('      Skipped header rows (ISTAT format)', COLOR_GREEN);
    echo PHP_EOL;

    // Prepare insert statement
    printColored('[5/6] Importing municipalities...', COLOR_YELLOW);

    $insertSql = "INSERT INTO italian_municipalities (
        istat_code,
        name,
        province_code,
        cadastral_code,
        postal_code_prefix,
        created_at
    ) VALUES (?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($insertSql);

    // Batch import configuration
    $batchSize = 500;
    $totalRecords = 0;
    $errorCount = 0;
    $batch = [];

    // Begin transaction
    $db->beginTransaction();

    printColored('      Starting import (batch size: ' . $batchSize . ')...', COLOR_GREEN);
    echo PHP_EOL;

    // Process each row
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        // Skip empty rows
        if (empty($row[0]) || count($row) < 20) {
            continue;
        }

        try {
            // Parse CSV columns (0-indexed)
            // Column 5 (index 4): ISTAT code
            // Column 7 (index 6): Municipality name in Italian
            // Column 15 (index 14): Province code (Sigla automobilistica)
            // Column 20 (index 19): Cadastral code
            $istatCode = trim($row[4] ?? '');
            $name = trim($row[6] ?? '');
            $provinceCode = trim($row[14] ?? '');
            $cadastralCode = trim($row[19] ?? '');

            // Validate required fields
            if (empty($istatCode) || empty($name) || empty($provinceCode)) {
                printColored("      Warning: Skipping invalid row - Missing required fields (ISTAT: {$istatCode}, Name: {$name}, Province: {$provinceCode})", COLOR_RED);
                $errorCount++;
                continue;
            }

            // Generate postal code prefix from ISTAT code
            $postalPrefix = extractPostalPrefix($istatCode);

            // Execute insert
            $stmt->execute([
                $istatCode,
                $name,
                $provinceCode,
                $cadastralCode,
                $postalPrefix
            ]);

            $totalRecords++;

            // Show progress every 500 records
            if ($totalRecords % $batchSize === 0) {
                $elapsed = microtime(true) - $startTime;
                $rate = $totalRecords / $elapsed;
                printColored(
                    sprintf('      Progress: %s records imported (%.0f records/sec)',
                        number_format($totalRecords),
                        $rate
                    ),
                    COLOR_BLUE
                );
            }

        } catch (Exception $e) {
            printColored("      Error: " . $e->getMessage(), COLOR_RED);
            $errorCount++;
        }
    }

    // Close CSV file
    fclose($handle);

    // Commit transaction
    $db->commit();
    printColored('      Transaction committed', COLOR_GREEN);
    echo PHP_EOL;

    // Re-enable foreign key checks
    $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    printColored('      Foreign key checks re-enabled', COLOR_GREEN);
    echo PHP_EOL;

    // Calculate statistics
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $rate = $totalRecords / $duration;

    // Verification
    printColored('[6/6] Verifying import...', COLOR_YELLOW);

    // Get total count
    $totalCount = $db->fetchOne('SELECT COUNT(*) as count FROM italian_municipalities');
    printColored('      Total municipalities in database: ' . number_format($totalCount['count']), COLOR_GREEN);

    // Get province distribution
    $provinceStats = $db->fetchAll(
        'SELECT province_code, COUNT(*) as count
         FROM italian_municipalities
         GROUP BY province_code
         ORDER BY count DESC
         LIMIT 10'
    );

    echo PHP_EOL;
    printColored('      Top 10 Provinces by Municipality Count:', COLOR_BLUE);
    foreach ($provinceStats as $stat) {
        printf("      - %s: %s municipalities\n",
            $stat['province_code'],
            number_format($stat['count'])
        );
    }

    echo PHP_EOL;
    printColored('=======================================================', COLOR_BLUE);
    printColored('  IMPORT COMPLETED SUCCESSFULLY', COLOR_GREEN);
    printColored('=======================================================', COLOR_BLUE);
    echo PHP_EOL;

    printColored('Summary:', COLOR_BLUE);
    printColored('  Total records imported: ' . number_format($totalRecords), COLOR_GREEN);
    printColored('  Errors encountered: ' . number_format($errorCount), $errorCount > 0 ? COLOR_YELLOW : COLOR_GREEN);
    printColored('  Duration: ' . formatDuration($duration), COLOR_BLUE);
    printColored('  Import rate: ' . number_format($rate, 2) . ' records/second', COLOR_BLUE);
    echo PHP_EOL;

    printColored('Next steps:', COLOR_YELLOW);
    printColored('  1. Verify data integrity: SELECT * FROM italian_municipalities LIMIT 10;', COLOR_BLUE);
    printColored('  2. Test search functionality with the new data', COLOR_BLUE);
    printColored('  3. Proceed to Phase 2 - API endpoint implementation', COLOR_BLUE);
    echo PHP_EOL;

} catch (Exception $e) {
    printColored('=======================================================', COLOR_RED);
    printColored('  ERROR DURING IMPORT', COLOR_RED);
    printColored('=======================================================', COLOR_RED);
    echo PHP_EOL;

    printColored('Error: ' . $e->getMessage(), COLOR_RED);
    printColored('File: ' . $e->getFile(), COLOR_RED);
    printColored('Line: ' . $e->getLine(), COLOR_RED);
    echo PHP_EOL;

    // Rollback transaction if active
    try {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
            printColored('Transaction rolled back', COLOR_YELLOW);
        }
    } catch (Exception $rollbackError) {
        printColored('Failed to rollback: ' . $rollbackError->getMessage(), COLOR_RED);
    }

    // Re-enable foreign key checks
    try {
        if (isset($conn)) {
            $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
            printColored('Foreign key checks re-enabled', COLOR_YELLOW);
        }
    } catch (Exception $fkError) {
        printColored('Failed to re-enable foreign keys: ' . $fkError->getMessage(), COLOR_RED);
    }

    exit(1);
}
