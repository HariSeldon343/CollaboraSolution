<?php
/**
 * CollaboraNexio - Production Smoke Tests
 * PHP 8.3 Compatible
 *
 * Post-deployment validation script
 * Returns exit code 0 for success, 1 for failure
 */

declare(strict_types=1);

// Test configuration
define('TEST_TIMEOUT', 30); // seconds
define('TEST_VERBOSE', true); // Set to false for production

// Color codes for terminal output
define('COLOR_RED', "\033[0;31m");
define('COLOR_GREEN', "\033[0;32m");
define('COLOR_YELLOW', "\033[0;33m");
define('COLOR_BLUE', "\033[0;34m");
define('COLOR_RESET', "\033[0m");

// Test results tracking
$testResults = [];
$testsPassed = 0;
$testsFailed = 0;
$testsWarning = 0;

/**
 * Output formatted test result
 */
function outputResult(string $testName, bool $passed, string $message = '', bool $warning = false): void {
    global $testResults, $testsPassed, $testsFailed, $testsWarning;

    $status = $passed ? 'PASS' : ($warning ? 'WARN' : 'FAIL');
    $color = $passed ? COLOR_GREEN : ($warning ? COLOR_YELLOW : COLOR_RED);

    if (TEST_VERBOSE || !$passed) {
        echo sprintf(
            "%s[%s]%s %s%s\n",
            $color,
            $status,
            COLOR_RESET,
            $testName,
            $message ? " - $message" : ''
        );
    }

    $testResults[] = [
        'name' => $testName,
        'status' => $status,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($passed) {
        $testsPassed++;
    } elseif ($warning) {
        $testsWarning++;
    } else {
        $testsFailed++;
    }
}

/**
 * Test 1: PHP Version Check
 */
function testPhpVersion(): bool {
    $required = '8.3.0';
    $current = PHP_VERSION;
    $passed = version_compare($current, $required, '>=');

    outputResult(
        'PHP Version',
        $passed,
        $passed ? "PHP $current OK" : "PHP $current < $required required"
    );

    return $passed;
}

/**
 * Test 2: Required PHP Extensions
 */
function testPhpExtensions(): bool {
    $requiredExtensions = [
        'pdo', 'pdo_mysql', 'mbstring', 'json', 'session',
        'openssl', 'curl', 'gd', 'fileinfo', 'zip',
        'intl', 'opcache', 'redis'
    ];

    $allPassed = true;
    $missing = [];

    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $allPassed = false;
            $missing[] = $ext;
        }
    }

    outputResult(
        'PHP Extensions',
        $allPassed,
        $allPassed ? 'All required extensions loaded' : 'Missing: ' . implode(', ', $missing)
    );

    return $allPassed;
}

/**
 * Test 3: Database Connectivity
 */
function testDatabaseConnection(): bool {
    try {
        // Load configuration
        if (!file_exists(__DIR__ . '/config.php')) {
            outputResult('Database Connection', false, 'config.php not found');
            return false;
        }

        require_once __DIR__ . '/config.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT ?? 3306,
            DB_NAME,
            DB_CHARSET ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS ?? []);

        // Test query
        $stmt = $pdo->query('SELECT VERSION() as version');
        $version = $stmt->fetch(PDO::FETCH_ASSOC)['version'];

        outputResult('Database Connection', true, "MySQL $version connected");
        return true;

    } catch (Exception $e) {
        outputResult('Database Connection', false, 'Connection failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Test 4: Database Tables Verification
 */
function testDatabaseTables(): bool {
    try {
        require_once __DIR__ . '/config.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT ?? 3306,
            DB_NAME,
            DB_CHARSET ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS ?? []);

        // Critical tables that must exist
        $requiredTables = [
            'users', 'tenants', 'sessions', 'projects', 'tasks',
            'messages', 'files', 'notifications', 'activity_log',
            'permissions', 'roles', 'user_roles'
        ];

        $stmt = $pdo->query('SHOW TABLES');
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missingTables = array_diff($requiredTables, $existingTables);

        $passed = empty($missingTables);
        outputResult(
            'Database Tables',
            $passed,
            $passed ? count($existingTables) . ' tables found' : 'Missing tables: ' . implode(', ', $missingTables)
        );

        return $passed;

    } catch (Exception $e) {
        outputResult('Database Tables', false, 'Check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Test 5: File System Permissions
 */
function testFilePermissions(): bool {
    $directories = [
        'logs' => true,      // Must be writable
        'uploads' => true,   // Must be writable
        'temp' => true,      // Must be writable
        'sessions' => true,  // Must be writable
        'backups' => true,   // Must be writable
        'assets' => false,   // Read-only is OK
        'api' => false,      // Read-only is OK
        'includes' => false  // Read-only is OK
    ];

    $allPassed = true;
    $issues = [];

    foreach ($directories as $dir => $mustBeWritable) {
        $path = __DIR__ . '/' . $dir;

        if (!is_dir($path)) {
            if ($mustBeWritable) {
                // Try to create it
                if (!@mkdir($path, 0755, true)) {
                    $allPassed = false;
                    $issues[] = "$dir (cannot create)";
                }
            } else {
                $issues[] = "$dir (missing)";
            }
        } elseif ($mustBeWritable && !is_writable($path)) {
            $allPassed = false;
            $issues[] = "$dir (not writable)";
        }
    }

    outputResult(
        'File Permissions',
        $allPassed,
        $allPassed ? 'All directories OK' : 'Issues: ' . implode(', ', $issues),
        !$allPassed && count($issues) <= 2
    );

    return $allPassed || count($issues) <= 2; // Warning for minor issues
}

/**
 * Test 6: Configuration Files
 */
function testConfigurationFiles(): bool {
    $requiredFiles = [
        'config.php' => 'Main configuration',
        '.htaccess' => 'Apache configuration',
        'includes/db.php' => 'Database include',
        'includes/auth.php' => 'Authentication include'
    ];

    $allPassed = true;
    $missing = [];

    foreach ($requiredFiles as $file => $description) {
        $path = __DIR__ . '/' . $file;
        if (!file_exists($path)) {
            $allPassed = false;
            $missing[] = $file;
        }
    }

    outputResult(
        'Configuration Files',
        $allPassed,
        $allPassed ? 'All config files present' : 'Missing: ' . implode(', ', $missing)
    );

    return $allPassed;
}

/**
 * Test 7: API Endpoints
 */
function testApiEndpoints(): bool {
    $baseUrl = 'http://localhost/CollaboraNexio';
    $endpoints = [
        '/api/auth.php' => 'Authentication API',
        '/api/users.php' => 'Users API',
        '/api/projects.php' => 'Projects API',
        '/api/tasks.php' => 'Tasks API'
    ];

    $allPassed = true;
    $failed = [];

    foreach ($endpoints as $endpoint => $description) {
        $path = __DIR__ . $endpoint;
        if (!file_exists($path)) {
            $allPassed = false;
            $failed[] = basename($endpoint);
        }
    }

    outputResult(
        'API Endpoints',
        $allPassed,
        $allPassed ? count($endpoints) . ' endpoints available' : 'Missing: ' . implode(', ', $failed)
    );

    return $allPassed;
}

/**
 * Test 8: Security Headers
 */
function testSecurityHeaders(): bool {
    // Check if .htaccess exists and contains security headers
    $htaccessPath = __DIR__ . '/.htaccess';

    if (!file_exists($htaccessPath)) {
        outputResult('Security Headers', false, '.htaccess file not found');
        return false;
    }

    $htaccess = file_get_contents($htaccessPath);
    $securityHeaders = [
        'X-Frame-Options',
        'X-Content-Type-Options',
        'X-XSS-Protection',
        'Strict-Transport-Security',
        'Content-Security-Policy'
    ];

    $found = [];
    $missing = [];

    foreach ($securityHeaders as $header) {
        if (stripos($htaccess, $header) !== false) {
            $found[] = $header;
        } else {
            $missing[] = $header;
        }
    }

    $passed = empty($missing);
    outputResult(
        'Security Headers',
        $passed,
        $passed ? 'All security headers configured' : 'Missing: ' . implode(', ', $missing),
        count($missing) <= 2
    );

    return $passed || count($missing) <= 2;
}

/**
 * Test 9: Session Configuration
 */
function testSessionConfiguration(): bool {
    $settings = [
        'session.cookie_httponly' => '1',
        'session.use_only_cookies' => '1',
        'session.use_strict_mode' => '1'
    ];

    $allPassed = true;
    $issues = [];

    foreach ($settings as $setting => $expected) {
        $actual = ini_get($setting);
        if ($actual != $expected) {
            $allPassed = false;
            $issues[] = "$setting=$actual (expected $expected)";
        }
    }

    outputResult(
        'Session Security',
        $allPassed,
        $allPassed ? 'Session configuration secure' : 'Issues: ' . implode(', ', $issues),
        count($issues) <= 1
    );

    return $allPassed || count($issues) <= 1;
}

/**
 * Test 10: Performance Baseline
 */
function testPerformanceBaseline(): bool {
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    // Simulate basic operations
    for ($i = 0; $i < 1000; $i++) {
        $hash = hash('sha256', (string)$i);
    }

    $executionTime = (microtime(true) - $startTime) * 1000; // Convert to ms
    $memoryUsed = (memory_get_usage(true) - $startMemory) / 1048576; // Convert to MB

    $performanceOk = $executionTime < 100 && $memoryUsed < 10;

    outputResult(
        'Performance Baseline',
        $performanceOk,
        sprintf('Time: %.2fms, Memory: %.2fMB', $executionTime, $memoryUsed),
        $executionTime < 200
    );

    return $performanceOk || $executionTime < 200;
}

/**
 * Test 11: Cache System
 */
function testCacheSystem(): bool {
    $cacheDir = __DIR__ . '/cache';
    $testFile = $cacheDir . '/test_' . uniqid() . '.cache';

    // Test cache directory
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            outputResult('Cache System', false, 'Cannot create cache directory');
            return false;
        }
    }

    // Test cache write
    $testData = json_encode(['test' => true, 'timestamp' => time()]);
    if (!@file_put_contents($testFile, $testData)) {
        outputResult('Cache System', false, 'Cannot write to cache');
        return false;
    }

    // Test cache read
    $readData = @file_get_contents($testFile);
    if ($readData !== $testData) {
        outputResult('Cache System', false, 'Cache read/write mismatch');
        @unlink($testFile);
        return false;
    }

    // Cleanup
    @unlink($testFile);

    outputResult('Cache System', true, 'Cache system operational');
    return true;
}

/**
 * Test 12: Email Configuration (basic check)
 */
function testEmailConfiguration(): bool {
    // Check if mail function exists
    if (!function_exists('mail')) {
        outputResult('Email Configuration', false, 'mail() function not available', true);
        return false;
    }

    // Check SMTP settings in config
    if (defined('MAIL_HOST') && defined('MAIL_PORT')) {
        $host = MAIL_HOST;
        $port = MAIL_PORT;

        // Test SMTP connection (timeout 5 seconds)
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);

        if ($fp) {
            fclose($fp);
            outputResult('Email Configuration', true, "SMTP $host:$port reachable");
            return true;
        } else {
            outputResult('Email Configuration', false, "SMTP $host:$port unreachable", true);
            return false;
        }
    } else {
        outputResult('Email Configuration', true, 'Using local mail() function');
        return true;
    }
}

/**
 * Test 13: Error Logging
 */
function testErrorLogging(): bool {
    $logDir = __DIR__ . '/logs';
    $testLog = $logDir . '/test_' . date('Y-m-d') . '.log';

    // Check log directory
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            outputResult('Error Logging', false, 'Cannot create log directory');
            return false;
        }
    }

    // Test log writing
    $testMessage = sprintf("[%s] Smoke test log entry\n", date('Y-m-d H:i:s'));
    if (!@error_log($testMessage, 3, $testLog)) {
        outputResult('Error Logging', false, 'Cannot write to log file');
        return false;
    }

    outputResult('Error Logging', true, 'Logging system operational');
    return true;
}

/**
 * Test 14: OPcache Status
 */
function testOpcacheStatus(): bool {
    if (!function_exists('opcache_get_status')) {
        outputResult('OPcache', false, 'OPcache not available', true);
        return false;
    }

    $status = @opcache_get_status(false);

    if (!$status || !isset($status['opcache_enabled']) || !$status['opcache_enabled']) {
        outputResult('OPcache', false, 'OPcache not enabled', true);
        return false;
    }

    $memoryUsage = $status['memory_usage'];
    $usedPercentage = ($memoryUsage['used_memory'] /
                      ($memoryUsage['used_memory'] + $memoryUsage['free_memory'])) * 100;

    outputResult(
        'OPcache',
        true,
        sprintf('Enabled, Memory: %.1f%% used', $usedPercentage)
    );

    return true;
}

/**
 * Test 15: Critical Features Check
 */
function testCriticalFeatures(): bool {
    $features = [];
    $allPassed = true;

    // Check JSON support
    if (!function_exists('json_encode')) {
        $features[] = 'JSON support missing';
        $allPassed = false;
    }

    // Check hash functions
    if (!function_exists('password_hash')) {
        $features[] = 'Password hashing missing';
        $allPassed = false;
    }

    // Check session support
    if (session_status() === PHP_SESSION_DISABLED) {
        $features[] = 'Sessions disabled';
        $allPassed = false;
    }

    // Check timezone
    if (date_default_timezone_get() === 'UTC') {
        $features[] = 'Timezone not configured';
        // This is a warning, not a failure
    }

    outputResult(
        'Critical Features',
        $allPassed,
        $allPassed ? 'All critical features available' : 'Issues: ' . implode(', ', $features)
    );

    return $allPassed;
}

// ==============================================================
// MAIN TEST EXECUTION
// ==============================================================

echo COLOR_BLUE . "\n==============================================================\n";
echo "  CollaboraNexio - Production Smoke Tests\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "==============================================================\n" . COLOR_RESET;

// Run all tests
$criticalTests = [
    'testPhpVersion',
    'testPhpExtensions',
    'testDatabaseConnection',
    'testDatabaseTables',
    'testConfigurationFiles'
];

$standardTests = [
    'testFilePermissions',
    'testApiEndpoints',
    'testSecurityHeaders',
    'testSessionConfiguration',
    'testPerformanceBaseline',
    'testCacheSystem',
    'testEmailConfiguration',
    'testErrorLogging',
    'testOpcacheStatus',
    'testCriticalFeatures'
];

$criticalFailed = false;

echo "\n" . COLOR_YELLOW . "Running Critical Tests..." . COLOR_RESET . "\n";
foreach ($criticalTests as $test) {
    if (!$test()) {
        $criticalFailed = true;
    }
}

if ($criticalFailed) {
    echo "\n" . COLOR_RED . "Critical tests failed! Deployment should be rolled back." . COLOR_RESET . "\n";
} else {
    echo "\n" . COLOR_YELLOW . "Running Standard Tests..." . COLOR_RESET . "\n";
    foreach ($standardTests as $test) {
        $test();
    }
}

// Summary
echo "\n" . COLOR_BLUE . "==============================================================\n";
echo "  TEST SUMMARY\n";
echo "==============================================================\n" . COLOR_RESET;

echo COLOR_GREEN . "  Passed: $testsPassed\n" . COLOR_RESET;
if ($testsWarning > 0) {
    echo COLOR_YELLOW . "  Warnings: $testsWarning\n" . COLOR_RESET;
}
if ($testsFailed > 0) {
    echo COLOR_RED . "  Failed: $testsFailed\n" . COLOR_RESET;
}

$totalTests = $testsPassed + $testsFailed + $testsWarning;
$successRate = ($testsPassed / $totalTests) * 100;

echo sprintf("\n  Success Rate: %.1f%%\n", $successRate);

// Write results to JSON file
$resultsFile = __DIR__ . '/logs/smoke_test_' . date('Y-m-d_His') . '.json';
@file_put_contents($resultsFile, json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'total_tests' => $totalTests,
    'passed' => $testsPassed,
    'failed' => $testsFailed,
    'warnings' => $testsWarning,
    'success_rate' => $successRate,
    'results' => $testResults
], JSON_PRETTY_PRINT));

// Determine exit code
if ($criticalFailed || $testsFailed > 3 || $successRate < 70) {
    echo "\n" . COLOR_RED . "DEPLOYMENT VALIDATION FAILED!\n" . COLOR_RESET;
    exit(1);
} elseif ($testsFailed > 0 || $testsWarning > 3) {
    echo "\n" . COLOR_YELLOW . "DEPLOYMENT COMPLETED WITH WARNINGS\n" . COLOR_RESET;
    exit(0);
} else {
    echo "\n" . COLOR_GREEN . "DEPLOYMENT VALIDATION SUCCESSFUL!\n" . COLOR_RESET;
    exit(0);
}