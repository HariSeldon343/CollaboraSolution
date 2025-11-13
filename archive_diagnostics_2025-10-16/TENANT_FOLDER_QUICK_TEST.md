# TENANT FOLDER CREATION - QUICK TEST GUIDE
**Date:** 2025-10-16
**Time Required:** 2 minutes

---

## VISUAL WORKFLOW

```
┌─────────────────────────────────────────────────────────────────┐
│  USER CLICKS "Cartella Tenant" BUTTON                          │
│  [Location: files.php header, line 368]                        │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  JavaScript Event Listener Fires                                │
│  [filemanager_enhanced.js:63-65]                               │
│  Calls: showCreateTenantFolderModal()                          │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Modal Opens & Tenants Load                                     │
│  [filemanager_enhanced.js:2216-2275]                           │
│  - Shows modal (id: createTenantFolderModal)                   │
│  - Calls loadTenantOptions()                                    │
│  - Fetches: /api/tenants/list.php                             │
│  - Populates dropdown with tenant.denominazione                │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  USER FILLS FORM                                                │
│  [Modal HTML: files.php:648-678]                               │
│  - Selects tenant from dropdown (id: tenantSelect)             │
│  - Enters folder name (id: folderName)                         │
│  - Clicks "Crea Cartella" button                              │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Form Submission Handler                                        │
│  [Inline JS: files.php:856-878]                                │
│  Function: createTenantFolder()                                │
│  - Validates tenant selected                                    │
│  - Validates folder name not empty                             │
│  - Calls: window.fileManager.createRootFolder()                │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  API Call Method                                                │
│  [filemanager_enhanced.js:2277-2307]                           │
│  Function: createRootFolder(folderName, tenantId)              │
│  POST to: /api/files_tenant.php?action=create_root_folder     │
│  Body: {name, tenant_id, csrf_token}                           │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Backend API Handler                                            │
│  [api/files_tenant.php:256-329]                                │
│  Function: createRootFolder()                                  │
│  - Checks user is admin/super_admin                            │
│  - Validates folder name & tenant_id                           │
│  - Checks for duplicate folder name                            │
│  - Inserts into files table:                                   │
│    • is_folder = 1                                             │
│    • folder_id = NULL (root level)                             │
│    • tenant_id = [selected]                                    │
│  - Logs audit entry                                            │
│  - Returns success response                                    │
└────────────┬────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Success Handling                                               │
│  [files.php:856-878]                                           │
│  - Closes modal                                                 │
│  - Resets form fields                                          │
│  - Calls loadFiles() to refresh list                           │
│  - Shows success toast notification                            │
└─────────────────────────────────────────────────────────────────┘
```

---

## QUICK TESTS (In Order)

### Test 1: Button Exists ✓
**Open:** `http://localhost/CollaboraNexio/files.php`
**Check:** Header has "Cartella Tenant" button (purple gradient)
**Expected:** Button visible only for admin/super_admin users

---

### Test 2: Modal Opens ✓
**Action:** Click "Cartella Tenant" button
**Expected:**
- Modal appears with title "Crea Cartella Tenant Root"
- Contains tenant dropdown
- Contains folder name input
- Has "Annulla" and "Crea Cartella" buttons

---

### Test 3: Tenants Load ✓
**Check:** Tenant dropdown after modal opens
**Expected:**
- First option: "-- Seleziona un tenant --"
- Additional options with tenant names
- Console log: "Loaded X tenant(s)"

---

### Test 4: Form Validation ✓
**Action 1:** Click "Crea Cartella" without selecting tenant
**Expected:** Toast error: "Seleziona un tenant"

**Action 2:** Select tenant, leave folder name empty, click "Crea Cartella"
**Expected:** Toast error: "Inserisci il nome della cartella"

---

### Test 5: Folder Creation ✓
**Action:**
1. Select a tenant
2. Enter folder name: "Test Folder"
3. Click "Crea Cartella"

**Expected:**
- Modal closes
- Success toast: "Cartella tenant creata con successo"
- File list refreshes
- New folder appears in root

---

### Test 6: Duplicate Prevention ✓
**Action:**
1. Create folder "Test Folder" for Tenant A
2. Try creating another "Test Folder" for same Tenant A

**Expected:**
- Error message: "Una cartella root con questo nome esiste già per il tenant"

---

## BROWSER CONSOLE TESTS

Open browser console (F12) and run these commands:

### Check FileManager Loaded:
```javascript
console.log(window.fileManager);
// Should show: EnhancedFileManager {config: {...}, state: {...}}
```

### Check CSRF Token:
```javascript
console.log('CSRF:', document.getElementById('csrfToken')?.value);
// Should show: long random string
```

### Check User Role:
```javascript
console.log('Role:', document.getElementById('userRole')?.value);
// Should show: 'admin' or 'super_admin'
```

### Test Modal Function:
```javascript
window.fileManager.showCreateTenantFolderModal();
// Should: Open the modal
```

### Test Tenant API:
```javascript
fetch('/CollaboraNexio/api/tenants/list.php', {credentials: 'same-origin'})
  .then(r => r.json())
  .then(console.log);
// Should show: {success: true, data: {tenants: [...]}}
```

### Test Create API:
```javascript
fetch('/CollaboraNexio/api/files_tenant.php?action=create_root_folder', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  credentials: 'same-origin',
  body: JSON.stringify({
    name: 'API Test Folder',
    tenant_id: 1,
    csrf_token: document.getElementById('csrfToken').value
  })
})
.then(r => r.json())
.then(console.log);
// Should show: {success: true, data: {folder_id: X}, message: "..."}
```

---

## AUTOMATED TEST PAGE

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/test_tenant_folder_workflow.html`

**URL:** `http://localhost/CollaboraNexio/test_tenant_folder_workflow.html`

**What it tests:**
1. API endpoint availability
2. Tenant list loading
3. Root folder creation
4. Complete workflow trace

**How to use:**
1. Log in to CollaboraNexio as admin/super_admin
2. Open the test page URL
3. Click each test button in sequence
4. Review green checkmarks = passing, red X = failing

---

## TROUBLESHOOTING

### Issue: Button Not Visible
**Check:**
- User role is admin or super_admin
- Browser has cached old version (hard refresh: Ctrl+Shift+R)

### Issue: Modal Doesn't Open
**Check:**
- Browser console for JavaScript errors
- Modal element exists: `document.getElementById('createTenantFolderModal')`

### Issue: Dropdown Empty
**Check:**
- Network tab: /api/tenants/list.php returns data
- Console log: "Loaded X tenant(s)" message
- Response uses 'denominazione' field

### Issue: API Call Fails
**Check:**
- CSRF token exists and is being sent
- User is still logged in (session not expired)
- Network tab shows POST to correct endpoint

### Issue: Permission Denied
**Check:**
- User role is admin/super_admin
- Admin user has access to selected tenant (check user_tenant_access table)

---

## DATABASE VERIFICATION

### Check If Folder Was Created:
```sql
SELECT
    id,
    name,
    tenant_id,
    is_folder,
    folder_id,
    created_at
FROM files
WHERE is_folder = 1
  AND folder_id IS NULL
  AND deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 10;
```

### Check Tenant Access (For Admin Users):
```sql
SELECT
    u.id as user_id,
    u.name as user_name,
    t.id as tenant_id,
    t.denominazione as tenant_name
FROM user_tenant_access uta
JOIN users u ON u.id = uta.user_id
JOIN tenants t ON t.id = uta.tenant_id
WHERE u.id = [your_user_id];
```

---

## SUCCESS CRITERIA

✅ Button visible to admin/super_admin
✅ Modal opens on button click
✅ Tenants load in dropdown
✅ Form validation works
✅ API call succeeds
✅ Folder created in database
✅ Modal closes on success
✅ File list refreshes
✅ Success toast appears
✅ No JavaScript errors
✅ No PHP errors

---

## SUMMARY

**Total Components:** 10
**Status:** ✅ All implemented and connected
**Estimated Test Time:** 2 minutes
**Required User Role:** admin or super_admin

**Quick Test Command:**
```bash
# Open browser and navigate to:
http://localhost/CollaboraNexio/files.php

# Then:
1. Click "Cartella Tenant" button
2. Select tenant
3. Enter "Test Folder"
4. Click "Crea Cartella"
5. Verify folder appears
```

---

**Last Updated:** 2025-10-16
**Engineer:** Claude (Staff-Level System Diagnostic)
