@echo off
echo ========================================
echo APACHE SAFE MODE - BYPASS ALL .HTACCESS
echo ========================================
echo.
echo This will disable ALL .htaccess processing globally
echo.

REM Stop Apache
echo [1/5] Stopping Apache...
C:\xampp\apache\bin\httpd.exe -k stop
timeout /t 2 /nobreak >nul

REM Backup httpd.conf
echo [2/5] Backing up httpd.conf...
cd /d C:\xampp\apache\conf
if not exist httpd.conf.backup (
    copy httpd.conf httpd.conf.backup
    echo    Created backup: httpd.conf.backup
) else (
    echo    Backup already exists: httpd.conf.backup
)

REM Create safe mode configuration
echo [3/5] Creating safe mode configuration...
echo.
echo # SAFE MODE - .htaccess disabled > httpd_safemode.conf
echo # This file disables all .htaccess processing >> httpd_safemode.conf
echo. >> httpd_safemode.conf
echo ^<Directory "C:/xampp/htdocs"^> >> httpd_safemode.conf
echo     AllowOverride None >> httpd_safemode.conf
echo     Options Indexes FollowSymLinks >> httpd_safemode.conf
echo     Require all granted >> httpd_safemode.conf
echo ^</Directory^> >> httpd_safemode.conf
echo. >> httpd_safemode.conf
echo ^<Directory "C:/xampp/htdocs/CollaboraNexio"^> >> httpd_safemode.conf
echo     AllowOverride None >> httpd_safemode.conf
echo     Options Indexes FollowSymLinks >> httpd_safemode.conf
echo     Require all granted >> httpd_safemode.conf
echo ^</Directory^> >> httpd_safemode.conf

REM Modify httpd.conf to disable .htaccess
echo [4/5] Modifying Apache configuration...
powershell -Command "(Get-Content httpd.conf) -replace 'AllowOverride All', 'AllowOverride None' | Set-Content httpd_temp.conf"
move /y httpd_temp.conf httpd.conf >nul

REM Add safe mode include
echo. >> httpd.conf
echo # SAFE MODE CONFIGURATION >> httpd.conf
echo Include conf/httpd_safemode.conf >> httpd.conf

REM Start Apache
echo [5/5] Starting Apache in SAFE MODE...
C:\xampp\apache\bin\httpd.exe -k start
timeout /t 3 /nobreak >nul

echo.
echo ========================================
echo APACHE SAFE MODE ACTIVATED
echo ========================================
echo.
echo ALL .htaccess files are now IGNORED!
echo.
echo TEST THESE URLs:
echo 1. http://localhost/test_root.html
echo 2. http://localhost/CollaboraNexio/test_basic.html
echo.
echo If these work now, the problem was definitely .htaccess
echo.
echo TO RESTORE NORMAL MODE:
echo 1. Stop Apache
echo 2. Copy httpd.conf.backup to httpd.conf
echo 3. Delete httpd_safemode.conf
echo 4. Start Apache
echo.
echo Or run: restore_apache_normal.bat
echo.
pause

REM Create restore script
echo @echo off > restore_apache_normal.bat
echo echo Restoring Apache normal mode... >> restore_apache_normal.bat
echo C:\xampp\apache\bin\httpd.exe -k stop >> restore_apache_normal.bat
echo cd /d C:\xampp\apache\conf >> restore_apache_normal.bat
echo copy /y httpd.conf.backup httpd.conf >> restore_apache_normal.bat
echo del httpd_safemode.conf >> restore_apache_normal.bat
echo C:\xampp\apache\bin\httpd.exe -k start >> restore_apache_normal.bat
echo echo Apache restored to normal mode! >> restore_apache_normal.bat
echo pause >> restore_apache_normal.bat

echo.
echo Created restore_apache_normal.bat for easy restoration
echo.