-- ============================================
-- Module: Page Visibility Settings
-- Version: 2025-12-12
-- Author: Database Architect
-- Description: Create table for controlling page visibility per role and tenant.
--              Supports both global (super_admin) and tenant-specific configurations.
--
-- Features:
-- - Role-based page visibility (admin, manager, user)
-- - Multi-tenant support (tenant_id NULL = global config)
-- - Soft delete pattern compliance
-- - All pages visible by default (is_visible = TRUE)
--
-- Tables Created:
-- - page_visibility_settings
--
-- Rollback: See 17_create_page_visibility_settings_rollback.sql
-- ============================================

USE collaboranexio;

-- ============================================
-- PRE-FLIGHT CHECKS
-- ============================================

SELECT 'Starting Migration 17: Page Visibility Settings...' AS status;

-- Verify tenant table exists
SELECT 'Checking tenants table...' AS status;
SELECT COUNT(*) AS tenant_count FROM tenants WHERE deleted_at IS NULL;

-- Check if table already exists
SET @table_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'page_visibility_settings'
);

SELECT IF(@table_exists > 0,
    'WARNING: page_visibility_settings table already exists - skipping creation',
    'Table does not exist - proceeding with creation') AS table_check_status;

-- ============================================
-- TABLE CREATION: page_visibility_settings
-- ============================================

CREATE TABLE IF NOT EXISTS page_visibility_settings (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (NULL = global configuration by super_admin)
    -- When tenant_id IS NULL, the setting applies globally as default
    -- When tenant_id IS NOT NULL, it overrides the global setting for that tenant
    tenant_id INT UNSIGNED NULL COMMENT 'NULL for global (super_admin) config, tenant FK for tenant-specific override',

    -- Core visibility fields
    page_name VARCHAR(100) NOT NULL COMMENT 'Page identifier (e.g., dashboard, tasks, calendar, files)',
    role ENUM('admin', 'manager', 'user') NOT NULL COMMENT 'Target role for visibility setting',
    is_visible BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'TRUE = page visible, FALSE = page hidden for role',

    -- Optional metadata
    description VARCHAR(255) NULL COMMENT 'Optional description explaining the visibility rule',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    -- NOTE: tenant_id can be NULL for global settings, so no FK constraint on NULL values
    CONSTRAINT fk_page_visibility_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    -- Unique constraint: one setting per page/role/tenant combination
    -- Using a unique index that handles NULL tenant_id properly
    UNIQUE INDEX uk_page_visibility_config (page_name, role, tenant_id, deleted_at),

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_page_visibility_tenant_created (tenant_id, created_at),
    INDEX idx_page_visibility_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_page_visibility_page (page_name),
    INDEX idx_page_visibility_role (role),
    INDEX idx_page_visibility_visible (is_visible),

    -- Composite index for common query pattern
    INDEX idx_page_visibility_lookup (tenant_id, page_name, role, deleted_at, is_visible)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Page visibility settings per role and tenant. NULL tenant_id = global config.';

SELECT 'page_visibility_settings table created successfully' AS status;

-- ============================================
-- DEFAULT DATA: Global Settings (super_admin)
-- All pages visible by default for all roles
-- ============================================

SELECT 'Inserting default global visibility settings...' AS status;

-- Define pages to configure
-- Area Operativa: dashboard, files, calendar, tasks, ticket, conformita, ai
-- Gestione: aziende
-- Amministrazione: utenti, audit_log, configurazioni

-- Insert global defaults (tenant_id = NULL) for all pages and roles
-- Using INSERT IGNORE to avoid duplicates on re-run

-- DASHBOARD - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'dashboard', 'admin', TRUE, 'Dashboard - Area Operativa', NOW()),
    (NULL, 'dashboard', 'manager', TRUE, 'Dashboard - Area Operativa', NOW()),
    (NULL, 'dashboard', 'user', TRUE, 'Dashboard - Area Operativa', NOW());

-- FILES - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'files', 'admin', TRUE, 'File Manager - Area Operativa', NOW()),
    (NULL, 'files', 'manager', TRUE, 'File Manager - Area Operativa', NOW()),
    (NULL, 'files', 'user', TRUE, 'File Manager - Area Operativa', NOW());

-- CALENDAR - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'calendar', 'admin', TRUE, 'Calendario - Area Operativa', NOW()),
    (NULL, 'calendar', 'manager', TRUE, 'Calendario - Area Operativa', NOW()),
    (NULL, 'calendar', 'user', TRUE, 'Calendario - Area Operativa', NOW());

-- TASKS - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'tasks', 'admin', TRUE, 'Task Management - Area Operativa', NOW()),
    (NULL, 'tasks', 'manager', TRUE, 'Task Management - Area Operativa', NOW()),
    (NULL, 'tasks', 'user', TRUE, 'Task Management - Area Operativa', NOW());

-- TICKET - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'ticket', 'admin', TRUE, 'Ticket System - Area Operativa', NOW()),
    (NULL, 'ticket', 'manager', TRUE, 'Ticket System - Area Operativa', NOW()),
    (NULL, 'ticket', 'user', TRUE, 'Ticket System - Area Operativa', NOW());

-- CONFORMITA - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'conformita', 'admin', TRUE, 'Conformita - Area Operativa', NOW()),
    (NULL, 'conformita', 'manager', TRUE, 'Conformita - Area Operativa', NOW()),
    (NULL, 'conformita', 'user', TRUE, 'Conformita - Area Operativa', NOW());

-- AI - visible to all roles
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'ai', 'admin', TRUE, 'AI Assistant - Area Operativa', NOW()),
    (NULL, 'ai', 'manager', TRUE, 'AI Assistant - Area Operativa', NOW()),
    (NULL, 'ai', 'user', TRUE, 'AI Assistant - Area Operativa', NOW());

-- AZIENDE (Gestione) - typically admin and manager only, but default visible
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'aziende', 'admin', TRUE, 'Gestione Aziende - Gestione', NOW()),
    (NULL, 'aziende', 'manager', TRUE, 'Gestione Aziende - Gestione', NOW()),
    (NULL, 'aziende', 'user', TRUE, 'Gestione Aziende - Gestione', NOW());

-- UTENTI (Amministrazione) - typically admin only, but default visible
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'utenti', 'admin', TRUE, 'Gestione Utenti - Amministrazione', NOW()),
    (NULL, 'utenti', 'manager', TRUE, 'Gestione Utenti - Amministrazione', NOW()),
    (NULL, 'utenti', 'user', TRUE, 'Gestione Utenti - Amministrazione', NOW());

-- AUDIT_LOG (Amministrazione) - typically admin only, but default visible
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'audit_log', 'admin', TRUE, 'Audit Log - Amministrazione', NOW()),
    (NULL, 'audit_log', 'manager', TRUE, 'Audit Log - Amministrazione', NOW()),
    (NULL, 'audit_log', 'user', TRUE, 'Audit Log - Amministrazione', NOW());

-- CONFIGURAZIONI (Amministrazione) - typically admin only, but default visible
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (NULL, 'configurazioni', 'admin', TRUE, 'Configurazioni Sistema - Amministrazione', NOW()),
    (NULL, 'configurazioni', 'manager', TRUE, 'Configurazioni Sistema - Amministrazione', NOW()),
    (NULL, 'configurazioni', 'user', TRUE, 'Configurazioni Sistema - Amministrazione', NOW());

SELECT 'Default global visibility settings inserted successfully' AS status;

-- ============================================
-- SAMPLE TENANT-SPECIFIC OVERRIDES (OPTIONAL)
-- Example: Hide admin pages from 'user' role for tenant 1
-- Uncomment to apply
-- ============================================

/*
-- Example: Hide 'utenti' page from 'user' role for tenant 1
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES (1, 'utenti', 'user', FALSE, 'Override: Hide user management from regular users', NOW());

-- Example: Hide 'audit_log' from 'user' and 'manager' for tenant 1
INSERT IGNORE INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
VALUES
    (1, 'audit_log', 'user', FALSE, 'Override: Hide audit log from regular users', NOW()),
    (1, 'audit_log', 'manager', FALSE, 'Override: Hide audit log from managers', NOW());
*/

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

SELECT 'Running verification queries...' AS status;

-- Count total settings
SELECT 'Total visibility settings:' AS metric,
       (SELECT COUNT(*) FROM page_visibility_settings WHERE deleted_at IS NULL) AS count;

-- Count by type (global vs tenant-specific)
SELECT 'Global settings (tenant_id NULL):' AS metric,
       (SELECT COUNT(*) FROM page_visibility_settings WHERE tenant_id IS NULL AND deleted_at IS NULL) AS count;

SELECT 'Tenant-specific settings:' AS metric,
       (SELECT COUNT(*) FROM page_visibility_settings WHERE tenant_id IS NOT NULL AND deleted_at IS NULL) AS count;

-- List all unique pages configured
SELECT 'Unique pages configured:' AS metric,
       GROUP_CONCAT(DISTINCT page_name ORDER BY page_name SEPARATOR ', ') AS pages
FROM page_visibility_settings
WHERE deleted_at IS NULL;

-- Summary by page and role
SELECT page_name,
       role,
       CASE WHEN tenant_id IS NULL THEN 'GLOBAL' ELSE CONCAT('Tenant ', tenant_id) END AS scope,
       IF(is_visible, 'VISIBLE', 'HIDDEN') AS visibility
FROM page_visibility_settings
WHERE deleted_at IS NULL
ORDER BY page_name, role, tenant_id;

-- Verify indexes exist
SELECT 'Verifying indexes...' AS status;
SHOW INDEX FROM page_visibility_settings WHERE Key_name LIKE 'idx_%' OR Key_name LIKE 'uk_%';

-- Verify foreign keys
SELECT 'Verifying foreign keys...' AS status;
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'page_visibility_settings'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Check table structure
SELECT 'Table structure:' AS status;
DESCRIBE page_visibility_settings;

-- ============================================
-- USAGE EXAMPLES (INFORMATIONAL)
-- ============================================

/*
-- EXAMPLE 1: Check if a page is visible for a specific role and tenant
-- Logic: Check tenant-specific first, then fall back to global

SELECT COALESCE(
    -- First, check tenant-specific setting
    (SELECT is_visible
     FROM page_visibility_settings
     WHERE page_name = 'dashboard'
       AND role = 'user'
       AND tenant_id = 1
       AND deleted_at IS NULL
     LIMIT 1),
    -- Fall back to global setting
    (SELECT is_visible
     FROM page_visibility_settings
     WHERE page_name = 'dashboard'
       AND role = 'user'
       AND tenant_id IS NULL
       AND deleted_at IS NULL
     LIMIT 1),
    -- Default if no setting exists
    TRUE
) AS is_page_visible;


-- EXAMPLE 2: Get all visible pages for a role in a tenant
SELECT DISTINCT pvs.page_name
FROM page_visibility_settings pvs
WHERE pvs.role = 'manager'
  AND pvs.deleted_at IS NULL
  AND (
      -- Tenant-specific setting exists and is visible
      (pvs.tenant_id = 1 AND pvs.is_visible = TRUE)
      OR
      -- No tenant-specific setting, global is visible
      (pvs.tenant_id IS NULL
       AND pvs.is_visible = TRUE
       AND NOT EXISTS (
           SELECT 1 FROM page_visibility_settings pv2
           WHERE pv2.page_name = pvs.page_name
             AND pv2.role = pvs.role
             AND pv2.tenant_id = 1
             AND pv2.deleted_at IS NULL
       ))
  );


-- EXAMPLE 3: Override global setting for a specific tenant
INSERT INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description)
VALUES (2, 'audit_log', 'user', FALSE, 'Hide audit log from users in tenant 2');


-- EXAMPLE 4: Restore default visibility (soft delete override)
UPDATE page_visibility_settings
SET deleted_at = NOW()
WHERE tenant_id = 2
  AND page_name = 'audit_log'
  AND role = 'user'
  AND deleted_at IS NULL;
*/

-- ============================================
-- MIGRATION COMPLETE
-- ============================================

SELECT '=== MIGRATION 17 COMPLETE ===' AS status,
       (SELECT COUNT(*) FROM page_visibility_settings WHERE deleted_at IS NULL) AS total_settings,
       (SELECT COUNT(DISTINCT page_name) FROM page_visibility_settings WHERE deleted_at IS NULL) AS unique_pages,
       NOW() AS executed_at;

-- Final table info
SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_ROWS,
    DATA_LENGTH,
    INDEX_LENGTH,
    TABLE_COLLATION,
    TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'page_visibility_settings';
