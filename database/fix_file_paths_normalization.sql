-- ============================================================================
-- FIX FILE PATHS - Normalize to Filename-Only Format
-- ============================================================================
--
-- ISSUE: Files table contains inconsistent file_path formats:
--   - Old format: uploads/tenant_1/sample_doc_68eb8ceb36125.pdf
--   - New format: sample_doc_68eb8ceb36125.pdf
--
-- API EXPECTATION: file_path should contain ONLY the unique filename
-- API constructs full path as: UPLOAD_PATH/tenant_id/file_path
--
-- This script normalizes all file_path entries to contain filename only.
--
-- Date: 2025-10-15
-- Author: Automated Fix for PDF Viewer 404 Error
-- ============================================================================

-- Step 1: Diagnostic - Show current path formats
SELECT '==== BEFORE FIX: Current File Path Formats ====' as step;

SELECT
    id,
    name,
    file_path,
    tenant_id,
    CASE
        WHEN file_path LIKE 'uploads/%' THEN 'Legacy Full Path (NEEDS FIX)'
        WHEN file_path LIKE '%/%' AND file_path != '/' THEN 'Contains Slashes (CHECK)'
        ELSE 'Filename Only (OK)'
    END as path_format,
    deleted_at
FROM files
WHERE is_folder = 0
ORDER BY id;

-- Step 2: Show count of files needing fix
SELECT '==== Files Requiring Normalization ====' as step;

SELECT
    COUNT(*) as files_needing_fix,
    GROUP_CONCAT(id) as affected_file_ids
FROM files
WHERE
    is_folder = 0
    AND file_path LIKE 'uploads/%'
    AND deleted_at IS NULL;

-- Step 3: Backup current state (for rollback)
SELECT '==== Creating Backup ====' as step;

CREATE TABLE IF NOT EXISTS files_path_backup_20251015 AS
SELECT
    id,
    file_path as old_file_path,
    NOW() as backup_date
FROM files
WHERE
    is_folder = 0
    AND file_path LIKE 'uploads/%';

-- Step 4: Fix files with legacy full paths
SELECT '==== Applying Fix ====' as step;

UPDATE files
SET
    file_path = SUBSTRING_INDEX(file_path, '/', -1),
    updated_at = NOW()
WHERE
    is_folder = 0
    AND file_path LIKE 'uploads/%'
    AND deleted_at IS NULL;

-- Step 5: Verification - Check results
SELECT '==== AFTER FIX: Verification ====' as step;

SELECT
    id,
    name,
    file_path,
    tenant_id,
    CASE
        WHEN file_path LIKE 'uploads/%' THEN 'STILL HAS FULL PATH (ERROR)'
        WHEN file_path LIKE '%/%' AND file_path != '/' THEN 'STILL HAS SLASHES (WARNING)'
        ELSE 'Filename Only (OK)'
    END as path_format,
    updated_at
FROM files
WHERE is_folder = 0
ORDER BY id;

-- Step 6: Summary report
SELECT '==== Fix Summary ====' as step;

SELECT
    'Files Fixed' as metric,
    COUNT(*) as count
FROM files f
LEFT JOIN files_path_backup_20251015 b ON f.id = b.id
WHERE b.id IS NOT NULL;

-- Step 7: Show specific file ID 36 (test case)
SELECT '==== Test File (ID 36) Status ====' as step;

SELECT
    f.id,
    f.name,
    f.file_path as new_path,
    b.old_file_path,
    CONCAT('Expected physical location: ', 'UPLOAD_PATH/', f.tenant_id, '/', f.file_path) as expected_location,
    f.updated_at
FROM files f
LEFT JOIN files_path_backup_20251015 b ON f.id = b.id
WHERE f.id = 36;

-- Step 8: Rollback script (comment out - for reference only)
-- To rollback this fix, run:
/*
UPDATE files f
JOIN files_path_backup_20251015 b ON f.id = b.id
SET f.file_path = b.old_file_path,
    f.updated_at = NOW()
WHERE f.is_folder = 0;
*/

SELECT '==== Fix Complete ====' as step;
SELECT 'Run automated_pdf_test.php again to verify all tests pass' as next_step;
