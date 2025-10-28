# Istruzioni Pulizia Cache Browser - BUG-040

**Data:** 2025-10-28
**Problema:** Errori 403/500 causati da cache browser obsoleta
**Soluzione:** Pulizia completa cache + riavvio browser

---

## IMPORTANTE: Azione Richiesta

Il codice è stato corretto, ma il tuo browser sta servendo **risposte vecchie dalla cache**.
Devi **pulire completamente la cache** per vedere i fix applicati.

---

## Guida Passo-Passo

### 1️⃣ Apri Strumenti Sviluppatore (DevTools)

**Tasto rapido:** Premi `F12` (o `Fn+F12` su alcuni laptop)

**Alternativa:**
- Chrome/Edge: Click destro → "Ispeziona"
- Firefox: Click destro → "Analizza elemento"

### 2️⃣ Vai alla Tab "Network" (Rete)

- Nella barra in alto di DevTools, clicca su **"Network"** (o **"Rete"** in italiano)
- Lascia aperta questa tab durante i test

### 3️⃣ Pulisci Cache Completa

**Metodo 1 - Tastiera (RACCOMANDATO):**

1. Premi `CTRL + SHIFT + DELETE` (Windows/Linux)
2. Oppure `CMD + SHIFT + DELETE` (Mac)
3. Si aprirà finestra "Cancella dati di navigazione"
4. Seleziona:
   - ✅ **"Intervallo di tempo": Tutto** (o "All time")
   - ✅ **Cookie e altri dati dei siti**
   - ✅ **Immagini e file memorizzati nella cache**
5. Clicca **"Cancella dati"** (o "Clear data")

**Metodo 2 - Menu Browser:**

**Chrome/Edge:**
- Menu (⋮) → Impostazioni → Privacy e sicurezza
- → Cancella dati di navigazione
- Seleziona "Dall'inizio" e "Tutto"
- Spunta: Cookie + Immagini e file memorizzati
- Clicca "Cancella dati"

**Firefox:**
- Menu (☰) → Impostazioni → Privacy e sicurezza
- → Cookies e dati dei siti → Elimina dati
- Spunta entrambe le opzioni
- Clicca "Elimina"

### 4️⃣ Riavvia Browser COMPLETAMENTE

**IMPORTANTE:** Non basta ricaricare la pagina!

1. Chiudi **TUTTE** le finestre del browser
2. Chiudi anche dalle icone della barra (system tray)
3. Riapri il browser da zero

### 5️⃣ Test Audit Log Page

1. Naviga a: http://localhost:8888/CollaboraNexio/audit_log.php
2. Login con le tue credenziali
3. Apri DevTools (F12) → Tab "Network"
4. Ricarica la pagina con `CTRL + F5` (hard refresh)

### 6️⃣ Verifica Users Dropdown

1. Nella pagina Audit Log, trova il dropdown "Filtra per utente"
2. Clicca sul dropdown
3. **DOVREBBE mostrare nomi utente reali** (non vuoto)

### 7️⃣ Verifica Network Tab (DevTools)

1. In DevTools → Tab "Network", cerca la richiesta:
   - Nome: `list_managers.php`
   - Tipo: `xhr` o `fetch`
2. Clicca sulla richiesta
3. Verifica:
   - ✅ **Status:** `200 OK` (NON 403 Forbidden)
   - ✅ **Response Headers:** Dovrebbe contenere:
     ```
     Cache-Control: no-store, no-cache, must-revalidate, max-age=0
     Pragma: no-cache
     Expires: 0
     ```
   - ✅ **Response Body (Preview/Response tab):** Dovrebbe mostrare:
     ```json
     {
       "success": true,
       "data": {
         "users": [
           {"id": 1, "name": "...", ...}
         ]
       }
     }
     ```

### 8️⃣ Test Delete API (Solo Super Admin)

**NOTA:** Questo test è disponibile SOLO per utenti con ruolo `super_admin`.

1. Nella pagina Audit Log, clicca su **"Elimina Log"** (bottone rosso in alto a destra)
2. Si apre un modal di conferma
3. Seleziona modalità (Tutto / Intervallo)
4. Inserisci motivo eliminazione (minimo 10 caratteri)
5. Clicca **"Elimina"**
6. In DevTools → Network, verifica:
   - Request: `delete.php`
   - ✅ **Status:** `200 OK` (NON 500 Internal Server Error)
   - ✅ Messaggio di successo visualizzato nella pagina

---

## Risoluzione Problemi

### Problema 1: Vedo ancora 403 Forbidden

**Causa:** Cache non completamente pulita

**Soluzione:**
1. Ripeti pulizia cache (Metodo 1)
2. Prova modalità **Incognito/Privata**:
   - Chrome/Edge: `CTRL + SHIFT + N`
   - Firefox: `CTRL + SHIFT + P`
3. Verifica che il browser sia completamente chiuso prima di riaprire

### Problema 2: Dropdown utenti vuoto

**Causa possibile 1:** Cache non pulita
- Vedi "Problema 1" sopra

**Causa possibile 2:** Ruolo utente
- Endpoint `list_managers.php` richiede ruolo: `manager`, `admin`, o `super_admin`
- Verifica il tuo ruolo nel database

**Verifica ruolo:**
```sql
SELECT u.email, u.role, t.name as tenant_name
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.email = 'tua-email@example.com';
```

### Problema 3: Vedo ancora 500 Internal Server Error

**Causa:** Cache non pulita O problema diverso

**Soluzione:**
1. Pulisci cache come sopra
2. Verifica log errori PHP in `/logs/php_errors.log`
3. In DevTools → Network, clicca su richiesta fallita
4. Vai a tab "Response" per vedere messaggio di errore
5. Se problema persiste dopo pulizia cache, riporta errore completo

### Problema 4: DevTools non mostra "Cache-Control: no-store"

**Causa:** Cache non pulita O richiesta ancora in cache

**Soluzione:**
1. In DevTools → Network, **spunta "Disable cache"** (checkbox in alto)
2. Ricarica pagina con `CTRL + F5` (hard refresh)
3. Verifica che richiesta `list_managers.php` sia nuova (non "from cache")

---

## Verifica Finale

### ✅ Checklist Successo

Dopo aver seguito tutti i passaggi, verifica che:

- [ ] Browser cache completamente pulita
- [ ] Browser riavviato completamente
- [ ] Pagina audit_log.php caricata
- [ ] DevTools → Network tab aperto
- [ ] Hard refresh eseguito (CTRL+F5)
- [ ] Request `list_managers.php` returns **200 OK** (non 403)
- [ ] Response headers contengono **"Cache-Control: no-store"**
- [ ] Response body contiene **`data.users` array**
- [ ] Users dropdown mostra **nomi reali** (non vuoto)
- [ ] Delete API returns **200 OK** (non 500) - *solo super_admin*

### ✅ Tutto OK?

Se TUTTI i check sopra sono verdi (✅), il problema è risolto!

---

## Screenshot di Riferimento

### DevTools - Network Tab (Esempio Corretto)

```
Name: list_managers.php
Status: 200 OK
Type: xhr
Size: 1.2 KB
Time: 150 ms

Headers:
  Cache-Control: no-store, no-cache, must-revalidate, max-age=0
  Pragma: no-cache
  Expires: 0
  Content-Type: application/json

Response:
  {
    "success": true,
    "data": {
      "users": [
        {
          "id": 1,
          "name": "Super Admin",
          "email": "superadmin@collaboranexio.com",
          ...
        }
      ]
    }
  }
```

---

## Contatti Supporto

Se dopo aver seguito TUTTE le istruzioni il problema persiste:

1. Crea screenshot di:
   - DevTools → Network tab (con richiesta list_managers.php visibile)
   - DevTools → Console tab (eventuali errori JavaScript)
   - Response Headers della richiesta
   - Response Body della richiesta

2. Raccogli informazioni:
   - Browser utilizzato (Chrome/Edge/Firefox) + versione
   - Sistema operativo (Windows/Mac/Linux)
   - Ruolo utente (super_admin/admin/manager)
   - Timestamp errore

3. Invia tutto via email o ticket support

---

## Note Tecniche (Per Sviluppatori)

### Cosa è stato fixato (BUG-040)

**Fix 1 - Permission Check:**
- Endpoint `list_managers.php` ora permette anche ruolo `manager`
- Prima: solo `admin` e `super_admin` → 403 error
- Ora: `manager`, `admin`, `super_admin` → 200 OK

**Fix 2 - Response Structure:**
- Response ora wrappa array in chiave `users`
- Prima: `{data: [...]}` → frontend cercava `data.users` (undefined)
- Ora: `{data: {users: [...]}}` → frontend trova `data.users` (OK)

**Fix 3 - Cache Headers (NUOVO):**
- Aggiunti header `Cache-Control: no-store` a:
  - `/audit_log.php` (pagina HTML)
  - `/api/users/list_managers.php` (endpoint API)
- Impedisce al browser di servire risposte vecchie dalla cache
- Headers forzano fetch fresco su ogni richiesta

### Files Modificati

1. `/audit_log.php` (lines 2-6) - Cache headers
2. `/api/users/list_managers.php` (lines 11-14, 21, 65) - Cache + fix originali

### Documentazione Completa

- `/BUG-040-CACHE-FIX-VERIFICATION.md` - Report tecnico completo
- `/BUG-040-FINAL-SUMMARY.md` - Summary esecutivo
- `/bug.md` - Aggiornato con cache fix
- `/progression.md` - Aggiornato con nuova entry
- `/CLAUDE.md` - Pattern cache aggiunti

---

**Fine Istruzioni**

**Ricorda:** La pulizia cache è OBBLIGATORIA per vedere i fix!
