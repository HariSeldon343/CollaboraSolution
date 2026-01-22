-- ============================================
-- MIGRATION 19: ADD TENANT ROLES SYSTEM
-- Feature: Ruoli Aziendali (Tenant Roles)
-- Version: 1.0.0
-- Date: 2025-12-15
-- Author: CollaboraNexio Team
-- ============================================
--
-- This migration adds:
-- 1. New table: tenant_roles (custom business roles per tenant)
-- 2. New column: user_tenant_access.tenant_role_id (FK to tenant_roles)
-- 3. New column: tenants.has_custom_roles (flag to indicate if tenant uses custom roles)
--
-- ============================================

USE collaboranexio;

-- Pre-flight check
SELECT 'Starting Migration 19: Add Tenant Roles System' as status;

-- ============================================
-- STEP 1: Create tenant_roles table
-- ============================================

CREATE TABLE IF NOT EXISTS tenant_roles (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant that owns this role',

    -- Role Definition
    name VARCHAR(100) NOT NULL COMMENT 'Role display name (e.g., Commerciale)',
    code VARCHAR(50) NOT NULL COMMENT 'Role code for programmatic use (e.g., commerciale)',
    description TEXT NULL COMMENT 'Optional description of role responsibilities',
    color VARCHAR(7) NULL DEFAULT '#6366f1' COMMENT 'HEX color for UI badges',
    icon VARCHAR(50) NULL COMMENT 'Optional icon identifier',

    -- Ordering and Status
    sort_order INT UNSIGNED DEFAULT 0 COMMENT 'Display order in lists',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can be temporarily disabled',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'User who created this role',

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_tenant_roles_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_tenant_roles_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique Constraints (include deleted_at for soft-delete compatibility)
    -- This allows re-using same name/code after soft delete
    UNIQUE KEY uk_tenant_role_code (tenant_id, code, deleted_at),
    UNIQUE KEY uk_tenant_role_name (tenant_id, name, deleted_at),

    -- Indexes for Multi-Tenant Queries
    INDEX idx_tenant_roles_tenant_active (tenant_id, is_active, deleted_at),
    INDEX idx_tenant_roles_sort (tenant_id, sort_order),
    INDEX idx_tenant_roles_deleted (tenant_id, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Custom business roles defined by each tenant (Ruoli Aziendali)';

SELECT 'Step 1 completed: tenant_roles table created' as status;

-- ============================================
-- STEP 2: Add tenant_role_id to user_tenant_access
-- ============================================

-- Check if column exists before adding
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND COLUMN_NAME = 'tenant_role_id'
);

-- Add column if not exists (using dynamic SQL)
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD COLUMN tenant_role_id INT UNSIGNED NULL
     COMMENT ''FK to tenant_roles.id - company-specific business role''
     AFTER granted_at',
    'SELECT ''Column tenant_role_id already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add FK constraint if not exists
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND CONSTRAINT_NAME = 'fk_user_tenant_access_tenant_role'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD CONSTRAINT fk_user_tenant_access_tenant_role
     FOREIGN KEY (tenant_role_id) REFERENCES tenant_roles(id) ON DELETE SET NULL',
    'SELECT ''FK fk_user_tenant_access_tenant_role already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for quick role-based queries
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND INDEX_NAME = 'idx_user_tenant_access_tenant_role'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD INDEX idx_user_tenant_access_tenant_role (tenant_id, tenant_role_id)',
    'SELECT ''Index idx_user_tenant_access_tenant_role already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Step 2 completed: user_tenant_access modified' as status;

-- ============================================
-- STEP 3: Add has_custom_roles to tenants
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'has_custom_roles'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE tenants
     ADD COLUMN has_custom_roles TINYINT(1) NOT NULL DEFAULT 0
     COMMENT ''TRUE if tenant has defined custom business roles''
     AFTER settings',
    'SELECT ''Column has_custom_roles already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for filtering tenants with roles
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND INDEX_NAME = 'idx_tenants_custom_roles'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE tenants
     ADD INDEX idx_tenants_custom_roles (has_custom_roles)',
    'SELECT ''Index idx_tenants_custom_roles already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Step 3 completed: tenants table modified' as status;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

SELECT '=== Migration 19 Verification ===' as section;

-- Check tenant_roles table exists and has correct structure
SELECT
    'tenant_roles table' as check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles';

-- Check column count for tenant_roles
SELECT
    'tenant_roles columns' as check_item,
    COUNT(*) as count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles';

-- Check user_tenant_access.tenant_role_id
SELECT
    'user_tenant_access.tenant_role_id' as check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'user_tenant_access'
AND COLUMN_NAME = 'tenant_role_id';

-- Check tenants.has_custom_roles
SELECT
    'tenants.has_custom_roles' as check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'tenants'
AND COLUMN_NAME = 'has_custom_roles';

-- Check FK constraints
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
AND (CONSTRAINT_NAME LIKE '%tenant_role%' OR TABLE_NAME = 'tenant_roles');

-- Final summary
SELECT '=== Migration 19 Summary ===' as section;

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles') as tenant_roles_table,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'user_tenant_access'
     AND COLUMN_NAME = 'tenant_role_id') as uta_column,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenants'
     AND COLUMN_NAME = 'has_custom_roles') as tenants_column;

SELECT 'Migration 19 completed successfully!' as final_status;
