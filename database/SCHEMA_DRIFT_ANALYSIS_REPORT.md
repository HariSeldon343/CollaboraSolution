# CollaboraNexio Schema Drift Analysis Report

**Date:** 2025-10-03
**Database:** collaboranexio
**Analyst:** Database Architect
**Status:** CRITICAL - Schema Drift Detected

---

## Executive Summary

A critical schema drift has been identified between the documented schema files and the actual database structure for the `files` table and related `file_versions` table. The production database uses different column naming conventions than what is documented, causing potential bugs and maintenance issues.

**Impact Level:** HIGH
**Affected Tables:** `files`, `file_versions`
**Data at Risk:** 12 files in production database

---

## 1. COMPLETE SCHEMA DIFFERENCES

### 1.1 Files Table - Column Mapping

| Documented Schema (03_complete_schema.sql) | Actual Database | Status | Type Difference |
|---------------------------------------------|-----------------|--------|-----------------|
| `size_bytes` (BIGINT UNSIGNED) | `file_size` (bigint(20)) | **MISMATCH** | Different name, similar type |
| `storage_path` (VARCHAR(500)) | `file_path` (VARCHAR(500)) | **MISMATCH** | Different name, same type |
| `owner_id` (INT UNSIGNED) | `uploaded_by` (INT(11)) | **MISMATCH** | Different name, type variation |
| `checksum` (VARCHAR(64)) | **MISSING** | **CRITICAL** | Column does not exist |
| `tags` (JSON) | **MISSING** | **MISSING** | Column does not exist |
| `metadata` (JSON) | **MISSING** | **MISSING** | Column does not exist |

### 1.2 Additional Columns in Actual Database (Not in Documented Schema)

| Column Name | Type | Purpose |
|-------------|------|---------|
| `original_tenant_id` | INT(10) UNSIGNED | Tenant tracking before deletion |
| `original_name` | VARCHAR(255) | Original filename at upload |
| `is_public` | TINYINT(1) | Public accessibility flag |
| `public_token` | VARCHAR(64) | Token for public access |
| `shared_with` | LONGTEXT (JSON) | JSON array of shared users |
| `download_count` | INT(11) | Download statistics |
| `last_accessed_at` | TIMESTAMP | Last access tracking |
| `reassigned_at` | TIMESTAMP | When file was reassigned |
| `reassigned_by` | INT(10) UNSIGNED | User who reassigned |

### 1.3 File Versions Table - Schema Status

**Status:** Uses DOCUMENTED naming convention (size_bytes, storage_path, uploaded_by)

```sql
-- Actual file_versions structure:
- size_bytes (bigint(20) unsigned) ✓
- storage_path (varchar(500)) ✓
- uploaded_by (int(10) unsigned) ✓
- checksum (varchar(64)) ✓
```

This creates an **INCONSISTENCY** between parent (`files`) and child (`file_versions`) tables!

### 1.4 Folders Table - Schema Status

**Status:** Uses mixed convention with `owner_id`

The `folders` table uses `owner_id`, which matches the DOCUMENTED schema but NOT the actual `files` table that uses `uploaded_by`.

---

## 2. CODE DEPENDENCY ANALYSIS

### 2.1 Usage Statistics in Codebase

| Column Reference | Count in api/ & includes/ | Primary Usage |
|------------------|---------------------------|---------------|
| `file_size` | 41 occurrences | **DOMINANT** |
| `size_bytes` | 16 occurrences | Legacy/documented |
| `file_path` | 18 occurrences | **DOMINANT** |
| `storage_path` | 24 occurrences | Mixed usage |
| `uploaded_by` | 16 occurrences | **DOMINANT** |
| `owner_id` | 62 occurrences | **DOMINANT** (but includes folders!) |

**Note:** `owner_id` count is inflated because it's heavily used in `folders` table and other tables like `projects`.

### 2.2 Critical API Files Using ACTUAL Database Columns (file_size, file_path, uploaded_by)

**Primary Production APIs:**
1. `/api/files.php` - Main file management API (uses ACTUAL schema)
2. `/api/files_tenant.php` - Multi-tenant file API with smart detection
3. `/api/files_tenant_fixed.php` - Fixed version explicitly using file_size
4. `/api/files_tenant_production.php` - Production version with column detection

**Smart Adapters:**
- `/api/files_tenant.php` - Has intelligent column detection using COALESCE
- `/api/files_tenant_debug.php` - Debug version with schema detection

### 2.3 Files Using DOCUMENTED Schema (size_bytes, storage_path, owner_id)

**Schema Definition Files:**
1. `/database/03_complete_schema.sql` - Complete documented schema
2. `/database/04_demo_data.sql` - Demo data (may have references)
3. `/migrations/fix_files_table_migration.sql` - Migration script (MIXED usage!)

**Affected APIs:**
1. `/api/files_complete.php` - Uses documented schema (size_bytes, storage_path, owner_id)
2. `/api/files_enhanced.php` - Uses owner_id for folders
3. `/api/documents/pending.php` - Uses size_bytes and owner_id

### 2.4 Hybrid/Detection Files

Several files implement smart detection:
```php
// From /api/files_tenant.php
if ($this->hasColumn('files', 'file_size')) return 'file_size';
if ($this->hasColumn('files', 'size_bytes')) return 'size_bytes';

// COALESCE approach
"COALESCE(f.file_size, f.size_bytes, f.size) AS size"
```

---

## 3. ROOT CAUSE ANALYSIS

### 3.1 Timeline of Schema Evolution

1. **Initial Design:** Schema was designed with `size_bytes`, `storage_path`, `owner_id`, `checksum`
2. **Implementation Phase:** Database was created with `file_size`, `file_path`, `uploaded_by` (NO checksum)
3. **Development:** Code was written against actual database (file_size, etc.)
4. **Documentation:** Schema files never updated to match actual implementation
5. **Current State:** Split personality - some files use documented, some use actual

### 3.2 Why This Happened

- **Rapid Development:** Schema changes made directly in database without updating docs
- **Multiple Contributors:** Different developers may have used different conventions
- **No Migration Control:** No formal migration system to track schema changes
- **Copy-Paste Coding:** New APIs copied from different sources (some from docs, some from working code)

### 3.3 Critical Findings

1. **file_versions table is ORPHANED** - Uses documented schema but parent table uses actual schema
2. **No checksum column exists** - Security/integrity feature missing
3. **Mixed usage in codebase** - 40% of code may be broken or not using indexes properly

---

## 4. RECOMMENDED APPROACH

### 4.1 Decision Matrix

| Option | Pros | Cons | Recommendation |
|--------|------|------|----------------|
| **A) Migrate DB to match docs** | Clean schema, matches design docs, includes checksum | HIGH RISK: Must rename columns, update 12 files, complex migration | ❌ NOT RECOMMENDED |
| **B) Update code to match actual DB** | LOW RISK: No data changes, works with existing data | Must update ~16 files using documented schema, abandon checksum feature | ✅ **RECOMMENDED** |
| **C) Keep hybrid with COALESCE** | Zero risk, backwards compatible | Performance penalty, technical debt, confusing | ⚠️ Temporary only |

### 4.2 RECOMMENDED SOLUTION: Option B - Normalize Code to Match Database

**Rationale:**
1. **Data Safety First:** 12 production files exist - no risk of data loss
2. **Least Changes:** Only 16 code references to update vs. entire database + all queries
3. **Production Reality:** Actual schema is what's running and working
4. **Foreign Keys:** No FK constraint issues (uploaded_by has no FK anyway)
5. **Performance:** Existing indexes are optimized for actual column names

**What Gets Sacrificed:**
- `checksum` column feature (was never implemented)
- `tags` and `metadata` JSON columns (also never implemented)
- "Ideal" schema design in favor of working reality

---

## 5. MIGRATION PLAN (Option B - Code Normalization)

### 5.1 Phase 1: Update Documentation (SAFE)

**Files to Update:**
1. `/database/03_complete_schema.sql` - Change files table definition
2. `/database/04_demo_data.sql` - Update INSERT statements if needed
3. `/CLAUDE.md` - Update table reference documentation

**Changes Required:**
```sql
-- OLD (Documented)
size_bytes BIGINT UNSIGNED NOT NULL,
storage_path VARCHAR(500) NOT NULL,
owner_id INT UNSIGNED NOT NULL,
checksum VARCHAR(64) NULL,

-- NEW (Actual Production)
file_size BIGINT DEFAULT 0,
file_path VARCHAR(500) DEFAULT NULL,
uploaded_by INT(11) DEFAULT NULL,
-- checksum: REMOVED (not implemented)
```

### 5.2 Phase 2: Update API Files (MEDIUM RISK)

**Priority 1 - Critical APIs using wrong schema:**

1. **`/api/files_complete.php`**
   - Lines affected: 254, 271, 302, 354-458, 480
   - Changes: size_bytes → file_size, storage_path → file_path, owner_id → uploaded_by (for files only!)
   - Risk: HIGH usage API
   - Test: Upload, download, list files

2. **`/api/documents/pending.php`**
   - Lines affected: 39, 49, 61-62, 111-112, 156, 169
   - Changes: size_bytes → file_size, owner_id → uploaded_by
   - Risk: MEDIUM (document approval feature)
   - Test: Document approval workflow

3. **`/api/documents/approve.php` & `/api/documents/reject.php`**
   - Similar changes to pending.php
   - Changes: owner_id → uploaded_by (for files table queries only)
   - Risk: MEDIUM
   - Test: Approve and reject workflows

**Priority 2 - Hybrid Files (Clean Up):**

4. **`/api/files_tenant.php`**
   - Action: Remove COALESCE logic, standardize on actual schema
   - Risk: LOW (already handles both)
   - Test: Tenant-specific file operations

5. **`/api/router.php`**
   - Line 442: size_bytes → file_size
   - Risk: LOW (API routing metrics)

**Priority 3 - Includes:**

6. **`/includes/versioning.php`**
   - Keep as-is (file_versions uses size_bytes correctly)
   - Add comment about schema difference
   - Risk: NONE

### 5.3 Phase 3: Handle file_versions Inconsistency (CRITICAL DECISION)

**The Problem:**
- `files` table: uses `file_size`, `file_path`, `uploaded_by`
- `file_versions` table: uses `size_bytes`, `storage_path`, `uploaded_by`

**Options:**

**A) Rename file_versions columns to match files table** ⚠️
```sql
ALTER TABLE file_versions
  CHANGE COLUMN size_bytes file_size BIGINT,
  CHANGE COLUMN storage_path file_path VARCHAR(500);
```
- Pro: Consistency across related tables
- Con: Must update versioning.php code, risk to version history

**B) Leave file_versions with documented schema** ✅ RECOMMENDED
```sql
-- Keep file_versions as-is
-- Document the difference in code comments
```
- Pro: No risk to version history, clear semantic difference (versions are "archived snapshots")
- Con: Requires mental context switch when working with versions
- Justification: Versions are historical records with different lifecycle than active files

### 5.4 Phase 4: Remove Migration Script References

**Files to Update:**
1. `/migrations/fix_files_table_migration.sql` - Mark as OBSOLETE
2. Add comment: "This migration is obsolete - actual database uses different schema"

### 5.5 Phase 5: Testing & Verification

**Test Checklist:**
```
□ Upload file via /api/files.php
□ Upload file via /api/files_tenant.php
□ List files in folder
□ Download file
□ Search files
□ Move file between folders
□ Delete file (soft delete)
□ Submit document for approval
□ Approve document
□ Reject document
□ Check file statistics/metrics
□ Verify multi-tenant isolation
□ Test file versioning (if implemented)
```

**Verification Queries:**
```sql
-- Verify no broken queries
SELECT f.id, f.name, f.file_size, f.file_path, f.uploaded_by
FROM files f
WHERE f.tenant_id = 1 LIMIT 5;

-- Check file_versions still works
SELECT fv.id, fv.file_id, fv.size_bytes, fv.storage_path
FROM file_versions fv
LIMIT 5;

-- Verify joined queries
SELECT f.name, f.file_size, u.name as uploader
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.tenant_id = 1;
```

---

## 6. ROLLBACK STRATEGY

### 6.1 Code Rollback (Git)

All changes are code-only, so rollback is simple:
```bash
git checkout HEAD~1 -- api/files_complete.php
git checkout HEAD~1 -- api/documents/pending.php
# etc.
```

### 6.2 Database Rollback

**NOT REQUIRED** - No database changes in recommended approach!

### 6.3 Emergency Fallback

If issues arise, use hybrid approach temporarily:
```php
// Emergency compatibility layer
$size_col = column_exists('files', 'file_size') ? 'file_size' : 'size_bytes';
$path_col = column_exists('files', 'file_path') ? 'file_path' : 'storage_path';
$owner_col = column_exists('files', 'uploaded_by') ? 'uploaded_by' : 'owner_id';
```

---

## 7. ALTERNATIVE APPROACH (NOT RECOMMENDED BUT DOCUMENTED)

### Option A: Migrate Database to Match Documentation

If you MUST implement the documented schema:

```sql
-- BACKUP FIRST!
CREATE TABLE files_backup_20251003 AS SELECT * FROM files;

-- Rename columns
ALTER TABLE files
  CHANGE COLUMN file_size size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  CHANGE COLUMN file_path storage_path VARCHAR(500) NOT NULL,
  CHANGE COLUMN uploaded_by owner_id INT UNSIGNED NOT NULL,
  ADD COLUMN checksum VARCHAR(64) NULL AFTER storage_path,
  ADD COLUMN tags JSON NULL AFTER checksum,
  ADD COLUMN metadata JSON NULL AFTER tags;

-- Update indexes
DROP INDEX idx_uploaded_by ON files;
CREATE INDEX idx_owner ON files(owner_id, created_at);

-- Add foreign key
ALTER TABLE files
  ADD CONSTRAINT fk_files_owner
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT;
```

**Risk Level:** 🔴 CRITICAL
**Downtime Required:** Yes (5-10 minutes)
**Code Changes Required:** Update 41 references to file_size, 18 to file_path, 16 to uploaded_by
**Foreign Key Issues:** uploaded_by currently INT(11) NULL, would need to be INT UNSIGNED NOT NULL

---

## 8. IMPLEMENTATION TIMELINE

### Recommended Approach (Option B):

| Phase | Duration | Risk | Can Run in Production? |
|-------|----------|------|------------------------|
| Phase 1: Update Documentation | 1 hour | NONE | ✅ Yes |
| Phase 2: Update Critical APIs | 3 hours | MEDIUM | ⚠️ Test environment first |
| Phase 3: Decision on file_versions | 30 min | LOW | ✅ Documentation only |
| Phase 4: Clean up migrations | 30 min | NONE | ✅ Yes |
| Phase 5: Testing | 2 hours | N/A | Test environment |
| **TOTAL** | **7 hours** | **MEDIUM** | Deploy in maintenance window |

### Alternative Approach (Option A - NOT RECOMMENDED):

| Phase | Duration | Risk | Can Run in Production? |
|-------|----------|------|------------------------|
| Database Backup | 5 min | NONE | ✅ Yes |
| Column Renames | 10 min | 🔴 CRITICAL | ❌ Requires downtime |
| Update ALL Code | 6 hours | HIGH | ❌ Test environment |
| Testing | 4 hours | N/A | Test environment |
| **TOTAL** | **10+ hours** | **🔴 CRITICAL** | Requires maintenance window |

---

## 9. AFFECTED FILES SUMMARY

### Must Update (Option B - Recommended):

**Documentation (3 files):**
1. `/database/03_complete_schema.sql` - Rewrite files table definition
2. `/database/04_demo_data.sql` - Check INSERT statements
3. `/CLAUDE.md` - Update table reference

**Critical APIs (6 files):**
1. `/api/files_complete.php` - size_bytes → file_size, storage_path → file_path, owner_id → uploaded_by
2. `/api/documents/pending.php` - size_bytes → file_size, owner_id → uploaded_by
3. `/api/documents/approve.php` - owner_id → uploaded_by (for files)
4. `/api/documents/reject.php` - owner_id → uploaded_by (for files)
5. `/api/router.php` - size_bytes → file_size in metrics
6. `/api/files_tenant.php` - Remove COALESCE, use actual schema

**Migration Files (2 files):**
1. `/migrations/fix_files_table_migration.sql` - Mark OBSOLETE
2. `/migrations/fix_files_table_migration.sql` - Add warning comment

**Total Code Changes:** 11 files
**Lines of Code Affected:** ~50-80 lines

---

## 10. LONG-TERM RECOMMENDATIONS

### 10.1 Implement Migration System

Implement proper database migration tracking:
```sql
CREATE TABLE schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NOT NULL,
    status ENUM('pending', 'applied', 'failed') DEFAULT 'pending'
);
```

### 10.2 Schema Documentation Policy

1. **Single Source of Truth:** Database is truth, docs follow database
2. **Change Process:** Schema change → Migration script → Update docs → Code update
3. **Review Requirement:** All schema changes require peer review
4. **Testing Requirement:** Schema changes must include test suite updates

### 10.3 Naming Convention Standard

Establish and document standards:
```
Files Table Columns:
- file_size (NOT size_bytes) - size in bytes
- file_path (NOT storage_path) - relative path to file
- uploaded_by (NOT owner_id) - user ID who uploaded

Folders Table Columns:
- owner_id - user ID who owns folder (different semantic meaning)

Versioning Tables:
- Can use size_bytes/storage_path (historical record context)
```

### 10.4 Add Schema Validation Script

Create `/database/validate_schema.php`:
```php
// Compare actual database structure to documented schema
// Alert on drift
// Auto-generate migration suggestions
```

---

## 11. CONCLUSION

### Current Status: 🔴 CRITICAL SCHEMA DRIFT

**Impact:**
- 3 critical APIs using wrong column names
- Document approval system potentially broken
- file_versions table orphaned with different schema
- ~40% of file-related code may be inefficient or broken

### Recommended Action: ✅ OPTION B - Normalize Code to Match Database

**Justification:**
1. ✅ Lowest risk to production data (no database changes)
2. ✅ Fastest implementation (7 hours vs 10+ hours)
3. ✅ No downtime required
4. ✅ Preserves existing working APIs
5. ✅ Clear rollback strategy (git revert)

### Immediate Next Steps:

1. **DO NOT EXECUTE DATABASE CHANGES YET** ✅ (As requested)
2. Review this analysis report with team
3. Decision on file_versions table approach
4. Schedule 4-hour maintenance window for code updates + testing
5. Prepare test suite for verification
6. Create backup of current codebase
7. Execute Phase 1-4 of recommended migration plan
8. Run comprehensive test suite
9. Monitor production for 48 hours post-deployment

### Success Criteria:

- ✅ All file operations work correctly
- ✅ Document approval workflow functional
- ✅ No SQL errors in logs
- ✅ File upload/download statistics accurate
- ✅ Multi-tenant isolation maintained
- ✅ Code grep shows <5 references to old column names
- ✅ Documentation matches reality

---

**Report Prepared By:** Database Architect
**Date:** 2025-10-03
**Classification:** Internal - Technical
**Next Review:** After implementation completion
