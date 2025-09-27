@echo off
setlocal enabledelayedexpansion
color 0B
title Apache Configuration Restore - CollaboraNexio

echo ========================================
echo    APACHE CONFIGURATION RESTORE TOOL
echo ========================================
echo.

:: Check for admin rights
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Not running as Administrator.
    echo Configuration restore may fail without admin rights.
    echo.
    pause
)

:: Set paths
set APACHE_CONF_DIR=C:\xampp\apache\conf
set HTTPD_CONF=%APACHE_CONF_DIR%\httpd.conf
set BACKUP_DIR=%APACHE_CONF_DIR%\backup

:: Create backup directory if it doesn't exist
if not exist "%BACKUP_DIR%" (
    mkdir "%BACKUP_DIR%" 2>nul
)

:: Backup current configuration
echo [1] Backing up current configuration...
set timestamp=%date:~-4%%date:~3,2%%date:~0,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set timestamp=%timestamp: =0%
copy "%HTTPD_CONF%" "%BACKUP_DIR%\httpd.conf.backup_%timestamp%" >nul 2>&1
if %errorlevel% equ 0 (
    echo    Current config saved as: httpd.conf.backup_%timestamp%
) else (
    echo    [WARNING] Could not backup current configuration
)
echo.

:: List available backups
echo [2] Available configuration backups:
echo ----------------------------------------
set count=0

:: Check for standard XAMPP backup
if exist "%HTTPD_CONF%.bak" (
    set /a count+=1
    echo !count!. httpd.conf.bak (XAMPP original)
    set backup!count!=%HTTPD_CONF%.bak
)

:: Check for our backup
if exist "%HTTPD_CONF%.backup" (
    set /a count+=1
    echo !count!. httpd.conf.backup (Previous backup)
    set backup!count!=%HTTPD_CONF%.backup
)

:: Check for port 80 backup
if exist "%HTTPD_CONF%.port80backup" (
    set /a count+=1
    echo !count!. httpd.conf.port80backup (Port 80 configuration)
    set backup!count!=%HTTPD_CONF%.port80backup
)

:: List backups in backup directory
for %%f in ("%BACKUP_DIR%\httpd.conf.backup_*") do (
    set /a count+=1
    echo !count!. %%~nxf
    set backup!count!=%%f
)

:: Check for original installation file
if exist "C:\xampp\apache\conf\original\httpd.conf" (
    set /a count+=1
    echo !count!. original\httpd.conf (Factory default)
    set backup!count!=C:\xampp\apache\conf\original\httpd.conf
)

if !count! equ 0 (
    echo No backup files found!
    echo.
    echo Creating emergency backup from template...
    goto :create_emergency
)

echo ----------------------------------------
echo.

:: Let user choose backup
echo [3] Select backup to restore:
echo    0. Cancel (keep current configuration)
echo.
set /p choice="Enter your choice (0-%count%): "

if "%choice%"=="0" (
    echo Operation cancelled.
    goto :end
)

if !choice! gtr !count! (
    echo Invalid choice!
    goto :end
)

if !choice! lss 1 (
    echo Invalid choice!
    goto :end
)

:: Restore selected backup
echo.
echo [4] Restoring configuration...
set selected_backup=!backup%choice%!
echo    Source: !selected_backup!

:: Stop Apache before restoring
echo    Stopping Apache if running...
net stop Apache2.4 >nul 2>&1
taskkill /F /IM httpd.exe >nul 2>&1
timeout /t 2 /nobreak >nul

:: Perform restore
copy /Y "!selected_backup!" "%HTTPD_CONF%" >nul 2>&1
if %errorlevel% equ 0 (
    echo    [SUCCESS] Configuration restored!
) else (
    echo    [FAIL] Could not restore configuration!
    echo    Please check file permissions.
    goto :end
)
echo.

:: Test restored configuration
echo [5] Testing restored configuration...
C:\xampp\apache\bin\httpd.exe -t 2>&1 | findstr /C:"Syntax OK" >nul
if %errorlevel% equ 0 (
    echo    [OK] Configuration syntax is valid
) else (
    echo    [WARNING] Configuration has errors:
    C:\xampp\apache\bin\httpd.exe -t 2>&1
)
echo.

:: Try to start Apache
echo [6] Starting Apache with restored configuration...
net start Apache2.4 >nul 2>&1
if %errorlevel% equ 0 (
    echo    [SUCCESS] Apache started successfully!
    echo.
    echo Testing connection...
    timeout /t 3 /nobreak >nul
    powershell -Command "(Invoke-WebRequest -Uri 'http://localhost' -UseBasicParsing -TimeoutSec 5).StatusCode" >nul 2>&1
    if %errorlevel% equ 0 (
        echo    [OK] Apache is responding at http://localhost
    ) else (
        echo    [WARNING] Apache started but not responding
    )
) else (
    echo    [FAIL] Could not start Apache
    echo.
    echo    Trying alternative start method...
    start /B C:\xampp\apache\bin\httpd.exe >nul 2>&1
    timeout /t 3 /nobreak >nul
    tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
    if %errorlevel% equ 0 (
        echo    [SUCCESS] Apache started via httpd.exe
    ) else (
        echo    [FAIL] Apache still not starting
        echo.
        echo    Run apache_manual_start.bat to see detailed errors
    )
)
goto :end

:create_emergency
echo Creating minimal working configuration...
(
echo # Minimal Apache Configuration
echo ServerRoot "C:/xampp/apache"
echo Listen 80
echo LoadModule authz_core_module modules/mod_authz_core.so
echo LoadModule dir_module modules/mod_dir.so
echo LoadModule mime_module modules/mod_mime.so
echo LoadModule log_config_module modules/mod_log_config.so
echo LoadModule headers_module modules/mod_headers.so
echo LoadModule setenvif_module modules/mod_setenvif.so
echo LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
echo.
echo ServerAdmin admin@localhost
echo ServerName localhost:80
echo.
echo ^<Directory /^>
echo     AllowOverride none
echo     Require all denied
echo ^</Directory^>
echo.
echo DocumentRoot "C:/xampp/htdocs"
echo ^<Directory "C:/xampp/htdocs"^>
echo     Options Indexes FollowSymLinks
echo     AllowOverride All
echo     Require all granted
echo ^</Directory^>
echo.
echo ^<IfModule dir_module^>
echo     DirectoryIndex index.php index.html
echo ^</IfModule^>
echo.
echo ^<FilesMatch "\.php$"^>
echo     SetHandler application/x-httpd-php
echo ^</FilesMatch^>
echo.
echo ErrorLog "logs/error.log"
echo LogLevel warn
echo.
echo ^<IfModule mime_module^>
echo     TypesConfig conf/mime.types
echo     AddType application/x-httpd-php .php
echo ^</IfModule^>
echo.
echo PHPIniDir "C:/xampp/php"
) > "%BACKUP_DIR%\httpd.conf.emergency"

echo Emergency configuration created.
echo.
choice /C YN /M "Do you want to use this emergency configuration"
if %errorlevel% equ 1 (
    copy /Y "%BACKUP_DIR%\httpd.conf.emergency" "%HTTPD_CONF%" >nul 2>&1
    echo Emergency configuration applied.
    echo Attempting to start Apache...
    net start Apache2.4 >nul 2>&1
)

:end
echo.
echo ========================================
echo    RESTORE PROCESS COMPLETE
echo ========================================
echo.
echo Additional options:
echo  - Run START_APACHE_FIX.bat for comprehensive diagnostics
echo  - Run apache_manual_start.bat to see error messages
echo  - Check C:\xampp\apache\logs\error.log for details
echo.
echo Press any key to exit...
pause >nul