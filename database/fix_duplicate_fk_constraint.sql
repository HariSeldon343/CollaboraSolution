-- ============================================
-- Module: Database Schema Cleanup
-- Version: 2025-10-16
-- Author: Database Architect
-- Description: Remove duplicate foreign key constraint on files.uploaded_by
-- Issue: Two FK constraints exist for same column (fk_file_uploaded_by, fk_files_uploaded_by)
-- Resolution: Keep fk_files_uploaded_by (follows naming convention), remove fk_file_uploaded_by
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFICATION - BEFORE CLEANUP
-- ============================================

SELECT 'Current foreign key constraints on files.uploaded_by:' as status;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'uploaded_by'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Expected result: 2 rows (fk_file_uploaded_by, fk_files_uploaded_by)

-- ============================================
-- CLEANUP - REMOVE DUPLICATE CONSTRAINT
-- ============================================

SELECT 'Removing duplicate foreign key constraint...' as status;

-- Drop the legacy constraint (fk_file_uploaded_by)
-- Keep the standard one (fk_files_uploaded_by)
ALTER TABLE files
DROP FOREIGN KEY fk_file_uploaded_by;

-- ============================================
-- VERIFICATION - AFTER CLEANUP
-- ============================================

SELECT 'Remaining foreign key constraints on files.uploaded_by:' as status;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'uploaded_by'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Expected result: 1 row (fk_files_uploaded_by only)

-- ============================================
-- FINAL VERIFICATION
-- ============================================

SELECT 'All foreign key constraints on files table:' as status;

SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- Expected result: 5 constraints total
-- 1. fk_files_folder_self       (folder_id → files.id)
-- 2. fk_files_last_edited_by    (last_edited_by → users.id)
-- 3. fk_files_last_modified_by  (last_modified_by → users.id)
-- 4. fk_files_tenant            (tenant_id → tenants.id)
-- 5. fk_files_uploaded_by       (uploaded_by → users.id)

SELECT 'Cleanup completed successfully!' as status,
       NOW() as executed_at;

-- ============================================
-- NOTES
-- ============================================

/*
BEFORE:
- files.uploaded_by had 2 foreign key constraints:
  1. fk_file_uploaded_by (legacy, inconsistent naming)
  2. fk_files_uploaded_by (standard, follows table_column pattern)

AFTER:
- files.uploaded_by has 1 foreign key constraint:
  1. fk_files_uploaded_by (standard naming convention)

IMPACT:
- No functional impact - duplicate constraint removed
- Improves schema clarity and consistency
- Follows CollaboraNexio naming convention: fk_{table}_{reference}

CASCADE BEHAVIOR PRESERVED:
- ON DELETE SET NULL (user deletion sets uploaded_by to NULL)
- ON UPDATE CASCADE (user ID changes propagate)

ROLLBACK (if needed):
ALTER TABLE files
ADD CONSTRAINT fk_file_uploaded_by
FOREIGN KEY (uploaded_by) REFERENCES users(id)
ON DELETE SET NULL ON UPDATE CASCADE;
*/
