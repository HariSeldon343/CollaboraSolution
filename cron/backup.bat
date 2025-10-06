@echo off
REM ===================================================================
REM CollaboraNexio - Script Backup Automatico per Windows Task Scheduler
REM ===================================================================
REM Questo script esegue il backup automatico del database e dei file
REM per CollaboraNexio utilizzando XAMPP su Windows.
REM
REM Configurazione Task Scheduler:
REM - Trigger: Giornaliero alle 02:00
REM - Azione: Esegui questo file .bat
REM - Esegui con privilegi elevati: SI
REM - Esegui anche se utente non connesso: SI
REM ===================================================================

SETLOCAL ENABLEEXTENSIONS ENABLEDELAYEDEXPANSION

REM ===================================================================
REM CONFIGURAZIONE PERCORSI
REM ===================================================================

REM Percorso installazione XAMPP
SET XAMPP_PATH=C:\xampp

REM Percorso PHP di XAMPP
SET PHP_PATH=%XAMPP_PATH%\php\php.exe

REM Percorso MySQL di XAMPP
SET MYSQL_PATH=%XAMPP_PATH%\mysql\bin

REM Percorso progetto CollaboraNexio
SET PROJECT_PATH=%XAMPP_PATH%\htdocs\CollaboraNexio

REM Percorso script backup PHP
SET BACKUP_SCRIPT=%PROJECT_PATH%\cron\backup.php

REM Percorso directory backup
SET BACKUP_DIR=%PROJECT_PATH%\backups

REM Percorso file di log
SET LOG_DIR=%PROJECT_PATH%\backups\logs
SET LOG_FILE=%LOG_DIR%\backup_batch.log

REM ===================================================================
REM INIZIALIZZAZIONE
REM ===================================================================

REM Crea directory se non esistono
IF NOT EXIST "%BACKUP_DIR%" (
    mkdir "%BACKUP_DIR%"
    echo Directory backup creata: %BACKUP_DIR%
)

IF NOT EXIST "%LOG_DIR%" (
    mkdir "%LOG_DIR%"
    echo Directory log creata: %LOG_DIR%
)

REM Ottiene data e ora corrente
FOR /F "tokens=1-3 delims=/ " %%A IN ('DATE /T') DO (
    SET DAY=%%A
    SET MONTH=%%B
    SET YEAR=%%C
)

FOR /F "tokens=1-2 delims=: " %%A IN ('TIME /T') DO (
    SET HOUR=%%A
    SET MINUTE=%%B
)

SET TIMESTAMP=%YEAR%-%MONTH%-%DAY%_%HOUR%-%MINUTE%

REM ===================================================================
REM FUNZIONE LOG
REM ===================================================================

:WriteLog
echo [%DATE% %TIME%] %~1 >> "%LOG_FILE%"
echo [%DATE% %TIME%] %~1
goto :eof

REM ===================================================================
REM VERIFICA PREREQUISITI
REM ===================================================================

call :WriteLog "===== INIZIO BACKUP BATCH ====="
call :WriteLog "Timestamp: %TIMESTAMP%"

REM Verifica esistenza PHP
IF NOT EXIST "%PHP_PATH%" (
    call :WriteLog "ERRORE: PHP non trovato in %PHP_PATH%"
    goto :ErrorHandler
)
call :WriteLog "PHP trovato: %PHP_PATH%"

REM Verifica esistenza script backup
IF NOT EXIST "%BACKUP_SCRIPT%" (
    call :WriteLog "ERRORE: Script backup non trovato in %BACKUP_SCRIPT%"
    goto :ErrorHandler
)
call :WriteLog "Script backup trovato: %BACKUP_SCRIPT%"

REM Verifica che MySQL sia in esecuzione
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
IF NOT %ERRORLEVEL% EQU 0 (
    call :WriteLog "WARNING: MySQL non sembra essere in esecuzione"
    call :WriteLog "Tentativo di avvio MySQL..."

    REM Prova ad avviare MySQL
    start "" /B "%XAMPP_PATH%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP_PATH%\mysql\bin\my.ini"

    REM Attende 5 secondi per l'avvio
    timeout /t 5 /nobreak >NUL

    REM Verifica nuovamente
    tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
    IF NOT %ERRORLEVEL% EQU 0 (
        call :WriteLog "ERRORE: Impossibile avviare MySQL"
        goto :ErrorHandler
    )
    call :WriteLog "MySQL avviato con successo"
) ELSE (
    call :WriteLog "MySQL in esecuzione"
)

REM ===================================================================
REM DETERMINAZIONE TIPO BACKUP
REM ===================================================================

REM Ottiene giorno della settimana (0=Domenica, 1=Lunedi, etc.)
FOR /F "tokens=1" %%A IN ('WMIC Path Win32_LocalTime Get DayOfWeek /Format:List ^| FINDSTR "DayOfWeek"') DO (
    FOR /F "tokens=2 delims==" %%B IN ("%%A") DO SET DAY_OF_WEEK=%%B
)

REM Se domenica (0), esegue backup completo
IF %DAY_OF_WEEK% EQU 0 (
    SET BACKUP_TYPE=--full
    call :WriteLog "Giorno: Domenica - Backup COMPLETO (database + file)"
) ELSE (
    SET BACKUP_TYPE=
    call :WriteLog "Giorno: Feriale - Backup INCREMENTALE (solo database)"
)

REM ===================================================================
REM VERIFICA LOCK FILE
REM ===================================================================

SET LOCK_FILE=%BACKUP_DIR%\backup.lock

IF EXIST "%LOCK_FILE%" (
    call :WriteLog "WARNING: Lock file trovato, verifica se backup già in esecuzione"

    REM Legge PID dal lock file
    FOR /F "tokens=1" %%P IN ('TYPE "%LOCK_FILE%" 2^>NUL') DO SET OLD_PID=%%P

    REM Verifica se il processo è ancora attivo
    tasklist /FI "PID eq %OLD_PID%" 2>NUL | find /I "%OLD_PID%" >NUL
    IF %ERRORLEVEL% EQU 0 (
        call :WriteLog "ERRORE: Backup già in esecuzione con PID %OLD_PID%"
        goto :ErrorHandler
    ) ELSE (
        call :WriteLog "Lock file stale rimosso"
        del "%LOCK_FILE%"
    )
)

REM ===================================================================
REM ESECUZIONE BACKUP
REM ===================================================================

call :WriteLog "Esecuzione backup PHP..."
call :WriteLog "Comando: %PHP_PATH% %BACKUP_SCRIPT% %BACKUP_TYPE%"

REM Esegue backup e cattura output
"%PHP_PATH%" "%BACKUP_SCRIPT%" %BACKUP_TYPE% >> "%LOG_FILE%" 2>&1
SET BACKUP_RESULT=%ERRORLEVEL%

IF %BACKUP_RESULT% EQU 0 (
    call :WriteLog "Backup completato con successo"

    REM Log evento Windows (successo)
    eventcreate /T INFORMATION /ID 100 /L APPLICATION /SO "CollaboraNexio Backup" /D "Backup completato con successo" >NUL 2>&1

) ELSE (
    call :WriteLog "ERRORE: Backup fallito con codice errore %BACKUP_RESULT%"

    REM Log evento Windows (errore)
    eventcreate /T ERROR /ID 101 /L APPLICATION /SO "CollaboraNexio Backup" /D "Backup fallito con codice errore %BACKUP_RESULT%" >NUL 2>&1

    goto :ErrorHandler
)

REM ===================================================================
REM VERIFICA SPAZIO DISCO
REM ===================================================================

call :WriteLog "Verifica spazio disco..."

REM Ottiene spazio libero su C:
FOR /F "tokens=2" %%A IN ('WMIC LogicalDisk Where "DeviceID='C:'" Get FreeSpace /Format:Value ^| FINDSTR "FreeSpace"') DO (
    FOR /F "tokens=2 delims==" %%B IN ("%%A") DO SET FREE_SPACE=%%B
)

REM Converte in MB (approssimativo)
SET /A FREE_SPACE_MB=%FREE_SPACE:~0,-6% 2>NUL

IF %FREE_SPACE_MB% LSS 1024 (
    call :WriteLog "WARNING: Spazio disco basso! Solo %FREE_SPACE_MB% MB disponibili"
    eventcreate /T WARNING /ID 102 /L APPLICATION /SO "CollaboraNexio Backup" /D "Spazio disco basso: %FREE_SPACE_MB% MB" >NUL 2>&1
) ELSE (
    call :WriteLog "Spazio disco disponibile: %FREE_SPACE_MB% MB"
)

REM ===================================================================
REM CALCOLO DIMENSIONE BACKUP
REM ===================================================================

call :WriteLog "Calcolo dimensione backup..."

SET TOTAL_SIZE=0
FOR /R "%BACKUP_DIR%" %%F IN (*) DO (
    SET /A TOTAL_SIZE+=%%~zF/1024/1024 2>NUL
)

call :WriteLog "Dimensione totale backups: %TOTAL_SIZE% MB"

REM ===================================================================
REM PULIZIA LOG VECCHI
REM ===================================================================

call :WriteLog "Pulizia log vecchi..."

REM Elimina log più vecchi di 90 giorni
FORFILES /P "%LOG_DIR%" /M *.log /D -90 /C "cmd /c del @file" 2>NUL

IF %ERRORLEVEL% EQU 0 (
    call :WriteLog "Log vecchi eliminati"
) ELSE (
    call :WriteLog "Nessun log vecchio da eliminare"
)

REM ===================================================================
REM CONCLUSIONE
REM ===================================================================

:Success
call :WriteLog "===== BACKUP BATCH COMPLETATO CON SUCCESSO ====="
call :WriteLog ""
exit /b 0

REM ===================================================================
REM GESTIONE ERRORI
REM ===================================================================

:ErrorHandler
call :WriteLog "===== BACKUP BATCH TERMINATO CON ERRORI ====="
call :WriteLog ""

REM Invia notifica amministratore (opzionale)
REM Puoi configurare qui l'invio di email o altre notifiche

exit /b 1

REM ===================================================================
REM FINE SCRIPT
REM ===================================================================