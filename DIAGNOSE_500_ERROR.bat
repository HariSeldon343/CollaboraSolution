@echo off
echo ==========================================
echo    APACHE 500 ERROR DIAGNOSTIC TOOL
echo ==========================================
echo.
echo This will help identify why you're getting 500 errors.
echo.
pause

cls
echo ==========================================
echo STEP 1: TESTING BASIC APACHE
echo ==========================================
echo.
echo Open your browser and test these URLs:
echo.
echo 1. http://localhost/CollaboraNexio/test_basic.html
echo    - If this works: Apache is OK, PHP might be the issue
echo    - If this fails: Apache configuration problem
echo.
echo 2. http://localhost/CollaboraNexio/hello.php
echo    - If this works: Basic PHP is OK
echo    - If this fails: PHP module or configuration issue
echo.
echo 3. http://localhost/CollaboraNexio/phpinfo.php
echo    - If this works: PHP is fully functional
echo    - If this fails: PHP configuration issue
echo.
echo Try these NOW, then press any key to continue...
pause > nul

cls
echo ==========================================
echo STEP 2: CHECKING ERROR LOGS
echo ==========================================
echo.
echo --- LAST APACHE ERRORS ---
if exist "C:\xampp\apache\logs\error.log" (
    powershell -command "Get-Content 'C:\xampp\apache\logs\error.log' -Tail 10"
) else (
    echo No Apache error log found!
)
echo.
pause

cls
echo ==========================================
echo STEP 3: CHECKING PHP FROM COMMAND LINE
echo ==========================================
echo.
echo Testing if PHP works outside Apache:
echo.
C:\xampp\php\php.exe -r "echo 'PHP CLI works fine!' . PHP_EOL;"
echo.
echo PHP Version:
C:\xampp\php\php.exe -v | findstr /i "PHP"
echo.
pause

cls
echo ==========================================
echo STEP 4: CHECKING FOR .HTACCESS ISSUES
echo ==========================================
echo.
echo Looking for .htaccess files that might cause 500 errors:
echo.
for /r "%CD%" %%F in (.htaccess) do (
    if exist "%%F" (
        echo Found: %%F
        echo Content:
        type "%%F" 2>nul | findstr /v "^$"
        echo ----------------------------------------
    )
)
echo.
echo If you see problematic .htaccess files above,
echo run remove_all_htaccess.bat to disable them temporarily.
echo.
pause

cls
echo ==========================================
echo STEP 5: APACHE MODULE CHECK
echo ==========================================
echo.
cd /d C:\xampp\apache\bin
echo Checking critical modules:
echo.
httpd.exe -M 2>nul | findstr /i "php" > nul
if %errorlevel% equ 0 (
    echo [OK] PHP module loaded
) else (
    echo [ERROR] PHP module NOT loaded!
)

httpd.exe -M 2>nul | findstr /i "rewrite" > nul
if %errorlevel% equ 0 (
    echo [OK] mod_rewrite loaded
) else (
    echo [WARNING] mod_rewrite NOT loaded
)

httpd.exe -M 2>nul | findstr /i "headers" > nul
if %errorlevel% equ 0 (
    echo [OK] mod_headers loaded
) else (
    echo [WARNING] mod_headers NOT loaded
)
echo.
pause

cls
echo ==========================================
echo DIAGNOSIS COMPLETE
echo ==========================================
echo.
echo TROUBLESHOOTING STEPS:
echo.
echo 1. If test_basic.html doesn't work:
echo    - Apache is not running or misconfigured
echo    - Run XAMPP Control Panel as Administrator
echo    - Restart Apache service
echo.
echo 2. If HTML works but PHP doesn't:
echo    - PHP module not loaded in Apache
echo    - Check C:\xampp\apache\conf\httpd.conf for PHP configuration
echo    - Look for: LoadModule php_module
echo.
echo 3. If you see .htaccess errors:
echo    - Run remove_all_htaccess.bat
echo    - Test again without .htaccess files
echo.
echo 4. If specific to your project files:
echo    - Check for syntax errors in PHP files
echo    - Check file permissions
echo    - Check for BOM (Byte Order Mark) in PHP files
echo.
echo 5. Common fixes:
echo    - Delete all .htaccess files temporarily
echo    - Restart Apache from XAMPP Control Panel
echo    - Check Windows Firewall/Antivirus
echo    - Run XAMPP as Administrator
echo.
pause