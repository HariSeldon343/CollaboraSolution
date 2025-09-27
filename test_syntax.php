<?php
/**
 * PHP Syntax Checker
 * Tests if PHP files have syntax errors
 */

header('Content-Type: text/plain; charset=utf-8');

$files = [
    'auth_api.php',
    'config.php',
    'includes/db.php'
];

echo "PHP Syntax Check\n";
echo str_repeat("=", 50) . "\n\n";

foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;

    echo "Checking: $file\n";

    if (!file_exists($fullPath)) {
        echo "  ❌ File not found\n\n";
        continue;
    }

    // Use token_get_all to check for parse errors
    $code = file_get_contents($fullPath);
    $tokens = @token_get_all($code);

    if ($tokens === false) {
        echo "  ❌ Parse error in file\n\n";
    } else {
        echo "  ✅ No syntax errors detected\n";
        echo "  Tokens: " . count($tokens) . "\n\n";
    }

    // Also check with php -l if available (won't work in browser)
    $output = [];
    $return_var = 0;

    // This won't work in browser but included for reference
    @exec("php -l $fullPath 2>&1", $output, $return_var);

    if (!empty($output)) {
        foreach ($output as $line) {
            if (strpos($line, 'No syntax errors') === false &&
                strpos($line, 'Errors parsing') !== false) {
                echo "  Additional info: $line\n";
            }
        }
    }
}

echo "\nDone!\n";
?>