# Guida Avvio Apache XAMPP - CollaboraNexio

## Situazione Attuale
Apache NON è in esecuzione. L'ultimo avvio registrato è stato alle 11:25:24 di oggi, poi il servizio si è fermato.

## Script Disponibili

### 1. **Start-ApacheXAMPP.ps1** - Script principale di avvio
Script PowerShell completo per avviare Apache con verifica automatica e diagnostica.

### 2. **Test-ApacheStatus.ps1** - Script di verifica stato
Verifica completa dello stato di Apache e CollaboraNexio con test dettagliati.

### 3. **test_upload_endpoint.php** - Test upload API
Script PHP per testare il funzionamento dell'endpoint di upload dopo l'avvio.

---

## ISTRUZIONI PASSO-PASSO

### Passo 1: Avviare Apache

1. **Apri PowerShell come Amministratore**
   - Click destro su Start → Windows PowerShell (Admin)
   - Oppure: Win+X → Windows PowerShell (Amministratore)

2. **Naviga nella directory del progetto**
   ```powershell
   cd C:\xampp\htdocs\CollaboraNexio
   ```

3. **Esegui lo script di avvio**
   ```powershell
   .\Start-ApacheXAMPP.ps1
   ```

   **Se ricevi errore di esecuzione script:**
   ```powershell
   Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process -Force
   .\Start-ApacheXAMPP.ps1
   ```

4. **Attendi il completamento**
   - Lo script verificherà la configurazione
   - Avvierà Apache
   - Testerà automaticamente gli endpoint
   - Mostrerà un riepilogo finale

### Passo 2: Verificare lo Stato

**Dopo l'avvio, verifica che tutto funzioni:**

```powershell
.\Test-ApacheStatus.ps1
```

Questo script eseguirà:
- ✓ Verifica processo Apache
- ✓ Test porta 8888
- ✓ Test endpoint HTTP
- ✓ Verifica file system
- ✓ Controllo configurazione
- ✓ Analisi log

### Passo 3: Test Upload Endpoint

**Per testare specificamente l'upload API:**

1. **Da PowerShell (nella directory del progetto):**
   ```powershell
   php test_upload_endpoint.php
   ```

2. **Oppure da browser:**
   - Apri: http://localhost:8888/CollaboraNexio/test_upload_endpoint.php

---

## METODO ALTERNATIVO: Avvio Manuale

Se gli script non funzionano, puoi avviare Apache manualmente:

### Opzione A: XAMPP Control Panel

1. Esegui: `C:\xampp\xampp-control.exe`
2. Click su "Start" accanto ad Apache
3. Verifica che lo stato diventi verde
4. Apri browser su: http://localhost:8888/CollaboraNexio/

### Opzione B: Linea di Comando Diretta

1. **Apri CMD come Amministratore**

2. **Avvia Apache:**
   ```cmd
   C:\xampp\apache\bin\httpd.exe
   ```

3. **Lascia la finestra aperta** (Apache gira in foreground)

4. **Per fermare:** Premi Ctrl+C nella finestra CMD

---

## TROUBLESHOOTING

### Problema: "Script non può essere eseguito"

**Soluzione:**
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### Problema: "Porta 8888 già in uso"

**Verifica quale processo usa la porta:**
```powershell
netstat -ano | findstr :8888
```

**Termina il processo (sostituisci PID con il numero trovato):**
```powershell
taskkill /PID [PID] /F
```

### Problema: "Apache si avvia ma si ferma subito"

**Controlla il log errori:**
```powershell
Get-Content C:\xampp\apache\logs\error.log -Tail 20
```

**Verifica configurazione:**
```powershell
C:\xampp\apache\bin\httpd.exe -t
```

### Problema: "404 su upload.php"

Già risolto! I file `.htaccess` sono stati corretti. Se persiste:

1. Verifica che il file esista:
   ```powershell
   Test-Path C:\xampp\htdocs\CollaboraNexio\api\files\upload.php
   ```

2. Controlla mod_rewrite:
   ```powershell
   Select-String "LoadModule rewrite_module" C:\xampp\apache\conf\httpd.conf
   ```

---

## VERIFICA FINALE

Dopo l'avvio di Apache, questi URL dovrebbero funzionare:

1. **Root XAMPP:** http://localhost:8888/
2. **CollaboraNexio Home:** http://localhost:8888/CollaboraNexio/
3. **Login Page:** http://localhost:8888/CollaboraNexio/index.php
4. **Upload API:** http://localhost:8888/CollaboraNexio/api/files/upload.php (richiede autenticazione)

---

## FILE DI LOG

In caso di problemi, controlla questi log:

- **Apache Error Log:** `C:\xampp\apache\logs\error.log`
- **Apache Access Log:** `C:\xampp\apache\logs\access.log`
- **PHP Error Log:** `C:\xampp\php\logs\php_error_log`
- **App Error Log:** `C:\xampp\htdocs\CollaboraNexio\logs\php_errors.log`

---

## STATO BUG RISOLTI

- ✅ **BUG-006:** Schema mismatch audit_logs (RISOLTO)
- ✅ **BUG-007:** Include order in upload.php (RISOLTO)
- ✅ **BUG-008:** .htaccess rewrite rules (RISOLTO)
- ✅ **BUG-009:** Session timeout backend (RISOLTO - 5 minuti)

L'upload dovrebbe funzionare correttamente dopo l'avvio di Apache!

---

## COMANDI RAPIDI

```powershell
# Avvia Apache
.\Start-ApacheXAMPP.ps1

# Verifica stato
.\Test-ApacheStatus.ps1

# Test upload
php test_upload_endpoint.php

# Ferma Apache (se necessario)
taskkill /IM httpd.exe /F

# Vedi ultimi errori Apache
Get-Content C:\xampp\apache\logs\error.log -Tail 10

# Vedi ultimi errori PHP
Get-Content C:\xampp\htdocs\CollaboraNexio\logs\php_errors.log -Tail 10
```

---

**Ultimo aggiornamento:** 2025-10-21
**Autore:** Claude Code DevOps Specialist