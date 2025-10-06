@echo off
:: CollaboraNexio Automated Deployment Scheduler
:: Sets up scheduled deployment tasks for Windows Task Scheduler
:: Version: 1.0.0

setlocal enabledelayedexpansion

set PROJECT_DIR=C:\xampp\htdocs\CollaboraNexio
set DEPLOY_SCRIPT=%PROJECT_DIR%\deploy.bat
set TASK_NAME=CollaboraNexio_Deploy
set LOG_FILE=%PROJECT_DIR%\logs\scheduled_deploy.log

cls
echo ==============================================================
echo    CollaboraNexio - AUTOMATED DEPLOYMENT SCHEDULER
echo ==============================================================
echo.

echo Choose deployment schedule:
echo.
echo 1. Daily at 2:00 AM
echo 2. Weekly (Sunday at 2:00 AM)
echo 3. Monthly (1st day at 2:00 AM)
echo 4. Custom schedule
echo 5. Remove scheduled deployment
echo 6. View current schedule
echo 0. Exit
echo.

set /p CHOICE=Enter your choice (0-6):

if "%CHOICE%"=="0" goto :end
if "%CHOICE%"=="5" goto :remove_task
if "%CHOICE%"=="6" goto :view_task

:: Create the task XML
echo Creating deployment task...

if "%CHOICE%"=="1" (
    set SCHEDULE=/SC DAILY /ST 02:00
    set DESC=Daily deployment at 2:00 AM
) else if "%CHOICE%"=="2" (
    set SCHEDULE=/SC WEEKLY /D SUN /ST 02:00
    set DESC=Weekly deployment on Sunday at 2:00 AM
) else if "%CHOICE%"=="3" (
    set SCHEDULE=/SC MONTHLY /D 1 /ST 02:00
    set DESC=Monthly deployment on the 1st at 2:00 AM
) else if "%CHOICE%"=="4" (
    echo.
    echo Enter custom schedule in Task Scheduler format:
    echo Examples:
    echo   /SC DAILY /ST 03:30 - Daily at 3:30 AM
    echo   /SC WEEKLY /D MON,WED,FRI /ST 22:00 - Mon/Wed/Fri at 10 PM
    echo   /SC MONTHLY /MO 2 /D 15 /ST 01:00 - Every 2 months on 15th at 1 AM
    echo.
    set /p SCHEDULE=Enter schedule:
    set DESC=Custom deployment schedule
) else (
    echo Invalid choice!
    goto :end
)

:: Create wrapper script for logging
echo @echo off > "%PROJECT_DIR%\deploy_wrapper.bat"
echo echo [%%date%% %%time%%] Automated deployment started >> "%LOG_FILE%" >> "%PROJECT_DIR%\deploy_wrapper.bat"
echo call "%DEPLOY_SCRIPT%" ^>^> "%LOG_FILE%" 2^>^&1 >> "%PROJECT_DIR%\deploy_wrapper.bat"
echo echo [%%date%% %%time%%] Automated deployment completed >> "%LOG_FILE%" >> "%PROJECT_DIR%\deploy_wrapper.bat"

:: Create the scheduled task
schtasks /CREATE /TN "%TASK_NAME%" /TR "\"%PROJECT_DIR%\deploy_wrapper.bat\"" %SCHEDULE% /RU SYSTEM /RL HIGHEST /F /NP

if %errorlevel% equ 0 (
    echo.
    echo SUCCESS: Deployment task scheduled successfully!
    echo Description: %DESC%
    echo.
    echo The deployment will run automatically according to the schedule.
    echo Logs will be saved to: %LOG_FILE%
) else (
    echo.
    echo ERROR: Failed to create scheduled task.
    echo Please run this script as Administrator.
)

goto :end

:remove_task
echo.
echo Removing scheduled deployment task...
schtasks /DELETE /TN "%TASK_NAME%" /F
if %errorlevel% equ 0 (
    echo SUCCESS: Scheduled deployment removed.
) else (
    echo No scheduled deployment found or removal failed.
)
goto :end

:view_task
echo.
echo Current deployment schedule:
echo ----------------------------------------
schtasks /QUERY /TN "%TASK_NAME%" /V /FO LIST 2>nul
if %errorlevel% neq 0 (
    echo No scheduled deployment task found.
)
echo ----------------------------------------

:end
echo.
pause
endlocal