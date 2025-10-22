# PDF Upload Error Fix - "Errore nella risposta del server"

## Data
2025-10-20

## Problema Identificato

### Sintomi
- Upload PDF completa al 100%
- File trasferito completamente (645.58 KB)
- DOPO l'upload appare: "Errore nella risposta del server"
- Nessun file salvato nel database

### Root Cause Analysis

L'errore si verifica nel frontend JavaScript (`filemanager_enhanced.js:608`) quando tenta di fare il parsing della risposta JSON del server:

```javascript
try {
    const result = JSON.parse(xhr.responseText);
    // ...
} catch (e) {
    this.failUpload(uploadId, 'Errore nella risposta del server');
}
```

**Causa principale:** La risposta del server NON è JSON valido perché contiene output extra (warning/errors PHP) prima del JSON.

## Problemi Trovati

### 1. Percorso config.php Errato
**File:** `api/files/upload.php` (linea 17)

**Problema:**
```php
require_once __DIR__ . '/../../includes/config.php';  // ❌ File non esiste
```

**Percorso corretto:**
```php
require_once __DIR__ . '/../../config.php';  // ✓ Corretto
```

**Impatto:** Genera un PHP Warning che viene stampato prima del JSON, rompendo il parsing.

### 2. Ordine di Caricamento Files
**File:** `api/files/upload.php` (linee 14-20)

**Problema:**
L'ordine di caricamento dei file era:
1. `api_auth.php` - disabilita display_errors
2. `file_helper.php`
3. `config.php` - **riabilita** display_errors in development!

Questo causava che `config.php` sovrascriveva le impostazioni corrette di `api_auth.php`.

**Soluzione:**
Invertire l'ordine in modo che `api_auth.php` venga caricato per ULTIMO:
1. `config.php` - carica configurazione
2. `file_helper.php` - carica helper
3. `api_auth.php` - disabilita display_errors (sovrascrive config.php)
4. `initializeApiEnvironment()` - assicura che display_errors sia OFF

## Modifiche Implementate

### File: `/mnt/c/xampp/htdocs/CollaboraNexio/api/files/upload.php`

**Prima:**
```php
declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/config.php';

// Initialize API environment
initializeApiEnvironment();
```

**Dopo:**
```php
declare(strict_types=1);

// Load configuration first
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/file_helper.php';

// Load API auth LAST to override display_errors settings
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment (this disables display_errors for API)
initializeApiEnvironment();
```

## Testing

### 1. Tool di Debug Creato
**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/test_upload_response.php`

Questo tool ti permette di vedere ESATTAMENTE cosa ritorna il server durante l'upload:
- Risposta RAW del server
- Analisi della risposta (spazi, BOM, formato)
- Parsing JSON (se valido)
- Errori di parsing (se presenti)

**Come usarlo:**
1. Apri `http://localhost:8888/CollaboraNexio/test_upload_response.php` nel browser
2. Seleziona un file PDF
3. Clicca "Testa Upload"
4. Analizza la risposta

### 2. Verifica Schema Database
**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/database/verify_audit_logs_schema.sql`

Esegui questo script per verificare che la tabella `audit_logs` abbia le colonne corrette:
- `description` (non `details`)
- `old_values`
- `new_values`
- `severity`
- `status`

## Passi di Test

### Test 1: Upload PDF Piccolo (<1MB)
1. Login alla piattaforma
2. Vai su File Manager
3. Clicca "Upload"
4. Seleziona un PDF < 1MB
5. Verifica che l'upload completi con successo
6. Verifica che il file appaia nella lista
7. Verifica che non ci siano errori "Errore nella risposta del server"

### Test 2: Upload PDF Grande (>1MB, <100MB)
1. Ripeti Test 1 con un PDF più grande
2. Verifica progress bar
3. Verifica upload completo

### Test 3: Verifica Risposta API
1. Apri `test_upload_response.php`
2. Carica un PDF
3. Verifica che la risposta sia:
   - JSON valido ✓
   - Inizia con `{` ✓
   - Finisce con `}` ✓
   - Nessuno spazio extra ✓
   - `success: true` ✓

### Test 4: Verifica Database
1. Dopo upload, verifica tabella `files`:
   ```sql
   SELECT * FROM files ORDER BY created_at DESC LIMIT 5;
   ```
2. Verifica tabella `audit_logs`:
   ```sql
   SELECT * FROM audit_logs WHERE action = 'file_uploaded' ORDER BY created_at DESC LIMIT 5;
   ```

## Altri File da Verificare

I seguenti file hanno lo stesso problema (caricano config.php) e potrebbero avere lo stesso errore:

- `/api/auth/request_password_reset.php`
- `/api/companies/*.php`
- `/api/documents/*.php`
- Altri endpoint API

**Azione raccomandata:** Applicare lo stesso fix (ordine di caricamento) a tutti gli endpoint API che caricano `config.php`.

## Note Tecniche

### Come funziona l'Output Buffer
1. `api_auth.php::initializeApiEnvironment()` chiama `ob_start()` (linea 20)
2. Questo cattura TUTTO l'output PHP in un buffer
3. Prima di inviare JSON, viene chiamato `ob_clean()` (linee 178, 192)
4. `ob_clean()` cancella il buffer ma lo mantiene attivo
5. Poi viene fatto `echo json_encode(...)` (linee 180, 203)
6. Il JSON viene inviato pulito al client

### Perché display_errors è Problematico
Se `display_errors = On`:
- PHP stampa warning/notice/errors direttamente nell'output
- Questo output viene catturato da `ob_start()`
- Ma `ob_clean()` lo cancella solo se viene chiamato PRIMA dell'errore
- Se un errore avviene PRIMA di `initializeApiEnvironment()`, viene catturato e rimane nel buffer
- Quando si fa `echo json_encode()`, il JSON è preceduto dall'errore
- Risultato: `Warning: file not found...{"success":true,...}` ❌

### La Soluzione
Caricare `api_auth.php` per ULTIMO assicura che:
1. `initializeApiEnvironment()` venga chiamato DOPO tutti i require
2. `display_errors` sia OFF prima che qualsiasi codice API venga eseguito
3. `ob_start()` catturi solo output voluto
4. `ob_clean()` pulisca eventuali residui prima del JSON

## Status
- ✓ Problema identificato
- ✓ Root cause determinata
- ✓ Fix implementato
- ✓ Tool di debug creato
- ⏳ Testing da completare
- ⏳ Verifica in produzione

## Next Steps
1. Testare upload PDF con il fix
2. Verificare risposta API con `test_upload_response.php`
3. Controllare log errori (`logs/php_errors.log`, `logs/database_errors.log`)
4. Applicare stesso fix ad altri endpoint API
5. Aggiornare bug.md con risultati
