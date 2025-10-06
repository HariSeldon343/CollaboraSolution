@echo off
setlocal

:: CollaboraNexio Environment Test Script
:: Version: 1.0.0
:: This script checks your XAMPP environment

:: Set window title
title CollaboraNexio Environment Test

cls
echo ==============================================================
echo    COLLABORANEXIO - ENVIRONMENT DIAGNOSTIC
echo    Version: 1.0.0 - XAMPP Environment Checker
echo ==============================================================
echo.
echo This script will test your XAMPP environment.
echo It will NOT close automatically.
echo.

pause
echo.

echo ==============================================================
echo Test 1: Current Directory
echo ==============================================================
echo.
echo Script location: %~dp0
echo Current directory: %CD%
echo.

pause
echo.

echo ==============================================================
echo Test 2: PHP Installation
echo ==============================================================
echo.

echo Checking if PHP is in PATH...
where php >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] PHP found in PATH
    echo.
    echo PHP Version:
    php -v 2>nul
) else (
    echo [WARNING] PHP not found in PATH
    echo.
    echo Trying XAMPP default location...
    if exist "C:\xampp\php\php.exe" (
        echo [OK] PHP found at C:\xampp\php\php.exe
        echo.
        echo PHP Version:
        "C:\xampp\php\php.exe" -v 2>nul
        echo.
        echo TIP: Add C:\xampp\php to your system PATH for easier access
    ) else (
        echo [ERROR] PHP not found at C:\xampp\php\php.exe
        echo Please check your XAMPP installation
    )
)
echo.

pause
echo.

echo ==============================================================
echo Test 3: MySQL Installation
echo ==============================================================
echo.

echo Checking MySQL...
if exist "C:\xampp\mysql\bin\mysql.exe" (
    echo [OK] MySQL found at C:\xampp\mysql\bin\mysql.exe
    echo.
    echo MySQL Version:
    "C:\xampp\mysql\bin\mysql.exe" --version 2>nul
) else (
    echo [ERROR] MySQL not found
    echo Expected location: C:\xampp\mysql\bin\mysql.exe
)
echo.

pause
echo.

echo ==============================================================
echo Test 4: Apache Service
echo ==============================================================
echo.

echo Checking Apache service status...
sc query Apache2.4 >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Apache2.4 service is installed
    sc query Apache2.4 | findstr RUNNING >nul
    if %errorlevel% equ 0 (
        echo [OK] Apache is running
    ) else (
        echo [WARNING] Apache is installed but not running
        echo Start it from XAMPP Control Panel
    )
) else (
    echo [INFO] Apache2.4 service not found
    echo This is normal if you run Apache from XAMPP Control Panel
    echo.
    echo Checking XAMPP Apache...
    if exist "C:\xampp\apache\bin\httpd.exe" (
        echo [OK] Apache found at C:\xampp\apache\bin\httpd.exe
    ) else (
        echo [ERROR] Apache not found in XAMPP directory
    )
)
echo.

pause
echo.

echo ==============================================================
echo Test 5: Project Structure
echo ==============================================================
echo.

set PROJECT_DIR=%~dp0
if "%PROJECT_DIR:~-1%"=="\" set PROJECT_DIR=%PROJECT_DIR:~0,-1%

echo Checking project directories...
echo.

if exist "%PROJECT_DIR%\api" (
    echo [OK] api directory exists
) else (
    echo [WARNING] api directory not found
)

if exist "%PROJECT_DIR%\includes" (
    echo [OK] includes directory exists
) else (
    echo [WARNING] includes directory not found
)

if exist "%PROJECT_DIR%\assets" (
    echo [OK] assets directory exists
) else (
    echo [WARNING] assets directory not found
)

if exist "%PROJECT_DIR%\config.php" (
    echo [OK] config.php exists
) else (
    echo [WARNING] config.php not found
)

if exist "%PROJECT_DIR%\index.php" (
    echo [OK] index.php exists
) else (
    echo [WARNING] index.php not found
)

echo.

pause
echo.

echo ==============================================================
echo Test 6: Disk Space
echo ==============================================================
echo.

set DRIVE=%PROJECT_DIR:~0,2%
echo Checking free space on drive %DRIVE%
echo.

dir %DRIVE%\ | find "bytes free"
echo.

pause
echo.

echo ==============================================================
echo Test 7: Required PHP Extensions
echo ==============================================================
echo.

echo Checking PHP extensions...
echo.

:: Check if PHP is available
where php >nul 2>&1
if %errorlevel% equ 0 (
    php -r "echo 'PDO: ' . (extension_loaded('pdo') ? 'OK' : 'MISSING') . PHP_EOL;"
    php -r "echo 'PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL;"
    php -r "echo 'Session: ' . (extension_loaded('session') ? 'OK' : 'MISSING') . PHP_EOL;"
    php -r "echo 'JSON: ' . (extension_loaded('json') ? 'OK' : 'MISSING') . PHP_EOL;"
    php -r "echo 'Fileinfo: ' . (extension_loaded('fileinfo') ? 'OK' : 'MISSING') . PHP_EOL;"
    php -r "echo 'MBString: ' . (extension_loaded('mbstring') ? 'OK' : 'MISSING') . PHP_EOL;"
) else (
    if exist "C:\xampp\php\php.exe" (
        "C:\xampp\php\php.exe" -r "echo 'PDO: ' . (extension_loaded('pdo') ? 'OK' : 'MISSING') . PHP_EOL;"
        "C:\xampp\php\php.exe" -r "echo 'PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL;"
        "C:\xampp\php\php.exe" -r "echo 'Session: ' . (extension_loaded('session') ? 'OK' : 'MISSING') . PHP_EOL;"
        "C:\xampp\php\php.exe" -r "echo 'JSON: ' . (extension_loaded('json') ? 'OK' : 'MISSING') . PHP_EOL;"
        "C:\xampp\php\php.exe" -r "echo 'Fileinfo: ' . (extension_loaded('fileinfo') ? 'OK' : 'MISSING') . PHP_EOL;"
        "C:\xampp\php\php.exe" -r "echo 'MBString: ' . (extension_loaded('mbstring') ? 'OK' : 'MISSING') . PHP_EOL;"
    ) else (
        echo [ERROR] Cannot check PHP extensions - PHP not found
    )
)
echo.

pause
echo.

echo ==============================================================
echo Test 8: Write Permissions
echo ==============================================================
echo.

echo Testing write permissions...
echo.

:: Test creating a file
echo test > "%PROJECT_DIR%\test_write.tmp" 2>nul
if exist "%PROJECT_DIR%\test_write.tmp" (
    echo [OK] Can write to project directory
    del "%PROJECT_DIR%\test_write.tmp" >nul 2>&1
) else (
    echo [ERROR] Cannot write to project directory
    echo Check folder permissions
)

:: Test logs directory
if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs" 2>nul
echo test > "%PROJECT_DIR%\logs\test_write.tmp" 2>nul
if exist "%PROJECT_DIR%\logs\test_write.tmp" (
    echo [OK] Can write to logs directory
    del "%PROJECT_DIR%\logs\test_write.tmp" >nul 2>&1
) else (
    echo [WARNING] Cannot write to logs directory
)

:: Test temp directory
if not exist "%PROJECT_DIR%\temp" mkdir "%PROJECT_DIR%\temp" 2>nul
echo test > "%PROJECT_DIR%\temp\test_write.tmp" 2>nul
if exist "%PROJECT_DIR%\temp\test_write.tmp" (
    echo [OK] Can write to temp directory
    del "%PROJECT_DIR%\temp\test_write.tmp" >nul 2>&1
) else (
    echo [WARNING] Cannot write to temp directory
)

echo.

pause
echo.

echo ==============================================================
echo    DIAGNOSTIC COMPLETE
echo ==============================================================
echo.
echo Summary:
echo --------
echo 1. Check any [ERROR] or [WARNING] messages above
echo 2. Ensure XAMPP Control Panel is running
echo 3. Start Apache and MySQL from XAMPP Control Panel
echo 4. Add C:\xampp\php to system PATH if needed
echo.
echo Tips for common issues:
echo - If PHP is not found: Add C:\xampp\php to system PATH
echo - If Apache won't start: Check port 80 is not in use
echo - If MySQL won't start: Check port 3306 is not in use
echo.
echo This window will stay open until you close it.
echo.
echo Press any key to exit...
pause >nul
exit /b 0