@echo off
setlocal enabledelayedexpansion
color 0A
title Apache Recovery Tool - CollaboraNexio

echo ========================================
echo    APACHE RECOVERY AND FIX TOOL
echo ========================================
echo.

:: Check if running as admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Not running as Administrator. Some fixes may fail.
    echo Please right-click and "Run as administrator" for best results.
    echo.
    pause
)

:: Step 1: Check if Apache is running
echo [1] Checking Apache status...
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if %errorlevel% equ 0 (
    echo [OK] Apache is running!
    echo.
    goto :check_accessibility
) else (
    echo [FAIL] Apache is NOT running. Starting recovery process...
    echo.
)

:: Step 2: Check for port conflicts
echo [2] Checking for port 80 conflicts...
netstat -ano | findstr :80 | findstr LISTENING > temp_port.txt
if %errorlevel% equ 0 (
    echo [WARNING] Port 80 is in use by:
    for /f "tokens=5" %%a in (temp_port.txt) do (
        for /f "tokens=1,2 delims=," %%b in ('tasklist /FI "PID eq %%a" /FO CSV ^| findstr /v "Image Name"') do (
            echo    PID: %%a - Process: %%~b
        )
    )
    echo.
    echo Possible solutions:
    echo  - Stop IIS: net stop W3SVC
    echo  - Stop Skype and disable "Use port 80 and 443"
    echo  - Change Apache port in httpd.conf
    echo.
) else (
    echo [OK] Port 80 is available
    echo.
)
if exist temp_port.txt del temp_port.txt

:: Step 3: Test Apache configuration
echo [3] Testing Apache configuration...
C:\xampp\apache\bin\httpd.exe -t 2>&1 | findstr /v "Syntax OK" > temp_config.txt
for %%A in (temp_config.txt) do set size=%%~zA
if !size! gtr 0 (
    echo [FAIL] Configuration errors found:
    type temp_config.txt
    echo.
    echo Attempting to restore backup configuration...
    if exist C:\xampp\apache\conf\httpd.conf.backup (
        copy /Y C:\xampp\apache\conf\httpd.conf.backup C:\xampp\apache\conf\httpd.conf >nul 2>&1
        echo [INFO] Restored httpd.conf from backup
    ) else (
        echo [ERROR] No backup configuration found
    )
) else (
    echo [OK] Apache configuration syntax is valid
)
if exist temp_config.txt del temp_config.txt
echo.

:: Step 4: Check Apache error log
echo [4] Recent Apache errors (last 10 lines):
echo ----------------------------------------
if exist C:\xampp\apache\logs\error.log (
    powershell -Command "Get-Content 'C:\xampp\apache\logs\error.log' -Tail 10"
) else (
    echo No error log found
)
echo ----------------------------------------
echo.

:: Step 5: Try to start Apache using different methods
echo [5] Attempting to start Apache...

:: Method 1: Windows Service
echo    Method 1: Starting as Windows Service...
net start Apache2.4 >nul 2>&1
if %errorlevel% equ 0 (
    echo    [SUCCESS] Apache started as service
    goto :verify_running
)

:: Method 2: Direct executable
echo    Method 2: Starting via httpd.exe...
start /B C:\xampp\apache\bin\httpd.exe >nul 2>&1
timeout /t 3 /nobreak >nul
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if %errorlevel% equ 0 (
    echo    [SUCCESS] Apache started via httpd.exe
    goto :verify_running
)

:: Method 3: XAMPP start script
echo    Method 3: Starting via XAMPP script...
if exist C:\xampp\apache_start.bat (
    call C:\xampp\apache_start.bat >nul 2>&1
    timeout /t 3 /nobreak >nul
    tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
    if %errorlevel% equ 0 (
        echo    [SUCCESS] Apache started via XAMPP script
        goto :verify_running
    )
)

:: Method 4: XAMPP Control Panel
echo    Method 4: Trying via xampp-control.exe...
if exist C:\xampp\xampp-control.exe (
    echo    [INFO] Opening XAMPP Control Panel...
    echo    Please manually start Apache from the control panel
    start C:\xampp\xampp-control.exe
)

echo.
echo [FAIL] Could not start Apache automatically.
echo.
goto :manual_options

:verify_running
echo.
echo [6] Verifying Apache is responding...
timeout /t 2 /nobreak >nul
powershell -Command "(Invoke-WebRequest -Uri 'http://localhost' -UseBasicParsing -TimeoutSec 5).StatusCode" >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] Apache is running and responding on http://localhost
) else (
    echo [WARNING] Apache process is running but not responding on port 80
    echo Check firewall settings or port configuration
)
echo.
goto :end

:check_accessibility
echo [6] Testing web accessibility...
powershell -Command "(Invoke-WebRequest -Uri 'http://localhost' -UseBasicParsing -TimeoutSec 5).StatusCode" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Apache is accessible at http://localhost
) else (
    echo [WARNING] Apache is running but not accessible
    echo Check Windows Firewall or port configuration
)
echo.
goto :end

:manual_options
echo ========================================
echo    MANUAL RECOVERY OPTIONS
echo ========================================
echo.
echo 1. Run 'apache_manual_start.bat' to see detailed error messages
echo 2. Run 'check_port80.bat' to diagnose port conflicts
echo 3. Run 'restore_apache_config.bat' to restore configuration
echo 4. Check Windows Event Viewer for Apache errors
echo 5. Reinstall XAMPP (last resort)
echo.
echo Common fixes:
echo  - Disable Windows IIS: Control Panel > Programs > Turn Windows features on/off > Uncheck IIS
echo  - Change Skype settings: Tools > Options > Advanced > Connection > Uncheck "Use port 80 and 443"
echo  - Disable Windows Defender Firewall temporarily to test
echo  - Run XAMPP Control Panel as Administrator
echo.

:end
echo ========================================
echo    RECOVERY PROCESS COMPLETE
echo ========================================
echo.
echo Press any key to exit...
pause >nul