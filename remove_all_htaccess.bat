@echo off
setlocal enabledelayedexpansion

echo ========================================
echo EMERGENCY: REMOVING ALL .HTACCESS FILES
echo ========================================
echo.
echo This script will rename all .htaccess files to disable them.
echo Press Ctrl+C to cancel, or
pause

REM Get current timestamp for backup
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set timestamp=%datetime:~0,8%_%datetime:~8,6%

echo.
echo Creating backups with timestamp: %timestamp%
echo.

REM Counter for renamed files
set count=0

REM Find and rename all .htaccess files in current directory and subdirectories
for /r "%CD%" %%F in (.htaccess) do (
    if exist "%%F" (
        echo Found: %%F
        set "newname=%%F.DISABLED_%timestamp%"
        ren "%%F" ".htaccess.DISABLED_%timestamp%"
        if !errorlevel! equ 0 (
            echo   - Renamed to: .htaccess.DISABLED_%timestamp%
            set /a count+=1
        ) else (
            echo   - ERROR: Could not rename file!
        )
        echo.
    )
)

echo ========================================
echo SUMMARY
echo ========================================
echo Total .htaccess files disabled: %count%
echo.
echo To restore a specific .htaccess file, rename it back from:
echo   .htaccess.DISABLED_%timestamp% to .htaccess
echo.
echo Now test if your PHP files work!
echo.
pause