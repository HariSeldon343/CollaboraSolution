@echo off
chcp 1252 >nul 2>&1
setlocal enabledelayedexpansion

echo =====================================
echo COLLABORA PLATFORM - INSTALLER
echo =====================================
echo.

REM Configurazione percorsi
set XAMPP_PATH=C:\xampp
set MYSQL_BIN=%XAMPP_PATH%\mysql\bin
set PHP_BIN=%XAMPP_PATH%\php
set PROJECT_PATH=%XAMPP_PATH%\htdocs\collabora

REM Verifica XAMPP
echo [1/7] Verifica installazione XAMPP...
if not exist "%XAMPP_PATH%" (
    echo [ERRORE] XAMPP non trovato in %XAMPP_PATH%
    echo Installa XAMPP o modifica il percorso in questo script
    pause
    exit /b 1
)
echo [OK] XAMPP trovato

REM Verifica MySQL
echo [2/7] Verifica MySQL...
if not exist "%MYSQL_BIN%\mysql.exe" (
    echo [ERRORE] MySQL non trovato
    pause
    exit /b 1
)
echo [OK] MySQL trovato

REM Crea struttura directory
echo [3/7] Creazione struttura progetto...
if not exist "%PROJECT_PATH%" mkdir "%PROJECT_PATH%"
cd /d "%PROJECT_PATH%"

REM Crea tutte le sottocartelle
mkdir api 2>nul
mkdir includes 2>nul
mkdir uploads 2>nul
mkdir assets 2>nul
mkdir assets\css 2>nul
mkdir assets\js 2>nul
mkdir assets\icons 2>nul
mkdir temp 2>nul
mkdir test 2>nul
mkdir public 2>nul
mkdir cron 2>nul

echo [OK] Struttura cartelle creata

REM Crea .htaccess per proteggere uploads
echo [4/7] Configurazione sicurezza...
echo Deny from all > uploads\.htaccess
echo [OK] Directory uploads protetta

REM Crea config.php da template
echo [5/7] Creazione file configurazione...
if exist config.php.template (
    copy config.php.template config.php >nul
    echo [OK] config.php creato da template
) else (
    REM Crea config.php base se template non esiste
    (
        echo ^<?php
        echo // Configurazione Database
        echo define('DB_HOST', 'localhost'^);
        echo define('DB_NAME', 'collabora'^);
        echo define('DB_USER', 'root'^);
        echo define('DB_PASS', ''^);
        echo.
        echo // Timezone
        echo date_default_timezone_set('Europe/Rome'^);
        echo.
        echo // Error reporting per development
        echo error_reporting(E_ALL^);
        echo ini_set('display_errors', 1^);
        echo.
        echo // Path del progetto
        echo define('BASE_PATH', __DIR__^);
        echo define('UPLOAD_PATH', BASE_PATH . '/uploads'^);
        echo ?^>
    ) > config.php
    echo [OK] config.php base creato
)

REM Installa database
echo [6/7] Installazione database...
if exist install_phase1.sql (
    echo DROP DATABASE IF EXISTS collabora; > temp_install.sql
    echo CREATE DATABASE IF NOT EXISTS collabora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; >> temp_install.sql
    echo USE collabora; >> temp_install.sql
    type install_phase1.sql >> temp_install.sql

    "%MYSQL_BIN%\mysql.exe" -u root < temp_install.sql
    if !errorlevel! neq 0 (
        echo [ERRORE] Installazione database fallita
        echo Verifica che MySQL sia in esecuzione
        del temp_install.sql 2>nul
        pause
        exit /b 1
    )
    del temp_install.sql 2>nul
    echo [OK] Database installato
) else (
    echo [AVVISO] install_phase1.sql non trovato
    echo Dovrai installare il database manualmente
)

REM Crea file test connessione
echo [7/7] Creazione test di connessione...
(
    echo ^<?php
    echo require_once 'config.php';
    echo.
    echo echo "Test Connessione Database\n";
    echo echo "==========================\n\n";
    echo.
    echo try {
    echo     $pdo = new PDO(
    echo         "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    echo         DB_USER,
    echo         DB_PASS,
    echo         [PDO::ATTR_ERRMODE =^> PDO::ERRMODE_EXCEPTION]
    echo     ^);
    echo     echo "[OK] Connessione riuscita!\n";
    echo
    echo     // Test query
    echo     $stmt = $pdo-^>query("SELECT COUNT(*^) as count FROM users"^);
    echo     $result = $stmt-^>fetch(PDO::FETCH_ASSOC^);
    echo     echo "[OK] Tabella users contiene: " . $result['count'] . " record\n";
    echo
    echo } catch (Exception $e^) {
    echo     echo "[ERRORE] " . $e-^>getMessage(^) . "\n";
    echo }
    echo ?^>
) > test_connection.php

echo.
echo =====================================
echo INSTALLAZIONE COMPLETATA!
echo =====================================
echo.
echo Informazioni di accesso:
echo ------------------------
echo URL: http://localhost/collabora
echo Admin: asamodeo@fortibyte.it
echo Password: Ricord@1991
echo.
echo File di test: http://localhost/collabora/test_connection.php
echo.
echo Premi un tasto per testare la connessione...
pause >nul

REM Test connessione
"%PHP_BIN%\php.exe" test_connection.php

echo.
echo Premi un tasto per aprire il browser...
pause >nul
start http://localhost/collabora

exit /b 0