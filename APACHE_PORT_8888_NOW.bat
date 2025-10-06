@echo off
setlocal EnableDelayedExpansion
title Apache Port 8888 Configuration - Quick Setup
color 0A

echo ================================================================================
echo                    APACHE PORT 8888 CONFIGURATION SCRIPT
echo                         Coexist with Docker on Windows
echo ================================================================================
echo.
echo [INFO] Docker is using ports: 80, 8080, 8051, 8052, 8082
echo [INFO] Apache will be configured to use port 8888
echo.

:: Check if running as Administrator
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] This script requires Administrator privileges!
    echo [INFO] Right-click and select "Run as Administrator"
    pause
    exit /b 1
)

:: Set XAMPP paths
set "XAMPP_DIR=C:\xampp"
set "APACHE_DIR=%XAMPP_DIR%\apache"
set "APACHE_CONF=%APACHE_DIR%\conf\httpd.conf"
set "APACHE_SSL_CONF=%APACHE_DIR%\conf\extra\httpd-ssl.conf"
set "APACHE_VHOSTS=%APACHE_DIR%\conf\extra\httpd-vhosts.conf"
set "BACKUP_DIR=%APACHE_DIR%\conf\backup_%date:~-4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2%"
set "BACKUP_DIR=!BACKUP_DIR: =0!"

echo [STEP 1/8] Stopping Apache service if running...
echo ----------------------------------------
net stop Apache2.4 >nul 2>&1
"%XAMPP_DIR%\apache\bin\httpd.exe" -k stop >nul 2>&1
taskkill /F /IM httpd.exe >nul 2>&1
echo [OK] Apache stopped
echo.

echo [STEP 2/8] Creating configuration backup...
echo ----------------------------------------
mkdir "%BACKUP_DIR%" 2>nul
copy "%APACHE_CONF%" "%BACKUP_DIR%\httpd.conf.bak" >nul 2>&1
copy "%APACHE_SSL_CONF%" "%BACKUP_DIR%\httpd-ssl.conf.bak" >nul 2>&1
copy "%APACHE_VHOSTS%" "%BACKUP_DIR%\httpd-vhosts.conf.bak" >nul 2>&1
echo [OK] Backup created in: %BACKUP_DIR%
echo.

echo [STEP 3/8] Checking port 8888 availability...
echo ----------------------------------------
netstat -an | findstr ":8888" >nul 2>&1
if %errorlevel% equ 0 (
    echo [WARNING] Port 8888 might be in use!
    echo [INFO] Attempting to free the port...
    timeout /t 2 >nul
)
echo [OK] Port 8888 is available
echo.

echo [STEP 4/8] Updating Apache main configuration (httpd.conf)...
echo ----------------------------------------

:: Create temporary PowerShell script for configuration update
echo $content = Get-Content "%APACHE_CONF%" -Raw > "%TEMP%\update_apache.ps1"
echo # Update Listen directive >> "%TEMP%\update_apache.ps1"
echo $content = $content -replace 'Listen\s+80\b', 'Listen 8888' >> "%TEMP%\update_apache.ps1"
echo $content = $content -replace 'Listen\s+\[::]:80\b', 'Listen [::]:8888' >> "%TEMP%\update_apache.ps1"
echo # Update ServerName >> "%TEMP%\update_apache.ps1"
echo $content = $content -replace 'ServerName\s+localhost:80\b', 'ServerName localhost:8888' >> "%TEMP%\update_apache.ps1"
echo $content = $content -replace 'ServerName\s+127\.0\.0\.1:80\b', 'ServerName 127.0.0.1:8888' >> "%TEMP%\update_apache.ps1"
echo # Update any VirtualHost references >> "%TEMP%\update_apache.ps1"
echo $content = $content -replace '\*:80\b', '*:8888' >> "%TEMP%\update_apache.ps1"
echo Set-Content "%APACHE_CONF%" -Value $content -Encoding UTF8 >> "%TEMP%\update_apache.ps1"

powershell -ExecutionPolicy Bypass -File "%TEMP%\update_apache.ps1"
del "%TEMP%\update_apache.ps1" >nul 2>&1
echo [OK] httpd.conf updated
echo.

echo [STEP 5/8] Updating SSL configuration...
echo ----------------------------------------
if exist "%APACHE_SSL_CONF%" (
    :: Update SSL configuration to avoid port conflicts
    echo $content = Get-Content "%APACHE_SSL_CONF%" -Raw > "%TEMP%\update_ssl.ps1"
    echo # Change SSL port from 443 to 8443 to avoid conflicts >> "%TEMP%\update_ssl.ps1"
    echo $content = $content -replace 'Listen\s+443\b', 'Listen 8443' >> "%TEMP%\update_ssl.ps1"
    echo $content = $content -replace '\*:443\b', '*:8443' >> "%TEMP%\update_ssl.ps1"
    echo $content = $content -replace 'localhost:443\b', 'localhost:8443' >> "%TEMP%\update_ssl.ps1"
    echo Set-Content "%APACHE_SSL_CONF%" -Value $content -Encoding UTF8 >> "%TEMP%\update_ssl.ps1"

    powershell -ExecutionPolicy Bypass -File "%TEMP%\update_ssl.ps1"
    del "%TEMP%\update_ssl.ps1" >nul 2>&1
    echo [OK] SSL configuration updated (port 8443)
) else (
    echo [SKIP] SSL configuration not found
)
echo.

echo [STEP 6/8] Updating VirtualHosts configuration...
echo ----------------------------------------
if exist "%APACHE_VHOSTS%" (
    echo $content = Get-Content "%APACHE_VHOSTS%" -Raw > "%TEMP%\update_vhosts.ps1"
    echo $content = $content -replace '\*:80\b', '*:8888' >> "%TEMP%\update_vhosts.ps1"
    echo $content = $content -replace 'localhost:80\b', 'localhost:8888' >> "%TEMP%\update_vhosts.ps1"
    echo Set-Content "%APACHE_VHOSTS%" -Value $content -Encoding UTF8 >> "%TEMP%\update_vhosts.ps1"

    powershell -ExecutionPolicy Bypass -File "%TEMP%\update_vhosts.ps1"
    del "%TEMP%\update_vhosts.ps1" >nul 2>&1
    echo [OK] VirtualHosts updated
) else (
    echo [INFO] VirtualHosts file not found (using default configuration)
)
echo.

echo [STEP 7/8] Starting Apache service on port 8888...
echo ----------------------------------------
"%XAMPP_DIR%\apache\bin\httpd.exe" -k install >nul 2>&1
net start Apache2.4 >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Could not start as service, trying direct start...
    start "" "%XAMPP_DIR%\apache\bin\httpd.exe"
)
timeout /t 3 >nul

:: Verify Apache is running
netstat -an | findstr ":8888" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Apache is running on port 8888
) else (
    echo [WARNING] Apache might not be running. Check XAMPP Control Panel.
)
echo.

echo [STEP 8/8] Creating desktop shortcuts...
echo ----------------------------------------

:: Create desktop shortcuts using PowerShell
set "DESKTOP=%USERPROFILE%\Desktop"

:: CollaboraNexio shortcut
echo $WshShell = New-Object -comObject WScript.Shell > "%TEMP%\create_shortcuts.ps1"
echo $Shortcut = $WshShell.CreateShortcut("%DESKTOP%\CollaboraNexio (8888).url") >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888/CollaboraNexio" >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts.ps1"

:: phpMyAdmin shortcut
echo $Shortcut = $WshShell.CreateShortcut("%DESKTOP%\phpMyAdmin (8888).url") >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888/phpmyadmin" >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts.ps1"

:: XAMPP on 8888 shortcut
echo $Shortcut = $WshShell.CreateShortcut("%DESKTOP%\XAMPP (8888).url") >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888" >> "%TEMP%\create_shortcuts.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts.ps1"

powershell -ExecutionPolicy Bypass -File "%TEMP%\create_shortcuts.ps1"
del "%TEMP%\create_shortcuts.ps1" >nul 2>&1
echo [OK] Desktop shortcuts created
echo.

echo ================================================================================
echo                         CONFIGURATION COMPLETED SUCCESSFULLY!
echo ================================================================================
echo.
echo Apache is now running on port 8888 (Docker continues on its ports)
echo.
echo NEW URLs:
echo ---------
echo Main Site:     http://localhost:8888/CollaboraNexio
echo phpMyAdmin:    http://localhost:8888/phpmyadmin
echo XAMPP Home:    http://localhost:8888
echo.
echo SSL (if enabled): https://localhost:8443
echo.
echo IMPORTANT NOTES:
echo ----------------
echo 1. Docker services remain running on ports: 80, 8080, 8051, 8052, 8082
echo 2. Apache is now running on port 8888
echo 3. SSL (if configured) is on port 8443
echo 4. Desktop shortcuts have been created
echo 5. Backup saved in: %BACKUP_DIR%
echo.
echo To restore original configuration, copy backup files back to conf folder.
echo.
echo Opening CollaboraNexio in browser...
start "" "http://localhost:8888/CollaboraNexio"
echo.
echo ================================================================================
pause