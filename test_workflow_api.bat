@echo off
REM ============================================
REM TEST WORKFLOW API - Windows Batch Script
REM ============================================
REM Questo script testa il workflow usando le API HTTP
REM Richiede curl installato su Windows
REM ============================================

echo === TEST WORKFLOW API ===
echo.

REM Configurazione
set BASE_URL=http://localhost:8888/CollaboraNexio/api/documents/workflow
set AUTH_TOKEN=YOUR_AUTH_TOKEN_HERE
set FILE_ID=123

REM Headers comuni
set HEADERS=-H "Content-Type: application/json" -H "X-CSRF-Token: %AUTH_TOKEN%"

echo 1. Test Submit (Invio per validazione)
echo =====================================
curl -X POST %BASE_URL%/submit.php ^
  %HEADERS% ^
  -d "{\"file_id\": %FILE_ID%}"
echo.
echo.

timeout /t 2 > nul

echo 2. Test Validate (Validazione documento)
echo ========================================
curl -X POST %BASE_URL%/validate.php ^
  %HEADERS% ^
  -d "{\"file_id\": %FILE_ID%, \"comment\": \"Documento validato\"}"
echo.
echo.

timeout /t 2 > nul

echo 3. Test Approve (Approvazione finale)
echo =====================================
curl -X POST %BASE_URL%/approve.php ^
  %HEADERS% ^
  -d "{\"file_id\": %FILE_ID%, \"comment\": \"Documento approvato\"}"
echo.
echo.

echo === TEST COMPLETATO ===
pause
