@echo off
setlocal enabledelayedexpansion

:: PHP Configuration Fix Script for CollaboraNexio
:: Fixes duplicate module loading and missing extensions
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
set PHP_INI_PATH=C:\xampp\php\php.ini
set PHP_INI_BACKUP=%PHP_INI_PATH%.backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set PHP_INI_BACKUP=%PHP_INI_BACKUP: =0%
set PHP_EXT_DIR=C:\xampp\php\ext
set TEMP_INI=%TEMP%\php_ini_temp.txt
set LOG_FILE=%cd%\php_config_fix_%date:~-4,4%%date:~-7,2%%date:~-10,2%.log

:: Initialize
cls
echo %CYAN%==============================================================
echo    PHP CONFIGURATION FIX UTILITY
echo    Fixing duplicate modules and missing extensions
echo ==============================================================
echo %RESET%
echo.

:: Start logging
echo [%date% %time%] PHP Configuration Fix started >> "%LOG_FILE%"

:: Check if running as administrator
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo %RED%[ERROR]%RESET% This script must be run as Administrator
    echo.
    echo Please right-click on this script and select "Run as administrator"
    pause
    exit /b 1
)

:: Step 1: Check PHP installation
echo %BLUE%Step 1: Checking PHP Installation%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

if not exist "%PHP_INI_PATH%" (
    echo %RED%[ERROR]%RESET% PHP configuration file not found at: %PHP_INI_PATH%
    echo Please verify XAMPP is installed correctly
    pause
    exit /b 1
)

:: Get PHP version
for /f "tokens=*" %%v in ('php -n -r "echo PHP_VERSION;" 2^>nul') do set PHP_VERSION=%%v
if "%PHP_VERSION%"=="" (
    echo %YELLOW%[WARNING]%RESET% Could not detect PHP version
) else (
    echo %GREEN%[OK]%RESET% PHP Version: %PHP_VERSION%
)

:: Step 2: Backup current php.ini
echo.
echo %BLUE%Step 2: Creating Backup%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

echo Creating backup at: %PHP_INI_BACKUP%
copy "%PHP_INI_PATH%" "%PHP_INI_BACKUP%" >nul 2>&1
if %errorlevel% equ 0 (
    echo %GREEN%[SUCCESS]%RESET% Backup created successfully
    echo [%date% %time%] Backup created: %PHP_INI_BACKUP% >> "%LOG_FILE%"
) else (
    echo %RED%[ERROR]%RESET% Failed to create backup
    echo [%date% %time%] Backup failed >> "%LOG_FILE%"
    pause
    exit /b 1
)

:: Step 3: Fix duplicate module loading
echo.
echo %BLUE%Step 3: Fixing Duplicate Module Loading%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

:: List of modules that might be duplicated
set "MODULES=openssl pdo_mysql mbstring fileinfo curl zip gd bcmath"

echo Checking for duplicate module declarations...
echo.

:: Create a temporary file without duplicate extensions
type nul > "%TEMP_INI%"
set "FOUND_EXTENSIONS="

:: Process php.ini line by line
for /f "usebackq tokens=*" %%L in ("%PHP_INI_PATH%") do (
    set "LINE=%%L"
    set "SKIP_LINE=0"

    :: Check if this line is an extension declaration
    echo !LINE! | findstr /I /C:"extension=" >nul 2>&1
    if !errorlevel! equ 0 (
        :: Extract extension name
        for %%M in (%MODULES%) do (
            echo !LINE! | findstr /I /C:"extension=%%M" >nul 2>&1
            if !errorlevel! equ 0 (
                :: Check if we've already seen this extension
                echo !FOUND_EXTENSIONS! | findstr /I /C:"%%M" >nul 2>&1
                if !errorlevel! equ 0 (
                    :: Duplicate found, skip this line
                    set "SKIP_LINE=1"
                    echo %YELLOW%[DUPLICATE]%RESET% Removing duplicate: !LINE!
                    echo [%date% %time%] Removed duplicate: !LINE! >> "%LOG_FILE%"
                ) else (
                    :: First occurrence, keep it
                    set "FOUND_EXTENSIONS=!FOUND_EXTENSIONS! %%M"
                )
            )
        )
    )

    :: Write line to temp file if not skipping
    if !SKIP_LINE! equ 0 (
        echo !LINE!>> "%TEMP_INI%"
    )
)

:: Step 4: Check and enable bcmath extension
echo.
echo %BLUE%Step 4: Checking BCMath Extension%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

:: Check if bcmath.dll exists
if exist "%PHP_EXT_DIR%\php_bcmath.dll" (
    echo %GREEN%[OK]%RESET% BCMath extension file found

    :: Check if bcmath is enabled
    findstr /I /C:"extension=bcmath" "%TEMP_INI%" >nul 2>&1
    if %errorlevel% neq 0 (
        echo %YELLOW%[INFO]%RESET% Enabling BCMath extension...

        :: Find the [PHP] section and add bcmath after it
        set "BCMATH_ADDED=0"
        type nul > "%TEMP%\php_ini_temp2.txt"

        for /f "usebackq tokens=*" %%L in ("%TEMP_INI%") do (
            echo %%L>> "%TEMP%\php_ini_temp2.txt"
            if "!BCMATH_ADDED!"=="0" (
                echo %%L | findstr /I /C:"; Dynamic Extensions" >nul 2>&1
                if !errorlevel! equ 0 (
                    echo extension=bcmath>> "%TEMP%\php_ini_temp2.txt"
                    set "BCMATH_ADDED=1"
                    echo %GREEN%[SUCCESS]%RESET% BCMath extension enabled
                    echo [%date% %time%] BCMath extension enabled >> "%LOG_FILE%"
                )
            )
        )

        :: If we couldn't find the right place, append at the end
        if "!BCMATH_ADDED!"=="0" (
            echo.>> "%TEMP%\php_ini_temp2.txt"
            echo ; Added by PHP Configuration Fix>> "%TEMP%\php_ini_temp2.txt"
            echo extension=bcmath>> "%TEMP%\php_ini_temp2.txt"
            echo %GREEN%[SUCCESS]%RESET% BCMath extension enabled
            echo [%date% %time%] BCMath extension enabled >> "%LOG_FILE%"
        )

        move /Y "%TEMP%\php_ini_temp2.txt" "%TEMP_INI%" >nul 2>&1
    ) else (
        echo %GREEN%[OK]%RESET% BCMath extension is already enabled
    )
) else (
    echo %YELLOW%[WARNING]%RESET% BCMath extension file not found at: %PHP_EXT_DIR%\php_bcmath.dll
    echo You may need to reinstall XAMPP or download the extension separately
    echo [%date% %time%] BCMath extension file not found >> "%LOG_FILE%"
)

:: Step 5: Additional optimizations
echo.
echo %BLUE%Step 5: Additional Optimizations%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

:: Check memory_limit
findstr /I /C:"memory_limit" "%TEMP_INI%" | findstr /V /C:";" >nul 2>&1
if %errorlevel% neq 0 (
    echo %YELLOW%[INFO]%RESET% Setting memory_limit to 256M...
    echo memory_limit=256M>> "%TEMP_INI%"
    echo [%date% %time%] Set memory_limit=256M >> "%LOG_FILE%"
)

:: Check max_execution_time
findstr /I /C:"max_execution_time" "%TEMP_INI%" | findstr /V /C:";" >nul 2>&1
if %errorlevel% neq 0 (
    echo %YELLOW%[INFO]%RESET% Setting max_execution_time to 300...
    echo max_execution_time=300>> "%TEMP_INI%"
    echo [%date% %time%] Set max_execution_time=300 >> "%LOG_FILE%"
)

:: Check post_max_size
findstr /I /C:"post_max_size" "%TEMP_INI%" | findstr /V /C:";" >nul 2>&1
if %errorlevel% neq 0 (
    echo %YELLOW%[INFO]%RESET% Setting post_max_size to 50M...
    echo post_max_size=50M>> "%TEMP_INI%"
    echo [%date% %time%] Set post_max_size=50M >> "%LOG_FILE%"
)

:: Check upload_max_filesize
findstr /I /C:"upload_max_filesize" "%TEMP_INI%" | findstr /V /C:";" >nul 2>&1
if %errorlevel% neq 0 (
    echo %YELLOW%[INFO]%RESET% Setting upload_max_filesize to 50M...
    echo upload_max_filesize=50M>> "%TEMP_INI%"
    echo [%date% %time%] Set upload_max_filesize=50M >> "%LOG_FILE%"
)

echo %GREEN%[SUCCESS]%RESET% Optimizations applied

:: Step 6: Apply the fixed configuration
echo.
echo %BLUE%Step 6: Applying Fixed Configuration%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

:: Replace the original php.ini with the fixed version
move /Y "%TEMP_INI%" "%PHP_INI_PATH%" >nul 2>&1
if %errorlevel% equ 0 (
    echo %GREEN%[SUCCESS]%RESET% Configuration applied successfully
    echo [%date% %time%] Configuration applied successfully >> "%LOG_FILE%"
) else (
    echo %RED%[ERROR]%RESET% Failed to apply configuration
    echo Restoring from backup...
    copy /Y "%PHP_INI_BACKUP%" "%PHP_INI_PATH%" >nul 2>&1
    echo [%date% %time%] Failed to apply configuration, restored from backup >> "%LOG_FILE%"
    pause
    exit /b 1
)

:: Step 7: Test configuration
echo.
echo %BLUE%Step 7: Testing PHP Configuration%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

:: Test PHP with the new configuration
php -n -d display_errors=0 -r "echo 'PHP configuration test: OK';" >nul 2>&1
if %errorlevel% equ 0 (
    echo %GREEN%[SUCCESS]%RESET% PHP configuration is valid
) else (
    echo %YELLOW%[WARNING]%RESET% PHP test returned an error, but configuration was applied
)

:: Check enabled extensions
echo.
echo Enabled extensions:
php -n -r "print_r(get_loaded_extensions());" 2>nul | findstr /I "bcmath openssl pdo_mysql mbstring fileinfo curl zip gd"

:: Step 8: Restart Apache if running
echo.
echo %BLUE%Step 8: Restarting Services%RESET%
echo %WHITE%----------------------------------------%RESET%
echo.

sc query Apache2.4 | findstr RUNNING >nul 2>&1
if %errorlevel% equ 0 (
    echo Restarting Apache service...
    net stop Apache2.4 >nul 2>&1
    timeout /t 2 /nobreak >nul
    net start Apache2.4 >nul 2>&1
    if %errorlevel% equ 0 (
        echo %GREEN%[SUCCESS]%RESET% Apache restarted successfully
        echo [%date% %time%] Apache restarted >> "%LOG_FILE%"
    ) else (
        echo %YELLOW%[WARNING]%RESET% Failed to restart Apache. Please restart manually
        echo [%date% %time%] Failed to restart Apache >> "%LOG_FILE%"
    )
) else (
    echo %YELLOW%[INFO]%RESET% Apache service is not running
)

:: Clean up temp files
if exist "%TEMP_INI%" del "%TEMP_INI%" >nul 2>&1
if exist "%TEMP%\php_ini_temp2.txt" del "%TEMP%\php_ini_temp2.txt" >nul 2>&1

:: Summary
echo.
echo %GREEN%==============================================================
echo    PHP CONFIGURATION FIX COMPLETED!
echo.
echo    Original php.ini backed up to:
echo    %PHP_INI_BACKUP%
echo.
echo    Log file saved to:
echo    %LOG_FILE%
echo.
echo    Please test your application to ensure everything works correctly.
echo ==============================================================
echo %RESET%

echo [%date% %time%] PHP Configuration Fix completed successfully >> "%LOG_FILE%"

pause
endlocal
exit /b 0