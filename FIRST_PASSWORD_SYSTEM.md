# Sistema di Prima Password - CollaboraNexio

## Panoramica
Il sistema di "prima password" è stato implementato con successo. Ora quando un super admin crea un nuovo utente, non deve più specificare la password. L'utente riceve automaticamente un'email con un link sicuro per impostare la propria password.

## File Implementati

### 1. Migrazione Database
- **File**: `/database/08_first_password_system.sql`
- **Script esecuzione**: `/run_first_password_migration.php`
- **Campi aggiunti alla tabella `users`**:
  - `password_reset_token` VARCHAR(255)
  - `password_reset_expires` DATETIME
  - `first_login` BOOLEAN
  - `password_set_at` DATETIME
  - `welcome_email_sent_at` DATETIME
- **Nuova tabella**: `password_reset_attempts` (per rate limiting)

### 2. Sistema Email
- **Classe**: `/includes/EmailSender.php`
- **Template**: `/templates/email/welcome.html`
- **Configurazione SMTP**:
  - Server: mail.infomaniak.com
  - Porta: 465
  - Cifratura: SSL
  - Username: info@fortibyte.it
  - Password: Ricord@1991

### 3. Pagine Web
- **Set Password**: `/set_password.php` - Pagina per impostare la password
- **Password Dimenticata**: `/forgot_password.php` - Richiesta reset password
- **Modifiche a**: `/index.php` - Aggiunto link "Password dimenticata?"

### 4. API Modificate
- `/api/users/create.php` - Rimossa richiesta password, aggiunto invio email
- `/api/users/create_v2.php` - Versione FormData con stesso comportamento
- `/api/auth/request_password_reset.php` - Nuova API per reset password

### 5. Interfaccia Utente
- **Modifiche a**: `/utenti.php`
  - Rimosso campo password dal form di creazione utente
  - Aggiunto messaggio informativo sull'invio email
  - Gestione errori invio email con link manuale di backup

## Come Funziona

### Creazione Nuovo Utente
1. L'admin inserisce solo nome, cognome, email e ruolo
2. Il sistema genera automaticamente un token sicuro (32 bytes)
3. Salva l'utente con `first_login = TRUE` e senza password
4. Invia email di benvenuto con link valido 24 ore
5. Se l'email fallisce, mostra il link manuale all'admin

### Primo Accesso
1. L'utente clicca sul link nell'email
2. Arriva su `/set_password.php?token=xxx`
3. Il sistema verifica validità e scadenza del token
4. L'utente imposta una password sicura con:
   - Minimo 8 caratteri
   - Almeno una maiuscola
   - Almeno una minuscola
   - Almeno un numero
5. Dopo l'impostazione:
   - `first_login = FALSE`
   - `password_set_at = NOW()`
   - Token rimosso
   - Redirect al login

### Reset Password
1. Utente clicca "Password dimenticata?" dal login
2. Inserisce email su `/forgot_password.php`
3. Sistema verifica rate limiting (max 3 tentativi in 5 minuti)
4. Genera nuovo token e invia email
5. Processo identico al primo accesso

## Sicurezza Implementata

### Protezioni Token
- Token crittograficamente sicuro (64 caratteri hex)
- Scadenza 24 ore
- Usa-e-getta (invalidato dopo uso)
- Indice database per ricerche veloci

### Rate Limiting
- Massimo 3 tentativi per email/IP in 5 minuti
- Tracciamento in `password_reset_attempts`
- Pulizia automatica record vecchi

### Validazione Password
- Frontend: validazione real-time con indicatore forza
- Backend: controlli rigorosi su pattern richiesti
- Hash con PASSWORD_DEFAULT (bcrypt)

### Privacy
- Messaggi generici per richieste pubbliche
- Non rivela se email esiste nel sistema
- Log solo per utenti autenticati

## Test e Verifica

### Per Testare il Sistema
1. **Eseguire la migrazione**:
   ```
   http://localhost:8888/CollaboraNexio/run_first_password_migration.php
   ```

2. **Creare un nuovo utente**:
   - Vai su Gestione Utenti
   - Clicca "Nuovo Utente"
   - Inserisci dati (senza password)
   - Verifica ricezione email

3. **Testare reset password**:
   - Dal login, clicca "Password dimenticata?"
   - Inserisci email esistente
   - Verifica ricezione email

### Troubleshooting Email

Se le email non vengono inviate:

1. **Verifica configurazione SMTP** in `/includes/EmailSender.php`
2. **Per Windows/XAMPP**: PHP mail() non supporta autenticazione SMTP
   - Considera l'uso di PHPMailer per produzione
   - In sviluppo, usa il link manuale mostrato dopo creazione utente

3. **Log errori**: Controlla `error_log` di PHP per dettagli

## Credenziali Demo

Gli utenti demo esistenti mantengono le loro password:
- `admin@demo.local` - Admin123!
- `manager@demo.local` - Admin123!
- `user1@demo.local` - Admin123!

I nuovi utenti creati dovranno impostare la password tramite email.

## Note per Produzione

1. **Configurare SMTP reale** con credenziali corrette
2. **Installare PHPMailer** per supporto SMTP completo su Windows
3. **Configurare HTTPS** per link sicuri
4. **Personalizzare template email** con branding aziendale
5. **Configurare job di pulizia** per token scaduti
6. **Monitorare rate limiting** e ajustare soglie se necessario

## Prossimi Passi Consigliati

1. Implementare autenticazione a due fattori (2FA)
2. Aggiungere policy password configurabili per tenant
3. Implementare blocco account dopo tentativi falliti
4. Aggiungere notifiche push/SMS come alternativa email
5. Dashboard amministrativa per monitorare invii email

---

**Sistema implementato da**: Claude Code Assistant
**Data**: <?php echo date('Y-m-d'); ?>
**Versione**: 1.0.0