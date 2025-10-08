<?php
/**
 * Test Aziende Form and APIs
 * Comprehensive testing for company management system
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session_init.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Test Aziende Form</title></head><body>";
    echo "<pre style='background:#f4f4f4;padding:20px;'>";
}

echo "=== TEST AZIENDE FORM & APIs ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

$testsPassed = 0;
$testsFailed = 0;

// TEST 1: Check if form file exists
echo "============================================\n";
echo "TEST 1: Files Existence\n";
echo "============================================\n";

$files = [
    'aziende_new.php' => __DIR__ . '/aziende_new.php',
    'js/aziende.js' => __DIR__ . '/js/aziende.js',
    'api/tenants/create.php' => __DIR__ . '/api/tenants/create.php',
    'api/tenants/list.php' => __DIR__ . '/api/tenants/list.php',
    'api/tenants/get.php' => __DIR__ . '/api/tenants/get.php',
    'api/tenants/update.php' => __DIR__ . '/api/tenants/update.php',
    'api/users/list_managers.php' => __DIR__ . '/api/users/list_managers.php'
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "[✓] $name exists\n";
        $testsPassed++;
    } else {
        echo "[✗] $name NOT found at: $path\n";
        $testsFailed++;
    }
}

// TEST 2: Check API endpoints accessibility
echo "\n============================================\n";
echo "TEST 2: API Endpoints Syntax Check\n";
echo "============================================\n";

$apis = [
    'api/tenants/create.php',
    'api/tenants/list.php',
    'api/tenants/get.php',
    'api/tenants/update.php',
    'api/users/list_managers.php'
];

foreach ($apis as $api) {
    $fullPath = __DIR__ . '/' . $api;
    $output = [];
    $returnCode = 0;

    // Use php -l to check syntax
    exec("php -l \"$fullPath\" 2>&1", $output, $returnCode);

    if ($returnCode === 0 && strpos(implode('', $output), 'No syntax errors') !== false) {
        echo "[✓] $api - No syntax errors\n";
        $testsPassed++;
    } else {
        echo "[✗] $api - Syntax error:\n";
        echo "    " . implode("\n    ", $output) . "\n";
        $testsFailed++;
    }
}

// TEST 3: Database structure verification
echo "\n============================================\n";
echo "TEST 3: Database Structure for Tenants\n";
echo "============================================\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Check required columns
    $requiredColumns = [
        'denominazione', 'codice_fiscale', 'partita_iva',
        'sede_legale_indirizzo', 'sede_legale_civico',
        'sede_legale_comune', 'sede_legale_provincia', 'sede_legale_cap',
        'sedi_operative', 'manager_id'
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM tenants");
    $existingColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['Field'];
    }

    foreach ($requiredColumns as $col) {
        if (in_array($col, $existingColumns)) {
            echo "[✓] Column tenants.$col exists\n";
            $testsPassed++;
        } else {
            echo "[✗] Column tenants.$col NOT found\n";
            $testsFailed++;
        }
    }

} catch (Exception $e) {
    echo "[✗] Database error: " . $e->getMessage() . "\n";
    $testsFailed++;
}

// TEST 4: Check for managers in database
echo "\n============================================\n";
echo "TEST 4: Available Managers Check\n";
echo "============================================\n";

try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM users
        WHERE role IN ('super_admin', 'admin', 'manager')
        AND is_active = 1
    ");
    $managerCount = $stmt->fetchColumn();

    if ($managerCount > 0) {
        echo "[✓] Found $managerCount available manager(s)\n";
        $testsPassed++;

        // List managers
        $stmt = $pdo->query("
            SELECT id, email, role
            FROM users
            WHERE role IN ('super_admin', 'admin', 'manager')
            AND is_active = 1
            ORDER BY role, email
        ");

        echo "\nAvailable managers:\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  - {$row['email']} ({$row['role']})\n";
        }
    } else {
        echo "[✗] No active managers found\n";
        $testsFailed++;
    }

} catch (Exception $e) {
    echo "[✗] Error checking managers: " . $e->getMessage() . "\n";
    $testsFailed++;
}

// TEST 5: Test Partita IVA validation function
echo "\n============================================\n";
echo "TEST 5: Partita IVA Validation Logic\n";
echo "============================================\n";

function validatePartitaIva($piva) {
    $piva = preg_replace('/[^0-9]/', '', $piva);
    if (strlen($piva) !== 11) return false;

    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $digit = (int)$piva[$i];
        if ($i % 2 === 0) {
            $sum += $digit;
        } else {
            $double = $digit * 2;
            $sum += ($double > 9) ? ($double - 9) : $double;
        }
    }
    $checkDigit = (10 - ($sum % 10)) % 10;
    return $checkDigit === (int)$piva[10];
}

$testCases = [
    '01234567890' => true,  // Valid placeholder
    '12345678901' => false, // Invalid checksum
    '00000000000' => true,  // Valid (all zeros)
    '1234567890' => false,  // Too short
    '123456789012' => false, // Too long
];

foreach ($testCases as $piva => $expected) {
    $result = validatePartitaIva($piva);
    if ($result === $expected) {
        echo "[✓] P.IVA validation: $piva => " . ($result ? 'VALID' : 'INVALID') . "\n";
        $testsPassed++;
    } else {
        echo "[✗] P.IVA validation failed: $piva (expected: " . ($expected ? 'VALID' : 'INVALID') . ", got: " . ($result ? 'VALID' : 'INVALID') . ")\n";
        $testsFailed++;
    }
}

// TEST 6: JavaScript file content check
echo "\n============================================\n";
echo "TEST 6: JavaScript Functions Check\n";
echo "============================================\n";

$jsFile = __DIR__ . '/js/aziende.js';
if (file_exists($jsFile)) {
    $jsContent = file_get_contents($jsFile);

    $requiredFunctions = [
        'validateCodiceFiscale',
        'validatePartitaIVA',
        'addSedeOperativa',
        'removeSedeOperativa',
        'validateForm'
    ];

    foreach ($requiredFunctions as $func) {
        if (strpos($jsContent, "function $func") !== false || strpos($jsContent, "$func =") !== false) {
            echo "[✓] JavaScript function '$func' found\n";
            $testsPassed++;
        } else {
            echo "[✗] JavaScript function '$func' NOT found\n";
            $testsFailed++;
        }
    }
} else {
    echo "[✗] JavaScript file not found\n";
    $testsFailed++;
}

// TEST 7: Form HTML structure check
echo "\n============================================\n";
echo "TEST 7: Form HTML Structure\n";
echo "============================================\n";

$formFile = __DIR__ . '/aziende_new.php';
if (file_exists($formFile)) {
    $formContent = file_get_contents($formFile);

    $requiredElements = [
        'denominazione',
        'codice_fiscale',
        'partita_iva',
        'sede_legale_indirizzo',
        'sede_legale_civico',
        'sedi_operative',
        'manager_id'
    ];

    foreach ($requiredElements as $elem) {
        if (strpos($formContent, "name=\"$elem") !== false || strpos($formContent, "id=\"$elem") !== false) {
            echo "[✓] Form field '$elem' found\n";
            $testsPassed++;
        } else {
            echo "[✗] Form field '$elem' NOT found\n";
            $testsFailed++;
        }
    }
} else {
    echo "[✗] Form file not found\n";
    $testsFailed++;
}

// SUMMARY
echo "\n============================================\n";
echo "SUMMARY\n";
echo "============================================\n";
$totalTests = $testsPassed + $testsFailed;
echo "Tests passed: $testsPassed / $totalTests\n";
echo "Tests failed: $testsFailed / $totalTests\n";
$percentage = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 2) : 0;
echo "Success rate: $percentage%\n";

if ($testsFailed == 0) {
    echo "\n[SUCCESS] ✓ All tests passed! Form is ready to use.\n";
    echo "\nAccess the form at:\n";
    echo "http://localhost:8888/CollaboraNexio/aziende_new.php\n";
} else {
    echo "\n[WARNING] ⚠ Some tests failed - review results above\n";
}

if (!$isCli) {
    echo "</pre></body></html>";
}
?>
