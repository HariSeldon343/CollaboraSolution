<?php
/**
 * Database Schema Verification Script
 * Verifies integrity and congruence after schema drift fixes
 *
 * Usage: php verify_schema_fix.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Color output helpers
function colorOutput($text, $color = 'white') {
    $colors = [
        'red' => "\033[0;31m",
        'green' => "\033[0;32m",
        'yellow' => "\033[0;33m",
        'blue' => "\033[0;34m",
        'white' => "\033[0;37m",
        'bold' => "\033[1m",
        'reset' => "\033[0m"
    ];
    return $colors[$color] . $text . $colors['reset'];
}

function printHeader($text) {
    echo "\n" . colorOutput(str_repeat("=", 80), 'blue') . "\n";
    echo colorOutput($text, 'bold') . "\n";
    echo colorOutput(str_repeat("=", 80), 'blue') . "\n";
}

function printSuccess($text) {
    echo colorOutput("[✓] " . $text, 'green') . "\n";
}

function printError($text) {
    echo colorOutput("[✗] " . $text, 'red') . "\n";
}

function printWarning($text) {
    echo colorOutput("[!] " . $text, 'yellow') . "\n";
}

function printInfo($text) {
    echo colorOutput("[i] " . $text, 'white') . "\n";
}

// Database connection
$host = 'localhost';
$db = 'collaboranexio';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    printSuccess("Database connection established");
} catch (PDOException $e) {
    printError("Database connection failed: " . $e->getMessage());
    exit(1);
}

$errors = [];
$warnings = [];
$passed = 0;
$failed = 0;

// ============================================
// 1. VERIFY FILES TABLE STRUCTURE
// ============================================
printHeader("1. FILES TABLE STRUCTURE VERIFICATION");

$expectedColumns = [
    'id' => ['Type' => 'int unsigned', 'Null' => 'NO', 'Key' => 'PRI'],
    'tenant_id' => ['Type' => 'int unsigned', 'Null' => 'YES', 'Key' => 'MUL'],
    'folder_id' => ['Type' => 'int unsigned', 'Null' => 'YES', 'Key' => 'MUL'],
    'name' => ['Type' => 'varchar(255)', 'Null' => 'NO'],
    'file_path' => ['Type' => 'varchar(500)', 'Null' => 'YES'],
    'file_size' => ['Type' => 'bigint', 'Null' => 'YES'],
    'file_type' => ['Type' => 'varchar(50)', 'Null' => 'YES'],
    'mime_type' => ['Type' => 'varchar(100)', 'Null' => 'YES'],
    'uploaded_by' => ['Type' => 'int unsigned', 'Null' => 'YES', 'Key' => 'MUL'],
    'status' => ['Type' => 'varchar(50)', 'Null' => 'YES'],
    'created_at' => ['Type' => 'timestamp', 'Null' => 'NO'],
    'updated_at' => ['Type' => 'timestamp', 'Null' => 'NO']
];

$stmt = $pdo->query("DESCRIBE files");
$actualColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actualColumnMap = [];
foreach ($actualColumns as $col) {
    $actualColumnMap[$col['Field']] = $col;
}

printInfo("Checking critical columns...");
foreach ($expectedColumns as $colName => $expected) {
    if (!isset($actualColumnMap[$colName])) {
        printError("Column '$colName' is MISSING");
        $errors[] = "Missing column: $colName";
        $failed++;
        continue;
    }

    $actual = $actualColumnMap[$colName];

    // Check type (relaxed check for variations)
    $actualType = strtolower($actual['Type']);
    $expectedType = strtolower($expected['Type']);

    // Normalize type variations
    $actualType = preg_replace('/\(\d+\)/', '', $actualType); // Remove length specifiers for basic check
    $expectedType = preg_replace('/\(\d+\)/', '', $expectedType);

    if (strpos($actualType, $expectedType) === false && strpos($expectedType, $actualType) === false) {
        printWarning("Column '$colName' type mismatch: expected ~'$expectedType', got '$actualType'");
        $warnings[] = "Column $colName type variation";
    } else {
        printSuccess("Column '$colName' exists with correct type");
        $passed++;
    }
}

// ============================================
// 2. VERIFY FOREIGN KEYS
// ============================================
printHeader("2. FOREIGN KEY VERIFICATION");

$stmt = $pdo->query("
    SELECT
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$db'
    AND TABLE_NAME = 'files'
    AND REFERENCED_TABLE_NAME IS NOT NULL
");
$foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

$expectedFKs = [
    'tenant_id' => 'tenants',
    'uploaded_by' => 'users',
    'folder_id' => 'folders'
];

$foundFKs = [];
foreach ($foreignKeys as $fk) {
    $foundFKs[$fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
    printInfo("FK: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}");
}

foreach ($expectedFKs as $column => $refTable) {
    if (isset($foundFKs[$column])) {
        if ($foundFKs[$column] === $refTable) {
            printSuccess("Foreign key '$column' -> '$refTable' is correct");
            $passed++;
        } else {
            printError("Foreign key '$column' references wrong table: {$foundFKs[$column]} (expected $refTable)");
            $errors[] = "Wrong FK reference: $column";
            $failed++;
        }
    } else {
        printError("Foreign key '$column' -> '$refTable' is MISSING");
        $errors[] = "Missing FK: $column";
        $failed++;
    }
}

// ============================================
// 3. VERIFY INDEXES
// ============================================
printHeader("3. INDEX VERIFICATION");

$stmt = $pdo->query("SHOW INDEX FROM files");
$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$indexMap = [];
foreach ($indexes as $idx) {
    $indexMap[$idx['Key_name']][] = $idx['Column_name'];
}

printInfo("Found indexes:");
foreach ($indexMap as $idxName => $columns) {
    printInfo("  - $idxName: " . implode(', ', $columns));
}

// Check for tenant_id index (critical for multi-tenant queries)
$hasTenantIndex = false;
foreach ($indexMap as $idxName => $columns) {
    if (in_array('tenant_id', $columns)) {
        $hasTenantIndex = true;
        printSuccess("Index on 'tenant_id' exists (critical for multi-tenant queries)");
        $passed++;
        break;
    }
}

if (!$hasTenantIndex && $indexMap) {
    printWarning("No specific index on 'tenant_id' found (foreign key index may exist)");
    $warnings[] = "Consider adding composite index on tenant_id";
}

// ============================================
// 4. DATA INTEGRITY CHECKS
// ============================================
printHeader("4. DATA INTEGRITY VERIFICATION");

// Count records
$tables = ['files', 'folders', 'file_versions', 'users', 'tenants'];
$counts = [];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $counts[$table] = $result['cnt'];
        printInfo("$table: {$counts[$table]} records");
    } catch (PDOException $e) {
        printWarning("Could not count $table: " . $e->getMessage());
    }
}

// Check for NULL values in critical NOT NULL columns
printInfo("\nChecking for NULL values in critical columns...");

$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM files WHERE file_path IS NULL OR file_path = ''");
$nullPaths = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
if ($nullPaths > 0) {
    printError("Found $nullPaths files with NULL/empty file_path");
    $errors[] = "NULL file_path values found";
    $failed++;
} else {
    printSuccess("All files have valid file_path");
    $passed++;
}

$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM files WHERE file_size IS NULL");
$nullSizes = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
if ($nullSizes > 0) {
    printError("Found $nullSizes files with NULL file_size");
    $errors[] = "NULL file_size values found";
    $failed++;
} else {
    printSuccess("All files have valid file_size");
    $passed++;
}

$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM files WHERE uploaded_by IS NULL");
$nullUploader = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
if ($nullUploader > 0) {
    printError("Found $nullUploader files with NULL uploaded_by");
    $errors[] = "NULL uploaded_by values found";
    $failed++;
} else {
    printSuccess("All files have valid uploaded_by");
    $passed++;
}

// Verify tenant_id references
printInfo("\nVerifying tenant_id references...");
$stmt = $pdo->query("
    SELECT COUNT(*) as cnt
    FROM files f
    LEFT JOIN tenants t ON f.tenant_id = t.id
    WHERE t.id IS NULL
");
$orphanedTenants = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
if ($orphanedTenants > 0) {
    printError("Found $orphanedTenants files with invalid tenant_id");
    $errors[] = "Invalid tenant_id references found";
    $failed++;
} else {
    printSuccess("All files have valid tenant_id references");
    $passed++;
}

// Verify uploaded_by references
printInfo("\nVerifying uploaded_by references...");
$stmt = $pdo->query("
    SELECT COUNT(*) as cnt
    FROM files f
    LEFT JOIN users u ON f.uploaded_by = u.id
    WHERE u.id IS NULL
");
$orphanedUsers = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
if ($orphanedUsers > 0) {
    printError("Found $orphanedUsers files with invalid uploaded_by");
    $errors[] = "Invalid uploaded_by references found";
    $failed++;
} else {
    printSuccess("All files have valid uploaded_by references");
    $passed++;
}

// ============================================
// 5. TEST CRITICAL QUERIES
// ============================================
printHeader("5. CRITICAL QUERY TESTING");

// Test 1: File listing with joins
printInfo("\nTest 1: File listing with user join...");
try {
    $stmt = $pdo->query("
        SELECT
            f.id,
            f.name,
            f.file_path,
            f.file_size,
            f.mime_type,
            f.file_type,
            f.status,
            u.name as uploaded_by_name,
            f.created_at
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.tenant_id = 1
        AND f.deleted_at IS NULL
        LIMIT 5
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    printSuccess("Query executed successfully, returned " . count($results) . " rows");
    $passed++;

    if (count($results) > 0) {
        printInfo("Sample result:");
        $sample = $results[0];
        foreach ($sample as $key => $value) {
            printInfo("  - $key: " . (is_null($value) ? 'NULL' : substr($value, 0, 50)));
        }
    }
} catch (PDOException $e) {
    printError("Query failed: " . $e->getMessage());
    $errors[] = "File listing query failed";
    $failed++;
}

// Test 2: Document approval query
printInfo("\nTest 2: Document approval query...");
try {
    $stmt = $pdo->query("
        SELECT
            f.id,
            f.name,
            f.file_size,
            f.status,
            u.name as uploader,
            f.created_at
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.status = 'in_approvazione'
        AND f.tenant_id = 1
        AND f.deleted_at IS NULL
        LIMIT 5
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    printSuccess("Approval query executed successfully, found " . count($results) . " pending files");
    $passed++;
} catch (PDOException $e) {
    printError("Approval query failed: " . $e->getMessage());
    $errors[] = "Approval query failed";
    $failed++;
}

// Test 3: File with folder hierarchy
printInfo("\nTest 3: File with folder hierarchy...");
try {
    $stmt = $pdo->query("
        SELECT
            f.id,
            f.name,
            f.file_size,
            fo.name as folder_name,
            u.name as uploader
        FROM files f
        LEFT JOIN files fo ON f.folder_id = fo.id AND fo.is_folder = 1
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.tenant_id = 1
        AND f.deleted_at IS NULL
        LIMIT 5
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    printSuccess("Folder hierarchy query executed successfully");
    $passed++;
} catch (PDOException $e) {
    printError("Folder hierarchy query failed: " . $e->getMessage());
    $errors[] = "Folder hierarchy query failed";
    $failed++;
}

// ============================================
// 6. VERIFY CONSTRAINTS
// ============================================
printHeader("6. CONSTRAINT VERIFICATION");

printInfo("Checking table constraints...");
$stmt = $pdo->query("
    SELECT
        CONSTRAINT_NAME,
        CONSTRAINT_TYPE
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = '$db'
    AND TABLE_NAME = 'files'
");
$constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasFK = false;
$hasPK = false;

foreach ($constraints as $constraint) {
    printInfo("  - {$constraint['CONSTRAINT_NAME']}: {$constraint['CONSTRAINT_TYPE']}");
    if ($constraint['CONSTRAINT_TYPE'] === 'FOREIGN KEY') {
        $hasFK = true;
    }
    if ($constraint['CONSTRAINT_TYPE'] === 'PRIMARY KEY') {
        $hasPK = true;
    }
}

if ($hasPK) {
    printSuccess("Primary key constraint exists");
    $passed++;
} else {
    printError("Primary key constraint missing");
    $errors[] = "Missing primary key";
    $failed++;
}

if ($hasFK) {
    printSuccess("Foreign key constraints exist");
    $passed++;
} else {
    printError("No foreign key constraints found");
    $errors[] = "Missing foreign keys";
    $failed++;
}

// ============================================
// 7. FINAL REPORT
// ============================================
printHeader("VERIFICATION REPORT");

echo "\n";
printInfo("Database: $db");
printInfo("Timestamp: " . date('Y-m-d H:i:s'));
echo "\n";

printInfo("Table Record Counts:");
foreach ($counts as $table => $count) {
    echo "  - $table: $count\n";
}
echo "\n";

printInfo("Test Results:");
echo "  " . colorOutput("Passed: $passed", 'green') . "\n";
echo "  " . colorOutput("Failed: $failed", 'red') . "\n";
echo "  " . colorOutput("Warnings: " . count($warnings), 'yellow') . "\n";
echo "\n";

if (count($errors) > 0) {
    printError("CRITICAL ISSUES FOUND:");
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    printWarning("WARNINGS:");
    foreach ($warnings as $warning) {
        echo "  - $warning\n";
    }
    echo "\n";
}

// Final verdict
printHeader("FINAL VERDICT");

if ($failed === 0 && count($errors) === 0) {
    printSuccess("DATABASE IS PRODUCTION READY!");
    printSuccess("All critical checks passed successfully");
    printInfo("Schema drift has been resolved");
    printInfo("Data integrity is intact");
    printInfo("All foreign keys and constraints are working");
    echo "\n";
    exit(0);
} elseif ($failed > 0 || count($errors) > 0) {
    printError("DATABASE HAS CRITICAL ISSUES!");
    printError("Please review and fix the errors above before proceeding");
    echo "\n";
    exit(1);
} else {
    printWarning("DATABASE HAS MINOR WARNINGS");
    printInfo("Review warnings but system should be functional");
    echo "\n";
    exit(0);
}
