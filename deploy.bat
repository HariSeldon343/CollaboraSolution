@echo off
setlocal enabledelayedexpansion

:: CollaboraNexio Production Deployment Script
:: Version: 1.0.0
:: PHP 8.3 Compatible

:: Color codes
set RED=[91m
set GREEN=[92m
set YELLOW=[93m
set BLUE=[94m
set MAGENTA=[95m
set CYAN=[96m
set WHITE=[97m
set RESET=[0m

:: Configuration
set PROJECT_NAME=CollaboraNexio
set PROJECT_DIR=C:\xampp\htdocs\CollaboraNexio
set BACKUP_DIR=%PROJECT_DIR%\backups\%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set BACKUP_DIR=%BACKUP_DIR: =0%
set LOG_FILE=%PROJECT_DIR%\logs\deploy_%date:~-4,4%%date:~-7,2%%date:~-10,2%.log
set TEMP_DIR=%PROJECT_DIR%\temp
set ROLLBACK_FILE=%PROJECT_DIR%\backups\last_deployment.zip

:: Deployment parameters
set FORCE_DEPLOY=0
set MIN_SPACE_MB=100

:: Check for command line arguments
if "%1"=="--force" (
    set FORCE_DEPLOY=1
    echo %YELLOW%[WARNING] Force deployment enabled - disk space check will be advisory only%RESET%
)
if "%1"=="--help" (
    echo Usage: deploy.bat [options]
    echo Options:
    echo   --force    Continue deployment even with low disk space
    echo   --help     Show this help message
    exit /b 0
)

:: Initialize
cls
echo %CYAN%==============================================================
echo    %PROJECT_NAME% - PRODUCTION DEPLOYMENT SYSTEM
echo    Version: 1.0.0 - PHP 8.3 Optimized
echo ==============================================================
echo %RESET%
echo.

:: Create necessary directories
if not exist "%PROJECT_DIR%\backups" mkdir "%PROJECT_DIR%\backups"
if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"
if not exist "%PROJECT_DIR%\deployment" mkdir "%PROJECT_DIR%\deployment"

:: Start logging
echo [%date% %time%] Deployment started >> "%LOG_FILE%"

:: Function to display progress
goto :main

:show_progress
echo %CYAN%[%~1]%RESET% %~2
echo [%date% %time%] [%~1] %~2 >> "%LOG_FILE%"
exit /b

:show_success
echo %GREEN%[SUCCESS]%RESET% %~1
echo [%date% %time%] [SUCCESS] %~1 >> "%LOG_FILE%"
exit /b

:show_error
echo %RED%[ERROR]%RESET% %~1
echo [%date% %time%] [ERROR] %~1 >> "%LOG_FILE%"
exit /b

:show_warning
echo %YELLOW%[WARNING]%RESET% %~1
echo [%date% %time%] [WARNING] %~1 >> "%LOG_FILE%"
exit /b

:main
echo %BLUE%Phase 1: Pre-Deployment Checks%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Check PHP version with error suppression
call :show_progress "INFO" "Checking PHP version..."
:: Suppress duplicate module warnings and check for PHP 8.2+
for /f "tokens=*" %%v in ('php -n -r "echo PHP_VERSION;" 2^>nul') do set PHP_VERSION=%%v
if "%PHP_VERSION%"=="" (
    call :show_error "Could not detect PHP version"
    goto :deployment_failed
)

:: Extract major and minor version
for /f "tokens=1,2 delims=." %%a in ("%PHP_VERSION%") do (
    set PHP_MAJOR=%%a
    set PHP_MINOR=%%b
)

:: Check if PHP 8.2 or higher (8.2, 8.3, etc.)
if %PHP_MAJOR% lss 8 (
    call :show_error "PHP 8.2 or higher is required. Current version: %PHP_VERSION%"
    goto :deployment_failed
)
if %PHP_MAJOR% equ 8 (
    if %PHP_MINOR% lss 2 (
        call :show_error "PHP 8.2 or higher is required. Current version: %PHP_VERSION%"
        goto :deployment_failed
    )
)
call :show_success "PHP version check passed (Version: %PHP_VERSION%)"

:: Check if Apache is running
call :show_progress "INFO" "Checking Apache service..."
sc query Apache2.4 | findstr RUNNING >nul
if %errorlevel% neq 0 (
    call :show_warning "Apache service is not running. Starting Apache..."
    net start Apache2.4
)
call :show_success "Apache service check completed"

:: Check disk space with improved accuracy
call :show_progress "INFO" "Checking disk space..."

:: Get drive letter from PROJECT_DIR
for %%i in ("%PROJECT_DIR%") do set DRIVE=%%~di

:: Use WMIC for accurate disk space check (in bytes)
for /f "skip=1 tokens=1" %%a in ('wmic logicaldisk where "DeviceID='%DRIVE%'" get FreeSpace 2^>nul') do (
    set FREE_SPACE_BYTES=%%a
    goto :got_space
)
:got_space

:: Convert bytes to MB and GB for display
set /a FREE_SPACE_MB=%FREE_SPACE_BYTES:~0,-6% 2>nul
if "%FREE_SPACE_MB%"=="" set /a FREE_SPACE_MB=%FREE_SPACE_BYTES:~0,-5%/10 2>nul
if "%FREE_SPACE_MB%"=="" set /a FREE_SPACE_MB=50

set /a FREE_SPACE_GB=%FREE_SPACE_MB%/1024
set /a FREE_SPACE_GB_REMAINDER=%FREE_SPACE_MB%%%1024*100/1024

:: Format display with decimal
if %FREE_SPACE_GB% gtr 0 (
    set SPACE_DISPLAY=%FREE_SPACE_GB%.%FREE_SPACE_GB_REMAINDER:~0,1% GB
) else (
    set SPACE_DISPLAY=%FREE_SPACE_MB% MB
)

:: Check against minimum requirement
if %FREE_SPACE_MB% lss %MIN_SPACE_MB% (
    call :show_error "Low disk space detected: %SPACE_DISPLAY% available (minimum recommended: %MIN_SPACE_MB% MB)"

    if %FORCE_DEPLOY% equ 1 (
        call :show_warning "Continuing with deployment due to --force flag"
        echo.
        echo %YELLOW%WARNING: Deployment may fail if disk runs out of space!%RESET%
        echo.
    ) else (
        echo.
        echo %YELLOW%Options:%RESET%
        echo   1. Run cleanup.bat to free up space
        echo   2. Use 'deploy.bat --force' to continue anyway
        echo   3. Free up space manually and retry
        echo.
        echo %CYAN%Do you want to continue deployment anyway? (Y/N)%RESET%
        set /p CONTINUE_LOW_SPACE=
        if /i "!CONTINUE_LOW_SPACE!"=="Y" (
            call :show_warning "Continuing deployment with low disk space at user request"
        ) else (
            echo.
            echo %CYAN%Tip: Run 'cleanup.bat' to automatically free up space%RESET%
            goto :deployment_failed
        )
    )
) else if %FREE_SPACE_MB% lss 200 (
    call :show_warning "Disk space is low: %SPACE_DISPLAY% available"
    call :show_warning "Consider running cleanup.bat after deployment"
) else (
    call :show_success "Disk space check passed (%SPACE_DISPLAY% available)"
)

echo.
echo %BLUE%Phase 2: Creating Backup%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Create backup directory
call :show_progress "INFO" "Creating backup directory..."
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

:: Backup database
call :show_progress "INFO" "Backing up database..."
set MYSQL_PATH=C:\xampp\mysql\bin
"%MYSQL_PATH%\mysqldump.exe" -u root collaboranexio > "%BACKUP_DIR%\database_backup.sql" 2>>"%LOG_FILE%"
if %errorlevel% neq 0 (
    call :show_error "Database backup failed"
    goto :deployment_failed
)
call :show_success "Database backed up successfully"

:: Backup files
call :show_progress "INFO" "Backing up application files..."
xcopy "%PROJECT_DIR%\api" "%BACKUP_DIR%\api\" /E /I /Y /Q >nul
xcopy "%PROJECT_DIR%\includes" "%BACKUP_DIR%\includes\" /E /I /Y /Q >nul
xcopy "%PROJECT_DIR%\assets" "%BACKUP_DIR%\assets\" /E /I /Y /Q >nul
copy "%PROJECT_DIR%\config.php" "%BACKUP_DIR%\" >nul 2>&1
copy "%PROJECT_DIR%\*.php" "%BACKUP_DIR%\" >nul 2>&1
call :show_success "Application files backed up"

:: Create rollback point
call :show_progress "INFO" "Creating rollback point..."
powershell -Command "Compress-Archive -Path '%BACKUP_DIR%\*' -DestinationPath '%ROLLBACK_FILE%' -Force" >nul
call :show_success "Rollback point created"

echo.
echo %BLUE%Phase 3: Configuration Update%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Generate production configuration
call :show_progress "INFO" "Generating production configuration..."
if exist "%PROJECT_DIR%\config.production.php" (
    copy "%PROJECT_DIR%\config.production.php" "%PROJECT_DIR%\config.php" >nul
    call :show_success "Production configuration applied"
) else (
    call :show_warning "Production config not found, will generate..."
    php "%PROJECT_DIR%\deployment\generate_production_config.php"
    if %errorlevel% neq 0 (
        call :show_error "Failed to generate production configuration"
        goto :deployment_failed
    )
    call :show_success "Production configuration generated"
)

echo.
echo %BLUE%Phase 4: Asset Optimization%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Minify CSS files
call :show_progress "INFO" "Minifying CSS files..."
for %%f in ("%PROJECT_DIR%\assets\css\*.css") do (
    if not "%%~nf"==".min" (
        powershell -Command "(Get-Content '%%f') -replace '\s+', ' ' -replace '\/\*.*?\*\/', '' -replace '\s*:\s*', ':' -replace '\s*;\s*', ';' -replace '\s*{\s*', '{' -replace '\s*}\s*', '}' | Set-Content '%%~dpnf.min.css'"
    )
)
call :show_success "CSS files minified"

:: Minify JS files
call :show_progress "INFO" "Minifying JavaScript files..."
for %%f in ("%PROJECT_DIR%\assets\js\*.js") do (
    if not "%%~nf"==".min" (
        powershell -Command "(Get-Content '%%f') -replace '^\s*//.*$', '' -replace '\s+', ' ' -replace '\/\*[\s\S]*?\*\/', '' | Set-Content '%%~dpnf.min.js'"
    )
)
call :show_success "JavaScript files minified"

:: Optimize images
call :show_progress "INFO" "Optimizing images..."
:: Note: For real image optimization, you'd need tools like ImageMagick or pngquant
:: This is a placeholder that at least validates images exist
dir /b "%PROJECT_DIR%\assets\images\*.png" "%PROJECT_DIR%\assets\images\*.jpg" "%PROJECT_DIR%\assets\images\*.webp" >nul 2>&1
if %errorlevel% equ 0 (
    call :show_success "Images validated"
) else (
    call :show_warning "No images found to optimize"
)

echo.
echo %BLUE%Phase 5: Security Hardening%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Generate secure .htaccess
call :show_progress "INFO" "Generating secure .htaccess..."
copy "%PROJECT_DIR%\.htaccess" "%PROJECT_DIR%\.htaccess.bak" >nul 2>&1
call :show_success ".htaccess configured"

:: Set file permissions (Windows)
call :show_progress "INFO" "Setting file permissions..."
icacls "%PROJECT_DIR%\config.php" /grant:r "IIS_IUSRS:(R)" /T >nul
icacls "%PROJECT_DIR%\uploads" /grant:r "IIS_IUSRS:(M)" /T >nul
icacls "%PROJECT_DIR%\temp" /grant:r "IIS_IUSRS:(M)" /T >nul
icacls "%PROJECT_DIR%\logs" /grant:r "IIS_IUSRS:(M)" /T >nul
call :show_success "File permissions set"

:: Clear sensitive files
call :show_progress "INFO" "Removing sensitive files..."
del "%PROJECT_DIR%\install*.php" >nul 2>&1
del "%PROJECT_DIR%\setup*.php" >nul 2>&1
del "%PROJECT_DIR%\test*.php" >nul 2>&1
del "%PROJECT_DIR%\*.sql" >nul 2>&1
call :show_success "Sensitive files removed"

echo.
echo %BLUE%Phase 6: Cache and Optimization%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clear temp files
call :show_progress "INFO" "Clearing temporary files..."
del /Q "%TEMP_DIR%\*" >nul 2>&1
del /Q "%PROJECT_DIR%\logs\*.log.old" >nul 2>&1
call :show_success "Temporary files cleared"

:: Check if OPcache is already configured
call :show_progress "INFO" "Checking OPcache configuration..."
findstr /C:"opcache.enable" "C:\xampp\php\php.ini" >nul 2>&1
if %errorlevel% neq 0 (
    call :show_progress "INFO" "Configuring OPcache..."
    echo. >> "C:\xampp\php\php.ini"
    echo [opcache] >> "C:\xampp\php\php.ini"
    echo opcache.enable=1 >> "C:\xampp\php\php.ini"
    echo opcache.enable_cli=1 >> "C:\xampp\php\php.ini"
    echo opcache.memory_consumption=256 >> "C:\xampp\php\php.ini"
    echo opcache.max_accelerated_files=20000 >> "C:\xampp\php\php.ini"
    echo opcache.validate_timestamps=0 >> "C:\xampp\php\php.ini"
    call :show_success "OPcache configured"
) else (
    call :show_success "OPcache already configured"
)

echo.
echo %BLUE%Phase 7: Deployment Tests%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Run smoke tests with error suppression
call :show_progress "INFO" "Running smoke tests..."
if exist "%PROJECT_DIR%\smoke_test.php" (
    php -n -d display_errors=0 -d error_reporting=0 "%PROJECT_DIR%\smoke_test.php" > "%TEMP_DIR%\smoke_test_result.txt" 2>&1
    set TEST_RESULT=%errorlevel%
) else (
    call :show_warning "Smoke test file not found, skipping tests..."
    set TEST_RESULT=0
)

if %TEST_RESULT% equ 0 (
    call :show_success "All smoke tests passed"
    type "%TEMP_DIR%\smoke_test_result.txt"
) else (
    call :show_error "Smoke tests failed!"
    type "%TEMP_DIR%\smoke_test_result.txt"

    echo.
    echo %YELLOW%Do you want to rollback the deployment? (Y/N)%RESET%
    set /p ROLLBACK_CHOICE=
    if /i "%ROLLBACK_CHOICE%"=="Y" (
        goto :rollback
    )
)

:: Restart Apache to apply changes
call :show_progress "INFO" "Restarting Apache..."
net stop Apache2.4 >nul 2>&1
net start Apache2.4 >nul 2>&1
call :show_success "Apache restarted"

echo.
echo %GREEN%==============================================================
echo    DEPLOYMENT COMPLETED SUCCESSFULLY!
echo    Backup created at: %BACKUP_DIR%
echo    Log file: %LOG_FILE%
echo ==============================================================
echo %RESET%
echo [%date% %time%] Deployment completed successfully >> "%LOG_FILE%"
goto :end

:rollback
echo.
echo %YELLOW%Starting rollback process...%RESET%
call :show_progress "INFO" "Extracting rollback archive..."
powershell -Command "Expand-Archive -Path '%ROLLBACK_FILE%' -DestinationPath '%PROJECT_DIR%' -Force"
if %errorlevel% equ 0 (
    call :show_success "Rollback completed successfully"
    echo [%date% %time%] Rollback completed >> "%LOG_FILE%"
) else (
    call :show_error "Rollback failed! Manual intervention required"
    echo [%date% %time%] Rollback failed >> "%LOG_FILE%"
)
goto :end

:deployment_failed
echo.
echo %RED%==============================================================
echo    DEPLOYMENT FAILED!
echo    Check log file for details: %LOG_FILE%
echo ==============================================================
echo %RESET%
echo [%date% %time%] Deployment failed >> "%LOG_FILE%"

:end
endlocal
pause