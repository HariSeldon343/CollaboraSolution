# Audit Log System - User Testing Quick Guide
**CollaboraNexio - 5 Minute Verification**

## Quick Start

**URL:** http://localhost:8888/CollaboraNexio/audit_log.php
**Login:** `superadmin@collaboranexio.com` / `Admin123!`

---

## Step 1: Clear Browser Cache (30 seconds)

1. Press `CTRL + SHIFT + DELETE`
2. Select "All time"
3. Check: "Cached images and files" + "Cookies"
4. Click "Clear data"
5. **Restart browser**

---

## Step 2: Page Load Test (1 minute)

1. Navigate to audit_log.php
2. **Watch for:**
   - ✅ Statistics cards load with REAL numbers (not 342, 28, 156)
   - ✅ User dropdown shows REAL users (not "Mario Rossi")
   - ✅ Table shows REAL audit logs
   - ✅ No JavaScript errors in console (F12)

**PASS if:** Cards show real data, table populated

---

## Step 3: Detail Modal Test (30 seconds)

1. Click any "Dettagli" button
2. **Watch for:**
   - ✅ Modal opens smoothly
   - ✅ JSON data formatted nicely
   - ✅ Console shows: `GET .../detail.php?id=X 200 OK`

**PASS if:** Modal opens, no 400 error

---

## Step 4: Filters Test (1 minute)

1. Click "Utente" dropdown
2. Select any user
3. Click "Applica Filtri"
4. **Watch for:**
   - ✅ Table filters correctly
   - ✅ Only selected user's logs shown

**PASS if:** Filter works, table updates

---

## Step 5: Delete Test (super_admin ONLY) (2 minutes)

**CRITICAL TEST - BUG-039 Verification**

1. Click "Elimina Log" button (top right)
2. Select "Tutti i log"
3. Enter reason: "Test eliminazione sistema"
4. Click "Elimina"
5. **CRITICAL - Watch Console (F12):**
   - ✅ **MUST show: 200 OK** (NOT 500, NOT 400)
   - ✅ Success message appears
   - ✅ Modal closes

**PASS if:** 200 OK response, success message shown

**FAIL if:** 500 Internal Server Error → Report immediately

---

## Expected Results Summary

| Test | Expected | Status |
|------|----------|--------|
| Page Load | Real data in cards/table | [ ] PASS / [ ] FAIL |
| Detail Modal | Opens, JSON formatted, 200 OK | [ ] PASS / [ ] FAIL |
| Filters | Table filters correctly | [ ] PASS / [ ] FAIL |
| Delete (super_admin) | **200 OK** response | [ ] PASS / [ ] FAIL |

---

## Common Issues & Solutions

### Issue: Statistics show 342, 28, 156
**Solution:** Clear cache (CTRL+F5), reload page

### Issue: User dropdown shows "Mario Rossi"
**Solution:** Clear cache completely, restart browser

### Issue: Detail modal shows 400 error
**Solution:** Already fixed (BUG-032), clear cache

### Issue: Delete returns 500 error
**Solution:** BUG-039 should be fixed, check console for exact error

---

## Success Criteria

**READY FOR PRODUCTION if:**

- ✅ All 4 tests PASS
- ✅ Delete returns 200 OK (not 500)
- ✅ No JavaScript errors in console
- ✅ Real data loads everywhere

**DO NOT DEPLOY if:**

- ❌ Delete returns 500 error
- ❌ JavaScript errors in console
- ❌ Hardcoded data still showing

---

## Quick Verification Commands

```bash
# Check recent audit logs in database
mysql -u root -D collaboranexio -e "SELECT COUNT(*) FROM audit_logs WHERE deleted_at IS NULL;"

# Check for errors
tail -20 logs/php_errors.log

# Run automated tests
/mnt/c/xampp/php/php.exe test_audit_log_e2e.php
```

---

**Testing Time:** 5 minutes
**Critical Focus:** Delete API returns 200 OK (BUG-039 verification)
**Report Issues to:** Development team with console screenshot
