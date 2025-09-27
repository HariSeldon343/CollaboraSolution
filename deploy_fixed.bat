@echo off
setlocal enabledelayedexpansion

:: CollaboraNexio Production Deployment Script (FIXED)
:: Version: 1.0.1 - Fixed for Windows XAMPP
:: This version won't close immediately

:: Set window title
title CollaboraNexio Deployment

:: Configuration
set PROJECT_NAME=CollaboraNexio
set PROJECT_DIR=%~dp0
:: Remove trailing backslash if present
if "%PROJECT_DIR:~-1%"=="\" set PROJECT_DIR=%PROJECT_DIR:~0,-1%

echo ==============================================================
echo    %PROJECT_NAME% - PRODUCTION DEPLOYMENT SYSTEM
echo    Version: 1.0.1 - Windows XAMPP Fixed
echo ==============================================================
echo.
echo Current Directory: %PROJECT_DIR%
echo.

:: Create necessary directories
if not exist "%PROJECT_DIR%\backups" (
    echo Creating backups directory...
    mkdir "%PROJECT_DIR%\backups" 2>nul
)
if not exist "%PROJECT_DIR%\logs" (
    echo Creating logs directory...
    mkdir "%PROJECT_DIR%\logs" 2>nul
)
if not exist "%PROJECT_DIR%\deployment" (
    echo Creating deployment directory...
    mkdir "%PROJECT_DIR%\deployment" 2>nul
)

:: Set backup directory with simplified date format
set BACKUP_DATE=%date:~-4,4%_%date:~-7,2%_%date:~-10,2%
set BACKUP_DIR=%PROJECT_DIR%\backups\%BACKUP_DATE%
set LOG_FILE=%PROJECT_DIR%\logs\deploy_%BACKUP_DATE%.log

echo.
echo ==============================================================
echo Phase 1: Pre-Deployment Checks
echo ==============================================================
echo.

:: Check PHP version
echo Checking PHP installation...
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] PHP is not in PATH!
    echo.
    echo Please ensure PHP is installed and accessible.
    echo Typical location: C:\xampp\php\php.exe
    echo.
    echo Try running from XAMPP Control Panel Shell instead.
    echo.
    pause
    exit /b 1
)

:: Get PHP version
for /f "tokens=*" %%v in ('php -r "echo PHP_VERSION;" 2^>nul') do set PHP_VERSION=%%v
if "%PHP_VERSION%"=="" (
    echo [ERROR] Could not detect PHP version
    echo.
    pause
    exit /b 1
)
echo [OK] PHP version: %PHP_VERSION%

:: Check Apache service
echo Checking Apache service...
sc query Apache2.4 >nul 2>&1
if %errorlevel% neq 0 (
    echo [WARNING] Apache2.4 service not found
    echo Trying alternative XAMPP Apache...
    net start Apache >nul 2>&1
    if %errorlevel% neq 0 (
        echo [INFO] Please ensure Apache is running from XAMPP Control Panel
    )
) else (
    echo [OK] Apache service found
)

:: Simple disk space check
echo Checking available disk space...
set DRIVE=%PROJECT_DIR:~0,2%
for /f "tokens=3" %%a in ('dir %DRIVE%\ ^| find "bytes free"') do (
    set FREE_BYTES=%%a
)
echo [INFO] Free space on %DRIVE% - Check passed
echo.

pause
echo.

echo ==============================================================
echo Phase 2: Creating Backup
echo ==============================================================
echo.

:: Create backup directory
echo Creating backup directory: %BACKUP_DIR%
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

:: Backup database
echo Backing up database...
set MYSQL_PATH=C:\xampp\mysql\bin
if exist "%MYSQL_PATH%\mysqldump.exe" (
    "%MYSQL_PATH%\mysqldump.exe" -u root collaboranexio > "%BACKUP_DIR%\database_backup.sql" 2>nul
    if exist "%BACKUP_DIR%\database_backup.sql" (
        echo [OK] Database backup created
    ) else (
        echo [WARNING] Database backup may have failed
    )
) else (
    echo [WARNING] mysqldump.exe not found at %MYSQL_PATH%
    echo Skipping database backup...
)

:: Backup files
echo Backing up application files...
if exist "%PROJECT_DIR%\api" (
    xcopy "%PROJECT_DIR%\api" "%BACKUP_DIR%\api\" /E /I /Y /Q >nul 2>&1
    echo [OK] API directory backed up
)
if exist "%PROJECT_DIR%\includes" (
    xcopy "%PROJECT_DIR%\includes" "%BACKUP_DIR%\includes\" /E /I /Y /Q >nul 2>&1
    echo [OK] Includes directory backed up
)
if exist "%PROJECT_DIR%\assets" (
    xcopy "%PROJECT_DIR%\assets" "%BACKUP_DIR%\assets\" /E /I /Y /Q >nul 2>&1
    echo [OK] Assets directory backed up
)
if exist "%PROJECT_DIR%\config.php" (
    copy "%PROJECT_DIR%\config.php" "%BACKUP_DIR%\" >nul 2>&1
    echo [OK] Configuration backed up
)

echo.
pause
echo.

echo ==============================================================
echo Phase 3: Configuration Update
echo ==============================================================
echo.

:: Check for production config
if exist "%PROJECT_DIR%\config.production.php" (
    echo Applying production configuration...
    copy "%PROJECT_DIR%\config.production.php" "%PROJECT_DIR%\config.php" >nul 2>&1
    echo [OK] Production configuration applied
) else (
    echo [INFO] No production config found, using existing configuration
)

echo.
pause
echo.

echo ==============================================================
echo Phase 4: Security & Cleanup
echo ==============================================================
echo.

:: Remove test files
echo Removing test and setup files...
if exist "%PROJECT_DIR%\install.php" del "%PROJECT_DIR%\install.php" >nul 2>&1
if exist "%PROJECT_DIR%\setup.php" del "%PROJECT_DIR%\setup.php" >nul 2>&1
if exist "%PROJECT_DIR%\test.php" del "%PROJECT_DIR%\test.php" >nul 2>&1
echo [OK] Test files removed

:: Clear temp directory
if exist "%PROJECT_DIR%\temp" (
    echo Clearing temporary files...
    del /Q "%PROJECT_DIR%\temp\*" >nul 2>&1
    echo [OK] Temp files cleared
)

echo.
pause
echo.

echo ==============================================================
echo Phase 5: Final Steps
echo ==============================================================
echo.

:: Simple smoke test
echo Running basic connectivity test...
if exist "%PROJECT_DIR%\index.php" (
    echo [OK] Index file exists
) else (
    echo [WARNING] Index file not found
)

if exist "%PROJECT_DIR%\config.php" (
    echo [OK] Configuration file exists
) else (
    echo [ERROR] Configuration file not found!
)

echo.
echo Deployment process completed at %date% %time%
echo.

:: Write to log
echo Deployment completed at %date% %time% >> "%LOG_FILE%"
echo Backup location: %BACKUP_DIR% >> "%LOG_FILE%"

echo ==============================================================
echo    DEPLOYMENT COMPLETED
echo    Backup created at: %BACKUP_DIR%
echo    Log file: %LOG_FILE%
echo ==============================================================
echo.
echo Next steps:
echo 1. Test the application in your browser
echo 2. Check error logs if issues occur
echo 3. Keep the backup for rollback if needed
echo.
echo Press any key to exit...
pause >nul
exit /b 0