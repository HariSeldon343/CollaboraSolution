# Checklist Migrazione Sistema Email SMTP

Questa checklist guida attraverso la verifica completa del nuovo sistema email PHPMailer.

## ✅ Pre-requisiti

- [ ] PHP 8.3+ installato
- [ ] Estensione OpenSSL abilitata (`php -m | grep openssl`)
- [ ] XAMPP in esecuzione (se ambiente di sviluppo)
- [ ] Credenziali SMTP Infomaniak disponibili

## 🔧 Installazione

- [ ] PHPMailer scaricato in `includes/PHPMailer/`
- [ ] File `includes/mailer.php` creato
- [ ] File `includes/config_email.sample.php` creato
- [ ] File `includes/config_email.php` creato con password reale
- [ ] `.gitignore` aggiornato per escludere `config_email.php`

## ⚙️ Configurazione

### OpenSSL (XAMPP)

- [ ] Aperto `C:\xampp\php\php.ini`
- [ ] Decommentato `extension=openssl`
- [ ] Riavviato Apache in XAMPP Control Panel
- [ ] Verificato con `php -m | grep openssl`

### Config Email

- [ ] `EMAIL_SMTP_HOST` = `mail.infomaniak.com`
- [ ] `EMAIL_SMTP_PORT` = `465` (SSL)
- [ ] `EMAIL_SMTP_USERNAME` = `info@fortibyte.it`
- [ ] `EMAIL_SMTP_PASSWORD` = **password reale inserita**
- [ ] `EMAIL_FROM_EMAIL` = `info@fortibyte.it`
- [ ] `EMAIL_FROM_NAME` = `CollaboraNexio`
- [ ] `EMAIL_SMTP_VERIFY_SSL` = `false` (solo dev), `true` (prod)
- [ ] `EMAIL_DEBUG_MODE` = `true` (solo dev), `false` (prod)

## 🧪 Test Base

### 1. Test Configurazione

```bash
# Apri nel browser:
http://localhost:8888/CollaboraNexio/test_mailer_smtp.php
```

- [ ] Configurazione caricata correttamente
- [ ] OpenSSL extension attivo
- [ ] PHPMailer trovato e caricato
- [ ] Nessun errore PHP visualizzato

### 2. Test Invio Email

- [ ] Inserita email di test in `test_mailer_smtp.php`
- [ ] Cliccato "Invia Email di Test"
- [ ] Messaggio "Email inviata con successo" visualizzato
- [ ] Email ricevuta nella casella (controlla spam)
- [ ] Log creato in `logs/mailer_error.log`

## 📧 Test Funzionalità Applicative

### 3. Registrazione Utente

**Scenario**: Admin crea nuovo utente

- [ ] Login come admin: `admin@demo.local` / `Admin123!`
- [ ] Vai a "Utenti" → "Crea Nuovo Utente"
- [ ] Inserisci dati: nome, email, ruolo
- [ ] Clicca "Crea Utente"
- [ ] Verifica messaggio "Email di benvenuto inviata"
- [ ] Controlla casella email per "Benvenuto in CollaboraNexio"
- [ ] Verifica link "Imposta password" funziona
- [ ] Log entry creato in `logs/mailer_error.log`

### 4. Reset Password

**Scenario**: Utente ha dimenticato password

- [ ] Vai a pagina login: `http://localhost:8888/CollaboraNexio/`
- [ ] Clicca "Password dimenticata?"
- [ ] Inserisci email esistente
- [ ] Clicca "Invia"
- [ ] Verifica messaggio "Email inviata"
- [ ] Controlla casella per "Reimposta la tua password"
- [ ] Clicca link reset e imposta nuova password
- [ ] Login con nuova password funziona

### 5. Approvazione Documenti

**Scenario**: Manager approva documento

- [ ] Login come manager: `manager@demo.local` / `Admin123!`
- [ ] Vai a "Approvazioni Documenti"
- [ ] Approva un documento in attesa
- [ ] Verifica che l'owner del documento riceva notifica (se implementato)
- [ ] Log entry creato

### 6. Eventi Calendario

**Scenario**: Invito evento calendario

- [ ] Crea nuovo evento nel calendario
- [ ] Aggiungi partecipanti
- [ ] Salva evento
- [ ] Verifica che i partecipanti ricevano invito email (se implementato)
- [ ] Log entry creato

## 📊 Verifica Logging

- [ ] File `logs/mailer_error.log` esiste
- [ ] Log entries in formato JSON
- [ ] Timestamp corretti
- [ ] Status: "success" per invii riusciti
- [ ] Status: "error" per invii falliti (testare disabilitando credenziali)
- [ ] Tenant_id e user_id tracciati correttamente
- [ ] Log rotazione funziona (se file > 10MB)

## 🔒 Sicurezza

- [ ] File `config_email.php` **NON** committato in Git
- [ ] Password SMTP non visibile nel codice sorgente pubblico
- [ ] `.gitignore` include `includes/config_email.php`
- [ ] Verificato con `git status` che config_email.php è ignorato
- [ ] SSL/TLS abilitato in produzione (`EMAIL_SMTP_VERIFY_SSL = true`)
- [ ] Debug mode disabilitato in produzione (`EMAIL_DEBUG_MODE = false`)

## 🚀 Deploy Produzione

### Pre-Deploy

- [ ] Backup database completo
- [ ] Backup file `includes/` directory
- [ ] Backup vecchio `EmailSender.php`
- [ ] Credenziali SMTP produzione verificate

### Deploy

- [ ] Upload nuovi file su server produzione
- [ ] Copia `config_email.sample.php` → `config_email.php`
- [ ] Inserisci credenziali SMTP produzione in `config_email.php`
- [ ] Imposta `EMAIL_SMTP_VERIFY_SSL = true`
- [ ] Imposta `EMAIL_DEBUG_MODE = false`
- [ ] Verifica permessi file (644 per config_email.php)
- [ ] Verifica permessi directory logs/ (755)

### Post-Deploy

- [ ] Esegui `test_mailer_smtp.php` su produzione
- [ ] Test registrazione nuovo utente
- [ ] Test reset password
- [ ] Monitora `logs/mailer_error.log` per 24h
- [ ] Verifica nessun errore PHP error log
- [ ] Test velocità risposta API (< 1s)

## 🐛 Troubleshooting

### Problema: "Configuration not available"

- [ ] Verificato che `includes/config_email.php` esiste
- [ ] Verificato sintassi PHP corretta (no errori)
- [ ] Verificato define() con nomi corretti
- [ ] Riavviato Apache

### Problema: "OpenSSL extension not loaded"

- [ ] Aperto `php.ini` corretto (usa `php --ini` per trovarlo)
- [ ] Decommentato `extension=openssl`
- [ ] Riavviato Apache/PHP-FPM
- [ ] Verificato con `php -m | grep openssl`

### Problema: "SMTP connection failed"

- [ ] Verificato host SMTP: `mail.infomaniak.com`
- [ ] Verificato porta: `465` (SSL) o `587` (TLS)
- [ ] Verificato username = email mittente
- [ ] Verificato password corretta (no spazi)
- [ ] Verificato firewall non blocca porta 465/587
- [ ] Provato da linea comando: `telnet mail.infomaniak.com 465`

### Problema: "SSL certificate problem"

**Solo sviluppo**:
- [ ] Impostato `EMAIL_SMTP_VERIFY_SSL = false`
- [ ] Riavviato Apache
- [ ] Testato nuovamente

**Produzione**: Non disabilitare mai SSL verification!

### Problema: "Email inviata ma non ricevuta"

- [ ] Controllato cartella Spam/Posta indesiderata
- [ ] Verificato indirizzo destinatario corretto
- [ ] Controllato log SMTP server (Infomaniak panel)
- [ ] Verificato SPF/DKIM configurati per dominio
- [ ] Provato email alternativa (Gmail, Outlook)

## 📈 Performance

- [ ] API response time < 1 secondo (email non bloccante)
- [ ] Log file < 10MB (rotazione automatica)
- [ ] Nessun timeout SMTP (timeout configurato a 10s)
- [ ] CPU usage normale durante invio email
- [ ] Memory usage stabile

## ↩️ Rollback Plan

Se si verificano problemi critici:

### Rollback Immediato

```bash
# 1. Ripristina vecchio EmailSender (se necessario)
cp backups/EmailSender.php.bak includes/EmailSender.php

# 2. Commenta chiamate al nuovo mailer
# In api/users/create.php, api/auth/request_password_reset.php
# Commenta: require_once 'includes/mailer.php';

# 3. Riavvia Apache
sudo service apache2 restart  # Linux
# oppure
# Restart in XAMPP Control Panel
```

### Verifica Rollback

- [ ] Sistema torna a usare vecchio EmailSender
- [ ] Registrazione utenti funziona (anche senza email)
- [ ] Reset password funziona (link manuale)
- [ ] Nessun errore PHP

## ✅ Sign-off

### Sviluppo

- [ ] Tutti i test base superati
- [ ] Tutte le funzionalità applicative testate
- [ ] Logging funziona correttamente
- [ ] Nessun errore critico

**Testato da**: ________________
**Data**: ________________
**Firma**: ________________

### Produzione

- [ ] Deploy completato con successo
- [ ] Test post-deploy superati
- [ ] Monitoraggio 24h OK
- [ ] Performance accettabili
- [ ] Rollback plan documentato

**Deploy da**: ________________
**Data**: ________________
**Firma**: ________________

---

**Versione Checklist**: 1.0
**Data Creazione**: 6 Ottobre 2025
**Ultima Modifica**: 6 Ottobre 2025
