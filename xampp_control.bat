@echo off
color 0F
title XAMPP Control Panel Launcher - CollaboraNexio

echo ========================================
echo    XAMPP CONTROL PANEL LAUNCHER
echo ========================================
echo.

:: Check if XAMPP Control Panel exists
if not exist "C:\xampp\xampp-control.exe" (
    echo [ERROR] XAMPP Control Panel not found!
    echo Expected location: C:\xampp\xampp-control.exe
    echo.
    echo Please verify XAMPP installation.
    pause
    exit /b 1
)

:: Check if already running
tasklist /FI "IMAGENAME eq xampp-control.exe" 2>NUL | find /I /N "xampp-control.exe">NUL
if %errorlevel% equ 0 (
    echo [INFO] XAMPP Control Panel is already running.
    echo.
    echo Bringing it to foreground...
) else (
    echo Starting XAMPP Control Panel...
    echo.
    echo [TIP] Run as Administrator for full control over services
    echo.
)

:: Check current Apache status before opening
echo Current Apache Status:
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if %errorlevel% equ 0 (
    echo  - Apache: RUNNING
) else (
    echo  - Apache: STOPPED
)

:: Check MySQL status
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if %errorlevel% equ 0 (
    echo  - MySQL: RUNNING
) else (
    echo  - MySQL: STOPPED
)
echo.

:: Launch XAMPP Control Panel
echo Launching XAMPP Control Panel...
start "" "C:\xampp\xampp-control.exe"

echo.
echo ========================================
echo    XAMPP CONTROL PANEL TIPS
echo ========================================
echo.
echo From the Control Panel you can:
echo  1. Start/Stop Apache and MySQL services
echo  2. View service logs in real-time
echo  3. Access configuration files
echo  4. Open shell terminals
echo  5. Check port usage
echo.
echo Quick Actions in Control Panel:
echo  - Click "Start" next to Apache to start the web server
echo  - Click "Admin" next to Apache to open http://localhost
echo  - Click "Config" next to Apache to edit httpd.conf
echo  - Click "Logs" to view error and access logs
echo.
echo If Apache won't start:
echo  - Check the log window for errors
echo  - Click "Netstat" to check port conflicts
echo  - Run our diagnostic tools:
echo    - START_APACHE_FIX.bat
echo    - check_port80.bat
echo.
echo Press any key to close this window...
pause >nul