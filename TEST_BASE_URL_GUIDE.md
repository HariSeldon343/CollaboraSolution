# Guida Test BASE_URL Configuration

## Quick Start Testing Guide

### STEP 1: Verifica Configurazione (5 minuti)

#### A. Test in Sviluppo (Localhost)

```bash
# Apri browser
http://localhost:8888/CollaboraNexio/verify_base_url.php
```

**Risultato Atteso**:
```
Ambiente: SVILUPPO
BASE_URL: http://localhost:8888/CollaboraNexio
PRODUCTION_MODE: FALSE
DEBUG_MODE: TRUE
```

#### B. Test in Produzione (Cloudflare)

```bash
# Apri browser
https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php
```

**Risultato Atteso**:
```
Ambiente: PRODUZIONE
BASE_URL: https://app.nexiosolution.it/CollaboraNexio
PRODUCTION_MODE: TRUE
DEBUG_MODE: FALSE
```

---

### STEP 2: Test Funzionale Email (10 minuti)

#### A. Sviluppo - Test Link Email

1. **Login come admin**:
   ```
   http://localhost:8888/CollaboraNexio/
   Email: admin@demo.local
   Password: Admin123!
   ```

2. **Crea nuovo utente**:
   ```
   http://localhost:8888/CollaboraNexio/utenti.php

   Nome: Test User
   Email: testuser@example.com
   Ruolo: user
   ```

3. **Verifica email inviata**:
   - Controlla log: `/logs/mailer_error.log`
   - Cerca: `"status":"success"`
   - Verifica link contiene: `http://localhost:8888`

4. **Test link**:
   ```
   http://localhost:8888/CollaboraNexio/set_password.php?token=XXXXX
   ```

#### B. Produzione - Test Link Email

1. **Login come admin**:
   ```
   https://app.nexiosolution.it/CollaboraNexio/
   Email: admin@demo.local
   Password: Admin123!
   ```

2. **Crea nuovo utente**:
   ```
   https://app.nexiosolution.it/CollaboraNexio/utenti.php

   Nome: Test Produzione
   Email: testprod@example.com
   Ruolo: user
   ```

3. **Verifica email ricevuta**:
   - Apri email inbox
   - Link deve contenere: `https://app.nexiosolution.it`
   - **NON DEVE** contenere: `localhost`

4. **Test link**:
   ```
   https://app.nexiosolution.it/CollaboraNexio/set_password.php?token=XXXXX
   ```

---

### STEP 3: Test Reset Password (5 minuti)

#### A. Sviluppo

```bash
# 1. Vai alla pagina login
http://localhost:8888/CollaboraNexio/index.php

# 2. Click "Password dimenticata?"
# 3. Inserisci email: admin@demo.local
# 4. Verifica email con link:
http://localhost:8888/CollaboraNexio/set_password.php?token=XXXXX
```

#### B. Produzione

```bash
# 1. Vai alla pagina login
https://app.nexiosolution.it/CollaboraNexio/index.php

# 2. Click "Password dimenticata?"
# 3. Inserisci email: admin@demo.local
# 4. Verifica email con link:
https://app.nexiosolution.it/CollaboraNexio/set_password.php?token=XXXXX
```

---

### STEP 4: Verifica Log Mailer (Debug)

#### Check Mailer Logs

```bash
# Sviluppo
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/mailer_error.log

# Produzione (SSH)
tail -f /path/to/CollaboraNexio/logs/mailer_error.log
```

#### Cercare Successo

```json
{
  "timestamp": "2025-10-07 10:30:00",
  "status": "success",
  "to": "testuser@example.com",
  "subject": "Benvenuto in CollaboraNexio",
  "action": "welcome_email"
}
```

#### Cercare Errori

```json
{
  "timestamp": "2025-10-07 10:30:00",
  "status": "error",
  "error_type": "send_failed",
  "error": "SMTP Error: Could not connect",
  "to": "testuser@example.com"
}
```

---

### STEP 5: Test Cross-Domain Session (Opzionale)

#### Verifica Sessione Condivisa

**IMPORTANTE**: Funziona solo se Session Domain configurato correttamente

1. **Login in sviluppo**:
   ```
   http://localhost:8888/CollaboraNexio/
   Email: admin@demo.local
   ```

2. **Verifica cookie**:
   - Nome: `COLLAB_SID`
   - Domain: (empty) in dev
   - Secure: false
   - HttpOnly: true

3. **Login in produzione**:
   ```
   https://app.nexiosolution.it/CollaboraNexio/
   Email: admin@demo.local
   ```

4. **Verifica cookie**:
   - Nome: `COLLAB_SID`
   - Domain: `.nexiosolution.it`
   - Secure: true
   - HttpOnly: true

---

## Troubleshooting

### Problema 1: Email contiene localhost in produzione

**Sintomo**:
```
Link ricevuto: http://localhost:8888/CollaboraNexio/set_password.php
Ambiente: PRODUZIONE
```

**Soluzione**:
```bash
# 1. Verifica config
https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php

# 2. Check BASE_URL
Se BASE_URL != "https://app.nexiosolution.it/CollaboraNexio"
  -> HTTP_HOST non contiene 'nexiosolution.it'
  -> Controlla proxy/Cloudflare forwarding

# 3. Svuota cache opcache
<?php
opcache_reset();
```

### Problema 2: Auto-detect fallisce

**Sintomo**:
```
HTTP_HOST: app.nexiosolution.it
BASE_URL: http://localhost:8888/CollaboraNexio (WRONG!)
```

**Soluzione**:
```php
// In config.php - verifica questo codice:
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Se Cloudflare usa header diverso:
$currentHost = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
            ?? 'localhost';

if (strpos($currentHost, 'nexiosolution.it') !== false) {
    define('BASE_URL', 'https://app.nexiosolution.it/CollaboraNexio');
}
```

### Problema 3: Sessione non funziona in produzione

**Sintomo**:
```
Login effettuato ma redirect fallisce
Cookie non impostato
```

**Soluzione**:
```php
// Verifica in config.php:
if (PRODUCTION_MODE) {
    define('SESSION_SECURE', true);  // HTTPS richiesto
    define('SESSION_DOMAIN', '.nexiosolution.it');
}

// Assicurati che:
1. HTTPS sia attivo (Cloudflare)
2. Domain inizi con punto: '.nexiosolution.it'
3. SESSION_NAME sia uguale in dev/prod
```

### Problema 4: CORS error

**Sintomo**:
```
Access-Control-Allow-Origin error
Fetch API blocked
```

**Soluzione**:
```php
// Verifica includes/cors_helper.php:
$allowedOrigins = [
    'https://app.nexiosolution.it', // DEVE essere presente
    'http://localhost:8888'
];

// Aggiungi in API files:
require_once '../../includes/cors_helper.php';
setupCORS();
```

---

## Comandi Utili

### 1. Verifica HTTP_HOST

```php
<?php
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n";
echo "HTTP_X_FORWARDED_HOST: " . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'N/A') . "\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "\n";
```

### 2. Test BASE_URL in Console

```php
<?php
require_once 'config.php';
echo "BASE_URL: " . BASE_URL . "\n";
echo "Expected: https://app.nexiosolution.it/CollaboraNexio\n";
echo "Match: " . (BASE_URL === 'https://app.nexiosolution.it/CollaboraNexio' ? 'YES' : 'NO') . "\n";
```

### 3. Grep Link Errors

```bash
# Cerca link errati nei log
grep -i "localhost:8888" /path/to/logs/mailer_error.log

# Cerca invii email
grep '"status":"success"' /path/to/logs/mailer_error.log | tail -10
```

### 4. Clear Cache

```bash
# Sviluppo (XAMPP)
# Restart Apache da XAMPP Control Panel

# Produzione
sudo systemctl restart php-fpm
sudo systemctl restart nginx
```

---

## Checklist Pre-Produzione

### Pre-Deploy

- [ ] `config.php` contiene auto-detect
- [ ] Test `/verify_base_url.php` in localhost
- [ ] BASE_URL = `http://localhost:8888/CollaboraNexio`
- [ ] Creazione utente funziona
- [ ] Email con link localhost ricevuta
- [ ] Link localhost funzionanti

### Post-Deploy

- [ ] Accesso a `https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php`
- [ ] BASE_URL = `https://app.nexiosolution.it/CollaboraNexio`
- [ ] PRODUCTION_MODE = true
- [ ] DEBUG_MODE = false
- [ ] SESSION_SECURE = true
- [ ] SESSION_DOMAIN = `.nexiosolution.it`
- [ ] Creazione utente in produzione
- [ ] Email con link produzione ricevuta
- [ ] Link produzione funzionanti
- [ ] Reset password funzionante
- [ ] CORS non genera errori

### Post-Deploy Monitoring (48h)

- [ ] Check log mailer errori
- [ ] Verifica invii email successo
- [ ] Test creazione utenti
- [ ] Test reset password
- [ ] Verifica performance email
- [ ] Check tasso apertura link email

---

## Quick Reference

### URL Corretti

| Ambiente | BASE_URL |
|----------|----------|
| Sviluppo | `http://localhost:8888/CollaboraNexio` |
| Produzione | `https://app.nexiosolution.it/CollaboraNexio` |

### Pattern Rilevamento

| Variabile | Sviluppo | Produzione |
|-----------|----------|------------|
| HTTP_HOST | `localhost:8888` | `app.nexiosolution.it` |
| Pattern | NON contiene `nexiosolution.it` | Contiene `nexiosolution.it` |

### File Critici

| File | Funzione | Usa BASE_URL |
|------|----------|--------------|
| `/config.php` | Auto-detect | Definisce BASE_URL |
| `/includes/mailer.php` | Email links | ✅ Con fallback |
| `/api/users/create.php` | User creation | ✅ Diretto |
| `/api/auth/request_password_reset.php` | Password reset | ✅ Diretto |
| `/verify_base_url.php` | Test script | ✅ Test |

---

**Fine Guida**

Per supporto: Consulta BASE_URL_CONFIGURATION_REPORT.md
