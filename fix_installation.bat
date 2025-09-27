@echo off
setlocal enabledelayedexpansion

echo =====================================
echo FIX INSTALLAZIONE COLLABORA
echo =====================================
echo.

set PROJECT_NAME=CollaboraNexio
set XAMPP_PATH=C:\xampp
set PROJECT_PATH=%XAMPP_PATH%\htdocs\%PROJECT_NAME%

cd /d "%PROJECT_PATH%"

echo [1/5] Correzione config.php...

REM Aggiorna config.php con il percorso corretto
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
    echo // Path del progetto - CORRETTI PER CollaboraNexio
    echo define('BASE_PATH', 'C:/xampp/htdocs/CollaboraNexio'^);
    echo define('UPLOAD_PATH', BASE_PATH . '/uploads'^);
    echo define('PROJECT_URL', 'http://localhost/CollaboraNexio'^);
    echo.
    echo // Sessioni
    echo ini_set('session.cookie_httponly', 1^);
    echo ini_set('session.use_only_cookies', 1^);
    echo ini_set('session.cookie_samesite', 'Strict'^);
    echo ?^>
) > config.php

echo [OK] config.php aggiornato

echo [2/5] Creazione cartelle mancanti...

REM Crea tutte le sottocartelle necessarie
for %%D in (api includes uploads assets assets\css assets\js assets\icons temp test public cron) do (
    if not exist "%%D" (
        mkdir "%%D" 2>nul
        echo [OK] Creata cartella %%D
    )
)

echo [3/5] Protezione directory uploads...
echo Deny from all > uploads\.htaccess

echo [4/5] Installazione database...

REM Installa database usando il file SQL esistente
if exist "install_phase1.sql" (
    (
        echo DROP DATABASE IF EXISTS collabora;
        echo CREATE DATABASE collabora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        echo USE collabora;
        type install_phase1.sql
    ) | "%XAMPP_PATH%\mysql\bin\mysql.exe" -u root

    if !errorlevel! equ 0 (
        echo [OK] Database installato
    ) else (
        echo [ERRORE] Installazione database fallita
    )
) else (
    echo [ERRORE] install_phase1.sql non trovato
)

echo [5/5] Test finale...

REM Test di connessione aggiornato
(
    echo ^<?php
    echo require_once 'config.php';
    echo.
    echo try {
    echo     $pdo = new PDO(
    echo         'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    echo         DB_USER, DB_PASS
    echo     ^);
    echo     echo "[OK] Connessione database riuscita\n";
    echo     $stmt = $pdo-^>query('SELECT COUNT(*^) as c FROM users'^);
    echo     $count = $stmt-^>fetch(^)['c'];
    echo     echo "[OK] Trovati $count utenti nel database\n";
    echo } catch (Exception $e^) {
    echo     echo "[ERRORE] " . $e-^>getMessage(^) . "\n";
    echo }
    echo ?^>
) > test_final.php

"%XAMPP_PATH%\php\php.exe" test_final.php

echo.
echo =====================================
echo INSTALLAZIONE CORRETTA E COMPLETATA!
echo =====================================
echo.
echo URL Progetto: http://localhost/%PROJECT_NAME%/
echo.
pause

start http://localhost/%PROJECT_NAME%/