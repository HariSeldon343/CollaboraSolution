@echo off
echo ========================================
echo .HTACCESS FILE SCANNER
echo ========================================
echo.
echo Searching for ALL .htaccess files...
echo.

REM Set counter
set count=0

echo [SEARCHING IN PROJECT DIRECTORY]
echo --------------------------------
cd /d C:\xampp\htdocs\CollaboraNexio
for /r %%i in (.htaccess*) do (
    if exist "%%i" (
        set /a count+=1
        echo.
        echo [!count!] FOUND: %%i
        echo     Size: %%~zi bytes
        if %%~zi==0 echo     ^(EMPTY FILE^)
        if %%~zi GTR 1000 echo     ^(LARGE FILE - POTENTIAL ISSUE^)

        REM Show first 5 lines of content if not empty
        if %%~zi GTR 0 (
            echo     First 5 lines:
            set linecount=0
            for /f "usebackq delims=" %%a in ("%%i") do (
                set /a linecount+=1
                if !linecount! LEQ 5 echo         %%a
            )
        )
    )
)

echo.
echo [SEARCHING IN PARENT DIRECTORY]
echo --------------------------------
cd /d C:\xampp\htdocs
for %%i in (.htaccess*) do (
    if exist "%%i" (
        set /a count+=1
        echo.
        echo [!count!] FOUND: C:\xampp\htdocs\%%i
        echo     Size: %%~zi bytes
        if %%~zi==0 echo     ^(EMPTY FILE^)
        if %%~zi GTR 1000 echo     ^(LARGE FILE - POTENTIAL ISSUE^)

        REM Show first 5 lines of content if not empty
        if %%~zi GTR 0 (
            echo     First 5 lines:
            set linecount=0
            for /f "usebackq delims=" %%a in ("%%i") do (
                set /a linecount+=1
                if !linecount! LEQ 5 echo         %%a
            )
        )
    )
)

echo.
echo [SEARCHING IN XAMPP ROOT]
echo --------------------------------
cd /d C:\xampp
for %%i in (.htaccess*) do (
    if exist "%%i" (
        set /a count+=1
        echo.
        echo [!count!] FOUND: C:\xampp\%%i
        echo     Size: %%~zi bytes
        echo     ^(WARNING: .htaccess in XAMPP root!^)
    )
)

echo.
echo ========================================
echo SCAN COMPLETE
echo ========================================
echo Total .htaccess files found: !count!
echo.
echo NOTES:
echo - Files named .htaccess.disabled are inactive
echo - Empty files (0 bytes) are harmless
echo - Large files or those with complex rules may cause issues
echo.
echo To disable a problematic .htaccess:
echo   ren "path\to\.htaccess" .htaccess.disabled
echo.
pause