@echo off
setlocal enabledelayedexpansion
color 0E
echo ========================================
echo PHP CLI TEST SCRIPT
echo ========================================
echo.

:: Set XAMPP paths
set XAMPP_PATH=C:\xampp
set PHP_PATH=%XAMPP_PATH%\php
set PHP_EXE=%PHP_PATH%\php.exe

echo [1/7] Checking PHP installation path...
if not exist "%PHP_EXE%" (
    echo [ERROR] PHP not found at %PHP_EXE%
    echo.
    echo Searching for PHP in common locations...
    if exist "C:\php\php.exe" (
        echo Found PHP at C:\php\php.exe
        set PHP_EXE=C:\php\php.exe
        set PHP_PATH=C:\php
    ) else if exist "%ProgramFiles%\php\php.exe" (
        echo Found PHP at %ProgramFiles%\php\php.exe
        set PHP_EXE=%ProgramFiles%\php\php.exe
        set PHP_PATH=%ProgramFiles%\php
    ) else (
        echo [ERROR] Could not find PHP installation
        pause
        exit /b 1
    )
)
echo [OK] PHP found at %PHP_EXE%
echo.

echo [2/7] Testing PHP version...
echo ----------------------------------------
"%PHP_EXE%" -v
if %errorlevel% neq 0 (
    echo ----------------------------------------
    echo [ERROR] PHP version check failed
    echo This usually means PHP is corrupted or missing DLLs
    pause
    exit /b 1
)
echo ----------------------------------------
echo [OK] PHP version check successful
echo.

echo [3/7] Checking PHP configuration file...
echo ----------------------------------------
"%PHP_EXE%" --ini
echo ----------------------------------------
echo.

echo [4/7] Listing loaded PHP modules...
echo ----------------------------------------
"%PHP_EXE%" -m | more
echo ----------------------------------------
echo [OK] PHP modules listed
echo.

echo [5/7] Checking for Apache module...
echo ----------------------------------------
"%PHP_EXE%" -m | findstr /i "apache"
if %errorlevel% neq 0 (
    echo [INFO] Apache module not loaded in CLI (this is normal)
) else (
    echo [OK] Apache module detected
)

:: Check for php8apache2_4.dll
if exist "%PHP_PATH%\php8apache2_4.dll" (
    echo [OK] php8apache2_4.dll found
) else if exist "%PHP_PATH%\php7apache2_4.dll" (
    echo [INFO] php7apache2_4.dll found (PHP 7)
) else if exist "%PHP_PATH%\php5apache2_4.dll" (
    echo [INFO] php5apache2_4.dll found (PHP 5)
) else (
    echo [WARNING] No Apache DLL module found in %PHP_PATH%
)
echo ----------------------------------------
echo.

echo [6/7] Creating and running PHP test script...
echo ----------------------------------------

:: Create test PHP file
echo ^<?php > test_cli.php
echo echo "=== PHP CLI TEST ===" . PHP_EOL; >> test_cli.php
echo echo "PHP Version: " . PHP_VERSION . PHP_EOL; >> test_cli.php
echo echo "PHP Binary: " . PHP_BINARY . PHP_EOL; >> test_cli.php
echo echo "PHP OS: " . PHP_OS . PHP_EOL; >> test_cli.php
echo echo "PHP SAPI: " . PHP_SAPI . PHP_EOL; >> test_cli.php
echo echo "Loaded Extensions: " . PHP_EOL; >> test_cli.php
echo $extensions = get_loaded_extensions(); >> test_cli.php
echo foreach($extensions as $ext) { >> test_cli.php
echo     echo "  - " . $ext . PHP_EOL; >> test_cli.php
echo } >> test_cli.php
echo echo PHP_EOL . "=== Basic Operations Test ===" . PHP_EOL; >> test_cli.php
echo $test_array = ['a' =^> 1, 'b' =^> 2, 'c' =^> 3]; >> test_cli.php
echo echo "Array test: "; >> test_cli.php
echo print_r($test_array); >> test_cli.php
echo echo "Math test: 10 + 20 = " . (10 + 20) . PHP_EOL; >> test_cli.php
echo echo "String test: " . strtoupper("php is working") . PHP_EOL; >> test_cli.php
echo echo PHP_EOL . "=== Test Complete ===" . PHP_EOL; >> test_cli.php
echo ?^> >> test_cli.php

:: Run the test script
"%PHP_EXE%" test_cli.php
if %errorlevel% neq 0 (
    echo ----------------------------------------
    echo [ERROR] PHP test script failed to run
    del test_cli.php >nul 2>&1
    pause
    exit /b 1
)
echo ----------------------------------------
echo [OK] PHP CLI test completed successfully
del test_cli.php >nul 2>&1
echo.

echo [7/7] Checking PHP error reporting...
echo ----------------------------------------
"%PHP_EXE%" -r "echo 'Error reporting: ' . error_reporting() . PHP_EOL;"
"%PHP_EXE%" -r "echo 'Display errors: ' . (ini_get('display_errors') ? 'On' : 'Off') . PHP_EOL;"
"%PHP_EXE%" -r "echo 'Log errors: ' . (ini_get('log_errors') ? 'On' : 'Off') . PHP_EOL;"
echo ----------------------------------------
echo.

echo ========================================
echo PHP CLI TEST COMPLETE
echo ========================================
echo.
echo Summary:
echo - PHP CLI is working correctly
echo - PHP version and modules checked
echo - Basic PHP operations tested
echo.
echo If PHP CLI works but Apache doesn't serve PHP files:
echo 1. The issue is with Apache configuration
echo 2. Run fix_php_module.bat to fix Apache-PHP integration
echo 3. Check that LoadModule php_module is configured in httpd.conf
echo.
pause