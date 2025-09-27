@echo off
setlocal enabledelayedexpansion
color 0E
title Port 80 Conflict Checker - CollaboraNexio

echo ========================================
echo    PORT 80 CONFLICT CHECKER
echo ========================================
echo.

:: Check if running as admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Not running as Administrator.
    echo Some processes may not be identified correctly.
    echo.
)

echo Checking which process is using port 80...
echo.

:: Check port 80
netstat -ano | findstr :80 | findstr LISTENING > port80_check.txt
if %errorlevel% neq 0 (
    echo [OK] Port 80 is FREE and available for Apache!
    echo.
    goto :check_port_443
)

echo [CONFLICT] Port 80 is being used by:
echo ----------------------------------------
set conflict_found=0
for /f "tokens=5" %%a in (port80_check.txt) do (
    set pid=%%a
    for /f "tokens=1,2 delims=," %%b in ('tasklist /FI "PID eq !pid!" /FO CSV 2^>nul ^| findstr /v "Image Name"') do (
        set process_name=%%~b
        echo Process: !process_name! (PID: !pid!)
        set conflict_found=1

        :: Identify common culprits
        echo !process_name! | findstr /I "System" >nul
        if !errorlevel! equ 0 (
            echo ^> This is likely IIS or another Windows service
            set iis_conflict=1
        )

        echo !process_name! | findstr /I "Skype" >nul
        if !errorlevel! equ 0 (
            echo ^> This is Skype
            set skype_conflict=1
        )

        echo !process_name! | findstr /I "TeamViewer" >nul
        if !errorlevel! equ 0 (
            echo ^> This is TeamViewer
            set teamviewer_conflict=1
        )

        echo !process_name! | findstr /I "vmware" >nul
        if !errorlevel! equ 0 (
            echo ^> This is VMware Workstation
            set vmware_conflict=1
        )
    )
)
echo ----------------------------------------
echo.

if defined iis_conflict (
    echo SOLUTION FOR IIS:
    echo  1. Stop IIS Service:
    echo     net stop W3SVC
    echo     net stop WAS
    echo  2. Or disable IIS permanently:
    echo     - Control Panel ^> Programs ^> Turn Windows features on/off
    echo     - Uncheck "Internet Information Services"
    echo     - Restart computer
    echo.

    choice /C YN /M "Do you want to stop IIS now"
    if !errorlevel! equ 1 (
        echo Stopping IIS services...
        net stop W3SVC >nul 2>&1
        net stop WAS >nul 2>&1
        echo IIS services stopped.
    )
)

if defined skype_conflict (
    echo SOLUTION FOR SKYPE:
    echo  1. Open Skype
    echo  2. Go to Tools ^> Options ^> Advanced ^> Connection
    echo  3. UNCHECK "Use port 80 and 443 as alternatives"
    echo  4. Restart Skype
    echo.
)

if defined teamviewer_conflict (
    echo SOLUTION FOR TEAMVIEWER:
    echo  1. Open TeamViewer
    echo  2. Go to Extras ^> Options ^> Advanced
    echo  3. Change "Incoming connections" port from 80
    echo  4. Restart TeamViewer
    echo.
)

if defined vmware_conflict (
    echo SOLUTION FOR VMWARE:
    echo  1. Stop VMware Workstation Server:
    echo     net stop VMwareHostd
    echo  2. Or change VMware shared port in preferences
    echo.
)

:check_port_443
echo Checking port 443 (HTTPS)...
netstat -ano | findstr :443 | findstr LISTENING > port443_check.txt
if %errorlevel% neq 0 (
    echo [OK] Port 443 is FREE
    echo.
) else (
    echo [WARNING] Port 443 is also in use by:
    for /f "tokens=5" %%a in (port443_check.txt) do (
        set pid=%%a
        for /f "tokens=1,2 delims=," %%b in ('tasklist /FI "PID eq !pid!" /FO CSV 2^>nul ^| findstr /v "Image Name"') do (
            echo Process: %%~b (PID: !pid!)
        )
    )
    echo.
)

:: Clean up temp files
if exist port80_check.txt del port80_check.txt
if exist port443_check.txt del port443_check.txt

:: Offer to change Apache port
echo ========================================
echo    PORT CHANGE OPTION
echo ========================================
echo.
echo If you cannot free port 80, you can change Apache to use port 8080
echo.
choice /C YN /M "Do you want to change Apache to port 8080"
if %errorlevel% equ 1 (
    echo.
    echo Backing up current configuration...
    copy C:\xampp\apache\conf\httpd.conf C:\xampp\apache\conf\httpd.conf.port80backup >nul 2>&1

    echo Changing Apache port to 8080...
    powershell -Command "(Get-Content 'C:\xampp\apache\conf\httpd.conf') -replace 'Listen 80', 'Listen 8080' -replace 'ServerName localhost:80', 'ServerName localhost:8080' | Set-Content 'C:\xampp\apache\conf\httpd.conf'"

    echo.
    echo [SUCCESS] Apache port changed to 8080
    echo You will now access your site at: http://localhost:8080
    echo.
    echo Starting Apache on port 8080...
    net stop Apache2.4 >nul 2>&1
    net start Apache2.4 >nul 2>&1
    if %errorlevel% equ 0 (
        echo [OK] Apache started on port 8080
    ) else (
        echo [FAIL] Could not start Apache. Run START_APACHE_FIX.bat
    )
) else (
    echo.
    echo Port configuration unchanged.
)

echo.
echo ========================================
echo    ADDITIONAL CHECKS
echo ========================================
echo.
echo All ports in use:
netstat -ano | findstr LISTENING | findstr "0.0.0.0:80 0.0.0.0:443 0.0.0.0:8080"
echo.
echo To kill a process using its PID:
echo   taskkill /F /PID [pid_number]
echo.
echo Press any key to exit...
pause >nul