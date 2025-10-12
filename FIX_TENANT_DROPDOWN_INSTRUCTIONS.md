# TENANT DROPDOWN FIX - STEP-BY-STEP INSTRUCTIONS

**Date:** 2025-10-12
**Issue:** Multiple tenants appear in dropdown despite database cleanup
**Root Cause:** Browser cache / Session state (Database is correct)

---

## VERIFICATION RESULTS

✅ **Database cleanup was SUCCESSFUL**
✅ **Only Tenant ID 11 is active in database**
✅ **API query returns only Tenant ID 11**
❌ **Browser is showing cached data**

```
Database State:
- Active tenants:  1 (ID 11: S.CO Srls)
- Deleted tenants: 7 (IDs: 1, 2, 3, 4, 6, 8, 9)

API Response:
- Returns: 1 tenant (ID 11 only)
- Query: WHERE deleted_at IS NULL AND status != 'suspended'
```

---

## FIX INSTRUCTIONS (Choose ONE method)

### METHOD 1: Browser Hard Refresh (FASTEST)

**For Chrome/Edge/Firefox:**

1. Hold down `Ctrl + Shift` (Windows) or `Cmd + Shift` (Mac)
2. Press `R` while holding the keys
3. Release all keys
4. Wait for page to fully reload

**Or:**

1. Press `Ctrl + Shift + Delete` (Windows) or `Cmd + Shift + Delete` (Mac)
2. Select "All time" or "Everything"
3. Check these options:
   - ✅ Cached images and files
   - ✅ Cookies and site data
   - ✅ Hosted app data (if available)
4. Click "Clear data" or "Clear now"
5. Close ALL browser windows completely
6. Reopen browser and navigate to application

---

### METHOD 2: Use Debug Tool (RECOMMENDED)

1. Open in browser:
   ```
   http://localhost:8888/CollaboraNexio/debug_tenant_dropdown.html
   ```

2. Click these buttons in order:
   - **"Check Storage"** - See what's cached
   - **"Test API Directly"** - Verify API response
   - **"Clear All Cache"** - Remove cached data
   - Page will auto-reload

3. After reload, verify dropdown shows only "S.CO Srls"

---

### METHOD 3: Manual Browser Console (ADVANCED)

1. Press `F12` to open Developer Tools
2. Click **Console** tab
3. Paste this code and press Enter:

```javascript
// Clear all storage
localStorage.clear();
sessionStorage.clear();

// Clear all cookies
document.cookie.split(";").forEach(function(c) {
    document.cookie = c.replace(/^ +/, "")
        .replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
});

// Log clearing
console.log('✅ localStorage cleared');
console.log('✅ sessionStorage cleared');
console.log('✅ Cookies cleared');

// Reload page
console.log('🔄 Reloading page...');
location.reload(true);
```

---

### METHOD 4: PHP Session Clear

1. Navigate to:
   ```
   http://localhost:8888/CollaboraNexio/logout.php
   ```

2. Log back in with your credentials

3. Check if dropdown now shows only one tenant

---

### METHOD 5: Manual Session File Delete

1. Open Windows Explorer
2. Navigate to: `C:\xampp\tmp\`
3. Delete all files starting with `sess_`
4. Refresh browser (F5)

---

## VERIFICATION AFTER FIX

### Step 1: Check Browser Network Tab

1. Press `F12` → **Network** tab
2. Click **XHR** filter
3. Reload page
4. Find request: `files_tenant_fixed.php?action=get_tenant_list`
5. Click on it → **Preview** tab
6. Should see:

```json
{
  "success": true,
  "data": [
    {
      "id": "11",
      "name": "S.CO Srls",
      "is_active": "1",
      "status": "active"
    }
  ]
}
```

**If you see only 1 tenant in API but multiple in dropdown:**
- Frontend JavaScript is caching the list
- Check `assets/js/filemanager_enhanced.js` or similar files

---

### Step 2: Verify Database Directly

Run this command in terminal:

```bash
/mnt/c/xampp/php/php.exe verify_tenant_cleanup.php
```

Expected output:
```
Active (deleted_at NULL): 1
Deleted (deleted_at SET): 7

CURRENTLY ACTIVE TENANTS:
  - ID 11: S.CO Srls (Status: active)

[SUCCESS] Only Tenant ID 11 is active - cleanup was successful!
```

---

### Step 3: Check Dropdown Manually

1. Navigate to File Manager page
2. Look for tenant dropdown (usually in header/sidebar)
3. Click dropdown
4. Should show ONLY: "S.CO Srls"

If still showing multiple tenants → **Contact support with debug_tenant_dropdown.html results**

---

## TROUBLESHOOTING

### Issue: API shows 1 tenant, dropdown shows multiple

**Cause:** Frontend JavaScript caching

**Fix:**
1. Search for tenant list initialization in JavaScript
2. Look for files in `assets/js/` directory
3. Check for global variables caching tenant list
4. Add cache-busting parameter:

```javascript
// BAD: Cached forever
let tenants = [];
fetchTenants().then(data => tenants = data);

// GOOD: Fresh every time
function fetchTenants() {
    return fetch(`/api/files_tenant_fixed.php?action=get_tenant_list&_=${Date.now()}`)
        .then(r => r.json());
}
```

---

### Issue: API shows multiple tenants

**This should NOT happen** - Database verification shows only 1 tenant.

If API returns multiple tenants:

1. Check database again:
   ```bash
   /mnt/c/xampp/php/php.exe verify_tenant_cleanup.php
   ```

2. If database shows multiple active tenants, run fix:
   ```bash
   /c/xampp/mysql/bin/mysql.exe -u root collaboranexio < fix_tenant_cleanup.sql
   ```

3. Verify fix worked:
   ```bash
   /mnt/c/xampp/php/php.exe verify_tenant_cleanup.php
   ```

---

### Issue: No tenants appear in dropdown

**Cause:** All tenants deleted or user role insufficient

**Fix:**
1. Check user role - must be `admin` or `super_admin`
2. Verify tenant 11 is active:
   ```sql
   SELECT id, name, deleted_at FROM tenants WHERE id = 11;
   ```
3. If deleted_at is NOT NULL, activate it:
   ```sql
   UPDATE tenants SET deleted_at = NULL, status = 'active' WHERE id = 11;
   ```

---

## FILES REFERENCE

| File | Purpose | Location |
|------|---------|----------|
| `verify_tenant_cleanup.php` | Check database state | `/mnt/c/xampp/htdocs/CollaboraNexio/` |
| `fix_tenant_cleanup.sql` | Soft delete tenants | `/mnt/c/xampp/htdocs/CollaboraNexio/` |
| `fix_tenant_cleanup_hard.sql` | Hard delete tenants | `/mnt/c/xampp/htdocs/CollaboraNexio/` |
| `debug_tenant_dropdown.html` | Browser debugging | `/mnt/c/xampp/htdocs/CollaboraNexio/` |
| `TENANT_CLEANUP_ANALYSIS.md` | Full analysis report | `/mnt/c/xampp/htdocs/CollaboraNexio/` |
| `files_tenant_fixed.php` | API endpoint | `/mnt/c/xampp/htdocs/CollaboraNexio/api/` |

---

## QUICK COMMAND REFERENCE

```bash
# Verify database state
/mnt/c/xampp/php/php.exe verify_tenant_cleanup.php

# Open debug tool
# Navigate to: http://localhost:8888/CollaboraNexio/debug_tenant_dropdown.html

# Run database fix (if needed)
/c/xampp/mysql/bin/mysql.exe -u root collaboranexio < fix_tenant_cleanup.sql

# Check MySQL directly
/c/xampp/mysql/bin/mysql.exe -u root collaboranexio -e "SELECT id, name, deleted_at FROM tenants ORDER BY id;"
```

---

## EXPECTED FINAL STATE

After following fix instructions:

✅ **Database:** Only tenant 11 active
✅ **API Response:** Returns only tenant 11
✅ **Browser Dropdown:** Shows only "S.CO Srls"
✅ **No Cache Issues:** Fresh data on every load

---

## CONTACT SUPPORT

If issue persists after trying all methods:

1. Run debug tool: `debug_tenant_dropdown.html`
2. Take screenshots of:
   - Debug tool results
   - Browser console (F12 → Console)
   - Network tab (F12 → Network → XHR)
3. Run verification script:
   ```bash
   /mnt/c/xampp/php/php.exe verify_tenant_cleanup.php > tenant_debug_output.txt
   ```
4. Share `tenant_debug_output.txt` file

---

## SUCCESS CONFIRMATION

You'll know it's fixed when:

1. ✅ Dropdown shows ONLY "S.CO Srls"
2. ✅ Network tab shows API returning 1 tenant
3. ✅ `verify_tenant_cleanup.php` shows 1 active tenant
4. ✅ No browser console errors

**Estimated fix time:** 2-5 minutes

---

*Last Updated: 2025-10-12*
*Database Architect - CollaboraNexio*
