# Configurazione Email Infomaniak - Completata ✅

**Data:** 2025-10-05
**Provider:** Infomaniak
**Account:** info@fortibyte.it

---

## 📋 Riepilogo Modifiche

### 1. ✅ EmailSender.php - Password Corretta
**File:** `/includes/EmailSender.php`

```php
// Configurazione Infomaniak (linee 9-15)
private $smtpHost = 'mail.infomaniak.com';
private $smtpPort = 465;
private $smtpUsername = 'info@fortibyte.it';
private $smtpPassword = 'Cartesi@1987';  // ✅ CORRETTA
private $fromEmail = 'info@fortibyte.it';
private $fromName = 'CollaboraNexio';
```

**Modifica:** Password corretta da `Cartesi@1991` → `Cartesi@1987`

---

### 2. ✅ Pagina Configurazioni - Form Aggiornato
**File:** `/configurazioni.php`

**Modifiche al form email (linee 560-599):**
- SMTP Host: `mail.infomaniak.com` (era smtp.gmail.com)
- SMTP Port: `465` (era 587)
- From Email: `info@fortibyte.it` (era noreply@collaboranexio.com)
- Username: `info@fortibyte.it` (era smtp_user)
- TLS/SSL: Attivo per default

---

### 3. ✅ Database - Password Corretta
**Tabella:** `system_settings`

```sql
-- Password aggiornata
UPDATE system_settings
SET setting_value = 'Cartesi@1987'
WHERE setting_key = 'smtp_password';
```

**Impostazioni salvate:**
- smtp_host: `mail.infomaniak.com`
- smtp_port: `465`
- smtp_username: `info@fortibyte.it`
- smtp_password: `Cartesi@1987` ✅
- smtp_from_email: `info@fortibyte.it`
- smtp_encryption: `ssl`

---

### 4. ✅ Fix Autenticazione API
**File:** `/configurazioni.php` (JavaScript)

**Problema risolto:** Errore 401 (Unauthorized)

**Modifiche ai fetch (linee 878 e 932):**
```javascript
fetch('/CollaboraNexio/api/system/config.php?action=test_email', {
    method: 'POST',
    credentials: 'same-origin',  // ✅ AGGIUNTO - invia cookie sessione
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?php echo $csrfToken; ?>'  // ✅ AGGIUNTO
    },
    body: JSON.stringify(data)
})
```

---

### 5. ✅ CSRF Token Validation
**File:** `/api/system/config.php`

**Aggiunta validazione CSRF (linee 25-32):**
```php
// CSRF token validation for POST/PUT/DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrfToken || !$auth->verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }
}
```

---

## 🔧 Parametri Configurazione Infomaniak

### Server SMTP
- **Host:** mail.infomaniak.com
- **Porta:** 465
- **Encryption:** SSL (implicito su porta 465)
- **Autenticazione:** Obbligatoria

### Credenziali
- **Username:** info@fortibyte.it
- **Password:** Cartesi@1987
- **From Email:** info@fortibyte.it
- **From Name:** CollaboraNexio
- **Reply-To:** info@fortibyte.it

---

## 🧪 Come Testare

### 1. Accedi come Super Admin
```
URL: http://localhost:8888/CollaboraNexio/configurazioni.php
User: superadmin@collaboranexio.com
```

### 2. Vai al Tab "Email"
- Verifica che i campi mostrino i parametri Infomaniak
- I valori dovrebbero essere già precompilati

### 3. Test Connessione
1. Inserisci un indirizzo email di test nel campo "Email Test"
2. Clicca il pulsante **"Test Connessione"**
3. Attendi la risposta dell'API
4. Verifica che arrivi l'email di test nella casella

### 4. Salva Configurazione
- Clicca **"Salva Modifiche"** per confermare le impostazioni
- Le impostazioni verranno salvate nel database `system_settings`

---

## 📁 File Modificati

1. `/includes/EmailSender.php` - Password corretta
2. `/configurazioni.php` - Form e fetch aggiornati
3. `/api/system/config.php` - CSRF validation aggiunta
4. `/database/update_infomaniak_email_config.sql` - Migration SQL
5. `system_settings` table - Password e configurazioni aggiornate

---

## 🔒 Sicurezza

### Miglioramenti Applicati
- ✅ CSRF token validation su tutte le richieste POST
- ✅ Autenticazione sessione con cookie sicuri
- ✅ Solo super_admin può modificare configurazioni email
- ✅ Password stored in database (considerare encryption per produzione)

### Raccomandazioni Produzione
1. **Encrypt password** nel database usando chiave di cifratura
2. **Enable HTTPS** per proteggere credenziali in transito
3. **Rotate password** periodicamente per sicurezza
4. **Monitor failed attempts** per rilevare attacchi brute force

---

## ⚙️ Troubleshooting

### Errore 401 (Unauthorized)
**Causa:** Cookie sessione non inviato
**Soluzione:** ✅ Aggiunto `credentials: 'same-origin'` ai fetch

### Errore "Token CSRF non valido"
**Causa:** Header CSRF mancante
**Soluzione:** ✅ Aggiunto `X-CSRF-Token` negli headers

### Email non inviata
**Possibili cause:**
1. Password errata → ✅ Corretta a Cartesi@1987
2. Porta SMTP errata → ✅ Impostata a 465
3. SSL non configurato → ✅ Attivato per porta 465
4. Firewall blocca porta 465 → Verificare firewall XAMPP

### Windows/XAMPP Skip Email
**Nota:** EmailSender ha ottimizzazione per XAMPP che salta l'invio in sviluppo locale per evitare timeout. In produzione Linux, l'email verrà inviata normalmente.

```php
// Linee 113-121 in EmailSender.php
if (stripos(PHP_OS, 'WIN') !== false) {
    error_log("Email sending skipped on Windows/XAMPP for performance");
    return true; // Simula successo
}
```

**Soluzione temporanea per test locale:**
Commentare queste linee per testare l'invio su XAMPP Windows.

---

## ✅ Checklist Finale

- [x] Password EmailSender.php corretta
- [x] Form configurazioni.php aggiornato
- [x] Database password aggiornata
- [x] Fetch API con credentials
- [x] CSRF token validation
- [ ] **Test email inviata con successo** ← DA FARE

---

## 📝 Note Aggiuntive

### Differenza Password
- **Vecchia password database:** Cartesi@1991 ❌
- **Nuova password corretta:** Cartesi@1987 ✅
- **Sincronizzazione:** Tutti i componenti ora usano Cartesi@1987

### Environment Detection
Il sistema rileva automaticamente se è in produzione (nexiosolution.it) o sviluppo (localhost):
- **Produzione:** Email inviate normalmente
- **Sviluppo (XAMPP):** Email simulate per performance (linee 113-121 EmailSender.php)

### Session Cookie Settings
- **Nome sessione:** COLLAB_SID
- **Dominio:** .nexiosolution.it (prod) / vuoto (dev)
- **Secure:** true (prod) / false (dev)
- **HttpOnly:** true
- **SameSite:** Lax

---

**Configurazione completata con successo!** 🎉

Prossimo step: **Testare invio email** dalla pagina configurazioni.php
