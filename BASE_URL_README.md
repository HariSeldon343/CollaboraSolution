# BASE_URL Configuration - Quick Reference

## TL;DR - Quick Start

### Test in 30 secondi:

**Sviluppo:**
```
http://localhost:8888/CollaboraNexio/verify_base_url.php
```

**Produzione:**
```
https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php
```

### Expected Results:

| Environment | BASE_URL | Production Mode |
|-------------|----------|-----------------|
| Development | `http://localhost:8888/CollaboraNexio` | FALSE |
| Production | `https://app.nexiosolution.it/CollaboraNexio` | TRUE |

---

## How It Works

### Auto-Detection (config.php)

```php
// Checks HTTP_HOST for production domain
if (strpos($_SERVER['HTTP_HOST'], 'nexiosolution.it') !== false) {
    define('BASE_URL', 'https://app.nexiosolution.it/CollaboraNexio');
    define('PRODUCTION_MODE', true);
} else {
    define('BASE_URL', 'http://localhost:8888/CollaboraNexio');
    define('PRODUCTION_MODE', false);
}
```

### Detection Logic:

1. Read `$_SERVER['HTTP_HOST']`
2. Check if contains `nexiosolution.it`
3. Set BASE_URL accordingly
4. Configure environment variables

---

## Files Overview

### Documentation (Read These)

| File | Purpose | When to Use |
|------|---------|-------------|
| `BASE_URL_README.md` | **This file** - Quick reference | Always start here |
| `BASE_URL_MIGRATION_SUMMARY.md` | Executive summary | Overview & deployment |
| `BASE_URL_CONFIGURATION_REPORT.md` | Full technical docs | Deep dive & troubleshooting |
| `TEST_BASE_URL_GUIDE.md` | Testing procedures | Testing step-by-step |

### Test Scripts (Run These)

| File | Type | Usage |
|------|------|-------|
| `verify_base_url.php` | Web | Open in browser for visual check |
| `test_base_url_cli.php` | CLI | Run `php test_base_url_cli.php` |

---

## Common Tasks

### 1. Verify Configuration

```bash
# Browser (recommended)
http://localhost:8888/CollaboraNexio/verify_base_url.php

# Or CLI
php test_base_url_cli.php
```

### 2. Test Email Links

```bash
# 1. Login
http://localhost:8888/CollaboraNexio/

# 2. Create user
/utenti.php → Add User

# 3. Check email
Link should contain: http://localhost:8888 (dev) or https://app.nexiosolution.it (prod)
```

### 3. Check Logs

```bash
# Development
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/mailer_error.log

# Production (SSH)
tail -f /var/www/CollaboraNexio/logs/mailer_error.log

# Look for
grep '"status":"success"' logs/mailer_error.log
```

### 4. Troubleshoot Wrong URL

```bash
# If BASE_URL is wrong:
1. Open /verify_base_url.php
2. Check HTTP_HOST value
3. If production, should contain 'nexiosolution.it'
4. If not, check proxy/Cloudflare config
5. Clear opcache: restart PHP-FPM
```

---

## Quick Troubleshooting

### Problem: Email has localhost in production

**Solution:**
1. Check `/verify_base_url.php` → Should show production URL
2. If wrong, check Cloudflare forwards HTTP_HOST correctly
3. Clear PHP cache: `sudo systemctl restart php-fpm`

### Problem: BASE_URL not defined

**Solution:**
```php
// Always use fallback in email functions
$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
```

### Problem: Session not working

**Solution:**
Check session settings match environment:
- Dev: `SESSION_SECURE = false`, `SESSION_DOMAIN = ''`
- Prod: `SESSION_SECURE = true`, `SESSION_DOMAIN = '.nexiosolution.it'`

---

## Key Files to Know

### Core Configuration
- `/config.php` - Auto-detect logic
- `/includes/mailer.php` - Email links (uses BASE_URL)

### API Endpoints
- `/api/users/create.php` - User creation
- `/api/auth/request_password_reset.php` - Password reset

### Security
- `/includes/cors_helper.php` - CORS origins

---

## Deployment Checklist

### Before Deploy
- [ ] Run `verify_base_url.php` locally
- [ ] Confirm BASE_URL = localhost
- [ ] Test email creation works

### After Deploy
- [ ] Run `verify_base_url.php` on production
- [ ] Confirm BASE_URL = production
- [ ] Test email has production links
- [ ] Check logs for errors

---

## URLs Reference

### Development
| Page | URL |
|------|-----|
| Verify Script | `http://localhost:8888/CollaboraNexio/verify_base_url.php` |
| Login | `http://localhost:8888/CollaboraNexio/` |
| Users | `http://localhost:8888/CollaboraNexio/utenti.php` |

### Production
| Page | URL |
|------|-----|
| Verify Script | `https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php` |
| Login | `https://app.nexiosolution.it/CollaboraNexio/` |
| Users | `https://app.nexiosolution.it/CollaboraNexio/utenti.php` |

---

## Environment Variables

| Variable | Development | Production |
|----------|-------------|------------|
| BASE_URL | `http://localhost:8888/CollaboraNexio` | `https://app.nexiosolution.it/CollaboraNexio` |
| PRODUCTION_MODE | `FALSE` | `TRUE` |
| DEBUG_MODE | `TRUE` | `FALSE` |
| SESSION_SECURE | `FALSE` | `TRUE` |
| SESSION_DOMAIN | `` (empty) | `.nexiosolution.it` |
| HTTP_HOST | `localhost:8888` | `app.nexiosolution.it` |

---

## Code Snippets

### Using BASE_URL (Correct)

```php
// Method 1: Direct (if config.php loaded)
$resetLink = BASE_URL . '/set_password.php?token=' . $token;

// Method 2: With fallback (recommended)
$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
$resetLink = $baseUrl . '/set_password.php?token=' . $token;
```

### Testing Environment

```php
// Check if production
if (PRODUCTION_MODE) {
    // Production-specific code
} else {
    // Development-specific code
}

// Or check BASE_URL
if (strpos(BASE_URL, 'nexiosolution.it') !== false) {
    // Production
}
```

---

## Support

### Need Help?

1. **Quick check**: `/verify_base_url.php`
2. **Testing guide**: `TEST_BASE_URL_GUIDE.md`
3. **Full docs**: `BASE_URL_CONFIGURATION_REPORT.md`
4. **Summary**: `BASE_URL_MIGRATION_SUMMARY.md`

### Common Questions

**Q: How do I change the BASE_URL?**
A: Don't! It's auto-detected. Just deploy to the right environment.

**Q: Can I force a specific URL?**
A: Yes, but not recommended. Modify `config.php` auto-detect logic.

**Q: How do I test locally with production URL?**
A: Use `/etc/hosts` to map `app.nexiosolution.it` to `127.0.0.1`

**Q: Email links are wrong, what do I do?**
A: Run `/verify_base_url.php` first to see what BASE_URL is detected.

---

## Status

- ✅ Auto-detect: **WORKING**
- ✅ Email links: **CORRECT**
- ✅ Session config: **CORRECT**
- ✅ CORS: **CONFIGURED**
- ✅ Documentation: **COMPLETE**
- ✅ Test scripts: **PROVIDED**

**System Status: READY FOR PRODUCTION** 🚀

---

*Last Updated: 2025-10-07*
*Version: 1.0*
