@echo off
echo ========================================
echo CHECKING PHP SYNTAX ERRORS
echo ========================================
echo.

echo Testing hello.php:
C:\xampp\php\php.exe -l hello.php
echo.

echo Testing phpinfo.php:
C:\xampp\php\php.exe -l phpinfo.php
echo.

echo Testing emergency_access.php:
C:\xampp\php\php.exe -l emergency_access.php
echo.

echo ========================================
echo TESTING PHP CLI EXECUTION
echo ========================================
echo.

echo Executing hello.php via CLI:
C:\xampp\php\php.exe hello.php
echo.
echo.

echo If the above worked, PHP itself is OK.
echo The problem is likely Apache configuration or .htaccess
echo.

pause