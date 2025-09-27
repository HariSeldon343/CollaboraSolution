@echo off
setlocal EnableDelayedExpansion
title Create Desktop Shortcuts for Port 8888
color 0D

echo ================================================================================
echo                      CREATE DESKTOP SHORTCUTS FOR PORT 8888
echo                        Quick Access to CollaboraNexio & Tools
echo ================================================================================
echo.

:: Set paths
set "DESKTOP=%USERPROFILE%\Desktop"
set "PROJECT_NAME=CollaboraNexio"
set "PORT=8888"
set "XAMPP_DIR=C:\xampp"

echo [INFO] Creating shortcuts for port %PORT%...
echo ----------------------------------------
echo.

:: Create PowerShell script for shortcut creation
echo # PowerShell script to create desktop shortcuts > "%TEMP%\create_shortcuts_8888.ps1"
echo $WshShell = New-Object -comObject WScript.Shell >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Desktop = [Environment]::GetFolderPath("Desktop") >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[1/9] Creating CollaboraNexio main shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # CollaboraNexio Main >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\CollaboraNexio (8888).url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888/CollaboraNexio" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[2/9] Creating phpMyAdmin shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # phpMyAdmin >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\phpMyAdmin (8888).url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888/phpmyadmin" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[3/9] Creating XAMPP Dashboard shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # XAMPP Dashboard >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\XAMPP Dashboard (8888).url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[4/9] Creating CollaboraNexio API shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # CollaboraNexio API >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\CollaboraNexio API (8888).url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "http://localhost:8888/CollaboraNexio/api" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[5/9] Creating XAMPP Control Panel shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # XAMPP Control Panel >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\XAMPP Control Panel.lnk") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "%XAMPP_DIR%\xampp-control.exe" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.WorkingDirectory = "%XAMPP_DIR%" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.IconLocation = "%XAMPP_DIR%\xampp-control.exe, 0" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Description = "XAMPP Control Panel - Manage Apache, MySQL, etc." >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[6/9] Creating Port Configuration Info shortcut..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # Port Configuration Info >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$Desktop\Port 8888 Info.url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "file:///C:/xampp/htdocs/CollaboraNexio/port_8888_info.html" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[7/9] Creating Module shortcuts folder..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # Create folder for module shortcuts >> "%TEMP%\create_shortcuts_8888.ps1"
echo $ModulesFolder = "$Desktop\CollaboraNexio Modules (8888)" >> "%TEMP%\create_shortcuts_8888.ps1"
echo if (!(Test-Path $ModulesFolder)) { >> "%TEMP%\create_shortcuts_8888.ps1"
echo     New-Item -ItemType Directory -Path $ModulesFolder -Force ^| Out-Null >> "%TEMP%\create_shortcuts_8888.ps1"
echo } >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo # Module shortcuts >> "%TEMP%\create_shortcuts_8888.ps1"
echo $modules = @( >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Login"; Path="login.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Dashboard"; Path="dashboard.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Projects"; Path="projects.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Tasks"; Path="tasks.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Teams"; Path="teams.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Reports"; Path="reports.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Settings"; Path="settings.php"}, >> "%TEMP%\create_shortcuts_8888.ps1"
echo     @{Name="Admin"; Path="admin/index.php"} >> "%TEMP%\create_shortcuts_8888.ps1"
echo ) >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo foreach ($module in $modules) { >> "%TEMP%\create_shortcuts_8888.ps1"
echo     $Shortcut = $WshShell.CreateShortcut("$ModulesFolder\$($module.Name).url") >> "%TEMP%\create_shortcuts_8888.ps1"
echo     $Shortcut.TargetPath = "http://localhost:8888/CollaboraNexio/$($module.Path)" >> "%TEMP%\create_shortcuts_8888.ps1"
echo     $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo } >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[8/9] Creating batch script shortcuts..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # Create folder for utility shortcuts >> "%TEMP%\create_shortcuts_8888.ps1"
echo $UtilsFolder = "$Desktop\Port 8888 Tools" >> "%TEMP%\create_shortcuts_8888.ps1"
echo if (!(Test-Path $UtilsFolder)) { >> "%TEMP%\create_shortcuts_8888.ps1"
echo     New-Item -ItemType Directory -Path $UtilsFolder -Force ^| Out-Null >> "%TEMP%\create_shortcuts_8888.ps1"
echo } >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo # Utility script shortcuts >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut = $WshShell.CreateShortcut("$UtilsFolder\Configure Apache Port 8888.lnk") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "C:\xampp\htdocs\CollaboraNexio\APACHE_PORT_8888_NOW.bat" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.WorkingDirectory = "C:\xampp\htdocs\CollaboraNexio" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Description = "Configure Apache to run on port 8888" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo $Shortcut = $WshShell.CreateShortcut("$UtilsFolder\Test Port 8888.lnk") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "C:\xampp\htdocs\CollaboraNexio\test_port_8888.bat" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.WorkingDirectory = "C:\xampp\htdocs\CollaboraNexio" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Description = "Check if port 8888 is available" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo $Shortcut = $WshShell.CreateShortcut("$UtilsFolder\Update All Links.lnk") >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.TargetPath = "C:\xampp\htdocs\CollaboraNexio\update_all_links.bat" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.WorkingDirectory = "C:\xampp\htdocs\CollaboraNexio" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Description = "Update all project references to port 8888" >> "%TEMP%\create_shortcuts_8888.ps1"
echo $Shortcut.Save() >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"

echo Write-Host "[9/9] Creating README file on desktop..." -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo # Create README file >> "%TEMP%\create_shortcuts_8888.ps1"
echo $readme = @" >> "%TEMP%\create_shortcuts_8888.ps1"
echo ================================================================================ >> "%TEMP%\create_shortcuts_8888.ps1"
echo                     CollaboraNexio on Port 8888 - Quick Guide >> "%TEMP%\create_shortcuts_8888.ps1"
echo ================================================================================ >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo IMPORTANT: Apache is configured to run on port 8888 to coexist with Docker. >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo Docker is using ports: 80, 8080, 8051, 8052, 8082 >> "%TEMP%\create_shortcuts_8888.ps1"
echo Apache/XAMPP is using: 8888 (HTTP) and 8443 (HTTPS) >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo MAIN URLS: >> "%TEMP%\create_shortcuts_8888.ps1"
echo ---------- >> "%TEMP%\create_shortcuts_8888.ps1"
echo CollaboraNexio:  http://localhost:8888/CollaboraNexio >> "%TEMP%\create_shortcuts_8888.ps1"
echo phpMyAdmin:      http://localhost:8888/phpmyadmin >> "%TEMP%\create_shortcuts_8888.ps1"
echo XAMPP Dashboard: http://localhost:8888 >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo SHORTCUTS CREATED: >> "%TEMP%\create_shortcuts_8888.ps1"
echo ------------------ >> "%TEMP%\create_shortcuts_8888.ps1"
echo 1. Main application shortcuts (on Desktop) >> "%TEMP%\create_shortcuts_8888.ps1"
echo 2. Module shortcuts (in 'CollaboraNexio Modules (8888)' folder) >> "%TEMP%\create_shortcuts_8888.ps1"
echo 3. Utility tools (in 'Port 8888 Tools' folder) >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo TROUBLESHOOTING: >> "%TEMP%\create_shortcuts_8888.ps1"
echo ---------------- >> "%TEMP%\create_shortcuts_8888.ps1"
echo - If Apache is not running: Run 'APACHE_PORT_8888_NOW.bat' as Administrator >> "%TEMP%\create_shortcuts_8888.ps1"
echo - If port 8888 is busy: Run 'test_port_8888.bat' to find available ports >> "%TEMP%\create_shortcuts_8888.ps1"
echo - To update project files: Run 'update_all_links.bat' >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo STARTING SERVICES: >> "%TEMP%\create_shortcuts_8888.ps1"
echo ------------------ >> "%TEMP%\create_shortcuts_8888.ps1"
echo 1. Open XAMPP Control Panel >> "%TEMP%\create_shortcuts_8888.ps1"
echo 2. Start Apache (will use port 8888) >> "%TEMP%\create_shortcuts_8888.ps1"
echo 3. Start MySQL if needed >> "%TEMP%\create_shortcuts_8888.ps1"
echo 4. Click on CollaboraNexio shortcut to open the application >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo Created: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') >> "%TEMP%\create_shortcuts_8888.ps1"
echo ================================================================================ >> "%TEMP%\create_shortcuts_8888.ps1"
echo "@ >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo Set-Content -Path "$Desktop\PORT_8888_README.txt" -Value $readme >> "%TEMP%\create_shortcuts_8888.ps1"
echo. >> "%TEMP%\create_shortcuts_8888.ps1"
echo Write-Host "" >> "%TEMP%\create_shortcuts_8888.ps1"
echo Write-Host "=================================================================================" -ForegroundColor Cyan >> "%TEMP%\create_shortcuts_8888.ps1"
echo Write-Host "                    ALL SHORTCUTS CREATED SUCCESSFULLY!" -ForegroundColor Green >> "%TEMP%\create_shortcuts_8888.ps1"
echo Write-Host "=================================================================================" -ForegroundColor Cyan >> "%TEMP%\create_shortcuts_8888.ps1"

:: Run the PowerShell script
powershell -ExecutionPolicy Bypass -File "%TEMP%\create_shortcuts_8888.ps1"
del "%TEMP%\create_shortcuts_8888.ps1" >nul 2>&1

echo.
echo SHORTCUTS CREATED:
echo ------------------
echo.
echo DESKTOP SHORTCUTS:
echo   • CollaboraNexio (8888) - Main application
echo   • phpMyAdmin (8888) - Database management
echo   • XAMPP Dashboard (8888) - Server dashboard
echo   • XAMPP Control Panel - Manage services
echo   • Port 8888 Info - Configuration details
echo.
echo MODULE SHORTCUTS FOLDER:
echo   • CollaboraNexio Modules (8888)\
echo     - Login, Dashboard, Projects, Tasks, Teams, Reports, Settings, Admin
echo.
echo UTILITY TOOLS FOLDER:
echo   • Port 8888 Tools\
echo     - Configure Apache Port 8888
echo     - Test Port 8888
echo     - Update All Links
echo.
echo README FILE:
echo   • PORT_8888_README.txt - Quick reference guide
echo.

:: Create a visual separator
echo ================================================================================
echo.

:: Ask if user wants to open folder
echo Do you want to open the desktop folder to see the shortcuts?
echo.
set /p "OPEN_DESKTOP=Press Y to open desktop, or any other key to skip: "
if /i "%OPEN_DESKTOP%"=="Y" (
    explorer "%DESKTOP%"
)

echo.
echo ================================================================================
echo                              SETUP COMPLETE!
echo ================================================================================
echo.
echo Next steps:
echo 1. Use XAMPP Control Panel shortcut to start Apache and MySQL
echo 2. Click CollaboraNexio (8888) to access the application
echo 3. All modules are accessible through the shortcuts folder
echo.
echo Remember: Apache runs on port 8888, Docker services remain on their ports.
echo.
echo ================================================================================
pause