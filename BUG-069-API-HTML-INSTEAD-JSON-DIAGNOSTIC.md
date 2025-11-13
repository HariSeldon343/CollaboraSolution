# BUG-069: API Returning HTML Instead of JSON - Diagnostic Guide

**Date:** 2025-11-05
**Priority:** CRITICAL
**Module:** Workflow Roles API / Frontend Integration
**Status:** DIAGNOSIS IN PROGRESS

---

## Problem Statement

### Symptoms

1. **Frontend Error:**
   - Red toast: "Errore durante il caricamento degli utenti"
   - Empty dropdowns (both Validatori and Approvatori)
   - Console error: `SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON`
   - Error location: `[WorkflowManager] Failed to load roles:`

2. **Root Cause:**
   - API endpoint `/api/workflow/roles/list.php` is returning **HTML error page** instead of JSON
   - Frontend JavaScript tries to parse HTML as JSON → SyntaxError
   - Dropdown remains empty because no valid data received

---

## Diagnostic Steps

### Step 1: Database Schema Verification

**Execute:**
```
http://localhost:8888/CollaboraNexio/test_db_schema.php
```

**Purpose:** Verify actual column names in `users` table

**Expected columns:**
- `id` - Primary key
- `display_name` OR `name` OR `full_name` - User name column
- `email` - Email address
- `role` - System role (user/manager/admin/super_admin)
- `status` - User status (active/inactive) - OPTIONAL
- `tenant_id` - Tenant FK
- `deleted_at` - Soft delete marker

**Common issues:**
- ❌ Column `display_name` doesn't exist (table has `name` instead)
- ❌ Column `status` doesn't exist
- ❌ Column `role` is named differently

---

### Step 2: Direct API Query Test

**Execute:**
```
http://localhost:8888/CollaboraNexio/test_roles_api_direct.php
```

**Purpose:** Execute the exact SQL query from `list.php` without API authentication layer

**What it tests:**
1. ✅ Detects correct name column (`display_name` vs `name` vs `full_name`)
2. ✅ Checks if `status` column exists
3. ✅ Executes query with tenant_id = 11
4. ✅ Shows query results in table format
5. ✅ Builds API response structure (JSON preview)
6. ✅ Diagnoses if `user_tenant_access` is empty

**Expected results:**
- Query executes successfully
- Shows 1+ users for tenant 11
- JSON structure valid

**If query fails:**
- Error message shows exact SQL error
- Stack trace points to problematic line
- Column name mismatch will be obvious

---

## Common Root Causes

### Issue 1: Column Name Mismatch (Most Likely)

**Problem:** Query uses `u.display_name` but table has `u.name`

**File:** `/api/workflow/roles/list.php` line 118

**Current code:**
```sql
u.display_name AS name,
```

**Fix (if column is 'name'):**
```sql
u.name AS name,  -- or just u.name,
```

**Fix (if column is 'full_name'):**
```sql
u.full_name AS name,
```

---

### Issue 2: Status Column Doesn't Exist

**Problem:** Query uses `u.status = 'active'` but column doesn't exist

**File:** `/api/workflow/roles/list.php` line 139

**Current code:**
```sql
WHERE u.deleted_at IS NULL
  AND u.status = 'active'
```

**Fix:**
```sql
WHERE u.deleted_at IS NULL
  -- Remove: AND u.status = 'active'
```

---

### Issue 3: user_tenant_access Table Empty

**Problem:** No records in `user_tenant_access` for tenant 11

**Symptom:** Query returns 0 users (but no SQL error)

**Diagnosis:**
```sql
SELECT COUNT(*) FROM user_tenant_access WHERE tenant_id = 11 AND deleted_at IS NULL;
```

**If count = 0:**
- Users exist but not linked to tenant
- Need to populate `user_tenant_access` table

**Fix (example for user 19 → tenant 11):**
```sql
INSERT INTO user_tenant_access (user_id, tenant_id, created_at, updated_at)
VALUES (19, 11, NOW(), NOW());
```

---

### Issue 4: GROUP BY Compatibility

**Problem:** MySQL 5.7+ strict mode requires all non-aggregated columns in GROUP BY

**Symptom:** Error: "Expression #N of SELECT list is not in GROUP BY clause"

**File:** `/api/workflow/roles/list.php` line 140

**Current code:**
```sql
GROUP BY u.id, u.display_name, u.email, u.role
```

**Fix (add missing columns):**
```sql
GROUP BY u.id, u.display_name, u.email, u.role, u.tenant_id
-- Or use ANY_VALUE() for non-critical columns
```

---

## Execution Plan

### Phase 1: Diagnosis (Manual)

1. ✅ Execute `test_db_schema.php` → identify correct column names
2. ✅ Execute `test_roles_api_direct.php` → see exact error
3. ✅ Review error message and stack trace

### Phase 2: Fix (Based on Diagnosis)

**If column name mismatch:**
1. Edit `/api/workflow/roles/list.php`
2. Update line 118: Change `u.display_name` to correct column
3. Update line 140: Update GROUP BY to match

**If status column missing:**
1. Edit `/api/workflow/roles/list.php`
2. Remove line 139: `AND u.status = 'active'`

**If user_tenant_access empty:**
1. Identify users that should have access to tenant 11
2. Insert records into `user_tenant_access`

### Phase 3: Verification

1. ✅ Re-run `test_roles_api_direct.php` → should show users
2. ✅ Test actual API with auth: `/api/workflow/roles/list.php?tenant_id=11`
3. ✅ Open frontend modal → dropdowns should populate
4. ✅ Check console → no errors

---

## Expected Fix Pattern

### Scenario A: display_name → name

**File:** `/api/workflow/roles/list.php`

**Changes:**
```php
// Line 118 - BEFORE
u.display_name AS name,

// Line 118 - AFTER
u.name AS name,

// Line 140 - BEFORE
GROUP BY u.id, u.display_name, u.email, u.role

// Line 140 - AFTER
GROUP BY u.id, u.name, u.email, u.role
```

### Scenario B: Remove status column check

**File:** `/api/workflow/roles/list.php`

**Changes:**
```php
// Lines 138-139 - BEFORE
WHERE u.deleted_at IS NULL
  AND u.status = 'active'

// Lines 138-139 - AFTER
WHERE u.deleted_at IS NULL
```

---

## Testing After Fix

### Test 1: Direct Query (No Auth)
```
http://localhost:8888/CollaboraNexio/test_roles_api_direct.php
```
**Expected:** Table with 1+ users, JSON structure shown

### Test 2: API with Auth (Browser)
```
http://localhost:8888/CollaboraNexio/api/workflow/roles/list.php?tenant_id=11
```
**Expected:** JSON response (not HTML error page)

### Test 3: Frontend Integration
1. Open files.php
2. Right-click folder → "Gestisci Ruoli Workflow"
3. Modal opens → both dropdowns populated
4. Console clean (no errors)

---

## Files Created

1. `/test_db_schema.php` - Database schema verification
2. `/test_roles_api_direct.php` - Direct API query test
3. `/BUG-069-API-HTML-INSTEAD-JSON-DIAGNOSTIC.md` - This document

## Files to Modify (After Diagnosis)

1. `/api/workflow/roles/list.php` - Fix column names based on actual schema

---

## Success Criteria

- ✅ `test_roles_api_direct.php` shows array of users (not error)
- ✅ API returns JSON `{"success":true,"data":{...}}` (not HTML)
- ✅ Frontend dropdowns populate with user names
- ✅ Console clean (no SyntaxError)
- ✅ Toast shows success message (not error)

---

## Cleanup After Resolution

Delete temporary test files:
```bash
rm test_db_schema.php
rm test_roles_api_direct.php
```

---

**Last Updated:** 2025-11-05
**Status:** Diagnostic scripts created, awaiting manual execution
**Next Step:** User executes test_roles_api_direct.php to identify exact error
