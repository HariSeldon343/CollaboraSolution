@echo off
setlocal enabledelayedexpansion

:: CollaboraNexio Emergency Rollback Script
:: Version: 1.0.0

:: Color codes
set RED=[91m
set GREEN=[92m
set YELLOW=[93m
set BLUE=[94m
set CYAN=[96m
set WHITE=[97m
set RESET=[0m

:: Configuration
set PROJECT_DIR=C:\xampp\htdocs\CollaboraNexio
set BACKUP_DIR=%PROJECT_DIR%\backups
set ROLLBACK_FILE=%BACKUP_DIR%\last_deployment.zip
set LOG_FILE=%PROJECT_DIR%\logs\rollback_%date:~-4,4%%date:~-7,2%%date:~-10,2%.log

cls
echo %RED%==============================================================
echo    EMERGENCY ROLLBACK SYSTEM
echo    CollaboraNexio Production Deployment
echo ==============================================================
echo %RESET%
echo.

echo %YELLOW%WARNING: This will restore the previous deployment state.%RESET%
echo %YELLOW%All changes since the last deployment will be lost!%RESET%
echo.

set /p CONFIRM=%CYAN%Are you sure you want to proceed with rollback? (YES/NO): %RESET%
if /i not "%CONFIRM%"=="YES" (
    echo.
    echo %GREEN%Rollback cancelled. No changes made.%RESET%
    goto :end
)

echo.
echo %BLUE%Starting Rollback Process...%RESET%
echo [%date% %time%] Rollback started >> "%LOG_FILE%"

:: Check if rollback file exists
if not exist "%ROLLBACK_FILE%" (
    echo %RED%[ERROR] No rollback file found at:%RESET%
    echo %ROLLBACK_FILE%
    echo.
    echo %YELLOW%Checking for other backup files...%RESET%

    :: List available backups
    dir /B /O-D "%BACKUP_DIR%\*.zip" 2>nul
    if %errorlevel% neq 0 (
        echo %RED%No backup files found. Manual recovery required.%RESET%
        goto :failed
    )

    echo.
    set /p BACKUP_NAME=%CYAN%Enter backup filename to restore (or CANCEL to exit): %RESET%
    if /i "%BACKUP_NAME%"=="CANCEL" goto :end

    set ROLLBACK_FILE=%BACKUP_DIR%\%BACKUP_NAME%
    if not exist "%ROLLBACK_FILE%" (
        echo %RED%Backup file not found!%RESET%
        goto :failed
    )
)

:: Stop Apache
echo %CYAN%[1/5]%RESET% Stopping Apache service...
net stop Apache2.4 >nul 2>&1
echo [%date% %time%] Apache stopped >> "%LOG_FILE%"

:: Create emergency backup of current state
echo %CYAN%[2/5]%RESET% Creating emergency backup of current state...
set EMERGENCY_BACKUP=%BACKUP_DIR%\emergency_%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%.zip
set EMERGENCY_BACKUP=%EMERGENCY_BACKUP: =0%
powershell -Command "Compress-Archive -Path '%PROJECT_DIR%\*' -DestinationPath '%EMERGENCY_BACKUP%' -Force" >nul
echo [%date% %time%] Emergency backup created: %EMERGENCY_BACKUP% >> "%LOG_FILE%"

:: Clear current files (except backups and logs)
echo %CYAN%[3/5]%RESET% Clearing current deployment...
for %%d in (api assets includes public temp uploads) do (
    if exist "%PROJECT_DIR%\%%d" (
        rmdir /S /Q "%PROJECT_DIR%\%%d" 2>nul
    )
)
del /Q "%PROJECT_DIR%\*.php" 2>nul
del /Q "%PROJECT_DIR%\*.html" 2>nul
del /Q "%PROJECT_DIR%\.htaccess" 2>nul
echo [%date% %time%] Current deployment cleared >> "%LOG_FILE%"

:: Extract rollback archive
echo %CYAN%[4/5]%RESET% Restoring from backup...
powershell -Command "Expand-Archive -Path '%ROLLBACK_FILE%' -DestinationPath '%PROJECT_DIR%' -Force"
if %errorlevel% neq 0 (
    echo %RED%[ERROR] Failed to extract backup!%RESET%
    echo Attempting to restore emergency backup...
    powershell -Command "Expand-Archive -Path '%EMERGENCY_BACKUP%' -DestinationPath '%PROJECT_DIR%' -Force"
    goto :failed
)
echo [%date% %time%] Files restored from backup >> "%LOG_FILE%"

:: Restore database if SQL backup exists
set DB_BACKUP=%PROJECT_DIR%\database_backup.sql
if exist "%DB_BACKUP%" (
    echo %CYAN%[5/5]%RESET% Restoring database...
    set MYSQL_PATH=C:\xampp\mysql\bin
    "%MYSQL_PATH%\mysql.exe" -u root collaboranexio < "%DB_BACKUP%" 2>>"%LOG_FILE%"
    if %errorlevel% equ 0 (
        echo %GREEN%Database restored successfully%RESET%
        echo [%date% %time%] Database restored >> "%LOG_FILE%"
    ) else (
        echo %YELLOW%[WARNING] Database restoration failed. Manual restore may be required.%RESET%
        echo [%date% %time%] Database restore failed >> "%LOG_FILE%"
    )
) else (
    echo %YELLOW%[WARNING] No database backup found. Skipping database restore.%RESET%
)

:: Start Apache
echo.
echo %CYAN%Starting Apache service...%RESET%
net start Apache2.4 >nul 2>&1
echo [%date% %time%] Apache started >> "%LOG_FILE%"

:: Run basic health check
echo.
echo %CYAN%Running health check...%RESET%
php "%PROJECT_DIR%\smoke_test.php" >nul 2>&1
if %errorlevel% equ 0 (
    echo %GREEN%Health check passed%RESET%
) else (
    echo %YELLOW%Health check failed. Please verify the system manually.%RESET%
)

echo.
echo %GREEN%==============================================================
echo    ROLLBACK COMPLETED SUCCESSFULLY
echo    Emergency backup saved at:
echo    %EMERGENCY_BACKUP%
echo ==============================================================
echo %RESET%
echo [%date% %time%] Rollback completed successfully >> "%LOG_FILE%"
goto :end

:failed
echo.
echo %RED%==============================================================
echo    ROLLBACK FAILED!
echo    Manual intervention required.
echo    Check log file: %LOG_FILE%
echo    Emergency backup: %EMERGENCY_BACKUP%
echo ==============================================================
echo %RESET%
echo [%date% %time%] Rollback failed >> "%LOG_FILE%"

:end
endlocal
pause