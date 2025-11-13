# Tenant Folder Creation - Complete Diagnostic Guide

## Overview

This guide provides comprehensive diagnostic tools to identify and fix issues with the tenant folder creation feature in CollaboraNexio.

## Problem Context

User reports that the "Cartella Tenant" (Create Tenant Folder) button is not working, even though:
- Button exists in DOM
- Modal exists in HTML
- Event listener is attached
- API endpoints are implemented
- JavaScript methods are present

## Diagnostic Tools Created

### 1. **test_tenant_folder_browser.html** - Visual Browser Test

**Purpose:** Automatic visual testing in the browser with green/red indicators

**How to Use:**
1. Open in browser: `http://localhost/CollaboraNexio/test_tenant_folder_browser.html`
2. Tests run automatically on page load
3. View color-coded results:
   - ✅ GREEN = Test Passed
   - ❌ RED = Test Failed
   - 🔵 BLUE = Test Running

**What It Tests:**
- Button existence in DOM
- Modal existence and elements
- Event listener attachment
- CSRF token availability
- FileManager instance
- Modal open/close functionality
- Tenant List API
- Create Root Folder API endpoint

**Advantages:**
- Visual, easy to understand
- Runs automatically
- Shows specific fixes for each failure
- No technical knowledge required

---

### 2. **test_tenant_folder_api.php** - Backend API Test

**Purpose:** Test backend PHP components and database

**How to Use:**
1. Open in browser: `http://localhost/CollaboraNexio/test_tenant_folder_api.php`
2. View JSON output with test results
3. Check `overall_status` field

**What It Tests:**
- Session and authentication
- Required files existence
- Database connection and schema
- User roles and permissions
- Tenant List API functionality
- Create Root Folder endpoint configuration
- JavaScript file integrity

**Response Format:**
```json
{
  "overall_status": "PASS" | "FAIL",
  "total_tests": 7,
  "passed": 6,
  "failed": 1,
  "tests": [...]
}
```

**Advantages:**
- Tests backend directly
- Checks database schema
- Validates permissions
- Shows missing files or columns

---

### 3. **DIAGNOSTIC_COMMANDS.txt** - Console Commands

**Purpose:** Manual testing commands for browser console

**How to Use:**
1. Open files.php in browser
2. Press F12 to open Developer Console
3. Copy and paste commands from the file
4. Run them one by one

**Contains:**
- 14 individual diagnostic tests
- Quick copy-paste commands
- Expected outputs
- Fix instructions for each test
- Full workflow test

**Example Commands:**
```javascript
// Check if button exists
document.getElementById('createRootFolderBtn')

// Check FileManager instance
window.fileManager

// Test modal opening
window.fileManager.showCreateTenantFolderModal()
```

**Advantages:**
- Step-by-step debugging
- Immediate feedback
- Can test individual components
- No additional files needed

---

### 4. **fix_tenant_folder_issues.sql** - Database Fixes

**Purpose:** Fix common database schema issues

**How to Use:**
1. Open phpMyAdmin or MySQL client
2. Select `collabonexio` database
3. Copy and paste entire script
4. Execute

**What It Fixes:**
- Missing columns in files table
- Missing foreign keys
- Missing indexes
- Schema validation
- Shows existing root folders
- Verifies tenant data
- Checks user permissions

**Safe Features:**
- Only adds missing columns (won't error if they exist)
- Uses conditional logic
- Won't delete or modify existing data
- Includes verification queries

**Advantages:**
- Fixes database issues automatically
- Safe to run multiple times
- Comprehensive schema verification
- Shows diagnostic information

---

## Step-by-Step Diagnostic Process

### Phase 1: Quick Visual Check (5 minutes)

1. Open `test_tenant_folder_browser.html`
2. Wait for tests to complete
3. If all GREEN → Feature works, issue is elsewhere
4. If any RED → Note which tests failed

### Phase 2: Backend Verification (3 minutes)

1. Open `test_tenant_folder_api.php`
2. Check JSON output
3. Look for FAIL status
4. Read error messages and fixes

### Phase 3: Database Fix (if needed)

1. If API test shows schema issues:
2. Run `fix_tenant_folder_issues.sql`
3. Re-run both browser and API tests
4. Verify issues resolved

### Phase 4: Manual Console Testing

1. If visual tests pass but feature doesn't work:
2. Open `DIAGNOSTIC_COMMANDS.txt`
3. Run commands in browser console
4. Look for unexpected behavior
5. Check for JavaScript errors

### Phase 5: Full Workflow Test

1. Run this in browser console on files.php:
```javascript
async function testFullWorkflow() {
  console.log('=== STARTING FULL WORKFLOW TEST ===');

  // Open modal
  await window.fileManager.showCreateTenantFolderModal();
  await new Promise(r => setTimeout(r, 500));

  const modal = document.getElementById('createTenantFolderModal');
  console.log('Modal opened?', modal.style.display === 'flex');

  // Check tenants loaded
  const select = document.getElementById('tenantSelect');
  console.log('Tenants loaded?', select.options.length > 1);

  // Close modal
  modal.style.display = 'none';
  console.log('=== TEST COMPLETE ===');
}

testFullWorkflow();
```

---

## Common Issues and Solutions

### Issue 1: Button Not Visible

**Symptoms:** Browser test shows button exists but user can't see it

**Possible Causes:**
- User role is not admin/super_admin
- CSS display:none hiding button
- Button is outside viewport

**Fix:**
1. Check user role in session
2. Inspect button CSS
3. Check responsive design

### Issue 2: Modal Doesn't Open

**Symptoms:** Button click does nothing

**Possible Causes:**
- Event listener not attached
- JavaScript error preventing execution
- Modal HTML missing

**Fix:**
1. Check browser console for errors
2. Verify filemanager_enhanced.js loaded
3. Run: `window.fileManager.showCreateTenantFolderModal()`

### Issue 3: No Tenants in Dropdown

**Symptoms:** Modal opens but tenant dropdown is empty

**Possible Causes:**
- Tenant List API failing
- No tenants in database
- User has no tenant access

**Fix:**
1. Test API: `/api/tenants/list.php`
2. Check database for tenants
3. Verify user_tenant_access table

### Issue 4: Create Folder Fails

**Symptoms:** Can select tenant but creation fails

**Possible Causes:**
- CSRF token missing/invalid
- API endpoint not configured
- Database schema issues
- Permission denied

**Fix:**
1. Check CSRF token exists
2. Run database fix script
3. Verify user permissions
4. Check API logs

### Issue 5: Database Schema Errors

**Symptoms:** API returns schema-related errors

**Possible Causes:**
- Missing columns (is_folder, folder_id, etc.)
- Wrong column types
- Missing foreign keys

**Fix:**
1. Run `fix_tenant_folder_issues.sql`
2. Verify columns exist
3. Check foreign key constraints

---

## Interpreting Test Results

### Browser Test Results

#### All Tests PASS ✅
- Feature is correctly implemented
- Issue may be user-specific or environmental
- Check browser compatibility
- Verify user has correct permissions

#### Some Tests FAIL ❌
- Read failure details carefully
- Follow "Fix" instructions shown
- Re-run tests after fixes
- Most failures are configuration issues

#### Tests Don't Run 🔴
- JavaScript not loading
- Check browser console for errors
- Verify file paths correct
- Clear browser cache

### API Test Results

#### overall_status: "PASS" ✅
- Backend is configured correctly
- Database schema is valid
- User has necessary permissions

#### overall_status: "FAIL" ❌
- Check individual test details
- Look for missing files
- Verify database schema
- Fix permissions

#### Status: "WARNING" ⚠️
- Feature may work but has issues
- Review warning details
- Not critical but should be addressed

---

## File Locations Reference

```
/CollaboraNexio/
├── files.php                              (Main page - line 368: button, line 648: modal)
├── assets/js/filemanager_enhanced.js     (Line 63-65: event listener, 2216-2307: methods)
├── api/files_tenant.php                   (Line 256-329: createRootFolder endpoint)
├── api/tenants/list.php                   (Tenant list API)
├── test_tenant_folder_browser.html        (Visual browser test)
├── test_tenant_folder_api.php             (Backend API test)
├── DIAGNOSTIC_COMMANDS.txt                (Console commands)
└── fix_tenant_folder_issues.sql           (Database fixes)
```

---

## Quick Reference: What Each Tool Tests

| Tool | Tests Frontend | Tests Backend | Tests Database | User-Friendly | Automatic |
|------|---------------|---------------|----------------|---------------|-----------|
| Browser HTML | ✅ | ⚠️ | ❌ | ✅ | ✅ |
| API PHP | ❌ | ✅ | ✅ | ⚠️ | ✅ |
| Console Commands | ✅ | ⚠️ | ❌ | ⚠️ | ❌ |
| SQL Script | ❌ | ❌ | ✅ | ⚠️ | ✅ |

**Legend:**
- ✅ Yes / Full Support
- ⚠️ Partial / Some Support
- ❌ No Support

---

## Expected Workflow

**Normal Flow:**
1. Admin clicks "Cartella Tenant" button
2. Modal opens with tenant dropdown
3. Admin selects tenant from dropdown
4. Admin enters folder name
5. Admin clicks "Crea Cartella"
6. API creates folder in database
7. Success message shown
8. Modal closes
9. File list refreshes with new folder

**Test Each Step:**
- Step 1-2: Browser test #1, #2, #6
- Step 3: Browser test #7, API test #5
- Step 4: Browser test #2
- Step 5-6: API test #6, Console test #11
- Step 7-9: Manual verification

---

## Support Information

### For Developers

If all tests pass but feature still doesn't work:
1. Check browser console for JavaScript errors
2. Check PHP error logs
3. Check Apache/Nginx error logs
4. Verify user session is active
5. Check CORS settings
6. Verify file permissions

### For Users

If you see this error message:
- Check your user role (must be Admin or Super Admin)
- Try logging out and back in
- Clear browser cache
- Try different browser
- Contact system administrator

---

## Success Criteria

Feature is working correctly when:
- ✅ All browser tests PASS
- ✅ All API tests PASS
- ✅ Button visible to admin users
- ✅ Modal opens on button click
- ✅ Tenant dropdown populated
- ✅ Folder creation succeeds
- ✅ New folder appears in file list
- ✅ No JavaScript errors in console
- ✅ No PHP errors in logs

---

## Next Steps After Diagnosis

### If All Tests Pass
- Feature is working correctly
- Issue may be user error
- Provide user training
- Check user permissions

### If Tests Fail
1. Run database fix script
2. Re-run all tests
3. Fix remaining issues
4. Document changes made
5. Test manually

### If Issues Persist
- Check system logs
- Review recent code changes
- Verify web server configuration
- Contact technical support

---

## Maintenance

Run these diagnostic tests:
- After code updates
- After database migrations
- After server changes
- When bugs are reported
- Before production deployment

**Keep this guide updated with:**
- New test cases
- New common issues
- Updated file locations
- Version-specific notes

---

## Version Information

**Created:** 2025-10-16
**For:** CollaboraNexio File Manager
**Feature:** Tenant Folder Creation
**Components Tested:** Frontend, Backend, Database

**Compatible with:**
- PHP 7.4+
- MySQL 5.7+
- Modern browsers (Chrome, Firefox, Edge, Safari)

---

## Emergency Quick Fix

If you need the feature working IMMEDIATELY:

1. Run: `fix_tenant_folder_issues.sql` (2 minutes)
2. Clear browser cache (30 seconds)
3. Refresh files.php (10 seconds)
4. Test button click (30 seconds)

**Total time: ~3 minutes**

If still not working after this, deeper investigation needed.
