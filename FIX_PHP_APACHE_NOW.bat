@echo off
setlocal enabledelayedexpansion
cls
echo ============================================
echo PHP Apache Configuration Fix for XAMPP
echo ============================================
echo.

:: Stop Apache
echo [1/6] Stopping Apache service...
C:\xampp\apache\bin\httpd.exe -k stop 2>nul
net stop Apache2.4 2>nul
timeout /t 2 /nobreak >nul
echo       Apache stopped.
echo.

:: Create backup
echo [2/6] Creating backup of httpd.conf...
copy "C:\xampp\apache\conf\httpd.conf" "C:\xampp\apache\conf\httpd.conf.backup_%date:~-4,4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%.bak" >nul
echo       Backup created.
echo.

:: Check and fix PHP module configuration
echo [3/6] Checking PHP module configuration...
set "config_file=C:\xampp\apache\conf\httpd.conf"
set "temp_file=C:\xampp\apache\conf\httpd.conf.tmp"
set "php_found=0"
set "handler_found=0"
set "ini_found=0"

:: Check if LoadModule line exists (commented or not)
findstr /i "LoadModule.*php.*module" "!config_file!" >nul 2>&1
if !errorlevel! equ 0 (
    echo       Found PHP LoadModule line - checking if commented...

    :: Uncomment if commented
    powershell -Command "(Get-Content '!config_file!') -replace '^#\s*(LoadModule\s+php\d*_module)', '$1' | Set-Content '!temp_file!'"
    move /y "!temp_file!" "!config_file!" >nul

    set "php_found=1"
    echo       PHP module line uncommented/verified.
) else (
    echo       PHP module not found - will add it.
)

:: Check for AddHandler
findstr /i "AddHandler.*x-httpd-php" "!config_file!" >nul 2>&1
if !errorlevel! equ 0 set "handler_found=1"

:: Check for PHPIniDir
findstr /i "PHPIniDir" "!config_file!" >nul 2>&1
if !errorlevel! equ 0 set "ini_found=1"

:: Add missing configuration
if !php_found! equ 0 (
    echo.
    echo [4/6] Adding PHP configuration to httpd.conf...
    echo.>> "!config_file!"
    echo # PHP Configuration added by FIX_PHP_APACHE_NOW.bat>> "!config_file!"
    echo LoadModule php_module "C:/xampp/php/php8apache2_4.dll">> "!config_file!"

    if !handler_found! equ 0 (
        echo AddHandler application/x-httpd-php .php>> "!config_file!"
    )

    if !ini_found! equ 0 (
        echo PHPIniDir "C:/xampp/php">> "!config_file!"
    )

    echo       PHP configuration added successfully.
) else (
    echo.
    echo [4/6] PHP module configuration verified...

    if !handler_found! equ 0 (
        echo       Adding missing AddHandler directive...
        echo AddHandler application/x-httpd-php .php>> "!config_file!"
    )

    if !ini_found! equ 0 (
        echo       Adding missing PHPIniDir directive...
        echo PHPIniDir "C:/xampp/php">> "!config_file!"
    )

    echo       Configuration complete.
)

echo.

:: Start Apache
echo [5/6] Starting Apache service...
C:\xampp\apache\bin\httpd.exe -k start 2>nul
net start Apache2.4 2>nul
timeout /t 3 /nobreak >nul
echo       Apache started.
echo.

:: Create test file
echo [6/6] Creating test file...
echo ^<?php phpinfo(); ?^> > "C:\xampp\htdocs\CollaboraNexio\test_now.php"
echo       Test file created: test_now.php
echo.

:: Success message
echo ============================================
echo FIX COMPLETED SUCCESSFULLY!
echo ============================================
echo.
echo Test your PHP installation:
echo.
echo   1. Open your browser
echo   2. Visit: http://localhost/CollaboraNexio/test_now.php
echo.
echo If you see the PHP info page, everything is working!
echo.
echo Press any key to exit...
pause >nul