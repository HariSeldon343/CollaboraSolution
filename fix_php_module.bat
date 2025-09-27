@echo off
setlocal enabledelayedexpansion
color 0A
echo ========================================
echo PHP MODULE FIX FOR XAMPP - MAIN SCRIPT
echo ========================================
echo.

:: Set XAMPP paths
set XAMPP_PATH=C:\xampp
set PHP_PATH=%XAMPP_PATH%\php
set APACHE_PATH=%XAMPP_PATH%\apache
set HTTPD_CONF=%APACHE_PATH%\conf\httpd.conf
set PHP_DLL=%PHP_PATH%\php8apache2_4.dll

:: Check if running as admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Please run this script as Administrator!
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

echo [1/10] Checking XAMPP installation...
if not exist "%XAMPP_PATH%" (
    echo [ERROR] XAMPP not found at %XAMPP_PATH%
    pause
    exit /b 1
)
echo [OK] XAMPP found at %XAMPP_PATH%
echo.

echo [2/10] Checking PHP installation...
if not exist "%PHP_PATH%\php.exe" (
    echo [ERROR] PHP not found at %PHP_PATH%
    pause
    exit /b 1
)
echo [OK] PHP found at %PHP_PATH%

:: Test PHP from command line
echo [3/10] Testing PHP CLI...
"%PHP_PATH%\php.exe" -v >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] PHP CLI test failed
) else (
    echo [OK] PHP CLI works
    "%PHP_PATH%\php.exe" -v | findstr /i "PHP"
)
echo.

echo [4/10] Checking PHP Apache module...
if not exist "%PHP_DLL%" (
    echo [ERROR] PHP Apache module not found: %PHP_DLL%
    echo Searching for alternative PHP modules...
    dir "%PHP_PATH%\php*apache*.dll" 2>nul
    pause
    exit /b 1
)
echo [OK] PHP Apache module found: %PHP_DLL%
echo.

echo [5/10] Backing up httpd.conf...
copy "%HTTPD_CONF%" "%HTTPD_CONF%.backup_%date:~-4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%" >nul 2>&1
echo [OK] Backup created
echo.

echo [6/10] Checking current PHP module configuration...
findstr /i "LoadModule.*php" "%HTTPD_CONF%" >temp_php_check.txt 2>nul
set PHP_MODULE_FOUND=0
for /f "tokens=*" %%a in (temp_php_check.txt) do (
    echo Found: %%a
    echo %%a | findstr /v "^#" >nul
    if !errorlevel! equ 0 (
        set PHP_MODULE_FOUND=1
        echo [INFO] PHP module line is active (not commented)
    ) else (
        echo [WARNING] PHP module line is commented out
    )
)
del temp_php_check.txt >nul 2>&1

if %PHP_MODULE_FOUND% equ 0 (
    echo [WARNING] No active PHP module configuration found
)
echo.

echo [7/10] Fixing PHP configuration in httpd.conf...

:: Create a temporary file with the correct PHP configuration
echo # PHP 8 Apache Module Configuration > temp_php_config.txt
echo LoadModule php_module "%PHP_DLL:\=/%" >> temp_php_config.txt
echo AddHandler application/x-httpd-php .php >> temp_php_config.txt
echo PHPIniDir "%PHP_PATH:\=/%" >> temp_php_config.txt
echo. >> temp_php_config.txt

:: Remove existing PHP configurations (commented or not)
powershell -Command "(Get-Content '%HTTPD_CONF%') | Where-Object {$_ -notmatch 'LoadModule.*php|AddHandler.*php|PHPIniDir'} | Set-Content '%HTTPD_CONF%.temp'"
if exist "%HTTPD_CONF%.temp" (
    move /Y "%HTTPD_CONF%.temp" "%HTTPD_CONF%" >nul
)

:: Find the LoadModule section and add PHP configuration
powershell -Command "$content = Get-Content '%HTTPD_CONF%'; $phpConfig = Get-Content 'temp_php_config.txt'; $insertIndex = 0; for($i=0; $i -lt $content.Length; $i++) { if($content[$i] -match '^LoadModule') { $insertIndex = $i + 1 } }; if($insertIndex -gt 0) { $newContent = $content[0..($insertIndex-1)] + $phpConfig + $content[$insertIndex..($content.Length-1)]; $newContent | Set-Content '%HTTPD_CONF%' }"

del temp_php_config.txt >nul 2>&1
echo [OK] PHP configuration updated
echo.

echo [8/10] Verifying PHP handler configuration...
findstr /i "AddHandler.*php" "%HTTPD_CONF%" >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] PHP handler not found, adding it...
    echo AddHandler application/x-httpd-php .php >> "%HTTPD_CONF%"
)
echo [OK] PHP handler configured
echo.

echo [9/10] Creating test PHP file...
echo ^<?php > "%XAMPP_PATH%\htdocs\test_php.php"
echo echo "PHP Version: " . phpversion() . "^<br^>"; >> "%XAMPP_PATH%\htdocs\test_php.php"
echo echo "PHP is working correctly!^<br^>"; >> "%XAMPP_PATH%\htdocs\test_php.php"
echo echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "^<br^>"; >> "%XAMPP_PATH%\htdocs\test_php.php"
echo ?^> >> "%XAMPP_PATH%\htdocs\test_php.php"
echo [OK] Test file created at %XAMPP_PATH%\htdocs\test_php.php
echo.

echo [10/10] Restarting Apache...
:: Stop Apache
"%APACHE_PATH%\bin\httpd.exe" -k stop >nul 2>&1
"%XAMPP_PATH%\apache_stop.bat" >nul 2>&1
taskkill /F /IM httpd.exe >nul 2>&1
timeout /t 2 >nul

:: Start Apache
"%XAMPP_PATH%\apache_start.bat" >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Could not start Apache using apache_start.bat
    echo Trying alternative method...
    "%APACHE_PATH%\bin\httpd.exe" -k start >nul 2>&1
)
timeout /t 3 >nul

:: Check if Apache is running
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I /N "httpd.exe">nul
if %errorlevel% equ 0 (
    echo [OK] Apache is running
) else (
    echo [ERROR] Apache failed to start
    echo Check error logs at %APACHE_PATH%\logs\error.log
)
echo.

echo ========================================
echo FIX COMPLETE!
echo ========================================
echo.
echo Next steps:
echo 1. Open your browser
echo 2. Go to: http://localhost/test_php.php
echo 3. You should see "PHP is working correctly!"
echo.
echo If PHP still doesn't work:
echo - Run test_php_cli.bat to test PHP installation
echo - Run fix_httpd_conf.bat for advanced fixes
echo - Check Apache error log: %APACHE_PATH%\logs\error.log
echo.
pause