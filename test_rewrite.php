<?php
// Test if mod_rewrite is enabled
echo "<h1>Apache mod_rewrite Test</h1>";

if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    if (in_array('mod_rewrite', $modules)) {
        echo "<p style='color: green;'>✓ mod_rewrite is enabled</p>";
    } else {
        echo "<p style='color: red;'>✗ mod_rewrite is NOT enabled</p>";
    }
    echo "<h3>All Apache Modules:</h3><pre>";
    print_r($modules);
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>Cannot check Apache modules (apache_get_modules not available)</p>";
    echo "<p>This usually means PHP is running as CGI/FastCGI instead of as an Apache module.</p>";
}

// Show server info
echo "<h3>Server Info:</h3><pre>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "PHP Version: " . phpversion() . "\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "</pre>";

// Test if .htaccess is being processed
echo "<h3>Request URI Test:</h3><pre>";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "PATH_INFO: " . (isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : 'Not set') . "\n";
echo "</pre>";
?>