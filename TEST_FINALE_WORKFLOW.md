# TEST FINALE - WORKFLOW SYSTEM

## Data: 2025-11-02
## Versione: BUG-061 Final Fix

---

## PRIMA DI TESTARE

### ✅ COSA È STATO RISOLTO

1. ✅ **Vecchio file eliminato:** `document_workflow.js` rinominato in `document_workflow_OLD_BUG061.js`
2. ✅ **Nuovo file attivo:** Solo `document_workflow_v2.js` esiste con nome corretto
3. ✅ **Database popolato:** user_tenant_access ha 2 utenti (ID 19, 32)
4. ✅ **API verificata:** Ritorna 1 utente per tenant 11 (Pippo Baudo)
5. ✅ **Emergency script:** Chiude modal dopo 100ms
6. ✅ **Workflow activation migrated:** workflow_settings table creata

### 🚨 AZIONE OBBLIGATORIA

**DEVI USARE MODALITÀ INCOGNITO** (altrimenti cache problema):

```
CTRL + SHIFT + N (Chrome/Edge)
CTRL + SHIFT + P (Firefox)
```

---

## TEST 1: Modal NON Si Apre Automaticamente

**Procedura:**
1. Apri Incognito
2. Vai su: `http://localhost:8888/CollaboraNexio/`
3. Login
4. Naviga a files.php

**Risultato Atteso:**
- ✅ Pagina carica completamente
- ✅ NESSUN modal visibile
- ✅ File manager mostra file/cartelle normalmente

**Console Output Atteso (F12):**
```
[BUG-061] Emergency: workflowRoleConfigModal forced closed
[WorkflowManager] Initializing...
[WorkflowManager] Role config modal already exists in HTML, skipping creation
[WorkflowManager] Loaded roles: {validators: 0, approvers: 0}
[WorkflowManager] Initialized successfully
```

**Se FAIL:**
- Modal ancora aperto = Problema grave, screenshot console

---

## TEST 2: Dropdown Popolato con Utenti

**Procedura:**
1. In files.php (dopo TEST 1 PASS)
2. Right-click su qualsiasi FILE (non cartella)
3. Click "Gestisci Ruoli Workflow"
4. Modal si apre
5. Guarda dropdown "Seleziona utenti che possono validare documenti"

**Risultato Atteso:**
- ✅ Dropdown mostra: **"Pippo Baudo (a.oedoma@gmail.com) - user"**
- ✅ Dropdown NON vuoto
- ✅ Puoi selezionare utente

**Console Output Atteso:**
```
[WorkflowManager] Loading users for role config, tenant: 11
[WorkflowManager] Fetching from API: /CollaboraNexio/api/workflow/roles/list.php?tenant_id=11
[WorkflowManager] API response status: 200
[WorkflowManager] Available users from API: 1 [Array(1)]
[WorkflowManager] Combined users list: 1 [Array(1)]
[WorkflowManager] Populated validator dropdown with 1 users
[WorkflowManager] Populated approver dropdown with 1 users
```

**Se FAIL:**
- Dropdown vuoto = Copia ESATTO console output

---

## TEST 3: Salva Ruoli Workflow

**Procedura:**
1. Dopo TEST 2, con modal aperto
2. Seleziona "Pippo Baudo" nel dropdown validatori (CTRL+click)
3. Click pulsante "Salva Validatori"

**Risultato Atteso:**
- ✅ Toast notification: "1 validatori aggiornati con successo"
- ✅ Lista "Validatori Attuali" si aggiorna
- ✅ Mostra: "Pippo Baudo (a.oedoma@gmail.com)"

**Console Output Atteso:**
```
[WorkflowManager] Saving workflow roles...
[WorkflowManager] Assigned role to user X successfully
```

**Se FAIL:**
- Errore API = Copia messaggio errore

---

## TEST 4: Workflow Settings Modal (Nuovo Feature)

**Procedura:**
1. In files.php
2. Right-click su una CARTELLA (non file)
3. Verifica menu mostra: "Impostazioni Workflow Cartella"
4. Click su "Impostazioni Workflow Cartella"

**Risultato Atteso:**
- ✅ Modal si apre
- ✅ Mostra: "Stato Corrente: Disabilitato"
- ✅ Toggle per enable/disable
- ✅ Checkbox "Applica a sottocartelle"

**Se FAIL:**
- Menu item non visibile = Screenshot
- Modal non si apre = Console output

---

## TEST 5: Workflow Activation (Enable)

**Procedura:**
1. Dopo TEST 4, con modal aperto
2. Spunta checkbox "Abilita workflow per questa cartella"
3. Spunta "Applica a tutte le sottocartelle"
4. Click "Salva Impostazioni"

**Risultato Atteso:**
- ✅ Toast: "Workflow abilitato con successo"
- ✅ Badge verde 📋 appare sulla cartella
- ✅ Modal si chiude

**Verifica Database:**
```sql
SELECT * FROM workflow_settings WHERE deleted_at IS NULL;
```

Aspettato: 1 record con workflow_enabled=1

**Se FAIL:**
- API error = Console output

---

## TEST 6: Auto-Bozza Upload

**Procedura:**
1. Dopo TEST 5 (workflow enabled su cartella)
2. Upload un file nella cartella con workflow attivo
3. File upload successful

**Verifica Database:**
```sql
SELECT dw.*, f.name
FROM document_workflow dw
JOIN files f ON f.id = dw.file_id
WHERE dw.deleted_at IS NULL
ORDER BY dw.id DESC LIMIT 1;
```

**Risultato Atteso:**
- ✅ Record esiste
- ✅ current_state = 'bozza'
- ✅ created_by_user_id = tuo user ID
- ✅ file_id = file appena caricato

**Se FAIL:**
- Nessun record = Auto-bozza non funziona

---

## TEST 7: Workflow Disable

**Procedura:**
1. Right-click su cartella con workflow enabled
2. "Impostazioni Workflow Cartella"
3. Deseleziona checkbox "Abilita workflow"
4. Salva

**Risultato Atteso:**
- ✅ Toast: "Workflow disabilitato con successo"
- ✅ Badge verde scompare
- ✅ Upload successivo NON crea document_workflow

---

## RIEPILOGO TEST

| Test | Componente | Priorità | Status User |
|------|-----------|----------|-------------|
| 1 | Modal chiuso | CRITICO | ⬜ |
| 2 | Dropdown popolato | CRITICO | ⬜ |
| 3 | Salva ruoli | ALTO | ⬜ |
| 4 | Settings modal | ALTO | ⬜ |
| 5 | Enable workflow | MEDIO | ⬜ |
| 6 | Auto-bozza | MEDIO | ⬜ |
| 7 | Disable workflow | BASSO | ⬜ |

**Se TEST 1 e 2 PASSANO = Sistema funzionante!** ✅

---

## IN CASO DI PROBLEMI

**Se modal ancora si apre automaticamente:**
```javascript
// Check in console:
document.getElementById('workflowRoleConfigModal').style.display
// Dovrebbe essere: "none"
```

**Se dropdown ancora vuoto:**
```javascript
// Check in console:
document.getElementById('validatorUsers').options.length
// Dovrebbe essere: > 0
```

**API diretta test:**
```
http://localhost:8888/CollaboraNexio/api/workflow/roles/list.php?tenant_id=11
```

Dovrebbe mostrare JSON con 1 utente.

---

## CONTATTI

Se 1 o più test FAIL, riporta:
- Quale test specifico
- Esatto messaggio errore console
- Screenshot se utile

Procederò con fix ricorsivo ulteriore.

---

**Esegui in INCOGNITO MODE per risultati garantiti!** 🎯
