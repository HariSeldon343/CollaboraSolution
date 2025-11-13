-- ============================================
-- FILES TABLE STRUCTURE VERIFICATION
-- Post create_document API Fix
-- Date: 2025-10-12
-- ============================================

USE collaboranexio;

-- ============================================
-- 1. DESCRIBE TABLE STRUCTURE
-- ============================================

SELECT '=== FILES TABLE STRUCTURE ===' as '';
DESCRIBE files;

-- ============================================
-- 2. VERIFY REQUIRED COLUMNS
-- ============================================

SELECT '=== REQUIRED COLUMNS CHECK ===' as '';

SELECT
    'id' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'id'

UNION ALL

SELECT
    'tenant_id' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'tenant_id'

UNION ALL

SELECT
    'name' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'name'

UNION ALL

SELECT
    'file_path' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'file_path'

UNION ALL

SELECT
    'file_size' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'file_size'

UNION ALL

SELECT
    'file_type' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'file_type'

UNION ALL

SELECT
    'mime_type' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'mime_type'

UNION ALL

SELECT
    'folder_id' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'folder_id'

UNION ALL

SELECT
    'uploaded_by' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'uploaded_by'

UNION ALL

SELECT
    'is_folder' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'is_folder'

UNION ALL

SELECT
    'created_at' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'created_at'

UNION ALL

SELECT
    'updated_at' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'updated_at'

UNION ALL

SELECT
    'deleted_at' as column_name,
    COUNT(*) as exists_count,
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'deleted_at';

-- ============================================
-- 3. CHECK FOR DEPRECATED COLUMNS
-- ============================================

SELECT '=== DEPRECATED COLUMNS CHECK ===' as '';

SELECT
    COLUMN_NAME as deprecated_column,
    DATA_TYPE,
    COLUMN_TYPE,
    'Should be removed' as recommendation
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME IN ('path', 'size', 'extension', 'is_editable', 'editor_format', 'file_hash');

-- ============================================
-- 4. VERIFY INDEXES
-- ============================================

SELECT '=== INDEX VERIFICATION ===' as '';

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    INDEX_TYPE,
    NON_UNIQUE,
    CASE
        WHEN INDEX_NAME = 'PRIMARY' THEN 'Primary Key'
        WHEN NON_UNIQUE = 0 THEN 'Unique Index'
        ELSE 'Index'
    END as index_category
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
GROUP BY INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY
    CASE
        WHEN INDEX_NAME = 'PRIMARY' THEN 1
        WHEN INDEX_NAME LIKE 'idx_file_tenant%' THEN 2
        WHEN INDEX_NAME LIKE 'idx_tenant%' THEN 3
        ELSE 4
    END,
    INDEX_NAME;

-- ============================================
-- 5. VERIFY FOREIGN KEYS
-- ============================================

SELECT '=== FOREIGN KEY CONSTRAINTS ===' as '';

SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME,
    DELETE_RULE,
    UPDATE_RULE
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- ============================================
-- 6. DATA INTEGRITY CHECKS
-- ============================================

SELECT '=== DATA INTEGRITY CHECKS ===' as '';

-- Check for files with invalid tenant_id
SELECT
    'Invalid tenant references' as check_type,
    COUNT(*) as count,
    CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'ISSUES FOUND' END as status
FROM files f
LEFT JOIN tenants t ON f.tenant_id = t.id
WHERE f.tenant_id IS NOT NULL AND t.id IS NULL AND f.deleted_at IS NULL

UNION ALL

-- Check for files with invalid uploaded_by
SELECT
    'Invalid user references' as check_type,
    COUNT(*) as count,
    CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'WARNING' END as status
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE f.uploaded_by IS NOT NULL AND u.id IS NULL AND f.deleted_at IS NULL

UNION ALL

-- Check for files with invalid folder_id
SELECT
    'Invalid folder references' as check_type,
    COUNT(*) as count,
    CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'ISSUES FOUND' END as status
FROM files f
LEFT JOIN files parent ON f.folder_id = parent.id
WHERE f.folder_id IS NOT NULL AND parent.id IS NULL AND f.deleted_at IS NULL

UNION ALL

-- Check for files without tenant_id
SELECT
    'Files without tenant_id' as check_type,
    COUNT(*) as count,
    CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'CRITICAL' END as status
FROM files
WHERE tenant_id IS NULL AND deleted_at IS NULL;

-- ============================================
-- 7. STATISTICS
-- ============================================

SELECT '=== FILE STATISTICS ===' as '';

SELECT
    COUNT(*) as total_records,
    SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) as active_records,
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as soft_deleted_records,
    SUM(CASE WHEN is_folder = 1 AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_folders,
    SUM(CASE WHEN is_folder = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_files,
    COUNT(DISTINCT tenant_id) as tenants_with_files
FROM files;

-- ============================================
-- 8. SAMPLE RECORDS
-- ============================================

SELECT '=== SAMPLE RECORDS (First 5) ===' as '';

SELECT
    id,
    tenant_id,
    name,
    file_type,
    file_size,
    is_folder,
    uploaded_by,
    created_at,
    CASE WHEN deleted_at IS NULL THEN 'Active' ELSE 'Deleted' END as status
FROM files
ORDER BY created_at DESC
LIMIT 5;

-- ============================================
-- SUMMARY
-- ============================================

SELECT '=== VERIFICATION SUMMARY ===' as '';

SELECT
    CASE
        WHEN (
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = 'files'
            AND COLUMN_NAME IN ('id', 'tenant_id', 'name', 'file_path', 'file_size',
                                'file_type', 'mime_type', 'folder_id', 'uploaded_by',
                                'is_folder', 'created_at', 'updated_at', 'deleted_at')
        ) = 13 THEN 'PASS'
        ELSE 'FAIL'
    END as required_columns_check,

    CASE
        WHEN (
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = 'files'
            AND INDEX_NAME IN ('PRIMARY', 'idx_tenant', 'idx_folder', 'idx_deleted')
        ) >= 4 THEN 'PASS'
        ELSE 'FAIL'
    END as required_indexes_check,

    CASE
        WHEN (
            SELECT COUNT(*) FROM files f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.tenant_id IS NOT NULL AND t.id IS NULL AND f.deleted_at IS NULL
        ) = 0 THEN 'PASS'
        ELSE 'FAIL'
    END as data_integrity_check,

    CASE
        WHEN (
            SELECT COUNT(*) FROM files
            WHERE tenant_id IS NULL AND deleted_at IS NULL
        ) = 0 THEN 'PASS'
        ELSE 'FAIL'
    END as tenant_isolation_check;

SELECT '=== VERIFICATION COMPLETE ===' as '';
