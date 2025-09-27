@echo off
setlocal enabledelayedexpansion

:: PHP Version and Extension Check Script
:: For CollaboraNexio on Windows XAMPP

:: Color codes
set GREEN=[92m
set YELLOW=[93m
set RED=[91m
set CYAN=[96m
set WHITE=[97m
set RESET=[0m

cls
echo %CYAN%==============================================================
echo    PHP INSTALLATION CHECK
echo ==============================================================
echo %RESET%
echo.

:: Check PHP version without triggering module warnings
echo %WHITE%PHP Version:%RESET%
for /f "tokens=*" %%v in ('php -n -r "echo PHP_VERSION;" 2^>nul') do set PHP_VERSION=%%v
if "%PHP_VERSION%"=="" (
    echo %RED%[ERROR]%RESET% Could not detect PHP version
    echo Please ensure PHP is installed and in your PATH
    pause
    exit /b 1
)

echo %GREEN%[OK]%RESET% PHP %PHP_VERSION% detected
echo.

:: Extract version numbers for comparison
for /f "tokens=1,2,3 delims=." %%a in ("%PHP_VERSION%") do (
    set PHP_MAJOR=%%a
    set PHP_MINOR=%%b
    set PHP_PATCH=%%c
)

:: Check version requirements
if %PHP_MAJOR% lss 8 (
    echo %RED%[ERROR]%RESET% PHP 8.2 or higher is required
    echo Current version: %PHP_VERSION%
) else if %PHP_MAJOR% equ 8 (
    if %PHP_MINOR% lss 2 (
        echo %YELLOW%[WARNING]%RESET% PHP 8.2 or higher is recommended
        echo Current version: %PHP_VERSION%
    ) else (
        echo %GREEN%[OK]%RESET% PHP version meets requirements
    )
) else (
    echo %GREEN%[OK]%RESET% PHP version meets requirements
)

:: Check required extensions
echo.
echo %WHITE%Checking Required Extensions:%RESET%
echo %WHITE%----------------------------------------%RESET%

set "REQUIRED_EXTS=bcmath curl fileinfo gd mbstring openssl pdo_mysql zip"
set "MISSING_EXTS="

for %%E in (%REQUIRED_EXTS%) do (
    php -n -r "if(extension_loaded('%%E')) exit(0); else exit(1);" 2>nul
    if !errorlevel! neq 0 (
        :: Try with php_ prefix
        php -n -r "if(extension_loaded('php_%%E')) exit(0); else exit(1);" 2>nul
        if !errorlevel! neq 0 (
            :: Check in php.ini
            findstr /I /C:"extension=%%E" "C:\xampp\php\php.ini" >nul 2>&1
            if !errorlevel! neq 0 (
                echo %RED%[MISSING]%RESET% %%E
                set "MISSING_EXTS=!MISSING_EXTS! %%E"
            ) else (
                echo %YELLOW%[CONFIGURED]%RESET% %%E - configured but may not be loaded
            )
        ) else (
            echo %GREEN%[OK]%RESET% %%E
        )
    ) else (
        echo %GREEN%[OK]%RESET% %%E
    )
)

:: Check PHP configuration file
echo.
echo %WHITE%PHP Configuration:%RESET%
echo %WHITE%----------------------------------------%RESET%

if exist "C:\xampp\php\php.ini" (
    echo %GREEN%[OK]%RESET% php.ini found at: C:\xampp\php\php.ini

    :: Check for duplicate extension declarations
    echo.
    echo Checking for duplicate module declarations...
    set "DUP_COUNT=0"
    for %%E in (openssl pdo_mysql mbstring fileinfo curl zip gd bcmath) do (
        for /f %%C in ('findstr /I /C:"extension=%%E" "C:\xampp\php\php.ini" ^| find /c /v ""') do (
            if %%C gtr 1 (
                echo %YELLOW%[WARNING]%RESET% Extension %%E is declared %%C times
                set /a DUP_COUNT+=1
            )
        )
    )

    if !DUP_COUNT! gtr 0 (
        echo.
        echo %YELLOW%[WARNING]%RESET% Found !DUP_COUNT! duplicate extension declarations
        echo Run fix_php_config.bat to resolve these issues
    )
) else (
    echo %RED%[ERROR]%RESET% php.ini not found at expected location
)

:: Check memory settings
echo.
echo %WHITE%PHP Memory Settings:%RESET%
echo %WHITE%----------------------------------------%RESET%

for /f "tokens=*" %%v in ('php -n -r "echo ini_get('memory_limit');" 2^>nul') do set MEM_LIMIT=%%v
for /f "tokens=*" %%v in ('php -n -r "echo ini_get('post_max_size');" 2^>nul') do set POST_MAX=%%v
for /f "tokens=*" %%v in ('php -n -r "echo ini_get('upload_max_filesize');" 2^>nul') do set UPLOAD_MAX=%%v

echo Memory Limit: %MEM_LIMIT%
echo Post Max Size: %POST_MAX%
echo Upload Max Filesize: %UPLOAD_MAX%

:: Summary
echo.
echo %CYAN%==============================================================
echo    SUMMARY
echo ==============================================================
echo %RESET%

if "%MISSING_EXTS%"=="" (
    if !DUP_COUNT! equ 0 (
        echo %GREEN%[READY]%RESET% PHP is properly configured for CollaboraNexio
    ) else (
        echo %YELLOW%[ATTENTION]%RESET% PHP is functional but has configuration issues
        echo.
        echo Recommended action: Run fix_php_config.bat to optimize configuration
    )
) else (
    echo %RED%[ACTION REQUIRED]%RESET% Missing extensions:!MISSING_EXTS!
    echo.
    echo Please run fix_php_config.bat to resolve these issues
)

echo.
pause
endlocal
exit /b 0