@echo off
echo ========================================
echo CHECKING APACHE AND PHP ERRORS
echo ========================================
echo.

echo --- LAST 20 LINES OF APACHE ERROR LOG ---
echo.
if exist "C:\xampp\apache\logs\error.log" (
    powershell -command "Get-Content 'C:\xampp\apache\logs\error.log' -Tail 20"
) else (
    echo Apache error.log not found!
)
echo.
echo ========================================
echo.

echo --- LAST 20 LINES OF PHP ERROR LOG ---
echo.
if exist "C:\xampp\php\logs\php_error_log" (
    powershell -command "Get-Content 'C:\xampp\php\logs\php_error_log' -Tail 20"
) else (
    echo PHP error log not found at C:\xampp\php\logs\php_error_log
)
echo.
echo ========================================
echo.

echo --- CHECKING APACHE MODULES ---
echo.
cd /d C:\xampp\apache\bin
httpd.exe -M 2>nul | findstr /i "php"
if %errorlevel% equ 0 (
    echo PHP module is loaded in Apache
) else (
    echo WARNING: PHP module might not be loaded!
)
echo.
echo ========================================
echo.

echo --- CHECKING MOD_REWRITE ---
echo.
httpd.exe -M 2>nul | findstr /i "rewrite"
if %errorlevel% equ 0 (
    echo mod_rewrite is loaded
) else (
    echo WARNING: mod_rewrite is not loaded!
)
echo.
echo ========================================
echo.

echo --- PHP CONFIGURATION IN HTTPD.CONF ---
echo.
if exist "C:\xampp\apache\conf\httpd.conf" (
    echo Checking for PHP configuration lines:
    findstr /i "php" "C:\xampp\apache\conf\httpd.conf" | findstr /i "LoadModule"
    echo.
    echo Checking for PHP handler:
    findstr /i "AddHandler" "C:\xampp\apache\conf\httpd.conf" | findstr /i "php"
    echo.
    echo Checking for PHP MIME type:
    findstr /i "AddType" "C:\xampp\apache\conf\httpd.conf" | findstr /i "php"
) else (
    echo httpd.conf not found!
)
echo.
echo ========================================
echo.

echo --- CHECKING PHP VERSION ---
echo.
C:\xampp\php\php.exe -v 2>nul
if %errorlevel% neq 0 (
    echo ERROR: PHP executable not working!
)
echo.
echo ========================================
echo.

echo --- CHECKING .HTACCESS FILES ---
echo.
echo Looking for .htaccess files in current directory:
dir /b /s .htaccess 2>nul
echo.
echo ========================================
echo.

pause