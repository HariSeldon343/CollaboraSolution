# Workflow Roles Acceptance Tests

**Date:** 2025-11-05
**Module:** Workflow System / Roles Assignment
**Status:** Ready for Execution
**Related Bugs:** BUG-066 (API Normalization), BUG-067 (Prerequisites)

---

## Purpose

Comprehensive acceptance testing suite for the Workflow Roles Assignment feature, covering:
- API response structure and data integrity
- UI population and interaction
- Save functionality and persistence
- Multi-tenant isolation

---

## Prerequisites

### System Requirements

- ✅ Database: `user_tenant_access` table populated (2+ records)
- ✅ Backend: `/api/workflow/roles/list.php` normalized (BUG-066 fixed)
- ✅ Frontend: `document_workflow.js` connected to normalized API
- ✅ Test Users:
  - **User 19:** Antonio Silvestro Amodeo (super_admin, Tenant 1)
  - **User 32:** Pippo Baudo (user, Tenant 11)
- ✅ Test Tenants:
  - **Tenant 1:** Demo Company (SOFT DELETED but accessible for testing)
  - **Tenant 11:** S.CO Srls (ACTIVE)

### Pre-Test Setup

1. **Clear Browser Cache:**
   ```
   CTRL + SHIFT + DELETE → Clear all browsing data
   OR
   Use Incognito Mode (CTRL + SHIFT + N)
   ```

2. **Verify OnlyOffice (if testing document workflow):**
   ```bash
   docker ps | grep onlyoffice
   ```

3. **Check Database Connection:**
   ```bash
   mysql -u root -p collaboranexio -e "SELECT COUNT(*) FROM user_tenant_access WHERE deleted_at IS NULL"
   ```
   Expected: 2 (or more) records

---

## Test 1: API Direct Call

**User Specification:**
> "Open `/api/workflow/roles/list.php?tenant_id=11` authenticated → get `success:true`, `available_users ≥ 1`, `current.*` coherent."

### Automated Test

**Execute:**
```bash
# From project root
php test_workflow_roles_api.php
```

**Expected Output:**
```
================================================================================
  ACCEPTANCE TEST 1: Workflow Roles API Direct Call
================================================================================
  Date: 2025-11-05 XX:XX:XX
  Endpoint: /api/workflow/roles/list.php
  Purpose: Verify API response structure and multi-tenant filtering
================================================================================

📋 TEST 1: API Response Structure (Tenant 11)
✅ PASS: Response should have success: true
✅ PASS: Response should have 'data' field
✅ PASS: Data should have 'available_users' array
✅ PASS: Data should have 'current' object
✅ PASS: current.validators should be an array
✅ PASS: current.approvers should be an array

📊 Full API Response:
{
    "success": true,
    "data": {
        "available_users": [
            {
                "id": 32,
                "name": "Pippo Baudo",
                "email": "a.oedoma@gmail.com",
                "role": "user",
                "is_validator": false,
                "is_approver": false
            }
        ],
        "current": {
            "validators": [],
            "approvers": []
        }
    }
}

...
(additional tests)
...

================================================================================
  TEST SUMMARY
================================================================================
  Total Tests: 15+
  Passed: 15+
  Failed: 0
  Pass Rate: 100.0%

  🎉 ALL TESTS PASSED! API is working correctly.
  Status: ✅ PRODUCTION READY
================================================================================
```

### Manual API Test (Browser)

**Steps:**
1. Login as user 32 (Pippo Baudo) for Tenant 11
2. Open browser DevTools (F12) → Network tab
3. Navigate to Files page
4. Right-click any file → "Gestisci Ruoli Workflow"
5. Inspect network call to `/api/workflow/roles/list.php`

**Expected Network Call:**
```
Request URL: /CollaboraNexio/api/workflow/roles/list.php?tenant_id=11&file_id=XXX
Status: 200 OK
Headers:
  X-CSRF-Token: [token]
  Content-Type: application/json
Response:
{
  "success": true,
  "data": {
    "available_users": [...],  // ≥ 1 user
    "current": {
      "validators": [],        // array
      "approvers": []          // array
    }
  }
}
```

### PASS Criteria

- ✅ HTTP Status: 200 OK
- ✅ `success: true`
- ✅ `data.available_users` is array with ≥ 1 user
- ✅ `data.current.validators` is array
- ✅ `data.current.approvers` is array
- ✅ No console errors
- ✅ No 404/500 errors

### FAIL Scenarios

- ❌ HTTP Status: 403 (Auth failed) → Check session
- ❌ HTTP Status: 500 (Server error) → Check PHP error logs
- ❌ `success: false` → Check error message in response
- ❌ `available_users` empty → Check `user_tenant_access` table
- ❌ Missing `current` object → API normalization issue

---

## Test 2: UI Populated

**User Specification:**
> "Open modal → see users in both selects; assigned users are pre-selected."

### Steps

1. **Login as Manager/Admin:**
   - URL: `http://localhost:8888/CollaboraNexio/`
   - User: `a.oedoma@gmail.com` (Pippo Baudo - User 32)
   - Password: `Admin123!`
   - Tenant: **S.CO Srls (Tenant 11)**

2. **Navigate to Files:**
   - Click "Gestione Documenti" in sidebar
   - Wait for file list to load

3. **Open Workflow Roles Modal:**
   - Right-click any file (or use file context menu)
   - Click "Gestisci Ruoli Workflow"

4. **Verify Modal Content:**
   - Modal title: "Gestione Ruoli Workflow"
   - Two sections: "Validatori" and "Approvatori"
   - Each section has:
     - Multi-select dropdown (Select2 or similar)
     - "Salva" button

5. **Verify Dropdowns Populated:**
   - **Validatori dropdown:** Shows users from Tenant 11
   - **Approvatori dropdown:** Shows users from Tenant 11
   - Users with existing roles should be pre-selected
   - Each user shows: "Name Surname (email)"

6. **Verify Current Roles Section:**
   - "Ruoli Attuali" section displays below dropdowns
   - Shows currently assigned validators (if any)
   - Shows currently assigned approvers (if any)
   - If none assigned: Shows "Nessun validatore assegnato"

### Expected UI State

```
┌────────────────────────────────────────────────────┐
│  Gestione Ruoli Workflow - [Filename]             │
├────────────────────────────────────────────────────┤
│                                                    │
│  Validatori                                        │
│  ┌──────────────────────────────────────────────┐ │
│  │ Select users...                      ▼       │ │ ← Populated with ≥1 user
│  └──────────────────────────────────────────────┘ │
│  [Salva Validatori]                                │
│                                                    │
│  Approvatori                                       │
│  ┌──────────────────────────────────────────────┐ │
│  │ Select users...                      ▼       │ │ ← Populated with ≥1 user
│  └──────────────────────────────────────────────┘ │
│  [Salva Approvatori]                               │
│                                                    │
│  Ruoli Attuali                                     │
│  ─────────────────────────────────────────────    │
│  Validatori: [List or "Nessun validatore"]        │
│  Approvatori: [List or "Nessun approvatore"]      │
│                                                    │
│                                       [Chiudi]     │
└────────────────────────────────────────────────────┘
```

### Console Verification

**Open DevTools (F12) → Console tab**

Expected logs:
```javascript
[WorkflowRoles] loadAvailableUsers called
[WorkflowRoles] Fetching users for tenant: 11
[WorkflowRoles] API Response: {success: true, data: {...}}
[WorkflowRoles] Found 1 available user(s)
[WorkflowRoles] Dropdown populated with 1 user(s)
```

No errors expected.

### PASS Criteria

- ✅ Modal opens without errors
- ✅ Both dropdowns show ≥ 1 user
- ✅ Users are from correct tenant (Tenant 11 for Pippo Baudo)
- ✅ User format: "Name Surname (email)"
- ✅ Current roles section displays correctly
- ✅ Pre-selected users match current roles
- ✅ No console errors
- ✅ No 404/500 network errors

### FAIL Scenarios

- ❌ Dropdowns empty → API returned no users (check Test 1)
- ❌ Wrong users shown → Multi-tenant isolation broken
- ❌ Console error: "Cannot read property of undefined" → Frontend issue
- ❌ Modal doesn't open → JavaScript error (check console)
- ❌ Pre-selection doesn't work → `current` object missing/malformed

### Troubleshooting

**Issue:** Dropdowns empty
**Fix:**
1. Check Test 1 (API Direct Call) - does API return users?
2. Check `user_tenant_access` table:
   ```sql
   SELECT * FROM user_tenant_access WHERE tenant_id = 11 AND deleted_at IS NULL;
   ```
3. Verify tenant_id in API request (Network tab)
4. Check browser console for errors

**Issue:** Wrong users shown (cross-tenant)
**Fix:**
1. Verify API call includes correct `tenant_id` parameter
2. Check API response in Network tab
3. Verify `user_tenant_access` records are correct
4. Check `getCurrentTenantId()` function in `document_workflow.js`

---

## Test 3: Save Functionality

**User Specification:**
> "Select 1-2 users per role → 'Salva' → no 4xx/5xx; toast 'Ruoli aggiornati'; 'Ruoli attuali' updates."

### Steps

1. **Prerequisites:**
   - Complete Test 2 (Modal opens with populated dropdowns)
   - Modal is currently open

2. **Select Validators:**
   - Click "Validatori" dropdown
   - Select 1-2 users from the list
   - Dropdown should show selected users

3. **Save Validators:**
   - Click "Salva Validatori" button
   - Button should show loading state (optional)

4. **Verify Save Success:**
   - Watch Network tab (DevTools → Network)
   - Check for POST requests to `/api/workflow/roles/create.php`
   - Verify HTTP Status: 200 OK (not 400/403/500)
   - Success toast appears: "Ruoli salvati con successo" (or similar)

5. **Verify UI Updates:**
   - "Ruoli Attuali" section updates immediately
   - Shows newly assigned validators
   - No page reload required

6. **Repeat for Approvers:**
   - Click "Approvatori" dropdown
   - Select 1-2 users
   - Click "Salva Approvatori"
   - Verify success (toast + UI update)

### Expected Network Calls

**Request 1: Save Validator**
```
POST /CollaboraNexio/api/workflow/roles/create.php
Headers:
  Content-Type: application/json
  X-CSRF-Token: [token]
Body:
{
  "tenant_id": 11,
  "user_id": 32,
  "workflow_role": "validator"
}
Response (200 OK):
{
  "success": true,
  "message": "Ruolo workflow creato con successo"
}
```

**Request 2: Reload Roles**
```
GET /CollaboraNexio/api/workflow/roles/list.php?tenant_id=11&file_id=XXX
Response (200 OK):
{
  "success": true,
  "data": {
    "available_users": [...],
    "current": {
      "validators": [
        {"id": 32, "name": "Pippo Baudo", "email": "a.oedoma@gmail.com"}
      ],
      "approvers": []
    }
  }
}
```

### Expected Console Logs

```javascript
[WorkflowRoles] saveValidators called
[WorkflowRoles] Saving validators: [32]
[WorkflowRoles] User 32 (validator) saved successfully
[WorkflowRoles] All validators saved successfully
[WorkflowRoles] Reloading available users and current roles
[WorkflowRoles] Current roles updated
```

### PASS Criteria

- ✅ POST request(s) to create.php succeed (200 OK)
- ✅ No 400/403/500 errors
- ✅ Success toast appears (green notification)
- ✅ "Ruoli Attuali" section updates immediately
- ✅ Newly assigned users appear in "Ruoli Attuali"
- ✅ No console errors
- ✅ No JavaScript exceptions
- ✅ Save is idempotent (clicking save twice doesn't cause errors)

### FAIL Scenarios

- ❌ HTTP 403 Forbidden → CSRF token missing/invalid
- ❌ HTTP 500 Server Error → Check PHP error logs
- ❌ HTTP 400 Bad Request → Check request body format
- ❌ Toast doesn't appear → Check `showToast()` function
- ❌ "Ruoli Attuali" doesn't update → `reloadCurrentRoles()` not called
- ❌ Console error → JavaScript exception in save handler

### Troubleshooting

**Issue:** 403 Forbidden
**Fix:**
1. Verify CSRF token in request headers (Network tab)
2. Check `getCsrfToken()` method returns valid token
3. Verify meta tag exists: `<meta name="csrf-token" content="...">`
4. Clear cache and reload

**Issue:** Save succeeds but UI doesn't update
**Fix:**
1. Check console for errors in `reloadCurrentRoles()`
2. Verify API call after save (Network tab)
3. Check `renderCurrentRoles()` method logic
4. Inspect "Ruoli Attuali" DOM element

**Issue:** Multiple save clicks cause errors
**Fix:**
1. Add button disabled state during save
2. Check for duplicate API calls in Network tab
3. Verify idempotent behavior (save same roles twice = no error)

---

## Test 4: Persistence

**User Specification:**
> "Close and reopen modal → selections persist."

### Steps

1. **Prerequisites:**
   - Complete Test 3 (Save successful)
   - Validators and/or Approvers assigned

2. **Close Modal:**
   - Click "Chiudi" button (or X icon)
   - Modal should close smoothly

3. **Wait:**
   - Wait 2-3 seconds (to ensure no caching issues)

4. **Reopen Modal:**
   - Right-click same file again
   - Click "Gestisci Ruoli Workflow"

5. **Verify Persisted Data:**
   - "Ruoli Attuali" section shows previously saved roles
   - Dropdowns have previously selected users pre-selected
   - No changes lost

6. **Verify Database (Optional):**
   ```sql
   SELECT
     wr.id,
     wr.workflow_role,
     u.name,
     u.surname
   FROM workflow_roles wr
   JOIN users u ON wr.user_id = u.id
   WHERE wr.tenant_id = 11
   AND wr.deleted_at IS NULL;
   ```

### Expected State After Reopen

```
Ruoli Attuali
─────────────
Validatori:
  - Pippo Baudo (a.oedoma@gmail.com) [X]

Approvatori:
  - [None assigned or previously saved users]
```

### Expected Network Call

**On modal reopen:**
```
GET /CollaboraNexio/api/workflow/roles/list.php?tenant_id=11&file_id=XXX
Response (200 OK):
{
  "success": true,
  "data": {
    "available_users": [
      {"id": 32, "name": "Pippo Baudo", "is_validator": true, "is_approver": false}
    ],
    "current": {
      "validators": [
        {"id": 32, "name": "Pippo Baudo", "email": "a.oedoma@gmail.com"}
      ],
      "approvers": []
    }
  }
}
```

### PASS Criteria

- ✅ Modal reopens without errors
- ✅ "Ruoli Attuali" shows previously saved roles
- ✅ Dropdowns have correct users pre-selected
- ✅ No data loss
- ✅ Database records exist (`workflow_roles` table)
- ✅ No console errors

### FAIL Scenarios

- ❌ "Ruoli Attuali" empty → Data not persisted to database
- ❌ Dropdowns not pre-selected → Frontend pre-selection logic broken
- ❌ Different users shown → Cache issue or wrong tenant_id
- ❌ Database empty → Save didn't commit (check Test 3)

### Troubleshooting

**Issue:** Roles not persisted
**Fix:**
1. Check database:
   ```sql
   SELECT * FROM workflow_roles WHERE tenant_id = 11 AND deleted_at IS NULL;
   ```
2. If empty → Save failed (check Test 3)
3. Check PHP error logs for commit issues
4. Verify transaction was committed

**Issue:** UI shows old data
**Fix:**
1. Clear browser cache (CTRL + SHIFT + DELETE)
2. Use Incognito mode
3. Check API response in Network tab (should have latest data)
4. Verify `reloadCurrentRoles()` is called on modal open

---

## Test 5: Multi-Tenant Isolation

**User Specification:**
> "Change tenant (Super Admin) → user list changes; no cross-tenant users."

### Prerequisites

- Super Admin account (User 19 - Antonio Silvestro Amodeo)
- Access to multiple tenants (Tenant 1 and Tenant 11)

### Steps

1. **Login as Super Admin:**
   - URL: `http://localhost:8888/CollaboraNexio/`
   - User: `asamodeo@fortibyte.it`
   - Password: `Admin123!` (or your super_admin password)

2. **Navigate to Tenant 1 (Demo Company):**
   - If tenant switcher exists: Select "Demo Company (Tenant 1)"
   - Or navigate directly to Tenant 1 folder
   - Note: Tenant 1 is soft-deleted but accessible to super_admin

3. **Open Workflow Roles Modal (Tenant 1):**
   - Right-click any file in Tenant 1
   - Click "Gestisci Ruoli Workflow"

4. **Verify Tenant 1 Users:**
   - Dropdown should show ONLY Tenant 1 users
   - Expected: Antonio Silvestro Amodeo (User 19)
   - Should NOT show Pippo Baudo (User 32 - Tenant 11)

5. **Record API Call (Tenant 1):**
   - Open Network tab (F12)
   - Check API request:
     ```
     GET /api/workflow/roles/list.php?tenant_id=1&...
     ```
   - Response should contain ONLY Tenant 1 users

6. **Navigate to Tenant 11 (S.CO Srls):**
   - Switch to Tenant 11 (active tenant)
   - Navigate to Tenant 11 folder

7. **Open Workflow Roles Modal (Tenant 11):**
   - Right-click any file in Tenant 11
   - Click "Gestisci Ruoli Workflow"

8. **Verify Tenant 11 Users:**
   - Dropdown should show ONLY Tenant 11 users
   - Expected: Pippo Baudo (User 32)
   - Should NOT show Antonio Amodeo (User 19 - Tenant 1)

9. **Record API Call (Tenant 11):**
   - Check Network tab:
     ```
     GET /api/workflow/roles/list.php?tenant_id=11&...
     ```
   - Response should contain ONLY Tenant 11 users

### Expected API Responses

**Tenant 1:**
```json
{
  "success": true,
  "data": {
    "available_users": [
      {
        "id": 19,
        "name": "Antonio Silvestro Amodeo",
        "email": "asamodeo@fortibyte.it",
        "role": "super_admin",
        "is_validator": false,
        "is_approver": false
      }
    ],
    "current": {
      "validators": [],
      "approvers": []
    }
  }
}
```

**Tenant 11:**
```json
{
  "success": true,
  "data": {
    "available_users": [
      {
        "id": 32,
        "name": "Pippo Baudo",
        "email": "a.oedoma@gmail.com",
        "role": "user",
        "is_validator": false,
        "is_approver": false
      }
    ],
    "current": {
      "validators": [],
      "approvers": []
    }
  }
}
```

### Database Verification

**Check user_tenant_access:**
```sql
-- Tenant 1 users
SELECT u.id, u.name, u.surname, uta.tenant_id
FROM users u
JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE uta.tenant_id = 1 AND uta.deleted_at IS NULL;

-- Expected: User 19 (Antonio Amodeo)

-- Tenant 11 users
SELECT u.id, u.name, u.surname, uta.tenant_id
FROM users u
JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE uta.tenant_id = 11 AND uta.deleted_at IS NULL;

-- Expected: User 32 (Pippo Baudo)

-- Cross-tenant check (should be empty)
SELECT u.id, u.name, uta.tenant_id
FROM users u
JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE u.id = 19 AND uta.tenant_id = 11  -- User 19 should NOT have Tenant 11 access
UNION
SELECT u.id, u.name, uta.tenant_id
FROM users u
JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE u.id = 32 AND uta.tenant_id = 1;  -- User 32 should NOT have Tenant 1 access

-- Expected: 0 rows (no cross-tenant access)
```

### PASS Criteria

- ✅ Tenant 1 API call returns ONLY Tenant 1 users
- ✅ Tenant 11 API call returns ONLY Tenant 11 users
- ✅ NO cross-tenant user contamination
- ✅ API requests include correct `tenant_id` parameter
- ✅ `user_tenant_access` table has no cross-tenant records
- ✅ Super admin can access both tenants
- ✅ No console errors
- ✅ No security warnings

### FAIL Scenarios

- ❌ User 32 appears in Tenant 1 dropdown → Multi-tenant isolation broken
- ❌ User 19 appears in Tenant 11 dropdown → Multi-tenant isolation broken
- ❌ API returns users from multiple tenants → Security vulnerability
- ❌ Cross-tenant records in `user_tenant_access` → Data integrity issue

### Troubleshooting

**Issue:** Cross-tenant users appear
**Fix:**
1. Check API query in `/api/workflow/roles/list.php`
2. Verify `WHERE` clause includes `tenant_id` filter:
   ```sql
   WHERE (u.tenant_id = ? OR uta.tenant_id = ?)
   AND uta.deleted_at IS NULL
   ```
3. Check `user_tenant_access` for cross-tenant records
4. Verify frontend sends correct `tenant_id` parameter

**Issue:** Super admin can't access Tenant 1
**Fix:**
1. Verify `user_tenant_access` has User 19 → Tenant 1 record
2. Check if Tenant 1 is soft-deleted (should still be accessible to super_admin)
3. Verify super_admin role has override permissions

---

## Success Criteria (User's DONE Criteria)

### All Tests Must Pass

- [x] **No console errors**
  - DevTools → Console tab shows NO red errors
  - Only informational logs (blue/gray) allowed

- [x] **No 404/500 on roles API calls**
  - Network tab shows all API calls return 200 OK
  - `/api/workflow/roles/list.php` succeeds
  - `/api/workflow/roles/create.php` succeeds

- [x] **Both selects show tenant users**
  - Validators dropdown populated with ≥ 1 user
  - Approvers dropdown populated with ≥ 1 user
  - Users are from correct tenant only

- [x] **Current roles reflected correctly**
  - "Ruoli Attuali" section displays assigned roles
  - Pre-selection in dropdowns matches current roles
  - Updates immediately after save

- [x] **Save is idempotent and persistent**
  - Multiple saves don't cause errors
  - Close/reopen modal preserves selections
  - Database records persist correctly

- [x] **Works for Super Admin and Manager**
  - Super Admin can assign roles across tenants
  - Manager can assign roles within their tenant
  - Regular users cannot access (if enforced)

- [x] **Respects multi-tenant rules**
  - No cross-tenant user contamination
  - API filters by tenant_id correctly
  - user_tenant_access enforces boundaries

---

## Test Execution Checklist

### Pre-Test

- [ ] Clear browser cache (CTRL + SHIFT + DELETE)
- [ ] Verify database: `user_tenant_access` has 2+ records
- [ ] Check OnlyOffice running (if needed): `docker ps`
- [ ] Open DevTools (F12): Console + Network tabs

### Test 1: API Direct Call

- [ ] Run automated test: `php test_workflow_roles_api.php`
- [ ] Verify: All tests PASS (15+/15+)
- [ ] Check: No database errors in output

### Test 2: UI Populated

- [ ] Login as User 32 (Tenant 11)
- [ ] Open modal via context menu
- [ ] Verify: Dropdowns show ≥ 1 user
- [ ] Check: "Ruoli Attuali" section present
- [ ] Verify: No console errors

### Test 3: Save Functionality

- [ ] Select 1-2 validators
- [ ] Click "Salva Validatori"
- [ ] Verify: HTTP 200 OK in Network tab
- [ ] Check: Success toast appears
- [ ] Verify: "Ruoli Attuali" updates
- [ ] Repeat for approvers

### Test 4: Persistence

- [ ] Close modal
- [ ] Reopen modal (same file)
- [ ] Verify: Saved roles still present
- [ ] Check: Dropdowns pre-selected correctly
- [ ] Verify: No data loss

### Test 5: Multi-Tenant

- [ ] Login as Super Admin (User 19)
- [ ] Navigate to Tenant 1
- [ ] Open modal → Verify ONLY Tenant 1 users
- [ ] Navigate to Tenant 11
- [ ] Open modal → Verify ONLY Tenant 11 users
- [ ] Check: No cross-tenant contamination

### Post-Test

- [ ] All 5 tests PASSED
- [ ] No console errors logged
- [ ] No 404/500 errors in Network tab
- [ ] Database integrity verified
- [ ] Clean up: Delete `test_workflow_roles_api.php`

---

## Expected Results After Testing

| Test | Expected Result | Actual Result | Status |
|------|----------------|---------------|--------|
| **Test 1: API Direct Call** | ✅ All 15+ tests PASS | _[To be filled]_ | ⬜ |
| **Test 2: UI Populated** | ✅ Dropdowns show ≥1 user | _[To be filled]_ | ⬜ |
| **Test 3: Save Functionality** | ✅ Success toast + UI update | _[To be filled]_ | ⬜ |
| **Test 4: Persistence** | ✅ Selections persist | _[To be filled]_ | ⬜ |
| **Test 5: Multi-Tenant** | ✅ Correct user filtering | _[To be filled]_ | ⬜ |

**Overall Status:** ⬜ PENDING

---

## Troubleshooting Guide

### Common Issues

#### Issue: Dropdowns Empty

**Symptoms:**
- Modal opens but dropdowns show no users
- Console: "Found 0 available user(s)"

**Diagnosis:**
1. Check Test 1 (API Direct Call) - does API return users?
2. Check Network tab - is API call successful?
3. Check `user_tenant_access` table for records

**Fix:**
```sql
-- Check user_tenant_access
SELECT * FROM user_tenant_access WHERE tenant_id = 11 AND deleted_at IS NULL;

-- If empty, populate:
INSERT INTO user_tenant_access (user_id, tenant_id, granted_by, granted_at)
VALUES (32, 11, 19, NOW());
```

#### Issue: 403 Forbidden on Save

**Symptoms:**
- Save button clicked
- Network tab shows 403 Forbidden
- Console: CSRF token error

**Diagnosis:**
1. Check request headers for X-CSRF-Token
2. Verify meta tag exists on page
3. Check `getCsrfToken()` method

**Fix:**
1. Clear cache (CTRL + SHIFT + DELETE)
2. Hard reload (CTRL + SHIFT + R)
3. Verify PHP session is active
4. Check CSRF token generation in `auth_simple.php`

#### Issue: Cross-Tenant Users Appear

**Symptoms:**
- User from Tenant 1 appears in Tenant 11 dropdown
- Multi-tenant isolation broken

**Diagnosis:**
1. Check API query WHERE clause
2. Verify `user_tenant_access` table integrity
3. Check frontend sends correct tenant_id

**Fix:**
```sql
-- Remove invalid cross-tenant records
DELETE FROM user_tenant_access
WHERE user_id = 19 AND tenant_id = 11;

DELETE FROM user_tenant_access
WHERE user_id = 32 AND tenant_id = 1;
```

#### Issue: Save Succeeds But UI Doesn't Update

**Symptoms:**
- HTTP 200 OK in Network tab
- Success toast appears
- "Ruoli Attuali" doesn't update

**Diagnosis:**
1. Check console for errors in `reloadCurrentRoles()`
2. Verify API call after save (Network tab)
3. Inspect "Ruoli Attuali" DOM element

**Fix:**
1. Check `renderCurrentRoles()` method in `document_workflow.js`
2. Verify API response includes updated `current` object
3. Clear browser cache and retry

#### Issue: Modal Doesn't Open

**Symptoms:**
- Right-click context menu works
- "Gestisci Ruoli Workflow" clicked
- No modal appears

**Diagnosis:**
1. Check console for JavaScript errors
2. Verify `showWorkflowRolesModal()` exists
3. Check modal HTML exists in DOM

**Fix:**
1. Clear cache (CTRL + SHIFT + DELETE)
2. Check `document_workflow.js` loaded correctly (Network tab)
3. Verify no JavaScript syntax errors
4. Check modal HTML in `files.php`

---

## Database Verification Queries

### User Tenant Access

```sql
-- Check all user_tenant_access records
SELECT
    uta.id,
    uta.user_id,
    u.name,
    u.surname,
    uta.tenant_id,
    t.name AS tenant_name,
    uta.granted_by,
    uta.granted_at,
    uta.deleted_at
FROM user_tenant_access uta
JOIN users u ON uta.user_id = u.id
LEFT JOIN tenants t ON uta.tenant_id = t.id
ORDER BY uta.tenant_id, u.name;
```

### Workflow Roles

```sql
-- Check all workflow_roles assignments
SELECT
    wr.id,
    wr.tenant_id,
    t.name AS tenant_name,
    wr.user_id,
    u.name,
    u.surname,
    wr.workflow_role,
    wr.assigned_by_user_id,
    ab.name AS assigned_by_name,
    wr.created_at,
    wr.deleted_at
FROM workflow_roles wr
JOIN users u ON wr.user_id = u.id
LEFT JOIN tenants t ON wr.tenant_id = t.id
LEFT JOIN users ab ON wr.assigned_by_user_id = ab.id
ORDER BY wr.tenant_id, wr.workflow_role, u.name;
```

### Multi-Tenant Integrity

```sql
-- Verify NO cross-tenant access
SELECT
    'Cross-tenant user_tenant_access' AS issue,
    uta.id,
    uta.user_id,
    u.tenant_id AS user_tenant,
    uta.tenant_id AS access_tenant
FROM user_tenant_access uta
JOIN users u ON uta.user_id = u.id
WHERE uta.deleted_at IS NULL
AND u.deleted_at IS NULL
AND u.tenant_id != uta.tenant_id
AND u.tenant_id IS NOT NULL  -- Exclude super_admin (tenant_id = NULL)
UNION ALL
SELECT
    'Cross-tenant workflow_roles' AS issue,
    wr.id,
    wr.user_id,
    u.tenant_id AS user_tenant,
    wr.tenant_id AS role_tenant
FROM workflow_roles wr
JOIN users u ON wr.user_id = u.id
WHERE wr.deleted_at IS NULL
AND u.deleted_at IS NULL
AND u.tenant_id != wr.tenant_id
AND u.tenant_id IS NOT NULL;

-- Expected: 0 rows (no cross-tenant issues)
```

---

## Acceptance Criteria Summary

### Technical Acceptance

- ✅ API returns normalized JSON structure
- ✅ `success: true` for all successful requests
- ✅ `available_users` array with ≥ 1 user
- ✅ `current.validators` and `current.approvers` are arrays
- ✅ Multi-tenant filtering enforced by `user_tenant_access`
- ✅ No 404/500 errors
- ✅ No console errors
- ✅ CSRF protection working (no 403 errors)

### Functional Acceptance

- ✅ Modal opens and displays correctly
- ✅ Dropdowns populated with tenant users
- ✅ Current roles section displays assigned roles
- ✅ Save functionality works (validators + approvers)
- ✅ Success toast appears after save
- ✅ UI updates immediately without page reload
- ✅ Persistence: Close/reopen preserves selections
- ✅ Multi-tenant: Only correct tenant users shown

### Security Acceptance

- ✅ No cross-tenant user contamination
- ✅ `user_tenant_access` enforces boundaries
- ✅ Super admin can access multiple tenants
- ✅ Regular users restricted to their tenant
- ✅ CSRF tokens validated on all POST requests
- ✅ Database integrity maintained (foreign keys, soft delete)

### User Experience Acceptance

- ✅ Smooth modal interaction (open/close)
- ✅ Clear visual feedback (toast notifications)
- ✅ No page reloads required
- ✅ Intuitive dropdown selection (multi-select)
- ✅ Current roles clearly displayed
- ✅ No confusing errors or broken UI

---

## Cleanup After Testing

Once all tests PASS:

```bash
# Delete test files
rm /mnt/c/xampp/htdocs/CollaboraNexio/test_workflow_roles_api.php

# Optional: Keep this documentation for future reference
# Or move to archive:
# mv WORKFLOW_ROLES_ACCEPTANCE_TESTS.md docs/testing/
```

---

## Related Documentation

- **BUG-066:** API Normalization (Complete Rewrite)
- **BUG-067:** user_tenant_access Prerequisites Verification
- **USER_TENANT_ACCESS_VERIFICATION_REPORT.md:** Comprehensive database verification
- **CLAUDE.md:** Workflow Roles System documentation

---

## Test Execution Log

**Date:** _[To be filled after execution]_
**Executed By:** _[To be filled]_
**Environment:** Development (localhost:8888)

**Results:**
- Test 1 (API Direct Call): ⬜ PASS / ❌ FAIL
- Test 2 (UI Populated): ⬜ PASS / ❌ FAIL
- Test 3 (Save Functionality): ⬜ PASS / ❌ FAIL
- Test 4 (Persistence): ⬜ PASS / ❌ FAIL
- Test 5 (Multi-Tenant): ⬜ PASS / ❌ FAIL

**Overall Status:** ⬜ PASS / ❌ FAIL

**Notes:** _[Any issues or observations]_

---

**Last Updated:** 2025-11-05
**Status:** Ready for Execution
**Confidence:** 100% (Prerequisites verified in BUG-067)
