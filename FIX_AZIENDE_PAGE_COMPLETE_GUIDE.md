# Fix Aziende.php Page - Complete Implementation Guide

**Issue:** aziende.php shows "Nessuna azienda trovata" even though tenant data exists
**Root Cause:** Schema drift between database structure and API expectations
**Date:** 2025-10-07
**Status:** Ready to Apply

---

## Problem Summary

The aziende.php page is not displaying companies due to three critical issues:

1. **Missing `deleted_at` column** in tenants table (breaks soft-delete pattern)
2. **API column mismatch** - trying to use `first_name` and `last_name` columns that don't exist
3. **Missing indexes** for efficient query performance

---

## Solution Overview

### Step 1: Run Database Migration (Required)
Apply the SQL migration to fix schema issues

### Step 2: Update API Code (Already Done)
Fix the column name mismatch in `/api/tenants/list.php`

### Step 3: Test the Fix
Verify that aziende.php now displays companies correctly

---

## STEP 1: Apply Database Migration

### 1.1 Create Backup (Recommended)

```bash
# From WSL terminal
cd /mnt/c/xampp/htdocs/CollaboraNexio
mysqldump -u root collaboranexio tenants > tenants_backup_20251007.sql
```

### 1.2 Run Migration Script

**Option A: From WSL Terminal**
```bash
mysql -u root collaboranexio < database/fix_tenants_schema_drift.sql
```

**Option B: From MySQL Command Line**
```bash
# Connect to MySQL
mysql -u root collaboranexio

# Run script
SOURCE /mnt/c/xampp/htdocs/CollaboraNexio/database/fix_tenants_schema_drift.sql;
```

**Option C: Using phpMyAdmin**
1. Open phpMyAdmin: http://localhost:8888/phpmyadmin
2. Select database: `collaboranexio`
3. Click "SQL" tab
4. Copy contents of `/database/fix_tenants_schema_drift.sql`
5. Paste and click "Go"

### 1.3 Expected Output

You should see verification messages like:

```
test                        | status
----------------------------|--------
deleted_at column check     | PASS
indexes check              | PASS (3 indexes)
data integrity check       | PASS (1 tenant)
```

And a display of the tenant record:

```
id | denominazione | codice_fiscale    | partita_iva | status | manager_id | deleted_at
1  | Demo Company  | DMOCMP00A01H501X  | 01234567890 | active | 1          | NULL
```

---

## STEP 2: API Code Fix (Already Applied)

The file `/api/tenants/list.php` has been updated:

### Before (BROKEN):
```php
CONCAT(u.first_name, ' ', u.last_name) as manager_name
```

### After (FIXED):
```php
u.name as manager_name
```

**Additional Fix:** Added soft-delete filter to WHERE clause:
```sql
WHERE t.deleted_at IS NULL
```

---

## STEP 3: Verify the Fix

### 3.1 Test Database Changes

```bash
# Check deleted_at column exists
mysql -u root collaboranexio -e "DESCRIBE tenants;"

# Check indexes created
mysql -u root collaboranexio -e "SHOW INDEXES FROM tenants WHERE Key_name LIKE 'idx_tenants%';"

# Test the fixed query
mysql -u root collaboranexio -e "
SELECT
    t.id,
    t.denominazione,
    t.partita_iva,
    t.codice_fiscale,
    t.manager_id,
    u.name as manager_name
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id AND u.deleted_at IS NULL
WHERE t.deleted_at IS NULL;
"
```

Expected output:
```
id | denominazione | partita_iva | codice_fiscale    | manager_id | manager_name
1  | Demo Company  | 01234567890 | DMOCMP00A01H501X  | 1          | Admin User
```

### 3.2 Test API Endpoint

**From Browser:**
```
http://localhost:8888/CollaboraNexio/api/tenants/list.php
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "tenants": [
      {
        "id": 1,
        "denominazione": "Demo Company",
        "partita_iva": "01234567890",
        "codice_fiscale": "DMOCMP00A01H501X",
        "status": "active",
        "manager_id": 1,
        "manager_name": "Admin User",
        ...
      }
    ],
    "total": 1
  },
  "message": "Lista aziende recuperata con successo"
}
```

### 3.3 Test Frontend Page

1. Open: http://localhost:8888/CollaboraNexio/aziende.php
2. Login as super_admin: `admin@demo.local` / `Admin123!`
3. You should now see:
   - Table with 1 company (Demo Company)
   - Company ID: 1
   - Denominazione: Demo Company
   - Codice Fiscale / Partita IVA displayed
   - Manager: Admin User
   - Status: Active

---

## What Was Fixed

### Database Schema

**Added:**
- `deleted_at` column (TIMESTAMP NULL)
- `idx_tenants_deleted_at` index
- `idx_tenants_status_deleted` composite index
- `idx_tenants_manager` index

**Updated:**
- Tenant 1 now has `manager_id = 1` (Admin User)
- Tenant 1 now has `codice_fiscale = 'DMOCMP00A01H501X'`

### API Code

**File:** `/api/tenants/list.php`

**Changed:**
- Line 49: `CONCAT(u.first_name, ' ', u.last_name)` → `u.name`
- Line 54: Added `WHERE t.deleted_at IS NULL`

---

## Migration Script Details

**File:** `/database/fix_tenants_schema_drift.sql`

### What it does:

1. **Adds soft-delete support**
   ```sql
   ALTER TABLE tenants
   ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
   ```

2. **Creates performance indexes**
   ```sql
   CREATE INDEX idx_tenants_deleted_at ON tenants(deleted_at);
   CREATE INDEX idx_tenants_status_deleted ON tenants(status, deleted_at);
   CREATE INDEX idx_tenants_manager ON tenants(manager_id);
   ```

3. **Fixes data integrity**
   ```sql
   UPDATE tenants SET manager_id = 1 WHERE id = 1 AND manager_id IS NULL;
   UPDATE tenants SET codice_fiscale = 'DMOCMP00A01H501X' WHERE id = 1 AND codice_fiscale IS NULL;
   ```

4. **Runs verification checks**
   - Confirms column added
   - Confirms indexes created
   - Confirms data integrity

---

## Rollback Plan

If something goes wrong:

### 1. Restore Database Backup
```bash
mysql -u root collaboranexio < tenants_backup_20251007.sql
```

### 2. Revert API Changes
```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
git checkout api/tenants/list.php
```

### 3. Remove Added Indexes (if needed)
```sql
ALTER TABLE tenants DROP INDEX idx_tenants_deleted_at;
ALTER TABLE tenants DROP INDEX idx_tenants_status_deleted;
ALTER TABLE tenants DROP INDEX idx_tenants_manager;
```

### 4. Remove deleted_at Column (if needed)
```sql
ALTER TABLE tenants DROP COLUMN deleted_at;
```

---

## Post-Migration Best Practices

### Always Filter Soft-Deleted Records

**WRONG:**
```php
$tenants = $db->fetchAll("SELECT * FROM tenants WHERE status = 'active'");
```

**CORRECT:**
```php
$tenants = $db->fetchAll(
    "SELECT * FROM tenants WHERE status = 'active' AND deleted_at IS NULL"
);
```

### Use the `name` Column for Users

**WRONG:**
```php
CONCAT(u.first_name, ' ', u.last_name) as full_name
```

**CORRECT:**
```php
u.name as full_name
```

---

## Files Modified

1. `/api/tenants/list.php` - Fixed column names and added soft-delete filter
2. `/database/fix_tenants_schema_drift.sql` - Migration script (new)
3. `/TENANTS_DATABASE_INTEGRITY_REPORT.md` - Comprehensive analysis (new)
4. `/FIX_AZIENDE_PAGE_COMPLETE_GUIDE.md` - This file (new)

---

## Database Schema Changes

### Before:
```sql
CREATE TABLE tenants (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  denominazione VARCHAR(255) NOT NULL,
  -- ... other columns ...
  manager_id INT UNSIGNED,
  status ENUM('active','inactive','suspended'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  -- NO deleted_at column!
);
```

### After:
```sql
CREATE TABLE tenants (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  denominazione VARCHAR(255) NOT NULL,
  -- ... other columns ...
  manager_id INT UNSIGNED,
  status ENUM('active','inactive','suspended'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL, -- ADDED!

  INDEX idx_tenants_deleted_at (deleted_at), -- ADDED!
  INDEX idx_tenants_status_deleted (status, deleted_at), -- ADDED!
  INDEX idx_tenants_manager (manager_id) -- ADDED!
);
```

---

## Testing Checklist

- [ ] Database backup created
- [ ] Migration script executed successfully
- [ ] Verification queries show PASS status
- [ ] `deleted_at` column exists in tenants table
- [ ] 3 new indexes created
- [ ] Tenant 1 has manager_id = 1
- [ ] Tenant 1 has valid codice_fiscale
- [ ] API endpoint returns JSON with tenant data
- [ ] aziende.php displays Demo Company in table
- [ ] Manager name shows correctly (Admin User)
- [ ] No JavaScript console errors
- [ ] Can add new company via modal
- [ ] Can edit existing company
- [ ] Can search companies

---

## Troubleshooting

### Issue: "Column 'deleted_at' already exists"

**Solution:** Column was added by another migration. Check current structure:
```bash
mysql -u root collaboranexio -e "DESCRIBE tenants;"
```

### Issue: "Duplicate key name 'idx_tenants_deleted_at'"

**Solution:** Index already exists. Drop and recreate or skip:
```sql
ALTER TABLE tenants DROP INDEX idx_tenants_deleted_at;
-- Then re-run migration
```

### Issue: API still returns empty data

**Checklist:**
1. Clear browser cache (Ctrl+Shift+R)
2. Check browser console for errors (F12)
3. Verify API response directly: http://localhost:8888/CollaboraNexio/api/tenants/list.php
4. Check PHP error logs: `/mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log`
5. Verify session is valid (try logout/login)

### Issue: "Access denied" or permission errors

**Solution:** Ensure MySQL user has ALTER privileges:
```sql
GRANT ALL PRIVILEGES ON collaboranexio.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
```

---

## Additional Resources

- **Full Analysis:** `/TENANTS_DATABASE_INTEGRITY_REPORT.md`
- **Migration Script:** `/database/fix_tenants_schema_drift.sql`
- **Project Documentation:** `/CLAUDE.md`

---

## Summary

This fix addresses critical schema drift between the database structure and API expectations. After applying:

1. ✓ Soft-delete pattern fully implemented
2. ✓ API queries use correct column names
3. ✓ Performance indexes in place
4. ✓ Data integrity restored
5. ✓ aziende.php displays companies correctly

**Time to implement:** 5-10 minutes
**Risk level:** Low (backward-compatible changes)
**Testing required:** Yes (functional testing of aziende.php)

---

**Last Updated:** 2025-10-07
**Status:** READY TO APPLY
**Priority:** HIGH
