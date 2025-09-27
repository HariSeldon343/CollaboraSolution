@echo off
color 0C
title Apache Manual Start (Debug Mode) - CollaboraNexio

echo ========================================
echo    APACHE MANUAL START - DEBUG MODE
echo ========================================
echo.
echo This will run Apache in the foreground with debug output.
echo Press Ctrl+C to stop Apache when done testing.
echo.
echo ========================================
echo.

:: Check if Apache is already running
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if %errorlevel% equ 0 (
    echo [WARNING] Apache is already running!
    echo.
    echo Do you want to stop it first and run in debug mode?
    choice /C YN /M "Stop current Apache and continue"
    if !errorlevel! equ 1 (
        echo Stopping Apache...
        taskkill /F /IM httpd.exe >nul 2>&1
        net stop Apache2.4 >nul 2>&1
        timeout /t 2 /nobreak >nul
    ) else (
        echo Exiting...
        pause
        exit /b
    )
)

echo Starting Apache in debug mode...
echo ========================================
echo.
echo APACHE OUTPUT:
echo ----------------------------------------
echo.

:: Change to Apache directory
cd /d C:\xampp\apache\bin

:: Run Apache in debug mode (shows all errors)
echo Running: httpd.exe -e debug -X
echo.
httpd.exe -e debug -X

:: This part runs after Ctrl+C is pressed
echo.
echo ========================================
echo Apache stopped.
echo.
echo Common error meanings:
echo  - "could not bind to address" = Port already in use
echo  - "ServerRoot must be a valid directory" = Path configuration error
echo  - "Invalid command" = Module not loaded or syntax error
echo  - "No such file or directory" = Missing configuration file
echo  - "Permission denied" = Run as Administrator
echo.
echo To fix errors:
echo  1. Run check_port80.bat for port conflicts
echo  2. Run restore_apache_config.bat for config issues
echo  3. Check C:\xampp\apache\logs\error.log for more details
echo.
echo Press any key to exit...
pause >nul