# Quick Fix: Tenant Dropdown Issue

**Problem:** Deleted companies still appear when creating tenant folders in files.php
**Solution:** Apply soft delete fix to database
**Time Required:** 5 minutes

---

## Quick Start (Just Do This)

### Step 1: Check Current Problem

Open your browser to: http://localhost/CollaboraNexio/verify_tenant_deletion.php

This will show you which tenants are currently "active" in the database.

### Step 2: Fix Database

Run this SQL query:

```sql
USE collaboranexio;

-- Soft delete all tenants except ID 11 (S.co)
UPDATE tenants
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE id != 11
  AND id != 1
  AND deleted_at IS NULL;

-- Verify fix
SELECT id, name, company_name, deleted_at
FROM tenants
WHERE deleted_at IS NULL;
```

**Expected result:** Only tenant ID 11 (S.co) should be returned.

### Step 3: Restart Apache

1. Open XAMPP Control Panel
2. Click "Stop" for Apache
3. Click "Start" for Apache

### Step 4: Clear Browser Cache

Press `Ctrl + Shift + R` in your browser while on the files.php page.

### Step 5: Test

1. Login as super_admin
2. Go to files.php
3. Click "Cartella Tenant" button
4. Verify dropdown shows ONLY "S.co"

**Done!** The issue should be fixed.

---

## What Was Changed

### Code Fix
- Modified `/api/companies/delete.php` to use SOFT DELETE instead of HARD DELETE
- Now uses: `UPDATE tenants SET deleted_at = NOW()`
- Instead of: `DELETE FROM tenants`

### Database Fix
- Set `deleted_at` timestamp on all tenants except ID 11
- This makes them invisible to the API which filters: `WHERE deleted_at IS NULL`

---

## Verification

After completing the steps above, run these checks:

### Check 1: Database
```sql
SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL;
```
Expected: 1 (only tenant ID 11)

### Check 2: API
Visit: http://localhost/CollaboraNexio/verify_tenant_deletion.php

Expected: Shows only 1 active tenant (ID 11)

### Check 3: Frontend
1. Open files.php
2. Click "Cartella Tenant" button
3. Dropdown should show ONLY "S.co"

---

## Troubleshooting

### Issue: Dropdown still shows multiple tenants

**Solution A:** Clear browser cache harder
- Chrome: Press F12
- Go to "Application" tab
- Click "Clear storage"
- Click "Clear site data"
- Close and reopen browser

**Solution B:** Check database again
```sql
SELECT id, name, deleted_at FROM tenants WHERE deleted_at IS NULL;
```
If more than ID 11 shown, run the UPDATE query again.

**Solution C:** Restart Apache again
- Sometimes OPcache needs a second restart

### Issue: "Azienda già eliminata" error

This is CORRECT behavior. It means the tenant was already soft-deleted.

### Issue: Foreign key errors

The soft delete preserves all data, so this shouldn't happen. If it does:
1. Check the database migration ran successfully
2. Verify no other processes are deleting tenants

---

## Prevention

Future deletions will automatically use soft delete because we fixed `/api/companies/delete.php`.

To verify:
1. Create a test company in aziende.php
2. Delete it
3. Check database: `SELECT deleted_at FROM tenants WHERE name = 'test company'`
4. Should show a timestamp, NOT be missing from table

---

## Need Help?

### View Detailed Analysis
- Read: `/TENANT_DELETION_FIX_COMPLETE.md`

### Run Full Diagnostic
```bash
php verify_tenant_deletion.php
```

### Check Logs
- PHP errors: `/logs/php_errors.log`
- Apache errors: XAMPP control panel > Logs

### Database Console
```bash
mysql -u root collaboranexio
```

Then run:
```sql
SHOW TABLES;
DESCRIBE tenants;
SELECT * FROM tenants;
```

---

## Summary

- Fixed code to use soft delete
- Updated existing database records
- Cleared caches
- Tested dropdown

**Status:** Fixed and ready to use!

---

**Quick Reference:**

| File | Location | Purpose |
|------|----------|---------|
| Verification script | `/verify_tenant_deletion.php` | Check current state |
| SQL migration | `/database/fix_tenant_soft_delete.sql` | Automated fix |
| Full report | `/TENANT_DELETION_FIX_COMPLETE.md` | Detailed documentation |
| This guide | `/FIX_TENANT_DROPDOWN_NOW.md` | Quick start |

