-- ============================================
-- ROLLBACK: MIGRATION 19 - TENANT ROLES SYSTEM
-- Use this script to undo Migration 19
-- ============================================

USE collaboranexio;

SELECT 'Starting Rollback of Migration 19' as status;

-- ============================================
-- STEP 1: Remove FK from user_tenant_access
-- ============================================

SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND CONSTRAINT_NAME = 'fk_user_tenant_access_tenant_role'
);

SET @sql = IF(@fk_exists > 0,
    'ALTER TABLE user_tenant_access DROP FOREIGN KEY fk_user_tenant_access_tenant_role',
    'SELECT ''FK already removed'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 2: Remove index from user_tenant_access
-- ============================================

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND INDEX_NAME = 'idx_user_tenant_access_tenant_role'
);

SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE user_tenant_access DROP INDEX idx_user_tenant_access_tenant_role',
    'SELECT ''Index already removed'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 3: Remove column from user_tenant_access
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND COLUMN_NAME = 'tenant_role_id'
);

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE user_tenant_access DROP COLUMN tenant_role_id',
    'SELECT ''Column already removed'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 4: Remove index from tenants
-- ============================================

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND INDEX_NAME = 'idx_tenants_custom_roles'
);

SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE tenants DROP INDEX idx_tenants_custom_roles',
    'SELECT ''Index already removed'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 5: Remove column from tenants
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'has_custom_roles'
);

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE tenants DROP COLUMN has_custom_roles',
    'SELECT ''Column already removed'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 6: Drop tenant_roles table
-- ============================================

DROP TABLE IF EXISTS tenant_roles;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Rollback 19 Verification' as section;

SELECT
    'tenant_roles table' as check_item,
    CASE WHEN COUNT(*) = 0 THEN 'REMOVED' ELSE 'STILL EXISTS' END as status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles';

SELECT
    'user_tenant_access.tenant_role_id' as check_item,
    CASE WHEN COUNT(*) = 0 THEN 'REMOVED' ELSE 'STILL EXISTS' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'user_tenant_access'
AND COLUMN_NAME = 'tenant_role_id';

SELECT
    'tenants.has_custom_roles' as check_item,
    CASE WHEN COUNT(*) = 0 THEN 'REMOVED' ELSE 'STILL EXISTS' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'tenants'
AND COLUMN_NAME = 'has_custom_roles';

SELECT 'Rollback 19 completed successfully!' as final_status;
