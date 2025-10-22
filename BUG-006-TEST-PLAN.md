# BUG-006 Test Plan - PDF Upload Fix Verification

**Bug:** PDF Upload Failing Due to Audit Log Schema Mismatch
**Status:** Fixed (Comprehensive - 13 files)
**Date:** 2025-10-20
**Tester:** [Your Name]

---

## Test Environment

- **Server:** XAMPP/Apache/PHP 8.3
- **Database:** MySQL/MariaDB
- **Browser:** Chrome/Firefox/Edge (latest)
- **Test User:** Any authenticated user with file upload permissions
- **Test Tenant:** Any active tenant

---

## Pre-Test Verification

### 1. Verify Fix Applied
```bash
# Check that all 13 files were modified
cd /mnt/c/xampp/htdocs/CollaboraNexio

# Verify NO remaining 'details' references in audit logs
grep -r "INSERT INTO audit_logs" --include="*.php" | grep -i "details"
# Expected: No output (empty)

# Verify 'description' is used instead
grep -r "INSERT INTO audit_logs" --include="*.php" | grep -i "description" | wc -l
# Expected: Should show multiple matches
```

### 2. Clear Previous Error Logs
```bash
# Backup and clear PHP error log
cd /mnt/c/xampp/htdocs/CollaboraNexio/logs
cp php_errors.log php_errors.log.backup-$(date +%Y%m%d-%H%M%S)
> php_errors.log
```

### 3. Verify Database Schema
```sql
-- Check audit_logs table structure
DESCRIBE audit_logs;

-- Verify columns exist:
-- ✅ description (TEXT)
-- ✅ old_values (JSON)
-- ✅ new_values (JSON)
-- ✅ severity (ENUM)
-- ✅ status (ENUM)
-- ❌ details (should NOT exist)

-- If 'details' column exists, fix with:
-- ALTER TABLE audit_logs DROP COLUMN details;
```

---

## Test Cases

### Test 1: Upload PDF File

**Priority:** Critical
**Expected Result:** File uploads successfully, no errors

**Steps:**
1. Login to CollaboraNexio
2. Navigate to Files page (files.php)
3. Select a tenant from dropdown (if applicable)
4. Click "Upload File" button
5. Select a PDF file (e.g., test-document.pdf, size < 100MB)
6. Click upload/confirm
7. Wait for upload completion

**Expected Behavior:**
- ✅ Upload progress indicator shows
- ✅ File appears in file list
- ✅ File icon shows PDF icon
- ✅ File size displays correctly
- ✅ Success message appears
- ✅ NO error messages
- ✅ NO console errors (F12)

**Verify in Logs:**
```bash
# Check for errors
tail -20 /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# Expected: NO "Audit log failed" errors
# Expected: NO "Unknown column 'details'" errors
```

**Verify in Database:**
```sql
-- Check file was created
SELECT * FROM files
WHERE name LIKE '%.pdf'
ORDER BY created_at DESC
LIMIT 1;

-- Check audit log was created
SELECT * FROM audit_logs
WHERE action = 'file_uploaded'
  AND entity_type = 'file'
ORDER BY created_at DESC
LIMIT 1;

-- Verify audit log structure:
-- ✅ description should be human-readable (e.g., "File caricato: test.pdf (2.5 MB)")
-- ✅ new_values should contain JSON data
-- ✅ severity should be 'info'
-- ✅ status should be 'success'
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 2: Upload Multiple File Types

**Priority:** High
**Expected Result:** All file types upload successfully

**Test Files:**
1. Document: `test-document.docx`
2. Spreadsheet: `test-spreadsheet.xlsx`
3. Presentation: `test-presentation.pptx`
4. Image: `test-image.jpg`
5. Archive: `test-archive.zip`

**Steps:**
For each file type:
1. Upload file
2. Verify success
3. Check file appears in list
4. Verify no errors in logs

**Expected Behavior:**
- ✅ All files upload successfully
- ✅ Correct icons displayed for each type
- ✅ File sizes displayed correctly
- ✅ NO audit log errors

**Verify in Database:**
```sql
-- Check all files were created
SELECT name, mime_type, size, created_at
FROM files
WHERE created_at > NOW() - INTERVAL 10 MINUTE
ORDER BY created_at DESC;

-- Check audit logs for all uploads
SELECT action, entity_type, description, severity, status
FROM audit_logs
WHERE action = 'file_uploaded'
  AND created_at > NOW() - INTERVAL 10 MINUTE
ORDER BY created_at DESC;
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 3: Create New Folder

**Priority:** Medium
**Expected Result:** Folder creation logs audit without errors

**Steps:**
1. Click "New Folder" button
2. Enter folder name: "Test Folder BUG-006"
3. Click Create/Confirm
4. Verify folder appears in list

**Expected Behavior:**
- ✅ Folder created successfully
- ✅ Folder icon displayed
- ✅ Success message shown
- ✅ NO audit log errors

**Verify in Logs:**
```bash
tail -10 /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
# Expected: NO errors
```

**Verify in Database:**
```sql
SELECT * FROM audit_logs
WHERE action = 'folder_created'
  AND entity_type = 'file'
ORDER BY created_at DESC
LIMIT 1;
-- Verify 'description' column used, not 'details'
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 4: Delete File

**Priority:** Medium
**Expected Result:** File deletion logs audit correctly

**Steps:**
1. Select a test file
2. Click Delete button
3. Confirm deletion
4. Verify file removed from list

**Expected Behavior:**
- ✅ File deleted successfully
- ✅ File removed from view
- ✅ Success message shown
- ✅ Audit log created with severity 'warning'

**Verify in Database:**
```sql
-- Check deletion audit log
SELECT * FROM audit_logs
WHERE action = 'file_deleted'
  AND entity_type = 'file'
ORDER BY created_at DESC
LIMIT 1;

-- Verify:
-- ✅ description is human-readable
-- ✅ old_values contains file metadata
-- ✅ severity is 'warning' (for deletions)
-- ✅ status is 'success'
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 5: Rename File

**Priority:** Low
**Expected Result:** File rename logs audit correctly

**Steps:**
1. Select a file
2. Click Rename
3. Enter new name: "renamed-test-file.pdf"
4. Confirm

**Expected Behavior:**
- ✅ File renamed successfully
- ✅ New name displayed
- ✅ NO errors

**Verify in Database:**
```sql
SELECT * FROM audit_logs
WHERE action = 'file_renamed'
ORDER BY created_at DESC
LIMIT 1;
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 6: Move File

**Priority:** Low
**Expected Result:** File move logs audit correctly

**Steps:**
1. Create a folder if not exists
2. Select a file
3. Click Move
4. Select destination folder
5. Confirm

**Expected Behavior:**
- ✅ File moved successfully
- ✅ File appears in destination
- ✅ NO errors

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 7: Download File

**Priority:** Medium
**Expected Result:** File download logs audit correctly

**Steps:**
1. Select a file
2. Click Download
3. Verify file downloads

**Expected Behavior:**
- ✅ File downloads successfully
- ✅ Audit log created
- ✅ NO errors

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

### Test 8: Create New Document (OnlyOffice)

**Priority:** High
**Expected Result:** Document creation logs audit correctly

**Steps:**
1. Click "New Document" button
2. Select document type (Word/Excel/PowerPoint)
3. Enter document name
4. Click Create
5. Verify document created

**Expected Behavior:**
- ✅ Document created successfully
- ✅ Document appears in list
- ✅ NO errors related to document_editor_helper.php

**Verify in Logs:**
```bash
# Specifically check for document_editor_helper.php errors
grep "document_editor_helper" /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
# Expected: NO "details" column errors
```

**Result:** [ ] PASS  [ ] FAIL
**Notes:**

---

## Regression Testing

### Test 9: Legacy File Operations (files_tenant.php)

**Priority:** Medium
**Expected Result:** Legacy API endpoints work if called

**Note:** These files may not be actively used, but should not cause errors if called

**Files to verify:**
- `api/files_tenant.php`
- `api/files_tenant_fixed.php`
- `api/files_tenant_production.php`

**If accessible, test basic operations through these endpoints**

**Result:** [ ] PASS  [ ] FAIL  [ ] N/A
**Notes:**

---

## Post-Test Verification

### 1. Check Error Logs
```bash
# Review entire error log for any issues
cat /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# Expected: NO "details" column errors
# Expected: NO "Audit log failed" errors
```

### 2. Verify Audit Log Integrity
```sql
-- Count recent audit logs
SELECT COUNT(*) FROM audit_logs
WHERE created_at > NOW() - INTERVAL 1 HOUR;

-- Verify all have proper structure
SELECT
    COUNT(*) as total,
    COUNT(description) as has_description,
    COUNT(severity) as has_severity,
    COUNT(status) as has_status
FROM audit_logs
WHERE created_at > NOW() - INTERVAL 1 HOUR;

-- All counts should match (total = has_description = has_severity = has_status)

-- Check for any NULL descriptions (should be ZERO)
SELECT COUNT(*) FROM audit_logs
WHERE description IS NULL
  AND created_at > NOW() - INTERVAL 1 HOUR;
-- Expected: 0
```

### 3. Performance Check
```sql
-- Verify audit log insertions are not slowing down operations
SELECT action, COUNT(*) as count,
       AVG(TIMESTAMPDIFF(MICROSECOND, created_at, NOW())) as avg_time_ms
FROM audit_logs
WHERE created_at > NOW() - INTERVAL 1 HOUR
GROUP BY action;
```

---

## Test Summary

**Total Test Cases:** 9
**Passed:** _____
**Failed:** _____
**N/A:** _____

**Critical Issues Found:** _____
**Minor Issues Found:** _____

---

## Sign-Off

**Fix Verified:** [ ] YES  [ ] NO
**Ready for Production:** [ ] YES  [ ] NO

**Tester Name:** _____________________
**Test Date:** _____________________
**Signature:** _____________________

---

## Rollback Plan (If Needed)

If tests fail, the fix can be rolled back by reverting the 13 modified files:

```bash
# If using git
git checkout HEAD -- includes/document_editor_helper.php
git checkout HEAD -- api/files_tenant.php
git checkout HEAD -- api/files_tenant_fixed.php
git checkout HEAD -- api/files_tenant_production.php
# ... etc for all 13 files
```

However, **this is NOT recommended** as it would break file uploads again.

Instead, investigate specific failure and apply targeted fix.

---

## Additional Notes

- All tests should be performed with different user roles (admin, manager, user)
- Test with different tenant contexts
- Test with large files (close to 100MB limit)
- Test concurrent uploads if possible
- Monitor database performance during tests

**Test completed successfully indicates BUG-006 is fully resolved.**
