# Configurazione Email Nexio Solution

## ✅ Stato: COMPLETATA

Data aggiornamento: 6 Ottobre 2025

---

## 📧 Configurazione Applicata

La piattaforma CollaboraNexio è ora configurata con il server email Nexio Solution:

### Parametri Server SMTP

| Parametro | Valore |
|-----------|--------|
| **Server SMTP** | `mail.nexiosolution.it` |
| **Porta** | `465` |
| **Crittografia** | `SSL` |
| **Autenticazione** | Richiesta |

### Credenziali Account

| Campo | Valore |
|-------|--------|
| **Username** | `info@nexiosolution.it` |
| **Password** | `Ricord@1991` |
| **Email mittente** | `info@nexiosolution.it` |
| **Nome mittente** | `Nexio Solution` |
| **Reply-To** | `info@nexiosolution.it` |

### Parametri IMAP (ricezione)

| Parametro | Valore |
|-----------|--------|
| **Server IMAP** | `mail.nexiosolution.it` |
| **Porta** | `993` |
| **Crittografia** | `SSL/TLS` |

---

## 🔧 File Modificati

### 1. Database `system_settings`

La configurazione è salvata nella tabella `system_settings` con i seguenti record:

```sql
-- Configurazione email globale (tenant_id = NULL)
smtp_host = 'mail.nexiosolution.it'
smtp_port = '465'
smtp_encryption = 'ssl'
smtp_username = 'info@nexiosolution.it'
smtp_password = 'Ricord@1991'
from_email = 'info@nexiosolution.it'
from_name = 'Nexio Solution'
reply_to = 'info@nexiosolution.it'
smtp_enabled = '1'
```

### 2. Script Creati

- `/apply_nexio_email_config.php` - Applica la configurazione email
- `/cleanup_old_email_settings.php` - Rimuove configurazioni duplicate
- `/database/update_nexio_email_config.sql` - Script SQL alternativo

---

## 📋 Operazioni Eseguite

1. ✅ Creazione configurazione email Nexio Solution
2. ✅ Salvataggio nella tabella `system_settings`
3. ✅ Rimozione 9 record duplicati/obsoleti (vecchie configurazioni Infomaniak/Fortibyte)
4. ✅ Verifica integrità configurazione finale

---

## 🔍 Come Funziona

Il sistema carica automaticamente la configurazione email tramite:

### File: `includes/email_config.php`

```php
// Funzione che carica configurazione da database
$emailConfig = getEmailConfigFromDatabase();

// Restituisce array con:
[
    'smtpHost' => 'mail.nexiosolution.it',
    'smtpPort' => 465,
    'smtpUsername' => 'info@nexiosolution.it',
    'smtpPassword' => 'Ricord@1991',
    'fromEmail' => 'info@nexiosolution.it',
    'fromName' => 'Nexio Solution',
    'replyTo' => 'info@nexiosolution.it'
]
```

### Classe: `includes/EmailSender.php`

La classe `EmailSender` usa automaticamente `getEmailConfigFromDatabase()` per caricare le impostazioni SMTP correnti dal database.

---

## 🧪 Test della Configurazione

### Metodo 1: Script di test esistente

```bash
php test_real_email_infomaniak.php
```

### Metodo 2: Tramite interfaccia web

1. Accedi come amministratore
2. Vai su **Gestione Utenti** → **Crea Nuovo Utente**
3. Il sistema invierà email di notifica usando la nuova configurazione

### Metodo 3: API di sistema

```
GET http://localhost:8888/CollaboraNexio/api/system/config.php?action=test_email
```

---

## 📝 Note Importanti

### Ambiente di Sviluppo (XAMPP)

In ambiente locale, `EmailSender.php` potrebbe saltare l'invio SMTP per performance:

```
EmailSender: Ambiente Windows/XAMPP rilevato - Skip SMTP per performance
```

Questo è normale in sviluppo. Per testare realmente l'invio:

1. Commentare la rilevazione ambiente in `EmailSender.php`
2. Oppure deployare su produzione (https://app.nexiosolution.it)

### Ambiente di Produzione

In produzione su `app.nexiosolution.it`, il sistema:
- ✅ Rileva automaticamente l'ambiente production
- ✅ Carica configurazione da database
- ✅ Invia email reali tramite `mail.nexiosolution.it`

---

## 🔐 Sicurezza

### Password nel Database

⚠️ **IMPORTANTE**: La password SMTP è salvata in chiaro nella tabella `system_settings`.

**Raccomandazioni**:
1. Limitare accesso al database solo a utenti autorizzati
2. Usare firewall per proteggere MySQL (porta 3306)
3. Considerare crittografia password in futuro con `openssl_encrypt()`

### Permessi File

Verificare che i file sensibili abbiano permessi corretti:
```bash
chmod 640 config.php
chmod 640 includes/email_config.php
```

---

## 🔄 Rollback (se necessario)

Per tornare alla configurazione precedente (Infomaniak):

```bash
php update_infomaniak_migration.php
```

Oppure eseguire:
```sql
UPDATE system_settings SET setting_value = 'mail.infomaniak.com' WHERE setting_key = 'smtp_host';
UPDATE system_settings SET setting_value = 'info@fortibyte.it' WHERE setting_key = 'smtp_username';
-- etc...
```

---

## 📞 Supporto

Per problemi con l'invio email:

1. Verificare log applicazione: `logs/php_errors.log`
2. Testare connessione SMTP manualmente con telnet:
   ```bash
   telnet mail.nexiosolution.it 465
   ```
3. Verificare credenziali nel pannello email Nexio Solution
4. Controllare firewall/antivirus che potrebbero bloccare porta 465

---

## ✨ Funzionalità Email Disponibili

La nuova configurazione abilita:

- ✅ Notifiche cambio password
- ✅ Email reset password
- ✅ Notifiche nuovi utenti
- ✅ Notifiche approvazione documenti
- ✅ Notifiche eventi calendario
- ✅ Notifiche task assignments
- ✅ Notifiche chat mentions

---

**Configurazione completata da**: Claude Code Assistant
**Data**: 6 Ottobre 2025
**Versione sistema**: CollaboraNexio v1.0
