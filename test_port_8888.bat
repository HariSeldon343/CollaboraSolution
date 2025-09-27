@echo off
setlocal EnableDelayedExpansion
title Port Availability Checker - Find Free Port for Apache
color 0B

echo ================================================================================
echo                           PORT AVAILABILITY CHECKER
echo                     Find Available Ports for Apache Server
echo ================================================================================
echo.

:: Show Docker ports in use
echo [INFO] Docker is currently using these ports:
echo ----------------------------------------
set "DOCKER_PORTS=80 8080 8051 8052 8082"
for %%p in (%DOCKER_PORTS%) do (
    netstat -an | findstr ":%%p " | findstr "LISTENING" >nul 2>&1
    if !errorlevel! equ 0 (
        echo   Port %%p: IN USE (Docker)
    ) else (
        echo   Port %%p: Listed for Docker but currently FREE
    )
)
echo.

echo [INFO] Checking preferred port 8888...
echo ----------------------------------------
netstat -an | findstr ":8888 " | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OCCUPIED] Port 8888 is currently IN USE!
    echo.

    :: Find what's using port 8888
    echo Checking what's using port 8888...
    for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8888" ^| findstr "LISTENING"') do (
        set "PID=%%a"
        for /f "tokens=1" %%b in ('tasklist /fi "PID eq !PID!" ^| findstr "!PID!"') do (
            echo Process: %%b (PID: !PID!)
        )
    )
    echo.
    set "PORT_8888_FREE=0"
) else (
    echo [AVAILABLE] Port 8888 is FREE and ready to use!
    echo.
    set "PORT_8888_FREE=1"
)

:: Check alternative ports
echo [INFO] Checking alternative ports...
echo ----------------------------------------
set "ALTERNATIVE_PORTS=8889 8890 8891 8892 8893 8894 8895 9000 9001 9002"
set "FIRST_FREE="
set "FREE_PORTS="

for %%p in (%ALTERNATIVE_PORTS%) do (
    netstat -an | findstr ":%%p " | findstr "LISTENING" >nul 2>&1
    if !errorlevel! neq 0 (
        echo   Port %%p: AVAILABLE
        set "FREE_PORTS=!FREE_PORTS! %%p"
        if "!FIRST_FREE!"=="" set "FIRST_FREE=%%p"
    ) else (
        echo   Port %%p: IN USE
    )
)
echo.

:: Comprehensive port scan for range 8000-9999
echo [INFO] Scanning port range 8000-9999 for availability...
echo ----------------------------------------
set "SCAN_COUNT=0"
set "AVAILABLE_COUNT=0"
set "RECOMMENDED_PORTS="

for /l %%p in (8000,1,9999) do (
    set /a "SCAN_COUNT+=1"

    :: Skip Docker ports and commonly used ports
    set "SKIP=0"
    for %%d in (80 8080 8051 8052 8082 8443 8000 8008 8081) do (
        if %%p==%%d set "SKIP=1"
    )

    if !SKIP! equ 0 (
        netstat -an | findstr ":%%p " | findstr "LISTENING" >nul 2>&1
        if !errorlevel! neq 0 (
            set /a "AVAILABLE_COUNT+=1"
            if !AVAILABLE_COUNT! leq 5 (
                set "RECOMMENDED_PORTS=!RECOMMENDED_PORTS! %%p"
            )
        )
    )

    :: Show progress every 500 ports
    set /a "MOD=!SCAN_COUNT! %% 500"
    if !MOD! equ 0 (
        <nul set /p "=."
    )
)
echo.
echo [OK] Found !AVAILABLE_COUNT! available ports in range 8000-9999
echo.

:: Summary and recommendations
echo ================================================================================
echo                                   SUMMARY
echo ================================================================================
echo.

if "%PORT_8888_FREE%"=="1" (
    echo [RECOMMENDED] Port 8888 is AVAILABLE and ready for Apache!
    echo.
    echo To configure Apache for port 8888, run:
    echo   APACHE_PORT_8888_NOW.bat
) else (
    echo [WARNING] Port 8888 is currently OCCUPIED!
    echo.
    if not "!FIRST_FREE!"=="" (
        echo [RECOMMENDED] Use port !FIRST_FREE! instead
        echo.
        echo Available alternative ports:!FREE_PORTS!
    ) else (
        echo [ERROR] No alternative ports found in the predefined list!
        echo.
        echo First 5 available ports found:!RECOMMENDED_PORTS!
    )
    echo.
    echo To free port 8888:
    echo   1. Check what's using it with: netstat -ano | findstr :8888
    echo   2. Stop the service or change its port
    echo   3. Run this test again
)
echo.

:: Test HTTP request to localhost:8888 if something is running there
if "%PORT_8888_FREE%"=="0" (
    echo [INFO] Testing HTTP response on port 8888...
    echo ----------------------------------------
    powershell -Command "try { $response = Invoke-WebRequest -Uri 'http://localhost:8888' -Method Head -TimeoutSec 2 -ErrorAction Stop; Write-Host '[OK] HTTP service responding on port 8888' } catch { Write-Host '[INFO] No HTTP service on port 8888 or not responding' }"
    echo.
)

:: Create port configuration file
echo [INFO] Creating port configuration report...
echo ----------------------------------------
set "REPORT_FILE=%~dp0port_report_%date:~-4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2%.txt"
set "REPORT_FILE=!REPORT_FILE: =0!"

echo Port Availability Report > "!REPORT_FILE!"
echo Generated: %date% %time% >> "!REPORT_FILE!"
echo ================================ >> "!REPORT_FILE!"
echo. >> "!REPORT_FILE!"
echo Docker Reserved Ports: %DOCKER_PORTS% >> "!REPORT_FILE!"
echo Port 8888 Status: %PORT_8888_FREE% (1=Free, 0=Occupied) >> "!REPORT_FILE!"
echo Available Alternatives:!FREE_PORTS! >> "!REPORT_FILE!"
echo First Available in Range:!RECOMMENDED_PORTS! >> "!REPORT_FILE!"

echo [OK] Report saved to: !REPORT_FILE!
echo.

echo ================================================================================
echo Press any key to exit...
pause >nul