@echo off
echo ========================================
echo APACHE CONFIGURATION TEST
echo ========================================
echo.

echo Testing Apache configuration syntax:
C:\xampp\apache\bin\httpd.exe -t
echo.

echo ========================================
echo APACHE VERSION
echo ========================================
C:\xampp\apache\bin\httpd.exe -v
echo.

echo ========================================
echo CHECKING PORT 80 AVAILABILITY
echo ========================================
netstat -an | findstr :80
echo.
echo If you see LISTENING on :80, Apache is running.
echo If you see multiple entries, another program might be using port 80.
echo.

echo ========================================
echo XAMPP SERVICES STATUS
echo ========================================
echo Checking if Apache service is running:
sc query Apache2.4 2>nul
if %errorlevel% neq 0 (
    echo Apache service not found as Windows service.
    echo It might be running via XAMPP Control Panel.
)
echo.

echo ========================================
echo TESTING APACHE RESTART
echo ========================================
echo Attempting to restart Apache...
echo.
C:\xampp\apache\bin\httpd.exe -k restart 2>&1
echo.

pause