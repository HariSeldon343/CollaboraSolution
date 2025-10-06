@echo off
setlocal enabledelayedexpansion

:: CollaboraNexio Cleanup Script
:: Version: 1.0.0
:: Purpose: Free up disk space before deployment

:: Color codes
set RED=[91m
set GREEN=[92m
set YELLOW=[93m
set BLUE=[94m
set MAGENTA=[95m
set CYAN=[96m
set WHITE=[97m
set RESET=[0m

:: Configuration
set PROJECT_DIR=C:\xampp\htdocs\CollaboraNexio
set LOG_DIR=%PROJECT_DIR%\logs
set BACKUP_DIR=%PROJECT_DIR%\backups
set TEMP_DIR=%PROJECT_DIR%\temp
set UPLOAD_DIR=%PROJECT_DIR%\uploads
set DAYS_OLD_LOGS=7
set DAYS_OLD_BACKUPS=30
set INITIAL_SPACE=0
set FREED_SPACE=0

:: Initialize
cls
echo %CYAN%==============================================================
echo    %PROJECT_NAME% - DISK CLEANUP UTILITY
echo    Version: 1.0.0
echo ==============================================================
echo %RESET%
echo.

:: Function definitions
goto :main

:show_progress
echo %CYAN%[%~1]%RESET% %~2
exit /b

:show_success
echo %GREEN%[SUCCESS]%RESET% %~1
exit /b

:show_error
echo %RED%[ERROR]%RESET% %~1
exit /b

:show_warning
echo %YELLOW%[WARNING]%RESET% %~1
exit /b

:get_folder_size
set FOLDER_SIZE=0
for /f "tokens=3" %%a in ('dir /s /a /-c "%~1" 2^>nul ^| findstr /C:"bytes"') do set FOLDER_SIZE=%%a
set /a FOLDER_SIZE_MB=%FOLDER_SIZE:~0,-6% 2>nul
if "%FOLDER_SIZE_MB%"=="" set FOLDER_SIZE_MB=0
exit /b

:get_disk_space
for %%i in ("%PROJECT_DIR%") do set DRIVE=%%~di
for /f "skip=1 tokens=1" %%a in ('wmic logicaldisk where "DeviceID='%DRIVE%'" get FreeSpace 2^>nul') do (
    set FREE_SPACE_BYTES=%%a
    goto :got_initial_space
)
:got_initial_space
set /a FREE_SPACE_MB=%FREE_SPACE_BYTES:~0,-6% 2>nul
if "%FREE_SPACE_MB%"=="" set /a FREE_SPACE_MB=%FREE_SPACE_BYTES:~0,-5%/10 2>nul
if "%FREE_SPACE_MB%"=="" set FREE_SPACE_MB=0
exit /b

:main
:: Get initial disk space
call :get_disk_space
set INITIAL_SPACE=%FREE_SPACE_MB%

set /a INITIAL_GB=%INITIAL_SPACE%/1024
set /a INITIAL_GB_REMAINDER=%INITIAL_SPACE%%%1024*100/1024

if %INITIAL_GB% gtr 0 (
    echo %WHITE%Initial free space: %INITIAL_GB%.%INITIAL_GB_REMAINDER:~0,1% GB (%INITIAL_SPACE% MB)%RESET%
) else (
    echo %WHITE%Initial free space: %INITIAL_SPACE% MB%RESET%
)
echo.

echo %BLUE%Phase 1: Windows Temporary Files%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clean Windows temp directory
call :show_progress "INFO" "Cleaning Windows temp directory..."
set TEMP_COUNT=0
for /f %%a in ('dir /b "%TEMP%" 2^>nul ^| find /c /v ""') do set TEMP_COUNT=%%a
if %TEMP_COUNT% gtr 0 (
    del /q /f "%TEMP%\*" 2>nul
    for /d %%x in ("%TEMP%\*") do @rd /s /q "%%x" 2>nul
    call :show_success "Cleaned Windows temp directory (%TEMP_COUNT% items)"
) else (
    call :show_progress "INFO" "Windows temp directory already clean"
)

:: Clean user temp directory
call :show_progress "INFO" "Cleaning user temp directory..."
set USER_TEMP_COUNT=0
for /f %%a in ('dir /b "%USERPROFILE%\AppData\Local\Temp" 2^>nul ^| find /c /v ""') do set USER_TEMP_COUNT=%%a
if %USER_TEMP_COUNT% gtr 0 (
    del /q /f "%USERPROFILE%\AppData\Local\Temp\*" 2>nul
    for /d %%x in ("%USERPROFILE%\AppData\Local\Temp\*") do @rd /s /q "%%x" 2>nul
    call :show_success "Cleaned user temp directory (%USER_TEMP_COUNT% items)"
) else (
    call :show_progress "INFO" "User temp directory already clean"
)

echo.
echo %BLUE%Phase 2: Project Temporary Files%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clean project temp directory
if exist "%TEMP_DIR%" (
    call :show_progress "INFO" "Cleaning project temp directory..."
    call :get_folder_size "%TEMP_DIR%"
    set TEMP_SIZE=%FOLDER_SIZE_MB%
    del /q /f "%TEMP_DIR%\*" 2>nul
    for /d %%x in ("%TEMP_DIR%\*") do @rd /s /q "%%x" 2>nul
    if !TEMP_SIZE! gtr 0 (
        call :show_success "Freed !TEMP_SIZE! MB from project temp"
    ) else (
        call :show_progress "INFO" "Project temp directory already clean"
    )
) else (
    call :show_progress "INFO" "Project temp directory not found"
)

:: Clean PHP session files
call :show_progress "INFO" "Cleaning PHP session files..."
set PHP_SESSION_DIR=C:\xampp\tmp
if exist "%PHP_SESSION_DIR%" (
    set SESSION_COUNT=0
    for /f %%a in ('dir /b "%PHP_SESSION_DIR%\sess_*" 2^>nul ^| find /c /v ""') do set SESSION_COUNT=%%a
    if !SESSION_COUNT! gtr 0 (
        del /q "%PHP_SESSION_DIR%\sess_*" 2>nul
        call :show_success "Removed !SESSION_COUNT! PHP session files"
    ) else (
        call :show_progress "INFO" "No PHP session files to clean"
    )
)

echo.
echo %BLUE%Phase 3: Old Log Files%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clean old log files
if exist "%LOG_DIR%" (
    call :show_progress "INFO" "Removing logs older than %DAYS_OLD_LOGS% days..."
    set LOG_COUNT=0
    set LOG_SIZE=0

    :: Delete old .log files
    forfiles /p "%LOG_DIR%" /m *.log /d -%DAYS_OLD_LOGS% /c "cmd /c del @path" 2>nul
    if !errorlevel! equ 0 (
        call :show_success "Removed old log files"
    ) else (
        call :show_progress "INFO" "No old log files found"
    )

    :: Delete .log.old files
    del /q "%LOG_DIR%\*.log.old" 2>nul

    :: Clean Apache logs
    if exist "C:\xampp\apache\logs" (
        forfiles /p "C:\xampp\apache\logs" /m *.log /d -%DAYS_OLD_LOGS% /c "cmd /c del @path" 2>nul
        call :show_progress "INFO" "Cleaned old Apache logs"
    )

    :: Clean MySQL logs
    if exist "C:\xampp\mysql\data" (
        del /q "C:\xampp\mysql\data\*.err" 2>nul
        call :show_progress "INFO" "Cleaned MySQL error logs"
    )
) else (
    call :show_warning "Log directory not found"
)

echo.
echo %BLUE%Phase 4: Old Backup Files%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clean old backup files
if exist "%BACKUP_DIR%" (
    call :show_progress "INFO" "Removing backups older than %DAYS_OLD_BACKUPS% days..."

    :: Count and size before deletion
    call :get_folder_size "%BACKUP_DIR%"
    set BACKUP_SIZE_BEFORE=%FOLDER_SIZE_MB%

    :: Delete old backup folders
    forfiles /p "%BACKUP_DIR%" /d -%DAYS_OLD_BACKUPS% /c "cmd /c if @isdir==TRUE rd /s /q @path" 2>nul

    :: Delete old zip files
    forfiles /p "%BACKUP_DIR%" /m *.zip /d -%DAYS_OLD_BACKUPS% /c "cmd /c del @path" 2>nul

    :: Get size after deletion
    call :get_folder_size "%BACKUP_DIR%"
    set BACKUP_SIZE_AFTER=%FOLDER_SIZE_MB%
    set /a BACKUP_FREED=%BACKUP_SIZE_BEFORE%-%BACKUP_SIZE_AFTER%

    if %BACKUP_FREED% gtr 0 (
        call :show_success "Freed %BACKUP_FREED% MB from old backups"
    ) else (
        call :show_progress "INFO" "No old backups to remove"
    )
) else (
    call :show_warning "Backup directory not found"
)

echo.
echo %BLUE%Phase 5: Browser Cache (Optional)%RESET%
echo %WHITE%----------------------------------------%RESET%

echo %YELLOW%Clean browser cache? This will close all browser windows. (Y/N)%RESET%
set /p CLEAN_BROWSER=

if /i "%CLEAN_BROWSER%"=="Y" (
    :: Chrome
    if exist "%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache" (
        call :show_progress "INFO" "Cleaning Chrome cache..."
        taskkill /f /im chrome.exe 2>nul
        rd /s /q "%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache" 2>nul
        call :show_success "Chrome cache cleaned"
    )

    :: Firefox
    if exist "%LOCALAPPDATA%\Mozilla\Firefox\Profiles" (
        call :show_progress "INFO" "Cleaning Firefox cache..."
        taskkill /f /im firefox.exe 2>nul
        for /d %%x in ("%LOCALAPPDATA%\Mozilla\Firefox\Profiles\*.default*") do (
            rd /s /q "%%x\cache2" 2>nul
        )
        call :show_success "Firefox cache cleaned"
    )

    :: Edge
    if exist "%LOCALAPPDATA%\Microsoft\Edge\User Data\Default\Cache" (
        call :show_progress "INFO" "Cleaning Edge cache..."
        taskkill /f /im msedge.exe 2>nul
        rd /s /q "%LOCALAPPDATA%\Microsoft\Edge\User Data\Default\Cache" 2>nul
        call :show_success "Edge cache cleaned"
    )
) else (
    call :show_progress "INFO" "Skipping browser cache cleanup"
)

echo.
echo %BLUE%Phase 6: Additional Cleanup%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Clean Windows Update cache (requires admin)
call :show_progress "INFO" "Checking Windows Update cache..."
net session >nul 2>&1
if %errorlevel% equ 0 (
    net stop wuauserv >nul 2>&1
    rd /s /q "%WINDIR%\SoftwareDistribution\Download" 2>nul
    net start wuauserv >nul 2>&1
    call :show_success "Windows Update cache cleaned"
) else (
    call :show_warning "Admin rights needed to clean Windows Update cache"
)

:: Clean recycle bin
call :show_progress "INFO" "Emptying Recycle Bin..."
rd /s /q %SYSTEMDRIVE%\$Recycle.bin 2>nul
call :show_success "Recycle Bin emptied"

:: Clean old upload files (if configured)
if exist "%UPLOAD_DIR%" (
    echo.
    echo %YELLOW%Clean old upload files? This may affect user data. (Y/N)%RESET%
    set /p CLEAN_UPLOADS=

    if /i "!CLEAN_UPLOADS!"=="Y" (
        call :show_progress "INFO" "Cleaning old upload files..."
        forfiles /p "%UPLOAD_DIR%" /d -30 /c "cmd /c del @path" 2>nul
        call :show_success "Old upload files cleaned"
    )
)

echo.
echo %BLUE%Phase 7: Final Report%RESET%
echo %WHITE%----------------------------------------%RESET%

:: Get final disk space
call :get_disk_space
set FINAL_SPACE=%FREE_SPACE_MB%
set /a FREED_SPACE=%FINAL_SPACE%-%INITIAL_SPACE%

set /a FINAL_GB=%FINAL_SPACE%/1024
set /a FINAL_GB_REMAINDER=%FINAL_SPACE%%%1024*100/1024
set /a FREED_GB=%FREED_SPACE%/1024
set /a FREED_GB_REMAINDER=%FREED_SPACE%%%1024*100/1024

echo.
if %FINAL_GB% gtr 0 (
    echo %WHITE%Final free space: %FINAL_GB%.%FINAL_GB_REMAINDER:~0,1% GB (%FINAL_SPACE% MB)%RESET%
) else (
    echo %WHITE%Final free space: %FINAL_SPACE% MB%RESET%
)

if %FREED_SPACE% gtr 0 (
    if %FREED_GB% gtr 0 (
        echo %GREEN%Total space freed: %FREED_GB%.%FREED_GB_REMAINDER:~0,1% GB (%FREED_SPACE% MB)%RESET%
    ) else (
        echo %GREEN%Total space freed: %FREED_SPACE% MB%RESET%
    )
) else (
    echo %YELLOW%No additional space was freed%RESET%
)

echo.
echo %GREEN%==============================================================
echo    CLEANUP COMPLETED SUCCESSFULLY!
echo ==============================================================
echo %RESET%

:: Offer to run deployment
echo.
echo %CYAN%Do you want to run the deployment now? (Y/N)%RESET%
set /p RUN_DEPLOY=

if /i "%RUN_DEPLOY%"=="Y" (
    echo.
    echo %CYAN%Starting deployment...%RESET%
    echo.
    call "%PROJECT_DIR%\deploy.bat"
)

:end
endlocal
pause