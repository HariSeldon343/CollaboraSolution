@echo off
setlocal enabledelayedexpansion
title Stop Docker and Free Ports for Apache

echo ========================================
echo   DOCKER PORT CONFLICT RESOLVER
echo   Stop Docker to Free Port 80 for Apache
echo ========================================
echo.

:: Check if running as administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] This script requires administrator privileges!
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

echo [1/6] Stopping Docker Desktop...
:: Stop Docker Desktop gracefully
taskkill /F /IM "Docker Desktop.exe" >nul 2>&1
if %errorlevel%==0 (
    echo       Docker Desktop stopped successfully
) else (
    echo       Docker Desktop was not running
)

echo.
echo [2/6] Stopping Docker backend processes...
:: Kill Docker backend process
taskkill /F /IM "com.docker.backend.exe" >nul 2>&1
if %errorlevel%==0 (
    echo       Docker backend stopped successfully
) else (
    echo       Docker backend was not running
)

:: Kill WSL relay process
taskkill /F /IM "wslrelay.exe" >nul 2>&1
if %errorlevel%==0 (
    echo       WSL relay stopped successfully
) else (
    echo       WSL relay was not running
)

echo.
echo [3/6] Stopping Docker services...
:: Stop Docker service
net stop com.docker.service >nul 2>&1
if %errorlevel%==0 (
    echo       Docker service stopped successfully
) else (
    echo       Docker service was not running or already stopped
)

:: Stop Docker Desktop Service
net stop "Docker Desktop Service" >nul 2>&1
if %errorlevel%==0 (
    echo       Docker Desktop Service stopped successfully
) else (
    echo       Docker Desktop Service was not running
)

echo.
echo [4/6] Waiting for ports to be freed...
timeout /t 5 /nobreak >nul
echo       Ports should now be free

echo.
echo [5/6] Checking if port 80 is available...
netstat -an | findstr :80 | findstr LISTENING >nul
if %errorlevel%==0 (
    echo       [WARNING] Port 80 may still be in use
    echo       Attempting additional cleanup...

    :: Try to stop other common services that might use port 80
    net stop W3SVC >nul 2>&1
    net stop HTTP >nul 2>&1
    timeout /t 2 /nobreak >nul
) else (
    echo       [SUCCESS] Port 80 is available!
)

echo.
echo [6/6] Starting Apache on port 80...
:: Start Apache
cd /d C:\xampp
apache\bin\httpd.exe -k stop >nul 2>&1
timeout /t 2 /nobreak >nul
apache\bin\httpd.exe -k start

:: Check if Apache started successfully
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if %errorlevel%==0 (
    echo       [SUCCESS] Apache started successfully!
    echo.
    echo ========================================
    echo   Apache is now running on port 80
    echo   Access your site at: http://localhost
    echo ========================================
) else (
    echo       [ERROR] Failed to start Apache
    echo       Please check XAMPP Control Panel
)

echo.
echo ----------------------------------------
echo Options:
echo   1. Press R to restart Docker Desktop
echo   2. Press X to open XAMPP Control Panel
echo   3. Press any other key to exit
echo ----------------------------------------
choice /C RXQ /N /M "Select option: "

if %errorlevel%==1 (
    echo.
    echo Restarting Docker Desktop...
    start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    echo Docker Desktop will start in the background
    echo Note: Apache will stop when Docker reclaims port 80
    timeout /t 3 /nobreak >nul
) else if %errorlevel%==2 (
    echo.
    echo Opening XAMPP Control Panel...
    start "" "C:\xampp\xampp-control.exe"
)

echo.
echo Script completed. Press any key to exit...
pause >nul
exit /b 0