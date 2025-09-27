@echo off
REM CollaboraNexio Project Structure Creator
REM Creates all necessary directories for the multi-tenant collaborative platform
REM Author: CollaboraNexio Development Team
REM Date: 2025-01-21

echo ========================================
echo CollaboraNexio Directory Structure Setup
echo ========================================
echo.

REM Get current directory
set PROJECT_ROOT=%~dp0
echo Project Root: %PROJECT_ROOT%
echo.

echo Creating directory structure...
echo.

REM API Directory
echo Creating /api/...
if not exist "%PROJECT_ROOT%api" mkdir "%PROJECT_ROOT%api"
if not exist "%PROJECT_ROOT%api\v1" mkdir "%PROJECT_ROOT%api\v1"
if not exist "%PROJECT_ROOT%api\v1\auth" mkdir "%PROJECT_ROOT%api\v1\auth"
if not exist "%PROJECT_ROOT%api\v1\files" mkdir "%PROJECT_ROOT%api\v1\files"
if not exist "%PROJECT_ROOT%api\v1\users" mkdir "%PROJECT_ROOT%api\v1\users"
if not exist "%PROJECT_ROOT%api\v1\chat" mkdir "%PROJECT_ROOT%api\v1\chat"
if not exist "%PROJECT_ROOT%api\v1\calendar" mkdir "%PROJECT_ROOT%api\v1\calendar"
if not exist "%PROJECT_ROOT%api\v1\tasks" mkdir "%PROJECT_ROOT%api\v1\tasks"
if not exist "%PROJECT_ROOT%api\v1\workflows" mkdir "%PROJECT_ROOT%api\v1\workflows"

REM Includes Directory
echo Creating /includes/...
if not exist "%PROJECT_ROOT%includes" mkdir "%PROJECT_ROOT%includes"
if not exist "%PROJECT_ROOT%includes\classes" mkdir "%PROJECT_ROOT%includes\classes"
if not exist "%PROJECT_ROOT%includes\functions" mkdir "%PROJECT_ROOT%includes\functions"
if not exist "%PROJECT_ROOT%includes\middleware" mkdir "%PROJECT_ROOT%includes\middleware"
if not exist "%PROJECT_ROOT%includes\validators" mkdir "%PROJECT_ROOT%includes\validators"

REM Uploads Directory (secured)
echo Creating /uploads/ (secured)...
if not exist "%PROJECT_ROOT%uploads" mkdir "%PROJECT_ROOT%uploads"
if not exist "%PROJECT_ROOT%uploads\avatars" mkdir "%PROJECT_ROOT%uploads\avatars"
if not exist "%PROJECT_ROOT%uploads\documents" mkdir "%PROJECT_ROOT%uploads\documents"
if not exist "%PROJECT_ROOT%uploads\temp" mkdir "%PROJECT_ROOT%uploads\temp"

REM Assets Directory
echo Creating /assets/...
if not exist "%PROJECT_ROOT%assets" mkdir "%PROJECT_ROOT%assets"
if not exist "%PROJECT_ROOT%assets\css" mkdir "%PROJECT_ROOT%assets\css"
if not exist "%PROJECT_ROOT%assets\js" mkdir "%PROJECT_ROOT%assets\js"
if not exist "%PROJECT_ROOT%assets\icons" mkdir "%PROJECT_ROOT%assets\icons"
if not exist "%PROJECT_ROOT%assets\fonts" mkdir "%PROJECT_ROOT%assets\fonts"
if not exist "%PROJECT_ROOT%assets\images" mkdir "%PROJECT_ROOT%assets\images"

REM Temp Directory
echo Creating /temp/...
if not exist "%PROJECT_ROOT%temp" mkdir "%PROJECT_ROOT%temp"
if not exist "%PROJECT_ROOT%temp\exports" mkdir "%PROJECT_ROOT%temp\exports"
if not exist "%PROJECT_ROOT%temp\imports" mkdir "%PROJECT_ROOT%temp\imports"

REM Test Directory
echo Creating /test/...
if not exist "%PROJECT_ROOT%test" mkdir "%PROJECT_ROOT%test"
if not exist "%PROJECT_ROOT%test\unit" mkdir "%PROJECT_ROOT%test\unit"
if not exist "%PROJECT_ROOT%test\integration" mkdir "%PROJECT_ROOT%test\integration"
if not exist "%PROJECT_ROOT%test\fixtures" mkdir "%PROJECT_ROOT%test\fixtures"

REM Public Directory
echo Creating /public/...
if not exist "%PROJECT_ROOT%public" mkdir "%PROJECT_ROOT%public"
if not exist "%PROJECT_ROOT%public\downloads" mkdir "%PROJECT_ROOT%public\downloads"
if not exist "%PROJECT_ROOT%public\shared" mkdir "%PROJECT_ROOT%public\shared"

REM Logs Directory
echo Creating /logs/...
if not exist "%PROJECT_ROOT%logs" mkdir "%PROJECT_ROOT%logs"
if not exist "%PROJECT_ROOT%logs\error" mkdir "%PROJECT_ROOT%logs\error"
if not exist "%PROJECT_ROOT%logs\access" mkdir "%PROJECT_ROOT%logs\access"
if not exist "%PROJECT_ROOT%logs\audit" mkdir "%PROJECT_ROOT%logs\audit"

REM Cache Directory
echo Creating /cache/...
if not exist "%PROJECT_ROOT%cache" mkdir "%PROJECT_ROOT%cache"
if not exist "%PROJECT_ROOT%cache\templates" mkdir "%PROJECT_ROOT%cache\templates"
if not exist "%PROJECT_ROOT%cache\data" mkdir "%PROJECT_ROOT%cache\data"
if not exist "%PROJECT_ROOT%cache\sessions" mkdir "%PROJECT_ROOT%cache\sessions"

REM Additional directories for organization
echo Creating additional directories...
if not exist "%PROJECT_ROOT%config" mkdir "%PROJECT_ROOT%config"
if not exist "%PROJECT_ROOT%migrations" mkdir "%PROJECT_ROOT%migrations"
if not exist "%PROJECT_ROOT%vendor" mkdir "%PROJECT_ROOT%vendor"
if not exist "%PROJECT_ROOT%backup" mkdir "%PROJECT_ROOT%backup"

echo.
echo ========================================
echo Setting directory permissions...
echo ========================================
echo.

REM Set permissions for sensitive directories (Windows approach)
REM Note: On Windows, we use ICACLS for permissions

REM Restrict uploads directory
icacls "%PROJECT_ROOT%uploads" /inheritance:r /grant:r "%USERNAME%:(OI)(CI)F" /grant:r "SYSTEM:(OI)(CI)F" 2>nul

REM Restrict logs directory
icacls "%PROJECT_ROOT%logs" /inheritance:r /grant:r "%USERNAME%:(OI)(CI)F" /grant:r "SYSTEM:(OI)(CI)F" 2>nul

REM Restrict config directory
icacls "%PROJECT_ROOT%config" /inheritance:r /grant:r "%USERNAME%:(OI)(CI)F" /grant:r "SYSTEM:(OI)(CI)F" 2>nul

REM Make cache and temp writable
icacls "%PROJECT_ROOT%cache" /grant:r "Users:(OI)(CI)M" 2>nul
icacls "%PROJECT_ROOT%temp" /grant:r "Users:(OI)(CI)M" 2>nul

echo.
echo ========================================
echo Creating placeholder files...
echo ========================================
echo.

REM Create index.html in sensitive directories to prevent listing
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%uploads\index.html"
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%logs\index.html"
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%cache\index.html"
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%temp\index.html"
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%config\index.html"
echo ^<!DOCTYPE html^>^<html^>^<head^>^<title^>403 Forbidden^</title^>^</head^>^<body^>^<h1^>Access Denied^</h1^>^</body^>^</html^> > "%PROJECT_ROOT%includes\index.html"

REM Create .gitkeep files for empty directories
echo. 2>"%PROJECT_ROOT%assets\icons\.gitkeep"
echo. 2>"%PROJECT_ROOT%assets\fonts\.gitkeep"
echo. 2>"%PROJECT_ROOT%vendor\.gitkeep"
echo. 2>"%PROJECT_ROOT%backup\.gitkeep"
echo. 2>"%PROJECT_ROOT%migrations\.gitkeep"

echo.
echo ========================================
echo Directory structure created successfully!
echo ========================================
echo.
echo Next steps:
echo 1. Copy config.php.template to config/config.php
echo 2. Update database credentials in config.php
echo 3. Run database_schema.sql to create database tables
echo 4. Set up your web server to point to this directory
echo.
echo Press any key to exit...
pause >nul