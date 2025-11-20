# Riepilogo Fix Sistema Workflow Approvazione Documenti

## Data: 16 Novembre 2025

### Problemi Identificati e Risolti

#### 1. **Errore 500 in approve.php e altri endpoint**
**Problema**: Le API del workflow restituivano errore 500 "Errore durante l'aggiornamento del record"

**Causa**: Le query di UPDATE utilizzavano condizioni WHERE multiple che non funzionavano correttamente:
```php
// PRIMA (non funzionava)
$db->update('document_workflow', $data, [
    'id' => $workflow['id'],
    'tenant_id' => $tenantId,
    'file_id' => $fileId
]);
```

**Soluzione**: Semplificato usando solo la chiave primaria:
```php
// DOPO (funziona)
$db->update('document_workflow', $data, [
    'id' => $workflow['id']
]);
```

**File modificati**:
- `api/documents/workflow/approve.php`
- `api/documents/workflow/validate.php`
- `api/documents/workflow/reject.php`
- `api/documents/workflow/recall.php`

#### 2. **Riferimenti a colonne inesistenti**
**Problema**: Le query facevano riferimento a colonne che non esistono nella tabella `document_workflow`:
- `validator_name`, `validator_email`
- `approver_name`, `approver_email`
- `validated_by_user_id`, `approved_by_user_id`, `rejected_by_user_id`

**Soluzione**: 
- Rimosso tutti i riferimenti a queste colonne inesistenti
- I ruoli validator/approver sono gestiti tramite la tabella `workflow_roles`
- Gli utenti che eseguono le azioni sono tracciati solo in `document_workflow_history`

#### 3. **Sistema Email**
**Problema**: Le email non venivano inviate

**Causa**: Debug mode era attivo nel file di configurazione

**Soluzione**: Disabilitato debug mode in `includes/config_email.php`:
```php
define('EMAIL_DEBUG_MODE', false); // Era true
```

### Struttura Database Corretta

#### Tabella `document_workflow`:
```sql
- id
- tenant_id
- file_id
- current_state
- created_by_user_id
- current_handler_user_id
- submitted_at
- validated_at
- approved_at
- rejected_at
- rejection_reason
- rejection_count
- deleted_at
- created_at
- updated_at
```

#### Tabella `workflow_roles`:
```sql
- id
- tenant_id
- user_id
- workflow_role (validator/approver)
- assigned_by_user_id
- is_active
- deleted_at
- created_at
- updated_at
```

### Script di Test Creati

1. **`database/workflow_configuration_check.sql`**
   - Verifica configurazione workflow
   - Setup automatico per tenant 1
   - Controllo ruoli e permessi

2. **`test_workflow_complete.php`**
   - Test completo del workflow via PHP
   - Simula tutto il processo: bozza → validazione → approvazione

3. **`test_workflow_api.bat`** (Windows)
   - Test delle API via curl

4. **`test_workflow_api.sh`** (Linux/Mac)
   - Test delle API via curl

### Come Testare il Workflow

1. **Configurare il database**:
   ```bash
   mysql -u root -p collaboranexio < database/workflow_configuration_check.sql
   ```

2. **Eseguire test PHP**:
   ```bash
   php test_workflow_complete.php
   ```

3. **Testare via API** (sostituire FILE_ID e AUTH_TOKEN):
   ```bash
   # Linux/Mac
   chmod +x test_workflow_api.sh
   ./test_workflow_api.sh
   
   # Windows
   test_workflow_api.bat
   ```

### Stati del Workflow

1. **bozza** - Stato iniziale
2. **in_validazione** - Inviato al validatore
3. **validato** - Approvato dal validatore (transizione automatica)
4. **in_approvazione** - In attesa di approvazione finale
5. **approvato** - Documento approvato definitivamente
6. **rifiutato** - Documento rifiutato (può tornare in bozza)

### Email Notifications

Il sistema invia email automatiche per:
- Documento creato (se workflow attivo)
- Documento inviato per validazione
- Documento validato
- Documento approvato
- Documento rifiutato
- File/cartella assegnati

### Verifica Funzionamento

Per verificare che tutto funzioni:

1. Controllare i log PHP:
   ```bash
   tail -f logs/php_errors.log
   ```

2. Controllare stato workflow nel database:
   ```sql
   SELECT * FROM document_workflow WHERE file_id = YOUR_FILE_ID;
   SELECT * FROM document_workflow_history WHERE file_id = YOUR_FILE_ID ORDER BY created_at DESC;
   ```

3. Verificare invio email:
   - Controllare che `EMAIL_DEBUG_MODE` sia `false`
   - Verificare credenziali SMTP in `includes/config_email.php`
   - Controllare log email nel server SMTP

### Note Importanti

- Il workflow si attiva automaticamente solo se è configurato per il tenant/cartella
- Gli utenti devono avere ruoli validator/approver assegnati
- Le email richiedono configurazione SMTP valida
- Il sistema è multi-tenant: ogni tenant ha le sue configurazioni
