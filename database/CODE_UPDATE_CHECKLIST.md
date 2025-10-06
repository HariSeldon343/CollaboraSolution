# Schema Drift Fix - Code Update Checklist

**Date:** 2025-10-03
**Project:** CollaboraNexio
**Task:** Update code to match actual database schema

---

## Overview

This checklist tracks the code changes required to normalize all file references to use the ACTUAL database schema:
- `file_size` (not size_bytes)
- `file_path` (not storage_path)
- `uploaded_by` (not owner_id - for files table only!)

**Important:** `folders` table uses `owner_id` - DO NOT change those!

---

## Pre-Flight Checklist

- [ ] Reviewed `/database/SCHEMA_DRIFT_ANALYSIS_REPORT.md`
- [ ] Created database backup
- [ ] Created code backup (git commit or manual backup)
- [ ] Set up test environment
- [ ] Prepared rollback plan

---

## Phase 1: Documentation Updates (SAFE)

### File: `/database/03_complete_schema.sql`
**Lines:** ~220-249
**Action:** Replace files table definition with corrected schema
**Changes:**
```sql
OLD:
  size_bytes BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  checksum VARCHAR(64) NULL,

NEW:
  file_size BIGINT(20) DEFAULT 0,
  file_path VARCHAR(500) DEFAULT NULL,
  uploaded_by INT(11) DEFAULT NULL,
  -- Remove: checksum (not implemented)
  -- Add: original_tenant_id, original_name, is_public, public_token,
  --      shared_with, download_count, last_accessed_at,
  --      reassigned_at, reassigned_by
```
**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ YES | ⬜ NO
**Notes:** _____________________________________

---

### File: `/database/04_demo_data.sql`
**Action:** Check INSERT statements for files table references
**Changes:** Update any column references to use: file_size, file_path, uploaded_by
**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ YES | ⬜ NO
**Notes:** _____________________________________

---

### File: `/CLAUDE.md`
**Section:** Database Tables (22 total) and Key Patterns
**Action:** Update table reference documentation
**Changes:**
- Document files table uses: uploaded_by
- Document folders table uses: owner_id (different semantic meaning)
- Document file_versions uses: size_bytes, storage_path (historical context)
- Add naming convention section
**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** N/A
**Notes:** _____________________________________

---

## Phase 2: Critical API Updates (MEDIUM RISK)

### Priority 1: Main File Management API

#### File: `/api/files_complete.php`
**Risk Level:** 🔴 HIGH (Main file API)
**Lines to Update:** 254, 271, 302, 354-458, 480

**Changes Required:**

**Line 254:** Sort clause
```php
OLD: 'size' => "f.size_bytes",
NEW: 'size' => "f.file_size",
```

**Line 271:** Format file size
```php
OLD: $file['size_formatted'] = formatFileSize($file['size_bytes']);
NEW: $file['size_formatted'] = formatFileSize($file['file_size']);
```

**Line 302:** Storage statistics
```php
OLD: SUM(size_bytes) as used_bytes,
NEW: SUM(file_size) as used_bytes,
```

**Lines 354-458:** File upload logic
```php
OLD:
  $storage_path = $storage_base . '/files/' . ...
  mkdir($storage_path, 0755, true);
  INSERT INTO files (..., size_bytes, storage_path, owner_id, ...)

NEW:
  $file_directory = $storage_base . '/files/' . ...
  mkdir($file_directory, 0755, true);
  INSERT INTO files (..., file_size, file_path, uploaded_by, ...)
```

**Line 446:** Permission check
```php
OLD: if ($file['owner_id'] != $user_id && !$file['is_shared'])
NEW: if ($file['uploaded_by'] != $user_id && !$file['is_shared'])
```

**Line 458:** File path for download
```php
OLD: $full_path = $storage_base . '/' . $file['storage_path'];
NEW: $full_path = $storage_base . '/' . $file['file_path'];
```

**Line 480:** Content length header
```php
OLD: header('Content-Length: ' . $file['size_bytes']);
NEW: header('Content-Length: ' . $file['file_size']);
```

**Lines 576, 791, 812, 830:** DELETE/UPDATE queries
```php
OLD: WHERE ... AND owner_id = ?
NEW: WHERE ... AND uploaded_by = ?
```
**IMPORTANT:** Only change for `files` table queries, NOT for `folders`!

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ Upload | ⬜ Download | ⬜ List | ⬜ Delete
**Notes:** _____________________________________

---

### Priority 2: Document Approval System

#### File: `/api/documents/pending.php`
**Risk Level:** 🟡 MEDIUM (Document approval)
**Lines to Update:** 39, 49, 61-62, 111-112, 156, 169

**Changes Required:**

**Line 39:** SELECT query
```php
OLD: SELECT f.id, f.name, f.original_name, f.mime_type, f.size_bytes,
NEW: SELECT f.id, f.name, f.original_name, f.mime_type, f.file_size,
```

**Line 49:** JOIN clause
```php
OLD: INNER JOIN users u ON f.owner_id = u.id
NEW: INNER JOIN users u ON f.uploaded_by = u.id
```

**Lines 61-62, 111-112:** WHERE clauses
```php
OLD:
  $query .= " AND f.owner_id = :owner_id";
  $params[':owner_id'] = $user_id;
NEW:
  $query .= " AND f.uploaded_by = :uploaded_by";
  $params[':uploaded_by'] = $user_id;
```

**Line 156:** Format file size
```php
OLD: $file['size_formatted'] = formatFileSize($file['size_bytes']);
NEW: $file['size_formatted'] = formatFileSize($file['file_size']);
```

**Line 169:** Permission check
```php
OLD: $file['can_edit'] = $file['owner_id'] == $user_id ||
NEW: $file['can_edit'] = $file['uploaded_by'] == $user_id ||
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ List pending | ⬜ Filter by user
**Notes:** _____________________________________

---

#### File: `/api/documents/approve.php`
**Risk Level:** 🟡 MEDIUM (Document approval)
**Lines to Update:** 56, 152, 176

**Changes Required:**

**Line 56:** JOIN clause
```php
OLD: LEFT JOIN users u ON f.owner_id = u.id
NEW: LEFT JOIN users u ON f.uploaded_by = u.id
```

**Line 152:** Notification data
```php
OLD: ':requested_by' => $file['owner_id'],
NEW: ':requested_by' => $file['uploaded_by'],
```

**Line 176:** Notification data
```php
OLD: ':user_id' => $file['owner_id'],
NEW: ':user_id' => $file['uploaded_by'],
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ Approve document | ⬜ Notification sent
**Notes:** _____________________________________

---

#### File: `/api/documents/reject.php`
**Risk Level:** 🟡 MEDIUM (Document approval)
**Lines to Update:** 61, 167, 192

**Changes Required:**

**Line 61:** JOIN clause
```php
OLD: LEFT JOIN users u ON f.owner_id = u.id
NEW: LEFT JOIN users u ON f.uploaded_by = u.id
```

**Line 167:** Notification data
```php
OLD: ':requested_by' => $file['owner_id'],
NEW: ':requested_by' => $file['uploaded_by'],
```

**Line 192:** Notification data
```php
OLD: ':user_id' => $file['owner_id'],
NEW: ':user_id' => $file['uploaded_by'],
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ Reject document | ⬜ Notification sent
**Notes:** _____________________________________

---

### Priority 3: Mixed Usage Files

#### File: `/api/files.php`
**Risk Level:** 🟢 LOW (Already uses correct schema mostly)
**Lines to Review:** 225, 228, 229, 236, 374, 377, 379, 801

**Status:** ✅ ALREADY CORRECT (uses file_size, file_path, uploaded_by)
**Action:** VERIFY ONLY - No changes needed
**Tested:** ⬜ Verified
**Notes:** This file already uses the correct schema!

---

#### File: `/api/files_tenant.php`
**Risk Level:** 🟢 LOW (Has smart detection)
**Action:** Simplify - Remove COALESCE logic, use actual schema directly

**Changes Required:**

**Lines 108-126:** Column detection methods
```php
OLD:
  // Priorità: file_size, size_bytes, size
  if ($this->hasColumn('files', 'file_size')) return 'file_size';
  if ($this->hasColumn('files', 'size_bytes')) return 'size_bytes';
  ...

NEW:
  // Production schema - direct return
  return 'file_size';  // For size
  return 'file_path';  // For path
  return 'uploaded_by'; // For owner
```

**Lines 158-162:** COALESCE expressions
```php
OLD:
  return "COALESCE(f.file_size, f.size_bytes, f.size)" . ($alias ? " AS $alias" : "");

NEW:
  return "f.file_size" . ($alias ? " AS $alias" : "");
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ Upload | ⬜ Download | ⬜ List
**Notes:** _____________________________________

---

#### File: `/api/router.php`
**Risk Level:** 🟢 LOW (Metrics only)
**Line:** 442

**Changes Required:**

**Line 442:** Storage statistics
```php
OLD: SUM(size_bytes) as total_size
NEW: SUM(file_size) as total_size
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ Dashboard metrics
**Notes:** _____________________________________

---

## Phase 3: Special Cases

### File: `/includes/versioning.php`
**Risk Level:** 🟢 NONE (Different table)
**Action:** ✅ KEEP AS-IS
**Rationale:** file_versions table intentionally uses size_bytes, storage_path

**Status:** ✅ NO CHANGES REQUIRED
**Notes:** Add comment explaining schema difference between files and file_versions

---

### File: `/api/files_enhanced.php`
**Risk Level:** ⚠️ REVIEW REQUIRED
**Lines:** 127, 148, 184, 308-328, 343, 375, 395, 430

**IMPORTANT:** This file uses `owner_id` extensively
**Action Required:** Determine if these are for `files` table or `folders` table
- If `files` table: Change to `uploaded_by`
- If `folders` table: ✅ KEEP as `owner_id`

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Tested:** ⬜ YES | ⬜ NO
**Notes:** _____________________________________

---

## Phase 4: Migration Scripts

### File: `/migrations/fix_files_table_migration.sql`
**Action:** Mark as OBSOLETE
**Changes:** Add header comment

```sql
-- ============================================
-- OBSOLETE MIGRATION - DO NOT RUN
-- ============================================
-- This migration was created when schema docs were out of sync.
-- The actual database already uses the correct schema:
--   - file_size (NOT size_bytes)
--   - file_path (NOT storage_path)
--   - uploaded_by (NOT owner_id)
--
-- Running this migration would BREAK the production database!
-- Date Marked Obsolete: 2025-10-03
-- Reason: Database schema already correct
-- ============================================
```

**Status:** ⬜ NOT STARTED | ⏳ IN PROGRESS | ✅ COMPLETED | ❌ BLOCKED
**Notes:** _____________________________________

---

## Phase 5: Comprehensive Testing

### Test Checklist

**File Operations:**
- [ ] Upload file via `/api/files.php`
- [ ] Upload file via `/api/files_tenant.php`
- [ ] Upload file via `/api/files_complete.php`
- [ ] List files in root folder
- [ ] List files in subfolder
- [ ] Search files by name
- [ ] Filter files by type
- [ ] Download file
- [ ] Move file to different folder
- [ ] Rename file
- [ ] Delete file (soft delete)
- [ ] Restore deleted file

**Document Approval:**
- [ ] List pending documents
- [ ] Filter pending by user
- [ ] Approve document as Manager
- [ ] Reject document as Manager
- [ ] Verify notification sent on approval
- [ ] Verify notification sent on rejection

**Multi-Tenant:**
- [ ] Upload file as Tenant 1 user
- [ ] Verify Tenant 2 cannot see Tenant 1 files
- [ ] Switch tenant as Admin
- [ ] Verify files isolated per tenant

**Statistics & Metrics:**
- [ ] Dashboard shows correct file counts
- [ ] Storage usage statistics accurate
- [ ] Download count increments correctly
- [ ] Last accessed timestamp updates

**Edge Cases:**
- [ ] Upload file with special characters in name
- [ ] Upload very large file (near size limit)
- [ ] Upload file to deeply nested folder
- [ ] Multiple simultaneous uploads
- [ ] Delete file while another user viewing it

---

## Verification Queries

Run these SQL queries to verify data integrity after updates:

```sql
-- 1. Verify all files have valid sizes
SELECT COUNT(*) as invalid_size
FROM files
WHERE file_size IS NULL
AND is_folder = 0;
-- Expected: 0

-- 2. Verify all files have paths
SELECT COUNT(*) as missing_path
FROM files
WHERE file_path IS NULL
AND is_folder = 0;
-- Expected: 0

-- 3. Verify uploaded_by references valid users
SELECT COUNT(*) as orphaned_files
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.uploaded_by IS NOT NULL
AND u.id IS NULL;
-- Expected: 0

-- 4. Check file_versions still works
SELECT COUNT(*) as version_count
FROM file_versions;
-- Should return count without error

-- 5. Verify no old column references in queries
-- (Manual check - grep codebase for size_bytes, storage_path, owner_id)
```

---

## Rollback Plan

### If Issues Occur:

1. **Restore Code:**
   ```bash
   git checkout HEAD~1 -- api/files_complete.php
   git checkout HEAD~1 -- api/documents/pending.php
   git checkout HEAD~1 -- api/documents/approve.php
   git checkout HEAD~1 -- api/documents/reject.php
   git checkout HEAD~1 -- api/files_tenant.php
   git checkout HEAD~1 -- api/router.php
   ```

2. **Restore Documentation:**
   ```bash
   # Backups are in /database/backups/[timestamp]/
   cp database/backups/[timestamp]/database/03_complete_schema.sql database/
   cp database/backups/[timestamp]/database/04_demo_data.sql database/
   cp database/backups/[timestamp]/CLAUDE.md .
   ```

3. **No Database Rollback Required:**
   - No database changes were made!
   - Data is 100% safe

---

## Post-Implementation

### Code Quality Checks:
- [ ] Grep for remaining `size_bytes` references (target: <5)
- [ ] Grep for remaining `storage_path` references (target: <5, only in file_versions)
- [ ] Grep for remaining `owner_id` in files context (target: 0)
- [ ] All API endpoints return valid JSON
- [ ] No PHP errors in error log
- [ ] No SQL errors in error log

### Documentation Updates:
- [ ] Update API documentation if exists
- [ ] Update developer onboarding docs
- [ ] Add comment to schema files about naming conventions
- [ ] Update CHANGELOG.md with schema standardization notes

### Monitoring (48 hours post-deployment):
- [ ] Monitor error logs for file-related errors
- [ ] Monitor user reports of file issues
- [ ] Check database query performance
- [ ] Verify backup jobs still work

---

## Sign-Off

**Developer:** __________________________ Date: __________
**Reviewer:** __________________________ Date: __________
**Tester:** __________________________ Date: __________
**Deployed By:** __________________________ Date: __________

---

## Notes & Issues

_Use this section to document any issues encountered during implementation:_

```
Issue #1:
  Description:
  Resolution:
  Date:

Issue #2:
  Description:
  Resolution:
  Date:
```

---

**Total Files to Update:** 11
**Total Lines to Change:** ~50-80
**Estimated Time:** 4-6 hours (including testing)
**Risk Level:** MEDIUM (code changes only, no database changes)
**Recommended Window:** Maintenance window or low-traffic period
