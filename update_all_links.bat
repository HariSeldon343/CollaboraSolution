@echo off
setlocal EnableDelayedExpansion
title Update Project Links to Port 8888
color 0E

echo ================================================================================
echo                        UPDATE ALL PROJECT REFERENCES
echo                    Updating localhost references to port 8888
echo ================================================================================
echo.

:: Set project paths
set "PROJECT_DIR=C:\xampp\htdocs\CollaboraNexio"
set "BACKUP_DIR=%PROJECT_DIR%\backup_links_%date:~-4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2%"
set "BACKUP_DIR=!BACKUP_DIR: =0!"
set "LOG_FILE=%PROJECT_DIR%\update_links.log"

:: Initialize counters
set "FILES_UPDATED=0"
set "TOTAL_CHANGES=0"

echo [STEP 1/6] Creating backup directory...
echo ----------------------------------------
mkdir "%BACKUP_DIR%" 2>nul
echo [OK] Backup directory: %BACKUP_DIR%
echo.

:: Create log file
echo Update Links Log - %date% %time% > "%LOG_FILE%"
echo ======================================== >> "%LOG_FILE%"
echo. >> "%LOG_FILE%"

echo [STEP 2/6] Creating .htaccess with base URL configuration...
echo ----------------------------------------

:: Create .htaccess file
echo # CollaboraNexio .htaccess configuration > "%PROJECT_DIR%\.htaccess"
echo # Auto-generated on %date% %time% >> "%PROJECT_DIR%\.htaccess"
echo. >> "%PROJECT_DIR%\.htaccess"
echo # Enable URL Rewriting >> "%PROJECT_DIR%\.htaccess"
echo RewriteEngine On >> "%PROJECT_DIR%\.htaccess"
echo. >> "%PROJECT_DIR%\.htaccess"
echo # Set base URL for port 8888 >> "%PROJECT_DIR%\.htaccess"
echo RewriteBase /CollaboraNexio/ >> "%PROJECT_DIR%\.htaccess"
echo. >> "%PROJECT_DIR%\.htaccess"
echo # PHP Configuration >> "%PROJECT_DIR%\.htaccess"
echo php_value session.cookie_domain localhost >> "%PROJECT_DIR%\.htaccess"
echo php_value session.cookie_path /CollaboraNexio >> "%PROJECT_DIR%\.htaccess"
echo. >> "%PROJECT_DIR%\.htaccess"
echo # Security Headers >> "%PROJECT_DIR%\.htaccess"
echo Header set X-Frame-Options "SAMEORIGIN" >> "%PROJECT_DIR%\.htaccess"
echo Header set X-Content-Type-Options "nosniff" >> "%PROJECT_DIR%\.htaccess"
echo Header set X-XSS-Protection "1; mode=block" >> "%PROJECT_DIR%\.htaccess"

echo [OK] .htaccess created
echo .htaccess created >> "%LOG_FILE%"
echo.

echo [STEP 3/6] Creating/Updating config.php with port settings...
echo ----------------------------------------

:: Backup existing config.php if it exists
if exist "%PROJECT_DIR%\config.php" (
    copy "%PROJECT_DIR%\config.php" "%BACKUP_DIR%\config.php.bak" >nul 2>&1
    echo [OK] Existing config.php backed up
)

:: Create/Update config.php
echo ^<?php > "%PROJECT_DIR%\config_port.php"
echo // Port Configuration for CollaboraNexio >> "%PROJECT_DIR%\config_port.php"
echo // Auto-generated on %date% %time% >> "%PROJECT_DIR%\config_port.php"
echo. >> "%PROJECT_DIR%\config_port.php"
echo // Server Configuration >> "%PROJECT_DIR%\config_port.php"
echo define('SERVER_PORT', 8888); >> "%PROJECT_DIR%\config_port.php"
echo define('SERVER_HOST', 'localhost'); >> "%PROJECT_DIR%\config_port.php"
echo define('SERVER_PROTOCOL', 'http'); >> "%PROJECT_DIR%\config_port.php"
echo define('BASE_URL', 'http://localhost:8888/CollaboraNexio'); >> "%PROJECT_DIR%\config_port.php"
echo define('API_URL', 'http://localhost:8888/CollaboraNexio/api'); >> "%PROJECT_DIR%\config_port.php"
echo. >> "%PROJECT_DIR%\config_port.php"
echo // SSL Configuration (if using) >> "%PROJECT_DIR%\config_port.php"
echo define('SSL_PORT', 8443); >> "%PROJECT_DIR%\config_port.php"
echo define('SSL_URL', 'https://localhost:8443/CollaboraNexio'); >> "%PROJECT_DIR%\config_port.php"
echo. >> "%PROJECT_DIR%\config_port.php"
echo // Docker Services (for reference) >> "%PROJECT_DIR%\config_port.php"
echo define('DOCKER_PORTS', [80, 8080, 8051, 8052, 8082]); >> "%PROJECT_DIR%\config_port.php"
echo. >> "%PROJECT_DIR%\config_port.php"
echo // Include this file in your main config.php >> "%PROJECT_DIR%\config_port.php"
echo // require_once 'config_port.php'; >> "%PROJECT_DIR%\config_port.php"
echo ?^> >> "%PROJECT_DIR%\config_port.php"

echo [OK] config_port.php created
echo config_port.php created >> "%LOG_FILE%"
echo.

echo [STEP 4/6] Updating PHP files...
echo ----------------------------------------

:: Create PowerShell script to update PHP files
echo $projectDir = "%PROJECT_DIR%" > "%TEMP%\update_php.ps1"
echo $backupDir = "%BACKUP_DIR%" >> "%TEMP%\update_php.ps1"
echo $logFile = "%LOG_FILE%" >> "%TEMP%\update_php.ps1"
echo $filesUpdated = 0 >> "%TEMP%\update_php.ps1"
echo $totalChanges = 0 >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo # Get all PHP files >> "%TEMP%\update_php.ps1"
echo $phpFiles = Get-ChildItem -Path $projectDir -Filter "*.php" -Recurse -File ^| Where-Object { $_.DirectoryName -notmatch "backup" } >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo foreach ($file in $phpFiles) { >> "%TEMP%\update_php.ps1"
echo     $content = Get-Content $file.FullName -Raw >> "%TEMP%\update_php.ps1"
echo     $originalContent = $content >> "%TEMP%\update_php.ps1"
echo     $changes = 0 >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo     # Update various localhost patterns >> "%TEMP%\update_php.ps1"
echo     $patterns = @( >> "%TEMP%\update_php.ps1"
echo         @{Find='http://localhost/CollaboraNexio'; Replace='http://localhost:8888/CollaboraNexio'}, >> "%TEMP%\update_php.ps1"
echo         @{Find='http://localhost/api'; Replace='http://localhost:8888/api'}, >> "%TEMP%\update_php.ps1"
echo         @{Find='http://127.0.0.1/CollaboraNexio'; Replace='http://127.0.0.1:8888/CollaboraNexio'}, >> "%TEMP%\update_php.ps1"
echo         @{Find='//localhost/CollaboraNexio'; Replace='//localhost:8888/CollaboraNexio'}, >> "%TEMP%\update_php.ps1"
echo         @{Find='localhost:80/CollaboraNexio'; Replace='localhost:8888/CollaboraNexio'} >> "%TEMP%\update_php.ps1"
echo     ) >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo     foreach ($pattern in $patterns) { >> "%TEMP%\update_php.ps1"
echo         if ($content -match [regex]::Escape($pattern.Find)) { >> "%TEMP%\update_php.ps1"
echo             $content = $content -replace [regex]::Escape($pattern.Find), $pattern.Replace >> "%TEMP%\update_php.ps1"
echo             $changes++ >> "%TEMP%\update_php.ps1"
echo         } >> "%TEMP%\update_php.ps1"
echo     } >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo     if ($changes -gt 0) { >> "%TEMP%\update_php.ps1"
echo         # Backup original file >> "%TEMP%\update_php.ps1"
echo         $backupPath = $file.FullName -replace [regex]::Escape($projectDir), $backupDir >> "%TEMP%\update_php.ps1"
echo         $backupFolder = Split-Path $backupPath -Parent >> "%TEMP%\update_php.ps1"
echo         if (!(Test-Path $backupFolder)) { >> "%TEMP%\update_php.ps1"
echo             New-Item -ItemType Directory -Path $backupFolder -Force ^| Out-Null >> "%TEMP%\update_php.ps1"
echo         } >> "%TEMP%\update_php.ps1"
echo         Copy-Item $file.FullName $backupPath >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo         # Save updated file >> "%TEMP%\update_php.ps1"
echo         Set-Content $file.FullName -Value $content -Encoding UTF8 >> "%TEMP%\update_php.ps1"
echo         $filesUpdated++ >> "%TEMP%\update_php.ps1"
echo         $totalChanges += $changes >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo         # Log the update >> "%TEMP%\update_php.ps1"
echo         Add-Content $logFile "Updated: $($file.FullName) - $changes changes" >> "%TEMP%\update_php.ps1"
echo         Write-Host "  Updated: $($file.Name) - $changes changes" >> "%TEMP%\update_php.ps1"
echo     } >> "%TEMP%\update_php.ps1"
echo } >> "%TEMP%\update_php.ps1"
echo. >> "%TEMP%\update_php.ps1"
echo Write-Host "[OK] PHP files processed: $filesUpdated files updated with $totalChanges total changes" >> "%TEMP%\update_php.ps1"
echo Add-Content $logFile "PHP files: $filesUpdated files updated, $totalChanges total changes" >> "%TEMP%\update_php.ps1"

powershell -ExecutionPolicy Bypass -File "%TEMP%\update_php.ps1"
del "%TEMP%\update_php.ps1" >nul 2>&1
echo.

echo [STEP 5/6] Updating JavaScript files...
echo ----------------------------------------

:: Create PowerShell script to update JavaScript files
echo $projectDir = "%PROJECT_DIR%" > "%TEMP%\update_js.ps1"
echo $backupDir = "%BACKUP_DIR%" >> "%TEMP%\update_js.ps1"
echo $logFile = "%LOG_FILE%" >> "%TEMP%\update_js.ps1"
echo $filesUpdated = 0 >> "%TEMP%\update_js.ps1"
echo $totalChanges = 0 >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo # Get all JavaScript files >> "%TEMP%\update_js.ps1"
echo $jsFiles = Get-ChildItem -Path $projectDir -Include "*.js","*.jsx","*.ts","*.tsx" -Recurse -File ^| Where-Object { $_.DirectoryName -notmatch "backup" -and $_.DirectoryName -notmatch "node_modules" } >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo foreach ($file in $jsFiles) { >> "%TEMP%\update_js.ps1"
echo     $content = Get-Content $file.FullName -Raw >> "%TEMP%\update_js.ps1"
echo     $originalContent = $content >> "%TEMP%\update_js.ps1"
echo     $changes = 0 >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo     # Update API URLs and localhost references >> "%TEMP%\update_js.ps1"
echo     $patterns = @( >> "%TEMP%\update_js.ps1"
echo         @{Find='http://localhost/CollaboraNexio'; Replace='http://localhost:8888/CollaboraNexio'}, >> "%TEMP%\update_js.ps1"
echo         @{Find='http://localhost/api'; Replace='http://localhost:8888/api'}, >> "%TEMP%\update_js.ps1"
echo         @{Find='//localhost/CollaboraNexio'; Replace='//localhost:8888/CollaboraNexio'}, >> "%TEMP%\update_js.ps1"
echo         @{Find='localhost:80/'; Replace='localhost:8888/'}, >> "%TEMP%\update_js.ps1"
echo         @{Find="'http://localhost'"; Replace="'http://localhost:8888'"}, >> "%TEMP%\update_js.ps1"
echo         @{Find='"http://localhost"'; Replace='"http://localhost:8888"'} >> "%TEMP%\update_js.ps1"
echo     ) >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo     foreach ($pattern in $patterns) { >> "%TEMP%\update_js.ps1"
echo         if ($content -match [regex]::Escape($pattern.Find)) { >> "%TEMP%\update_js.ps1"
echo             $content = $content -replace [regex]::Escape($pattern.Find), $pattern.Replace >> "%TEMP%\update_js.ps1"
echo             $changes++ >> "%TEMP%\update_js.ps1"
echo         } >> "%TEMP%\update_js.ps1"
echo     } >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo     if ($changes -gt 0) { >> "%TEMP%\update_js.ps1"
echo         # Backup original file >> "%TEMP%\update_js.ps1"
echo         $backupPath = $file.FullName -replace [regex]::Escape($projectDir), $backupDir >> "%TEMP%\update_js.ps1"
echo         $backupFolder = Split-Path $backupPath -Parent >> "%TEMP%\update_js.ps1"
echo         if (!(Test-Path $backupFolder)) { >> "%TEMP%\update_js.ps1"
echo             New-Item -ItemType Directory -Path $backupFolder -Force ^| Out-Null >> "%TEMP%\update_js.ps1"
echo         } >> "%TEMP%\update_js.ps1"
echo         Copy-Item $file.FullName $backupPath >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo         # Save updated file >> "%TEMP%\update_js.ps1"
echo         Set-Content $file.FullName -Value $content -Encoding UTF8 >> "%TEMP%\update_js.ps1"
echo         $filesUpdated++ >> "%TEMP%\update_js.ps1"
echo         $totalChanges += $changes >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo         # Log the update >> "%TEMP%\update_js.ps1"
echo         Add-Content $logFile "Updated: $($file.FullName) - $changes changes" >> "%TEMP%\update_js.ps1"
echo         Write-Host "  Updated: $($file.Name) - $changes changes" >> "%TEMP%\update_js.ps1"
echo     } >> "%TEMP%\update_js.ps1"
echo } >> "%TEMP%\update_js.ps1"
echo. >> "%TEMP%\update_js.ps1"
echo Write-Host "[OK] JavaScript files processed: $filesUpdated files updated with $totalChanges total changes" >> "%TEMP%\update_js.ps1"
echo Add-Content $logFile "JavaScript files: $filesUpdated files updated, $totalChanges total changes" >> "%TEMP%\update_js.ps1"

powershell -ExecutionPolicy Bypass -File "%TEMP%\update_js.ps1"
del "%TEMP%\update_js.ps1" >nul 2>&1
echo.

echo [STEP 6/6] Creating JavaScript configuration file...
echo ----------------------------------------

:: Create JavaScript config file
echo // CollaboraNexio Port Configuration > "%PROJECT_DIR%\js\config.port.js"
echo // Auto-generated on %date% %time% >> "%PROJECT_DIR%\js\config.port.js"
echo. >> "%PROJECT_DIR%\js\config.port.js"
echo const CONFIG = { >> "%PROJECT_DIR%\js\config.port.js"
echo     SERVER_PORT: 8888, >> "%PROJECT_DIR%\js\config.port.js"
echo     SERVER_HOST: 'localhost', >> "%PROJECT_DIR%\js\config.port.js"
echo     SERVER_PROTOCOL: 'http', >> "%PROJECT_DIR%\js\config.port.js"
echo     BASE_URL: 'http://localhost:8888/CollaboraNexio', >> "%PROJECT_DIR%\js\config.port.js"
echo     API_URL: 'http://localhost:8888/CollaboraNexio/api', >> "%PROJECT_DIR%\js\config.port.js"
echo     SSL_PORT: 8443, >> "%PROJECT_DIR%\js\config.port.js"
echo     SSL_URL: 'https://localhost:8443/CollaboraNexio', >> "%PROJECT_DIR%\js\config.port.js"
echo     DOCKER_PORTS: [80, 8080, 8051, 8052, 8082] >> "%PROJECT_DIR%\js\config.port.js"
echo }; >> "%PROJECT_DIR%\js\config.port.js"
echo. >> "%PROJECT_DIR%\js\config.port.js"
echo // Helper functions >> "%PROJECT_DIR%\js\config.port.js"
echo function getBaseUrl() { >> "%PROJECT_DIR%\js\config.port.js"
echo     return CONFIG.BASE_URL; >> "%PROJECT_DIR%\js\config.port.js"
echo } >> "%PROJECT_DIR%\js\config.port.js"
echo. >> "%PROJECT_DIR%\js\config.port.js"
echo function getApiUrl(endpoint = '') { >> "%PROJECT_DIR%\js\config.port.js"
echo     return CONFIG.API_URL + endpoint; >> "%PROJECT_DIR%\js\config.port.js"
echo } >> "%PROJECT_DIR%\js\config.port.js"
echo. >> "%PROJECT_DIR%\js\config.port.js"
echo // Export for module systems >> "%PROJECT_DIR%\js\config.port.js"
echo if (typeof module !== 'undefined' ^&^& module.exports) { >> "%PROJECT_DIR%\js\config.port.js"
echo     module.exports = CONFIG; >> "%PROJECT_DIR%\js\config.port.js"
echo } >> "%PROJECT_DIR%\js\config.port.js"

echo [OK] JavaScript configuration created
echo.

:: Create summary HTML file
echo ^<!DOCTYPE html^> > "%PROJECT_DIR%\port_8888_info.html"
echo ^<html^> >> "%PROJECT_DIR%\port_8888_info.html"
echo ^<head^> >> "%PROJECT_DIR%\port_8888_info.html"
echo     ^<title^>Port 8888 Configuration Info^</title^> >> "%PROJECT_DIR%\port_8888_info.html"
echo     ^<style^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         body { font-family: Arial, sans-serif; margin: 20px; background: #f0f0f0; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } >> "%PROJECT_DIR%\port_8888_info.html"
echo         h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #4CAF50; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         .link { display: block; margin: 10px 0; padding: 10px; background: #e7f3e7; text-decoration: none; color: #2e7d32; border-radius: 4px; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         .link:hover { background: #d4e8d4; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; } >> "%PROJECT_DIR%\port_8888_info.html"
echo         .warning { background: #fff3cd; border-left-color: #ffc107; color: #856404; } >> "%PROJECT_DIR%\port_8888_info.html"
echo     ^</style^> >> "%PROJECT_DIR%\port_8888_info.html"
echo ^</head^> >> "%PROJECT_DIR%\port_8888_info.html"
echo ^<body^> >> "%PROJECT_DIR%\port_8888_info.html"
echo     ^<div class="container"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<h1^>CollaboraNexio - Port 8888 Configuration^</h1^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<div class="section"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<h2^>Quick Links^</h2^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<a href="http://localhost:8888/CollaboraNexio" class="link"^>CollaboraNexio Main^</a^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<a href="http://localhost:8888/phpmyadmin" class="link"^>phpMyAdmin^</a^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<a href="http://localhost:8888" class="link"^>XAMPP Dashboard^</a^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<div class="section"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<h2^>Configuration Files^</h2^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>^<strong^>PHP Config:^</strong^> ^<code^>config_port.php^</code^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>^<strong^>JS Config:^</strong^> ^<code^>js/config.port.js^</code^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>^<strong^>.htaccess:^</strong^> Updated with port 8888 settings^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<div class="section warning"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<h2^>Important Notes^</h2^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>• Apache is running on port ^<strong^>8888^</strong^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>• Docker services remain on ports: 80, 8080, 8051, 8052, 8082^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>• Backup created at: ^<code^>%BACKUP_DIR%^</code^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>• Update log: ^<code^>update_links.log^</code^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<div class="section"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<h2^>How to Use in Your Code^</h2^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>^<strong^>PHP:^</strong^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<pre^>^<code^>require_once 'config_port.php'; >> "%PROJECT_DIR%\port_8888_info.html"
echo $baseUrl = BASE_URL;  // http://localhost:8888/CollaboraNexio^</code^>^</pre^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p^>^<strong^>JavaScript:^</strong^>^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<pre^>^<code^>// Include in HTML >> "%PROJECT_DIR%\port_8888_info.html"
echo ^&lt;script src="js/config.port.js"^&gt;^&lt;/script^&gt; >> "%PROJECT_DIR%\port_8888_info.html"
echo // Then use: >> "%PROJECT_DIR%\port_8888_info.html"
echo const apiUrl = CONFIG.API_URL;^</code^>^</pre^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^<div class="section"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^<p style="text-align: center; color: #666; margin-top: 30px;"^> >> "%PROJECT_DIR%\port_8888_info.html"
echo                 Generated on %date% %time% >> "%PROJECT_DIR%\port_8888_info.html"
echo             ^</p^> >> "%PROJECT_DIR%\port_8888_info.html"
echo         ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo     ^</div^> >> "%PROJECT_DIR%\port_8888_info.html"
echo ^</body^> >> "%PROJECT_DIR%\port_8888_info.html"
echo ^</html^> >> "%PROJECT_DIR%\port_8888_info.html"

echo [OK] Summary HTML page created: port_8888_info.html
echo.

echo ================================================================================
echo                         UPDATE COMPLETED SUCCESSFULLY!
echo ================================================================================
echo.
echo SUMMARY:
echo --------
echo 1. Created/Updated configuration files:
echo    - config_port.php (PHP configuration)
echo    - js/config.port.js (JavaScript configuration)
echo    - .htaccess (Apache rewrite rules)
echo    - port_8888_info.html (Summary page)
echo.
echo 2. Updated project files:
echo    - PHP files with localhost:8888 references
echo    - JavaScript files with new port
echo.
echo 3. Backup created:
echo    %BACKUP_DIR%
echo.
echo 4. Log file:
echo    %LOG_FILE%
echo.
echo NEXT STEPS:
echo -----------
echo 1. Make sure Apache is running on port 8888 (run APACHE_PORT_8888_NOW.bat)
echo 2. Clear browser cache
echo 3. Update any database stored URLs if needed
echo 4. Test all functionality
echo.
echo Opening summary page in browser...
start "" "file:///%PROJECT_DIR:\=/%/port_8888_info.html"
echo.
echo ================================================================================
pause