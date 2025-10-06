# ISTRUZIONI PER RISOLVERE I PROBLEMI IN COLLABORANEXIO

## PROBLEMI IDENTIFICATI E RISOLTI

### 1. ERRORE 500 IN API/USERS/CREATE_V2.PHP

**Causa identificata:**
- L'API tentava di inserire dati nella tabella `activity_logs` che non esiste
- La tabella corretta è `audit_logs`
- Mancavano alcune colonne nella tabella `users` necessarie per il sistema di prima password

**Soluzioni applicate:**

1. **Corretto il nome della tabella** (✓ FATTO)
   - File: `/api/users/create_v2.php` (riga 188)
   - Cambiato da `activity_logs` a `audit_logs`

2. **Aggiunto logging dettagliato** (✓ FATTO)
   - Aggiunti log con prefisso `[CREATE_USER]` per debug
   - Migliorata gestione errori per l'invio email

3. **Gestione graceful degli errori email** (✓ FATTO)
   - L'utente viene creato anche se l'email fallisce
   - Messaggio di warning invece di errore 500

### 2. LOGO MANCANTE NELLA SIDEBAR

**Causa identificata:**
- Le pagine usavano `<span class="logo-icon">N</span>` invece del logo SVG
- Il logo SVG esiste già in `/assets/images/logo.svg`

**Soluzione applicata:**

1. **Aggiornato utenti.php** (✓ FATTO)
   - Sostituito il tag span con `<img src="/CollaboraNexio/assets/images/logo.svg">`
   - Aggiunto CSS per la classe `.logo-img`

## AZIONI DA ESEGUIRE

### 1. Correggere le colonne mancanti nel database:
```bash
# Dal browser, accedi a:
http://localhost:8888/CollaboraNexio/fix_users_table_columns.php
```

### 2. Verificare che tutto funzioni:
```bash
# Test completo del sistema
http://localhost:8888/CollaboraNexio/debug_api_issues.php

# Test creazione utente
http://localhost:8888/CollaboraNexio/test_create_user.php

# Test invio email (opzionale, da CLI)
php /mnt/c/xampp/htdocs/CollaboraNexio/test_email_smtp.php
```

### 3. Aggiornare il logo nelle altre pagine:
Le seguenti pagine hanno ancora il vecchio logo e devono essere aggiornate manualmente:
- tasks.php
- calendar.php
- files.php
- dashboard.php
- audit_log.php
- ai.php
- profilo.php
- conformita.php
- ticket.php
- aziende.php
- chat.php

Per ogni file, sostituisci:
```html
<span class="logo-icon">N</span>
```
Con:
```html
<img src="/CollaboraNexio/assets/images/logo.svg" alt="CollaboraNexio" class="logo-img">
```

E aggiungi il CSS se manca:
```css
.logo-img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}
```

## NOTE IMPORTANTI

### Email su Windows/XAMPP
- La funzione `mail()` di PHP su Windows non supporta autenticazione SMTP
- Le email probabilmente NON funzioneranno su XAMPP Windows
- L'utente viene comunque creato con successo
- Viene generato un link per impostare la password che può essere condiviso manualmente

### Alternative per l'invio email su Windows:
1. Configurare sendmail in XAMPP
2. Usare PHPMailer invece della funzione mail() nativa
3. Usare un servizio email esterno (SendGrid, Mailgun, etc.)
4. In sviluppo, mostrare il link di reset direttamente nell'interfaccia

## VERIFICA FINALE

Dopo aver eseguito tutti i passaggi:

1. Vai su `http://localhost:8888/CollaboraNexio/utenti.php`
2. Clicca su "Nuovo Utente"
3. Compila il form e salva
4. L'utente dovrebbe essere creato con successo
5. Se l'email non viene inviata, verrà mostrato un warning ma l'utente sarà comunque creato
6. Il logo dovrebbe essere visibile nella sidebar

## FILE CREATI PER DEBUG E TEST

1. `/test_email_smtp.php` - Test invio email SMTP
2. `/test_create_user.php` - Test creazione utente via API
3. `/debug_api_issues.php` - Debug completo del sistema
4. `/fix_users_table_columns.php` - Aggiunge colonne mancanti al DB
5. `/fix_logo_all_pages.php` - Script per aggiornare il logo (da eseguire con PHP)

## STATUS

- ✅ Errore 500 risolto (dopo esecuzione fix_users_table_columns.php)
- ✅ Logo corretto in utenti.php
- ⚠️ Logo da aggiornare nelle altre pagine
- ⚠️ Email non funzionante su Windows (expected, gestito gracefully)