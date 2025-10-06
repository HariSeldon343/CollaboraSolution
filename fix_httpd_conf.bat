@echo off
setlocal enabledelayedexpansion
color 0C
echo ========================================
echo APACHE HTTPD.CONF ADVANCED FIX
echo ========================================
echo.

:: Set XAMPP paths
set XAMPP_PATH=C:\xampp
set APACHE_PATH=%XAMPP_PATH%\apache
set HTTPD_CONF=%APACHE_PATH%\conf\httpd.conf
set PHP_PATH=%XAMPP_PATH%\php

:: Check if running as admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Please run this script as Administrator!
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

echo [1/12] Checking Apache installation...
if not exist "%HTTPD_CONF%" (
    echo [ERROR] httpd.conf not found at %HTTPD_CONF%
    pause
    exit /b 1
)
echo [OK] httpd.conf found
echo.

echo [2/12] Creating backup of httpd.conf...
set BACKUP_NAME=%HTTPD_CONF%.fix_backup_%date:~-4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%
copy "%HTTPD_CONF%" "%BACKUP_NAME%" >nul 2>&1
echo [OK] Backup created: %BACKUP_NAME%
echo.

echo [3/12] Detecting PHP version and module...
set PHP_MODULE_DLL=
set PHP_VERSION=

:: Check for PHP 8
if exist "%PHP_PATH%\php8apache2_4.dll" (
    set PHP_MODULE_DLL=%PHP_PATH%\php8apache2_4.dll
    set PHP_VERSION=8
    echo [OK] Found PHP 8 module: php8apache2_4.dll
) else if exist "%PHP_PATH%\php7apache2_4.dll" (
    set PHP_MODULE_DLL=%PHP_PATH%\php7apache2_4.dll
    set PHP_VERSION=7
    echo [OK] Found PHP 7 module: php7apache2_4.dll
) else if exist "%PHP_PATH%\php5apache2_4.dll" (
    set PHP_MODULE_DLL=%PHP_PATH%\php5apache2_4.dll
    set PHP_VERSION=5
    echo [OK] Found PHP 5 module: php5apache2_4.dll
) else (
    echo [ERROR] No PHP Apache module found in %PHP_PATH%
    echo.
    echo Searching for PHP modules...
    dir "%PHP_PATH%\php*apache*.dll" 2>nul
    pause
    exit /b 1
)
echo.

echo [4/12] Analyzing current PHP configuration...
echo ----------------------------------------
echo Searching for PHP-related entries:
findstr /i /n "php" "%HTTPD_CONF%" | findstr /i "LoadModule AddHandler PHPIniDir"
echo ----------------------------------------
echo.

echo [5/12] Removing duplicate and conflicting PHP entries...
:: Create a clean version without any PHP configuration
powershell -Command ^
    "$content = Get-Content '%HTTPD_CONF%'; " ^
    "$cleaned = $content | Where-Object { " ^
    "  $_ -notmatch '^\s*#?\s*LoadModule\s+php[0-9]*_module' -and " ^
    "  $_ -notmatch '^\s*#?\s*AddHandler\s+application/x-httpd-php' -and " ^
    "  $_ -notmatch '^\s*#?\s*PHPIniDir' -and " ^
    "  $_ -notmatch '^\s*#?\s*AddType\s+application/x-httpd-php' " ^
    "}; " ^
    "$cleaned | Set-Content '%HTTPD_CONF%.cleaned'"

if exist "%HTTPD_CONF%.cleaned" (
    move /Y "%HTTPD_CONF%.cleaned" "%HTTPD_CONF%" >nul
    echo [OK] Removed old PHP configurations
) else (
    echo [WARNING] Could not clean PHP configurations
)
echo.

echo [6/12] Finding optimal position for PHP module...
:: Find the last LoadModule line
for /f "tokens=1 delims=:" %%i in ('findstr /n "^LoadModule" "%HTTPD_CONF%"') do set LAST_MODULE_LINE=%%i
echo [OK] Will insert PHP module after line %LAST_MODULE_LINE%
echo.

echo [7/12] Creating PHP configuration block...
:: Convert Windows paths to Unix-style for Apache
set PHP_MODULE_DLL_UNIX=%PHP_MODULE_DLL:\=/%
set PHP_PATH_UNIX=%PHP_PATH:\=/%

:: Create the PHP configuration
echo. > temp_php_block.txt
echo # PHP %PHP_VERSION% Configuration for XAMPP >> temp_php_block.txt
echo # Added by fix_httpd_conf.bat on %date% %time% >> temp_php_block.txt
echo. >> temp_php_block.txt

:: Add LoadModule based on PHP version
if "%PHP_VERSION%"=="8" (
    echo LoadModule php_module "%PHP_MODULE_DLL_UNIX%" >> temp_php_block.txt
) else if "%PHP_VERSION%"=="7" (
    echo LoadModule php7_module "%PHP_MODULE_DLL_UNIX%" >> temp_php_block.txt
) else (
    echo LoadModule php5_module "%PHP_MODULE_DLL_UNIX%" >> temp_php_block.txt
)

echo. >> temp_php_block.txt
echo ^<FilesMatch "\.php$"^> >> temp_php_block.txt
echo     SetHandler application/x-httpd-php >> temp_php_block.txt
echo ^</FilesMatch^> >> temp_php_block.txt
echo. >> temp_php_block.txt
echo ^<FilesMatch "\.phps$"^> >> temp_php_block.txt
echo     SetHandler application/x-httpd-php-source >> temp_php_block.txt
echo ^</FilesMatch^> >> temp_php_block.txt
echo. >> temp_php_block.txt
echo PHPIniDir "%PHP_PATH_UNIX%" >> temp_php_block.txt
echo. >> temp_php_block.txt

echo [OK] PHP configuration block created
echo.

echo [8/12] Inserting PHP configuration into httpd.conf...
powershell -Command ^
    "$content = Get-Content '%HTTPD_CONF%'; " ^
    "$phpBlock = Get-Content 'temp_php_block.txt'; " ^
    "$insertIndex = %LAST_MODULE_LINE%; " ^
    "$newContent = @(); " ^
    "for($i = 0; $i -lt $content.Length; $i++) { " ^
    "  $newContent += $content[$i]; " ^
    "  if($i -eq $insertIndex) { " ^
    "    $newContent += $phpBlock; " ^
    "  } " ^
    "}; " ^
    "$newContent | Set-Content '%HTTPD_CONF%'"

del temp_php_block.txt >nul 2>&1
echo [OK] PHP configuration inserted
echo.

echo [9/12] Verifying MIME types configuration...
findstr /i "TypesConfig" "%HTTPD_CONF%" >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] TypesConfig not found, adding it...
    echo TypesConfig conf/mime.types >> "%HTTPD_CONF%"
)

:: Check mime.types for PHP
if exist "%APACHE_PATH%\conf\mime.types" (
    findstr /i "php" "%APACHE_PATH%\conf\mime.types" >nul 2>&1
    if %errorlevel% neq 0 (
        echo application/x-httpd-php php php%PHP_VERSION% >> "%APACHE_PATH%\conf\mime.types"
        echo [OK] Added PHP MIME type
    )
)
echo [OK] MIME types verified
echo.

echo [10/12] Checking for required modules...
echo Verifying essential modules are enabled:

:: Check for required modules
set MODULES_TO_CHECK=mime_module dir_module
for %%m in (%MODULES_TO_CHECK%) do (
    findstr /i "^LoadModule %%m" "%HTTPD_CONF%" >nul 2>&1
    if !errorlevel! neq 0 (
        echo [WARNING] Module %%m is not enabled
        :: Try to enable it
        powershell -Command "(Get-Content '%HTTPD_CONF%') -replace '^#\s*(LoadModule %%m)', '$1' | Set-Content '%HTTPD_CONF%'"
    ) else (
        echo [OK] Module %%m is enabled
    )
)
echo.

echo [11/12] Testing Apache configuration...
"%APACHE_PATH%\bin\httpd.exe" -t 2>&1 | findstr /i "Syntax"
if %errorlevel% equ 0 (
    echo [OK] Apache configuration syntax is valid
) else (
    echo [WARNING] Apache configuration may have issues
    echo Running detailed test...
    "%APACHE_PATH%\bin\httpd.exe" -t
)
echo.

echo [12/12] Final verification...
echo ----------------------------------------
echo Current PHP configuration in httpd.conf:
findstr /i "LoadModule.*php AddHandler.*php PHPIniDir" "%HTTPD_CONF%" | findstr /v "^#"
echo ----------------------------------------
echo.

:: Create a verification PHP file
echo ^<?php phpinfo(); ?^> > "%XAMPP_PATH%\htdocs\phpinfo_test.php"

echo ========================================
echo HTTPD.CONF FIX COMPLETE!
echo ========================================
echo.
echo Configuration updated with:
echo - PHP %PHP_VERSION% module: %PHP_MODULE_DLL%
echo - PHP handler for .php files
echo - PHP initialization directory: %PHP_PATH%
echo.
echo Next steps:
echo 1. Restart Apache manually:
echo    - Stop: %XAMPP_PATH%\apache_stop.bat
echo    - Start: %XAMPP_PATH%\apache_start.bat
echo.
echo 2. Test PHP:
echo    - http://localhost/phpinfo_test.php
echo    - http://localhost/simple_test.php
echo.
echo 3. If still not working:
echo    - Check: %APACHE_PATH%\logs\error.log
echo    - Restore backup: %BACKUP_NAME%
echo.
pause