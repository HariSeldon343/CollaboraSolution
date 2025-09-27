@echo off
setlocal enabledelayedexpansion
title CollaboraNexio - Fix 500 Error
color 0A

echo ==========================================
echo   CollaboraNexio 500 Error Fix Tool
echo   Emergency repair for Apache errors
echo ==========================================
echo.

:: Check if running as administrator
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Run as Administrator for best results
    echo.
)

:: Set paths
set XAMPP_PATH=C:\xampp
set APACHE_LOG=%XAMPP_PATH%\apache\logs\error.log
set PROJECT_PATH=%XAMPP_PATH%\htdocs\CollaboraNexio
set PHP_PATH=%XAMPP_PATH%\php\php.exe

echo [1/10] Checking Apache error logs...
echo ----------------------------------------
if exist "%APACHE_LOG%" (
    echo Last 10 error entries:
    powershell -command "Get-Content '%APACHE_LOG%' -Tail 10"
    echo.
) else (
    echo Error log not found at %APACHE_LOG%
    echo.
)

echo [2/10] Checking for .htaccess issues...
echo ----------------------------------------
cd /d "%PROJECT_PATH%"
if exist .htaccess (
    echo Found .htaccess file
    echo Backing up to .htaccess.bak_%date:~-4,4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2%
    copy .htaccess .htaccess.bak_%date:~-4,4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2% >nul 2>&1

    echo Testing without .htaccess...
    ren .htaccess .htaccess.disabled >nul 2>&1

    echo Creating minimal .htaccess for testing...
    (
        echo # Minimal .htaccess for testing
        echo Options -Indexes
        echo DirectoryIndex index.php index.html
    ) > .htaccess

    echo .htaccess temporarily replaced with minimal version
) else (
    echo No .htaccess file found
)
echo.

echo [3/10] Checking PHP syntax in main files...
echo ----------------------------------------
for %%f in (index.php config.php login.php) do (
    if exist "%%f" (
        echo Checking %%f...
        "%PHP_PATH%" -l "%%f" >nul 2>&1
        if !errorlevel! == 0 (
            echo   [OK] %%f has valid syntax
        ) else (
            echo   [ERROR] %%f has syntax errors:
            "%PHP_PATH%" -l "%%f" 2>&1
        )
    )
)
echo.

echo [4/10] Checking PHP configuration...
echo ----------------------------------------
"%PHP_PATH%" -v >nul 2>&1
if %errorlevel% == 0 (
    echo PHP is working:
    "%PHP_PATH%" -v | findstr /i "PHP"
) else (
    echo [ERROR] PHP is not working properly
)
echo.

echo [5/10] Creating test.php file...
echo ----------------------------------------
(
    echo ^<?php
    echo // Test file to verify PHP is working
    echo error_reporting^(E_ALL^);
    echo ini_set^('display_errors', 1^);
    echo echo "^<h1^>PHP is working!^</h1^>";
    echo echo "^<p^>PHP Version: " . phpversion^(^) . "^</p^>";
    echo echo "^<p^>Server: " . $_SERVER['SERVER_SOFTWARE'] . "^</p^>";
    echo echo "^<p^>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "^</p^>";
    echo phpinfo^(^);
    echo ?^>
) > test.php
echo Created test.php - Try accessing http://localhost/CollaboraNexio/test.php
echo.

echo [6/10] Checking file permissions...
echo ----------------------------------------
echo Setting proper permissions for web files...
icacls "%PROJECT_PATH%" /grant Everyone:F /T >nul 2>&1
echo Permissions updated
echo.

echo [7/10] Checking required PHP extensions...
echo ----------------------------------------
"%PHP_PATH%" -m | findstr /i "pdo mysqli session json" >nul 2>&1
if %errorlevel% == 0 (
    echo Required extensions found
) else (
    echo [WARNING] Some required extensions may be missing
    "%PHP_PATH%" -m | findstr /i "pdo mysqli session json"
)
echo.

echo [8/10] Testing database connection...
echo ----------------------------------------
if exist config.php (
    echo Creating database test script...
    (
        echo ^<?php
        echo error_reporting^(E_ALL^);
        echo ini_set^('display_errors', 1^);
        echo if^(file_exists^('config.php'^)^) {
        echo     @include 'config.php';
        echo     if^(defined^('DB_HOST'^)^) {
        echo         try {
        echo             $pdo = new PDO^("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS^);
        echo             echo "Database connection successful!";
        echo         } catch^(Exception $e^) {
        echo             echo "Database connection failed: " . $e-^>getMessage^(^);
        echo         }
        echo     } else {
        echo         echo "Database constants not defined in config.php";
        echo     }
        echo } else {
        echo     echo "config.php not found";
        echo }
        echo ?^>
    ) > test_db.php

    "%PHP_PATH%" test_db.php
    echo.
    del test_db.php >nul 2>&1
) else (
    echo config.php not found
)
echo.

echo [9/10] Restarting Apache...
echo ----------------------------------------
net stop Apache2.4 >nul 2>&1
timeout /t 2 >nul
net start Apache2.4 >nul 2>&1
if %errorlevel% == 0 (
    echo Apache restarted successfully
) else (
    echo Trying XAMPP control...
    "%XAMPP_PATH%\apache\bin\httpd.exe" -k restart >nul 2>&1
    echo Apache restart attempted
)
echo.

echo [10/10] Final checks and recommendations...
echo ----------------------------------------
echo.
echo DIAGNOSTIC SUMMARY:
echo ===================
echo 1. Test basic PHP: http://localhost/CollaboraNexio/test.php
echo 2. Test diagnostic: http://localhost/CollaboraNexio/diagnostic.php
echo 3. Emergency access: http://localhost/CollaboraNexio/emergency_access.php
echo.
echo If site still shows 500 error:
echo - Check Apache error log: %APACHE_LOG%
echo - Restore original .htaccess: ren .htaccess.disabled .htaccess
echo - Check PHP error log: %XAMPP_PATH%\php\logs\php_error_log
echo - Run reset_htaccess.bat for .htaccess issues
echo.
echo MOST COMMON FIXES:
echo - .htaccess has been replaced with minimal version
echo - File permissions have been updated
echo - Test files created to verify PHP/DB
echo.

pause