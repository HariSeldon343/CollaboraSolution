<?php
/**
 * Test BASE_URL Configuration - CLI Version
 *
 * Script rapido da eseguire in console per verificare BASE_URL
 *
 * UTILIZZO:
 *   php test_base_url_cli.php
 */

require_once __DIR__ . '/config.php';

echo "\n";
echo "=====================================\n";
echo "   BASE_URL Configuration Test\n";
echo "=====================================\n\n";

// Funzione helper per formattare output
function printRow($label, $value, $expected = null) {
    $labelPadded = str_pad($label, 25);
    echo $labelPadded . ": " . $value;

    if ($expected !== null) {
        $match = ($value === $expected);
        echo $match ? " ✓" : " ✗ (expected: $expected)";
    }

    echo "\n";
}

function printSection($title) {
    echo "\n" . str_repeat("-", 40) . "\n";
    echo "  $title\n";
    echo str_repeat("-", 40) . "\n";
}

// SEZIONE 1: Environment Detection
printSection("ENVIRONMENT DETECTION");

$httpHost = $_SERVER['HTTP_HOST'] ?? 'N/A';
$serverName = $_SERVER['SERVER_NAME'] ?? 'N/A';
$isProduction = strpos($httpHost, 'nexiosolution.it') !== false;

printRow("HTTP_HOST", $httpHost);
printRow("SERVER_NAME", $serverName);
printRow("Environment", $isProduction ? 'PRODUCTION' : 'DEVELOPMENT');
printRow("PRODUCTION_MODE", PRODUCTION_MODE ? 'TRUE' : 'FALSE');
printRow("DEBUG_MODE", DEBUG_MODE ? 'TRUE' : 'FALSE');
printRow("ENVIRONMENT", ENVIRONMENT);

// SEZIONE 2: BASE_URL Configuration
printSection("BASE_URL CONFIGURATION");

$expectedBaseUrl = $isProduction
    ? 'https://app.nexiosolution.it/CollaboraNexio'
    : 'http://localhost:8888/CollaboraNexio';

printRow("BASE_URL (Current)", BASE_URL);
printRow("BASE_URL (Expected)", $expectedBaseUrl);
printRow("Match", BASE_URL === $expectedBaseUrl ? 'YES ✓' : 'NO ✗');

// SEZIONE 3: Session Configuration
printSection("SESSION CONFIGURATION");

printRow("SESSION_NAME", SESSION_NAME);
printRow("SESSION_SECURE", SESSION_SECURE ? 'TRUE (HTTPS)' : 'FALSE (HTTP)');
printRow("SESSION_DOMAIN", SESSION_DOMAIN ?: '(empty - localhost)');
printRow("SESSION_HTTPONLY", SESSION_HTTPONLY ? 'TRUE' : 'FALSE');
printRow("SESSION_SAMESITE", SESSION_SAMESITE);
printRow("SESSION_LIFETIME", SESSION_LIFETIME . ' seconds');

// Verifica correttezza configurazione sessione
if ($isProduction) {
    $sessionCorrect = SESSION_SECURE && SESSION_DOMAIN === '.nexiosolution.it';
} else {
    $sessionCorrect = !SESSION_SECURE && SESSION_DOMAIN === '';
}

printRow("Session Config", $sessionCorrect ? 'CORRECT ✓' : 'INCORRECT ✗');

// SEZIONE 4: Generated Links Test
printSection("GENERATED LINKS TEST");

$testToken = 'test_token_abc123xyz';
$resetLink = BASE_URL . '/set_password.php?token=' . urlencode($testToken);
$loginLink = BASE_URL . '/index.php';
$dashboardLink = BASE_URL . '/dashboard.php';

echo "Reset Password Link:\n  $resetLink\n\n";
echo "Login Link:\n  $loginLink\n\n";
echo "Dashboard Link:\n  $dashboardLink\n\n";

// Verifica che i link non contengano localhost in produzione
if ($isProduction) {
    $hasLocalhostError = (
        strpos($resetLink, 'localhost') !== false ||
        strpos($loginLink, 'localhost') !== false ||
        strpos($dashboardLink, 'localhost') !== false
    );

    if ($hasLocalhostError) {
        echo "❌ ERROR: Links contain 'localhost' in PRODUCTION environment!\n";
    } else {
        echo "✅ Links are correct for PRODUCTION\n";
    }
} else {
    $hasProductionDomain = (
        strpos($resetLink, 'nexiosolution.it') !== false ||
        strpos($loginLink, 'nexiosolution.it') !== false ||
        strpos($dashboardLink, 'nexiosolution.it') !== false
    );

    if ($hasProductionDomain) {
        echo "⚠️  WARNING: Links contain production domain in DEVELOPMENT\n";
    } else {
        echo "✅ Links are correct for DEVELOPMENT\n";
    }
}

// SEZIONE 5: Email Configuration
printSection("EMAIL CONFIGURATION");

// Carica configurazione email se disponibile
$emailConfigFile = __DIR__ . '/includes/config_email.php';
if (file_exists($emailConfigFile)) {
    require_once $emailConfigFile;

    if (defined('EMAIL_SMTP_HOST')) {
        printRow("SMTP Host", EMAIL_SMTP_HOST);
        printRow("SMTP Port", EMAIL_SMTP_PORT);
        printRow("From Email", EMAIL_FROM_EMAIL);
        printRow("From Name", EMAIL_FROM_NAME ?? 'CollaboraNexio');
    } else {
        echo "Email config file exists but constants not defined\n";
    }
} else {
    echo "Email config file not found: $emailConfigFile\n";
    echo "Using database configuration or defaults\n";
}

// SEZIONE 6: File Paths
printSection("FILE PATHS");

printRow("BASE_PATH", BASE_PATH);
printRow("UPLOAD_PATH", UPLOAD_PATH);
printRow("TEMP_PATH", TEMP_PATH);
printRow("LOG_PATH", LOG_PATH);

// Verifica esistenza directory
$uploadExists = is_dir(UPLOAD_PATH);
$tempExists = is_dir(TEMP_PATH);
$logExists = is_dir(LOG_PATH);

printRow("Upload Dir Exists", $uploadExists ? 'YES ✓' : 'NO ✗');
printRow("Temp Dir Exists", $tempExists ? 'YES ✓' : 'NO ✗');
printRow("Log Dir Exists", $logExists ? 'YES ✓' : 'NO ✗');

// SEZIONE 7: Security Settings
printSection("SECURITY SETTINGS");

printRow("CSRF Token Name", CSRF_TOKEN_NAME);
printRow("Max File Size", number_format(MAX_FILE_SIZE / 1024 / 1024, 2) . ' MB');
printRow("Login Max Attempts", LOGIN_MAX_ATTEMPTS);
printRow("Lockout Time", LOGIN_LOCKOUT_TIME . ' seconds');

// SEZIONE 8: Database Configuration
printSection("DATABASE CONFIGURATION");

printRow("DB Host", DB_HOST);
printRow("DB Port", DB_PORT);
printRow("DB Name", DB_NAME);
printRow("DB User", DB_USER);
printRow("DB Charset", DB_CHARSET);

// Test connessione database
try {
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();

    if ($conn) {
        echo "\n✅ Database Connection: SUCCESS\n";

        // Verifica tabelle principali
        $tables = ['users', 'tenants', 'files', 'projects'];
        $missingTables = [];

        foreach ($tables as $table) {
            $stmt = $conn->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        }

        if (empty($missingTables)) {
            echo "✅ Core Tables: ALL PRESENT\n";
        } else {
            echo "⚠️  Missing Tables: " . implode(', ', $missingTables) . "\n";
        }
    }
} catch (Exception $e) {
    echo "\n❌ Database Connection: FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
}

// SEZIONE 9: Final Summary
printSection("SUMMARY");

$allChecks = [
    'Environment Detection' => $isProduction === PRODUCTION_MODE,
    'BASE_URL Correct' => BASE_URL === $expectedBaseUrl,
    'Session Config' => $sessionCorrect,
    'Upload Dir' => $uploadExists,
    'Temp Dir' => $tempExists,
    'Log Dir' => $logExists
];

$passedChecks = count(array_filter($allChecks));
$totalChecks = count($allChecks);

echo "\nTests Passed: $passedChecks / $totalChecks\n\n";

foreach ($allChecks as $checkName => $passed) {
    $status = $passed ? '✅' : '❌';
    echo "$status $checkName\n";
}

// SEZIONE 10: Recommendations
printSection("RECOMMENDATIONS");

$hasIssues = false;

if (BASE_URL !== $expectedBaseUrl) {
    echo "❌ BASE_URL mismatch detected!\n";
    echo "   Current:  " . BASE_URL . "\n";
    echo "   Expected: " . $expectedBaseUrl . "\n";
    echo "   Fix: Check config.php auto-detect logic\n\n";
    $hasIssues = true;
}

if (!$sessionCorrect) {
    echo "⚠️  Session configuration incorrect for environment\n";
    if ($isProduction) {
        echo "   Production requires:\n";
        echo "   - SESSION_SECURE = true\n";
        echo "   - SESSION_DOMAIN = '.nexiosolution.it'\n\n";
    } else {
        echo "   Development requires:\n";
        echo "   - SESSION_SECURE = false\n";
        echo "   - SESSION_DOMAIN = ''\n\n";
    }
    $hasIssues = true;
}

if (!$uploadExists || !$tempExists || !$logExists) {
    echo "⚠️  Required directories missing\n";
    echo "   Run: mkdir -p uploads temp logs && chmod 755 uploads temp logs\n\n";
    $hasIssues = true;
}

if (!$hasIssues) {
    echo "\n✅ No issues detected! Configuration is correct.\n";
}

// SEZIONE 11: Next Steps
printSection("NEXT STEPS");

echo "\n";
echo "1. Test in browser:\n";
if ($isProduction) {
    echo "   https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php\n\n";
} else {
    echo "   http://localhost:8888/CollaboraNexio/verify_base_url.php\n\n";
}

echo "2. Test email creation:\n";
if ($isProduction) {
    echo "   https://app.nexiosolution.it/CollaboraNexio/utenti.php\n";
    echo "   Create user and verify email links use https://app.nexiosolution.it\n\n";
} else {
    echo "   http://localhost:8888/CollaboraNexio/utenti.php\n";
    echo "   Create user and verify email links use http://localhost:8888\n\n";
}

echo "3. Check logs:\n";
echo "   tail -f " . LOG_PATH . "/mailer_error.log\n";
echo "   Look for: \"status\":\"success\"\n\n";

echo "4. Full documentation:\n";
echo "   - BASE_URL_CONFIGURATION_REPORT.md\n";
echo "   - TEST_BASE_URL_GUIDE.md\n\n";

echo "=====================================\n";
echo "   Test Completed\n";
echo "=====================================\n\n";

// Exit code
exit($hasIssues ? 1 : 0);
