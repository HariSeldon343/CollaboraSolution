# Email Configuration Fix - Verification Checklist

## Pre-Deployment Verification

### 1. Syntax Validation
Access each file in browser to check for PHP syntax errors:

- [ ] `http://localhost:8888/CollaboraNexio/test_email_config_loading.php`
  - Should display test results without errors
  - Verify all 6 tests pass

- [ ] `http://localhost:8888/CollaboraNexio/configurazioni.php`
  - Should load without errors (requires super_admin login)
  - Form fields should show database values

### 2. Database Verification

Run this query to check current email settings:

```sql
SELECT setting_key, setting_value, value_type, updated_at
FROM system_settings
WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'reply_to')
ORDER BY setting_key;
```

**Expected Results:**
- Should return 7 rows (one for each email setting)
- If empty, system will use hardcoded fallback (OK for first run)

### 3. Functional Testing

#### Test 1: Configuration Loading
- [ ] Access `/test_email_config_loading.php`
- [ ] Verify "Test 1: Load Email Config from Database" passes
- [ ] Verify all required keys are present
- [ ] Check "Test 5: Direct Database Query" shows settings

#### Test 2: Configuration Page
- [ ] Login as super_admin
- [ ] Navigate to `configurazioni.php`
- [ ] Verify email form shows current database values
- [ ] Check password field has helper text "Lascia vuoto per mantenere la password esistente"

#### Test 3: Save Configuration
- [ ] In `configurazioni.php`, modify SMTP settings
- [ ] Click "Salva Modifiche"
- [ ] Should show success alert
- [ ] Reload page - verify changes persisted

#### Test 4: Password Preservation
- [ ] Update email settings WITHOUT entering password
- [ ] Click "Salva Modifiche"
- [ ] Verify password was NOT deleted in database:
   ```sql
   SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password';
   ```
- [ ] Should still contain password value

#### Test 5: User Creation Email
- [ ] Navigate to `utenti.php` (or users management page)
- [ ] Create a new test user
- [ ] Check error logs for email config loading:
   ```
   EmailConfig: Configurazione caricata da database - Host: [your_host]
   ```
- [ ] Verify no errors about missing configuration

#### Test 6: Password Reset Email
- [ ] Use password reset flow (forgot password)
- [ ] Check that email configuration loads from database
- [ ] Verify logs show database config usage

### 4. Error Handling Tests

#### Test 1: Database Unavailable
- [ ] Stop MySQL temporarily
- [ ] Create EmailSender instance
- [ ] Should fall back to hardcoded values
- [ ] Check error log for fallback message:
   ```
   EmailConfig: Errore caricamento da database
   EmailConfig: Utilizzo configurazione fallback Infomaniak
   ```

#### Test 2: Empty Database Configuration
- [ ] Clear email settings from database:
   ```sql
   DELETE FROM system_settings WHERE setting_key LIKE 'smtp%';
   ```
- [ ] Access test page
- [ ] Should show "Email NOT configured in database (using fallback)"
- [ ] System should still work with hardcoded values

#### Test 3: Partial Configuration
- [ ] Insert only some email settings (missing password)
- [ ] Verify fallback fills in missing values
- [ ] System should use mix of DB + fallback values

### 5. Performance Tests

#### Test 1: Caching
- [ ] Access `/test_email_config_loading.php`
- [ ] Check "Test 6: Configuration Caching"
- [ ] Second call should be faster than first
- [ ] Verify cached config matches original

#### Test 2: Multiple Instances
- [ ] Create multiple EmailSender instances in same request
- [ ] Should only query database once (static cache)
- [ ] Check query count in database logs

### 6. Security Tests

#### Test 1: Access Control
- [ ] Login as non-super_admin user
- [ ] Try to access `configurazioni.php`
- [ ] Should redirect to dashboard (403 or redirect)

#### Test 2: CSRF Protection
- [ ] Access `configurazioni.php` as super_admin
- [ ] Open browser dev tools
- [ ] Try to save config without CSRF token in request
- [ ] Should return 403 error

#### Test 3: Input Sanitization
- [ ] Enter `<script>alert('xss')</script>` in email fields
- [ ] Save configuration
- [ ] Reload page
- [ ] Should show escaped HTML, not execute script

### 7. Integration Tests

#### Test 1: API User Creation
Test all 4 user creation APIs:

- [ ] `/api/users/create.php`
  ```bash
  curl -X POST http://localhost:8888/CollaboraNexio/api/users/create.php \
    -H "Content-Type: application/json" \
    -d '{"email":"test@example.com","name":"Test User","role":"user"}'
  ```

- [ ] `/api/users/create_simple.php`
- [ ] `/api/users/create_v2.php`
- [ ] `/api/users/create_v3.php`

**Verify:**
- No PHP errors
- Email config loads from database (check logs)
- User created successfully

#### Test 2: Password Reset API
- [ ] `/api/auth/request_password_reset.php`
  ```bash
  curl -X POST http://localhost:8888/CollaboraNexio/api/auth/request_password_reset.php \
    -H "Content-Type: application/json" \
    -d '{"email":"existing@user.com"}'
  ```

**Verify:**
- Email config loads from database
- Password reset email prepared correctly

### 8. Log Verification

Check logs for proper configuration loading:

```bash
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

**Expected Log Entries:**
```
EmailConfig: Configurazione caricata da database - Host: mail.infomaniak.com, Port: 465, From: info@fortibyte.it
```

**Should NOT see:**
```
EmailConfig: Errore caricamento da database (unless testing error scenarios)
EmailSender: Impossibile caricare configurazione da database
```

### 9. Backwards Compatibility

#### Test 1: Explicit Config Override
```php
$customConfig = [
    'smtpHost' => 'custom.smtp.com',
    'smtpPort' => 587
];
$sender = new EmailSender($customConfig);
```

- [ ] Verify custom config takes priority over database
- [ ] Test still works with explicit config

#### Test 2: Legacy Code
```php
$sender = new EmailSender();  // No config passed
```

- [ ] Verify auto-loads from database
- [ ] Falls back to hardcoded if DB fails

### 10. Production Readiness

- [ ] All syntax checks pass
- [ ] All functional tests pass
- [ ] No PHP warnings or errors in logs
- [ ] CSRF protection working
- [ ] Access control enforced
- [ ] Caching working correctly
- [ ] Fallback mechanism tested
- [ ] Password preservation working
- [ ] All 5 production APIs updated
- [ ] Documentation complete

---

## Post-Deployment Verification

After deploying to production:

1. **Monitor Error Logs**
   - Watch for email configuration errors
   - Check for database connection issues
   - Verify no SMTP authentication failures

2. **Test User Registration**
   - Create test user via UI
   - Verify welcome email sent with correct SMTP
   - Check email received successfully

3. **Test Password Reset**
   - Request password reset for test user
   - Verify reset email sent correctly
   - Confirm email uses production SMTP settings

4. **Verify Configuration UI**
   - Login as super_admin
   - Check configurazioni.php loads correctly
   - Verify current settings display accurately
   - Test email button works

5. **Performance Monitoring**
   - Check email sending latency
   - Verify caching reduces DB queries
   - Monitor memory usage (static cache)

---

## Rollback Plan

If issues occur in production:

### Quick Rollback (Restore Hardcoded Behavior)

**Option 1: Revert EmailSender.php**
```php
// In EmailSender.php constructor, comment out auto-load:
public function __construct($config = []) {
    // TEMPORARY ROLLBACK - Remove after fixing issue
    // if (empty($config)) {
    //     require_once __DIR__ . '/email_config.php';
    //     $dbConfig = getEmailConfigFromDatabase();
    //     if (!empty($dbConfig)) {
    //         $config = $dbConfig;
    //     }
    // }

    if (!empty($config)) {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
```

**Option 2: Force Hardcoded Values**
```sql
-- Clear database settings to force fallback
DELETE FROM system_settings WHERE setting_key LIKE 'smtp%';
DELETE FROM system_settings WHERE setting_key LIKE 'from_%';
DELETE FROM system_settings WHERE setting_key = 'reply_to';
```

**Option 3: Git Revert**
```bash
# Revert all changes
git checkout HEAD~1 includes/EmailSender.php
git checkout HEAD~1 includes/email_config.php
git checkout HEAD~1 api/users/*.php
git checkout HEAD~1 api/auth/request_password_reset.php
git checkout HEAD~1 configurazioni.php
```

---

## Success Criteria

✅ **Fix is successful when:**

1. Email configuration loads from database in production
2. Fallback works if database unavailable
3. Configuration UI (configurazioni.php) works correctly
4. All user creation APIs send emails with DB config
5. Password reset emails use DB config
6. No performance degradation
7. Password preservation works
8. All tests in checklist pass

---

## Support & Troubleshooting

**Common Issues:**

1. **"Configuration not loading"**
   - Check `system_settings` table has email keys
   - Verify database connection works
   - Check file permissions on `includes/email_config.php`

2. **"Password keeps getting deleted"**
   - Verify JavaScript sends password only if changed
   - Check save logic in `api/system/config.php`
   - Ensure `email_config.php` handles password correctly

3. **"Emails not sending"**
   - Verify SMTP credentials are correct in database
   - Test SMTP connection manually
   - Check Windows/XAMPP environment detection

**Get Help:**
- Review `/EMAIL_CONFIG_DRIFT_FIX.md` for detailed documentation
- Run `/test_email_config_loading.php` for diagnostics
- Check error logs in `/logs/php_errors.log`

---

**Checklist Version:** 1.0
**Last Updated:** 2025-10-05
**Status:** Ready for Testing
