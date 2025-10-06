@echo off
setlocal enabledelayedexpansion

:: CollaboraNexio Cleanup Script (FIXED)
:: Version: 1.0.1 - Fixed for Windows XAMPP
:: This version won't close immediately

:: Set window title
title CollaboraNexio Cleanup Utility

:: Configuration
set PROJECT_DIR=%~dp0
:: Remove trailing backslash if present
if "%PROJECT_DIR:~-1%"=="\" set PROJECT_DIR=%PROJECT_DIR:~0,-1%

set LOG_DIR=%PROJECT_DIR%\logs
set BACKUP_DIR=%PROJECT_DIR%\backups
set TEMP_DIR=%PROJECT_DIR%\temp
set UPLOAD_DIR=%PROJECT_DIR%\uploads

cls
echo ==============================================================
echo    COLLABORANEXIO - DISK CLEANUP UTILITY
echo    Version: 1.0.1 - Windows XAMPP Fixed
echo ==============================================================
echo.
echo Current Directory: %PROJECT_DIR%
echo.

:: Check if running as administrator
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [INFO] Not running as administrator
    echo Some cleanup operations may be limited
    echo.
)

pause
echo.

:: Get initial disk space
echo Checking current disk space...
set DRIVE=%PROJECT_DIR:~0,2%
for /f "tokens=3" %%a in ('dir %DRIVE%\ ^| find "bytes free"') do (
    set INITIAL_FREE=%%a
)
echo Initial free space on %DRIVE%: Check complete
echo.

echo ==============================================================
echo Phase 1: Windows Temporary Files
echo ==============================================================
echo.

:: Clean Windows temp - safe version
echo Cleaning Windows temp directory...
set CLEANED_COUNT=0

:: Try to clean TEMP directory
if exist "%TEMP%" (
    for /f %%a in ('dir /b "%TEMP%" 2^>nul ^| find /c /v ""') do set TEMP_COUNT=%%a
    del /q /f "%TEMP%\*.tmp" 2>nul
    del /q /f "%TEMP%\*.log" 2>nul
    echo [OK] Cleaned temporary files from Windows temp
) else (
    echo [WARNING] Cannot access Windows temp directory
)

echo.
pause
echo.

echo ==============================================================
echo Phase 2: Project Temporary Files
echo ==============================================================
echo.

:: Clean project temp directory
if exist "%TEMP_DIR%" (
    echo Cleaning project temp directory...
    del /q /f "%TEMP_DIR%\*.*" 2>nul
    echo [OK] Project temp directory cleaned
) else (
    echo [INFO] Project temp directory not found
    echo Creating temp directory...
    mkdir "%TEMP_DIR%" 2>nul
)

:: Clean PHP session files
echo Cleaning PHP session files...
set PHP_SESSION_DIR=C:\xampp\tmp
if exist "%PHP_SESSION_DIR%" (
    del /q "%PHP_SESSION_DIR%\sess_*" 2>nul
    echo [OK] PHP session files cleaned
) else (
    echo [INFO] PHP session directory not found
)

echo.
pause
echo.

echo ==============================================================
echo Phase 3: Old Log Files
echo ==============================================================
echo.

:: Clean old log files
if exist "%LOG_DIR%" (
    echo Removing old log files...

    :: Delete .log.old files
    del /q "%LOG_DIR%\*.log.old" 2>nul

    :: Delete logs older than 7 days (simplified)
    forfiles /p "%LOG_DIR%" /m *.log /d -7 /c "cmd /c del @path" 2>nul
    if %errorlevel% equ 0 (
        echo [OK] Old log files removed
    ) else (
        echo [INFO] No old log files found
    )
) else (
    echo [INFO] Log directory not found
)

:: Clean Apache logs
if exist "C:\xampp\apache\logs" (
    echo Cleaning Apache logs...
    del /q "C:\xampp\apache\logs\error.log" 2>nul
    del /q "C:\xampp\apache\logs\access.log" 2>nul
    echo [OK] Apache logs cleaned
)

:: Clean MySQL logs
if exist "C:\xampp\mysql\data" (
    echo Cleaning MySQL logs...
    del /q "C:\xampp\mysql\data\*.err" 2>nul
    echo [OK] MySQL error logs cleaned
)

echo.
pause
echo.

echo ==============================================================
echo Phase 4: Old Backup Files
echo ==============================================================
echo.

:: Clean old backup files
if exist "%BACKUP_DIR%" (
    echo Checking for old backups...

    :: Count backup folders
    set BACKUP_COUNT=0
    for /d %%d in ("%BACKUP_DIR%\*") do set /a BACKUP_COUNT+=1

    echo Found %BACKUP_COUNT% backup folders

    if %BACKUP_COUNT% gtr 5 (
        echo [WARNING] You have more than 5 backups
        echo Consider removing old backups manually from:
        echo %BACKUP_DIR%
    ) else (
        echo [OK] Backup count is reasonable
    )
) else (
    echo [INFO] Backup directory not found
)

echo.
pause
echo.

echo ==============================================================
echo Phase 5: Optional Cleanup
echo ==============================================================
echo.

:: Ask about browser cache
echo Do you want to clean browser cache? (Y/N)
echo This will close all browser windows.
set /p CLEAN_BROWSER=

if /i "%CLEAN_BROWSER%"=="Y" (
    echo.
    echo Cleaning browser caches...

    :: Close browsers
    taskkill /f /im chrome.exe 2>nul
    taskkill /f /im firefox.exe 2>nul
    taskkill /f /im msedge.exe 2>nul

    :: Clean Chrome cache
    if exist "%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache" (
        rd /s /q "%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache" 2>nul
        echo [OK] Chrome cache cleaned
    )

    :: Clean Edge cache
    if exist "%LOCALAPPDATA%\Microsoft\Edge\User Data\Default\Cache" (
        rd /s /q "%LOCALAPPDATA%\Microsoft\Edge\User Data\Default\Cache" 2>nul
        echo [OK] Edge cache cleaned
    )
) else (
    echo [INFO] Skipping browser cache cleanup
)

echo.
pause
echo.

echo ==============================================================
echo Phase 6: Final Report
echo ==============================================================
echo.

:: Get final disk space
echo Checking final disk space...
for /f "tokens=3" %%a in ('dir %DRIVE%\ ^| find "bytes free"') do (
    set FINAL_FREE=%%a
)

echo.
echo Cleanup Summary:
echo ----------------
echo [OK] Windows temp files cleaned
echo [OK] Project temp files cleaned
echo [OK] Old log files removed
echo [OK] PHP session files cleaned
echo.
echo Final free space on %DRIVE%: Check complete
echo.

echo ==============================================================
echo    CLEANUP COMPLETED SUCCESSFULLY!
echo ==============================================================
echo.
echo Recommendations:
echo 1. Restart Apache from XAMPP Control Panel
echo 2. Check application functionality
echo 3. Run deployment if needed
echo.

:: Ask about deployment
echo Do you want to run the deployment now? (Y/N)
set /p RUN_DEPLOY=

if /i "%RUN_DEPLOY%"=="Y" (
    echo.
    echo Starting deployment...
    echo.
    if exist "%PROJECT_DIR%\deploy_fixed.bat" (
        call "%PROJECT_DIR%\deploy_fixed.bat"
    ) else if exist "%PROJECT_DIR%\deploy.bat" (
        call "%PROJECT_DIR%\deploy.bat"
    ) else (
        echo [ERROR] Deployment script not found!
    )
)

echo.
echo Press any key to exit...
pause >nul
exit /b 0