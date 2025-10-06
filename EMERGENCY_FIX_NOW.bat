@echo off
echo ========================================
echo EMERGENCY APACHE 500 ERROR FIX
echo ========================================
echo.

REM Stop Apache
echo [1/7] Stopping Apache...
C:\xampp\apache\bin\httpd.exe -k stop
timeout /t 2 /nobreak >nul

REM Rename all .htaccess files in CollaboraNexio and subdirectories
echo [2/7] Disabling ALL .htaccess files in CollaboraNexio...
cd /d C:\xampp\htdocs\CollaboraNexio
for /r %%i in (.htaccess) do (
    if exist "%%i" (
        echo    Found: %%i
        ren "%%i" .htaccess.disabled
        echo    Renamed to .htaccess.disabled
    )
)

REM Check parent directory
echo [3/7] Checking parent directory C:\xampp\htdocs...
cd /d C:\xampp\htdocs
if exist .htaccess (
    echo    WARNING: Found .htaccess in parent directory!
    echo    Renaming C:\xampp\htdocs\.htaccess to .htaccess.parent_disabled
    ren .htaccess .htaccess.parent_disabled
)

REM Create empty .htaccess in CollaboraNexio
echo [4/7] Creating empty .htaccess file...
cd /d C:\xampp\htdocs\CollaboraNexio
type nul > .htaccess
echo    Created empty .htaccess (0 bytes)

REM Create test file in xampp root
echo [5/7] Creating test file in XAMPP root...
cd /d C:\xampp\htdocs
echo ^<!DOCTYPE html^>^<html^>^<body^>^<h1^>Apache Works Outside Project!^</h1^>^</body^>^</html^> > test_emergency.html
echo    Created C:\xampp\htdocs\test_emergency.html

REM Start Apache
echo [6/7] Starting Apache...
C:\xampp\apache\bin\httpd.exe -k start
timeout /t 3 /nobreak >nul

REM Test results
echo [7/7] Testing results...
echo.
echo ========================================
echo FIX COMPLETE - TEST THESE URLs:
echo ========================================
echo.
echo 1. http://localhost/test_emergency.html
echo    ^(Should show "Apache Works Outside Project!"^)
echo.
echo 2. http://localhost/CollaboraNexio/test_basic.html
echo    ^(Should show your test page^)
echo.
echo If these work, Apache is fixed!
echo All old .htaccess files are renamed to .htaccess.disabled
echo.
echo ========================================
echo TROUBLESHOOTING:
echo ========================================
echo - If still getting 500 errors, run check_htaccess_all.bat
echo - Check Apache error logs at C:\xampp\apache\logs\error.log
echo - Try apache_safe_mode.bat for complete .htaccess bypass
echo.
pause