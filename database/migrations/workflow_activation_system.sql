-- ============================================
-- WORKFLOW ACTIVATION AND CONFIGURATION SYSTEM
-- Module: Document Workflow Extension
-- Version: 1.0.0
-- Date: 2025-11-02
-- Author: Database Architect
-- Description: Enable/disable workflow per tenant or folder with inheritance
-- ============================================

USE collaboranexio;

-- ============================================
-- TABLE: WORKFLOW_SETTINGS
-- Purpose: Granular workflow activation for tenants and folders
-- Business Rule: Folder inherits from parent if no setting exists
-- ============================================

CREATE TABLE IF NOT EXISTS workflow_settings (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Scope (either tenant-wide OR folder-specific, NOT both)
    scope_type ENUM('tenant', 'folder') NOT NULL COMMENT 'Applies to tenant or specific folder',
    folder_id INT UNSIGNED NULL COMMENT 'NULL if scope=tenant, folder ID if scope=folder',

    -- Workflow Configuration
    workflow_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Workflow active for this scope',
    auto_create_workflow TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto-create workflow on file upload (if enabled)',

    -- Advanced Configuration (future-proof with JSON)
    require_validation TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Requires validator approval',
    require_approval TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Requires approver after validation',
    auto_approve_on_validation TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Skip approver if validator approves',

    -- Inheritance Metadata (for debugging)
    inherit_from_parent TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Folder inherits from parent if no setting',
    override_parent TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'TRUE = explicit override, FALSE = uses parent settings',

    -- Metadata (JSON for future extensions)
    settings_metadata JSON NULL COMMENT 'Additional configuration: allowed_file_types, max_validators, etc.',

    -- Audit: Who configured workflow
    configured_by_user_id INT UNSIGNED NULL COMMENT 'Manager/Super Admin who configured',
    configuration_reason TEXT NULL COMMENT 'Why workflow was enabled/disabled',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete - configuration disabled',

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys (CASCADE for tenant isolation)
    CONSTRAINT fk_workflow_settings_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_workflow_settings_folder
        FOREIGN KEY (folder_id)
        REFERENCES folders(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_workflow_settings_configured_by
        FOREIGN KEY (configured_by_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique Constraints
    -- Constraint 1: Only ONE active tenant-wide configuration per tenant (Prevents multiple tenant-wide configs)
    UNIQUE KEY uk_workflow_settings_tenant (tenant_id, scope_type, deleted_at),

    -- Constraint 2: Only ONE active folder configuration per folder (Prevents duplicate folder configs)
    UNIQUE KEY uk_workflow_settings_folder (folder_id, deleted_at),

    -- CHECK Constraints (MySQL 8.0+)
    -- Tenant scope MUST have NULL folder_id, folder scope MUST have folder_id
    CONSTRAINT chk_workflow_settings_scope_consistency
        CHECK (
            (scope_type = 'tenant' AND folder_id IS NULL) OR
            (scope_type = 'folder' AND folder_id IS NOT NULL)
        ),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_workflow_settings_tenant_created (tenant_id, created_at),
    INDEX idx_workflow_settings_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_workflow_settings_folder (folder_id, deleted_at),
    INDEX idx_workflow_settings_scope (scope_type, workflow_enabled, deleted_at),
    INDEX idx_workflow_settings_enabled (tenant_id, workflow_enabled, deleted_at),
    INDEX idx_workflow_settings_configured_by (configured_by_user_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Workflow activation configuration per tenant or folder with inheritance';

-- ============================================
-- HELPER FUNCTION: Get Effective Workflow Status
-- Purpose: Resolve workflow status with inheritance (folder → parent → tenant)
-- Returns: 1 (enabled) or 0 (disabled)
-- ============================================

DELIMITER //

CREATE FUNCTION IF NOT EXISTS get_workflow_enabled_for_folder(
    p_tenant_id INT UNSIGNED,
    p_folder_id INT UNSIGNED
)
RETURNS TINYINT(1)
DETERMINISTIC
READS SQL DATA
-- Get effective workflow status for folder (with inheritance)
BEGIN
    DECLARE v_workflow_enabled TINYINT(1) DEFAULT 0;
    DECLARE v_current_folder_id INT UNSIGNED;
    DECLARE v_parent_folder_id INT UNSIGNED;
    DECLARE v_max_depth INT DEFAULT 10; -- Prevent infinite loop
    DECLARE v_depth INT DEFAULT 0;

    -- Step 1: Check if folder has explicit setting
    SELECT workflow_enabled INTO v_workflow_enabled
    FROM workflow_settings
    WHERE tenant_id = p_tenant_id
      AND scope_type = 'folder'
      AND folder_id = p_folder_id
      AND deleted_at IS NULL
    LIMIT 1;

    -- Found explicit folder setting
    IF v_workflow_enabled IS NOT NULL THEN
        RETURN v_workflow_enabled;
    END IF;

    -- Step 2: Walk up folder hierarchy to find inherited setting
    SET v_current_folder_id = p_folder_id;

    WHILE v_current_folder_id IS NOT NULL AND v_depth < v_max_depth DO
        -- Get parent folder ID
        SELECT parent_id INTO v_parent_folder_id
        FROM folders
        WHERE id = v_current_folder_id
          AND tenant_id = p_tenant_id
          AND deleted_at IS NULL
        LIMIT 1;

        -- Check if parent folder has workflow setting
        IF v_parent_folder_id IS NOT NULL THEN
            SELECT workflow_enabled INTO v_workflow_enabled
            FROM workflow_settings
            WHERE tenant_id = p_tenant_id
              AND scope_type = 'folder'
              AND folder_id = v_parent_folder_id
              AND deleted_at IS NULL
            LIMIT 1;

            -- Found parent setting
            IF v_workflow_enabled IS NOT NULL THEN
                RETURN v_workflow_enabled;
            END IF;
        END IF;

        -- Move up hierarchy
        SET v_current_folder_id = v_parent_folder_id;
        SET v_depth = v_depth + 1;
    END WHILE;

    -- Step 3: No folder settings found, check tenant-wide setting
    SELECT workflow_enabled INTO v_workflow_enabled
    FROM workflow_settings
    WHERE tenant_id = p_tenant_id
      AND scope_type = 'tenant'
      AND deleted_at IS NULL
    LIMIT 1;

    -- Return tenant setting (or 0 if not configured)
    RETURN COALESCE(v_workflow_enabled, 0);
END //

DELIMITER ;

-- ============================================
-- DEMO DATA INSERTION
-- ============================================

-- Insert tenant-wide workflow disabled by default (if tenant_id=1 exists)
INSERT INTO workflow_settings (
    tenant_id,
    scope_type,
    folder_id,
    workflow_enabled,
    auto_create_workflow,
    require_validation,
    require_approval,
    configured_by_user_id,
    configuration_reason
)
SELECT
    1,                      -- tenant_id
    'tenant',               -- scope_type
    NULL,                   -- folder_id (tenant-wide)
    0,                      -- workflow_enabled (disabled by default)
    1,                      -- auto_create_workflow
    1,                      -- require_validation
    1,                      -- require_approval
    1,                      -- configured_by_user_id (assume super_admin id=1)
    'Default tenant workflow configuration - disabled by default'
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_settings WHERE tenant_id = 1 AND scope_type = 'tenant'
)
AND EXISTS (SELECT 1 FROM tenants WHERE id = 1)
AND EXISTS (SELECT 1 FROM users WHERE id = 1);

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Verify table creation
SELECT
    'Migration completed successfully' as status,
    (SELECT COUNT(*) FROM workflow_settings) as workflow_settings_count,
    NOW() as executed_at;

-- Verify indexes
SHOW INDEX FROM workflow_settings WHERE Key_name LIKE 'idx_%';

-- Test helper function (if tenant_id=1 exists)
SELECT
    'Function test' as test_type,
    get_workflow_enabled_for_folder(1, 1) as workflow_enabled_result,
    'Expected: 0 (disabled by default)' as expected;

-- ============================================
-- COMMON QUERY PATTERNS
-- ============================================

-- ============================================
-- Pattern 1: Check if workflow enabled for file
-- ============================================
/*
Usage: Before creating file or checking access

SELECT
    f.id,
    f.name,
    f.folder_id,
    COALESCE(
        get_workflow_enabled_for_folder(f.tenant_id, f.folder_id),
        0
    ) as workflow_enabled
FROM files f
WHERE f.id = ?
  AND f.tenant_id = ?
  AND f.deleted_at IS NULL;
*/

-- ============================================
-- Pattern 2: Enable workflow for entire tenant
-- ============================================
/*
Usage: Manager/Admin enables workflow for all documents

INSERT INTO workflow_settings (
    tenant_id,
    scope_type,
    folder_id,
    workflow_enabled,
    auto_create_workflow,
    require_validation,
    require_approval,
    configured_by_user_id,
    configuration_reason
) VALUES (
    ?,          -- tenant_id
    'tenant',   -- scope_type
    NULL,       -- folder_id (tenant-wide)
    1,          -- workflow_enabled
    1,          -- auto_create_workflow
    1,          -- require_validation
    1,          -- require_approval
    ?,          -- configured_by_user_id
    ?           -- configuration_reason
)
ON DUPLICATE KEY UPDATE
    workflow_enabled = VALUES(workflow_enabled),
    auto_create_workflow = VALUES(auto_create_workflow),
    require_validation = VALUES(require_validation),
    require_approval = VALUES(require_approval),
    configured_by_user_id = VALUES(configured_by_user_id),
    configuration_reason = VALUES(configuration_reason),
    updated_at = CURRENT_TIMESTAMP;
*/

-- ============================================
-- Pattern 3: Enable workflow for specific folder
-- ============================================
/*
Usage: Manager enables workflow ONLY for "Contratti" folder

INSERT INTO workflow_settings (
    tenant_id,
    scope_type,
    folder_id,
    workflow_enabled,
    auto_create_workflow,
    require_validation,
    require_approval,
    override_parent,
    configured_by_user_id,
    configuration_reason
) VALUES (
    ?,          -- tenant_id
    'folder',   -- scope_type
    ?,          -- folder_id (specific folder)
    1,          -- workflow_enabled
    1,          -- auto_create_workflow
    1,          -- require_validation
    1,          -- require_approval
    1,          -- override_parent (explicit override)
    ?,          -- configured_by_user_id
    ?           -- configuration_reason
)
ON DUPLICATE KEY UPDATE
    workflow_enabled = VALUES(workflow_enabled),
    auto_create_workflow = VALUES(auto_create_workflow),
    require_validation = VALUES(require_validation),
    require_approval = VALUES(require_approval),
    override_parent = VALUES(override_parent),
    configured_by_user_id = VALUES(configured_by_user_id),
    configuration_reason = VALUES(configuration_reason),
    updated_at = CURRENT_TIMESTAMP;
*/

-- ============================================
-- Pattern 4: Disable workflow for tenant
-- ============================================
/*
Usage: Manager disables workflow for all tenant documents

UPDATE workflow_settings
SET workflow_enabled = 0,
    configured_by_user_id = ?,
    configuration_reason = ?,
    updated_at = CURRENT_TIMESTAMP
WHERE tenant_id = ?
  AND scope_type = 'tenant'
  AND deleted_at IS NULL;
*/

-- ============================================
-- Pattern 5: Disable workflow for folder (soft delete)
-- ============================================
/*
Usage: Revoke folder-specific workflow configuration (inherits from parent/tenant)

UPDATE workflow_settings
SET deleted_at = CURRENT_TIMESTAMP
WHERE tenant_id = ?
  AND scope_type = 'folder'
  AND folder_id = ?
  AND deleted_at IS NULL;

-- OR disable without deleting (keeps audit trail)
UPDATE workflow_settings
SET workflow_enabled = 0,
    configured_by_user_id = ?,
    configuration_reason = ?,
    updated_at = CURRENT_TIMESTAMP
WHERE tenant_id = ?
  AND scope_type = 'folder'
  AND folder_id = ?
  AND deleted_at IS NULL;
*/

-- ============================================
-- Pattern 6: Get all folders with workflow enabled (with inheritance)
-- ============================================
/*
Usage: Dashboard showing all folders requiring workflow

WITH RECURSIVE folder_hierarchy AS (
    -- Base case: root folders
    SELECT
        f.id,
        f.tenant_id,
        f.parent_id,
        f.name,
        f.path,
        get_workflow_enabled_for_folder(f.tenant_id, f.id) as workflow_enabled,
        0 as depth
    FROM folders f
    WHERE f.tenant_id = ?
      AND f.parent_id IS NULL
      AND f.deleted_at IS NULL

    UNION ALL

    -- Recursive case: child folders
    SELECT
        f.id,
        f.tenant_id,
        f.parent_id,
        f.name,
        f.path,
        get_workflow_enabled_for_folder(f.tenant_id, f.id) as workflow_enabled,
        fh.depth + 1
    FROM folders f
    INNER JOIN folder_hierarchy fh ON f.parent_id = fh.id
    WHERE f.tenant_id = ?
      AND f.deleted_at IS NULL
      AND fh.depth < 10  -- Prevent infinite recursion
)
SELECT
    id,
    name,
    path,
    workflow_enabled,
    depth
FROM folder_hierarchy
WHERE workflow_enabled = 1
ORDER BY path;
*/

-- ============================================
-- Pattern 7: Get workflow configuration inheritance chain
-- ============================================
/*
Usage: Debug/audit - show where folder inherits workflow setting from

WITH RECURSIVE folder_chain AS (
    -- Start with target folder
    SELECT
        f.id,
        f.tenant_id,
        f.parent_id,
        f.name,
        f.path,
        0 as level,
        ws.workflow_enabled,
        ws.configured_by_user_id,
        u.name as configured_by_name,
        ws.configuration_reason
    FROM folders f
    LEFT JOIN workflow_settings ws ON ws.folder_id = f.id
        AND ws.scope_type = 'folder'
        AND ws.tenant_id = f.tenant_id
        AND ws.deleted_at IS NULL
    LEFT JOIN users u ON ws.configured_by_user_id = u.id
    WHERE f.id = ?
      AND f.tenant_id = ?
      AND f.deleted_at IS NULL

    UNION ALL

    -- Walk up to parents
    SELECT
        f.id,
        f.tenant_id,
        f.parent_id,
        f.name,
        f.path,
        fc.level + 1,
        ws.workflow_enabled,
        ws.configured_by_user_id,
        u.name,
        ws.configuration_reason
    FROM folders f
    INNER JOIN folder_chain fc ON f.id = fc.parent_id
    LEFT JOIN workflow_settings ws ON ws.folder_id = f.id
        AND ws.scope_type = 'folder'
        AND ws.tenant_id = f.tenant_id
        AND ws.deleted_at IS NULL
    LEFT JOIN users u ON ws.configured_by_user_id = u.id
    WHERE f.tenant_id = fc.tenant_id
      AND f.deleted_at IS NULL
      AND fc.level < 10
)
SELECT
    fc.level,
    fc.name as folder_name,
    fc.path,
    COALESCE(fc.workflow_enabled, 'inherited') as workflow_status,
    fc.configured_by_name,
    fc.configuration_reason,
    CASE
        WHEN fc.workflow_enabled IS NOT NULL THEN 'Explicit'
        ELSE 'Inherited'
    END as source_type
FROM folder_chain fc
ORDER BY fc.level
LIMIT 10;

-- Add tenant-level config at end
SELECT
    'TENANT' as level,
    'Tenant-wide' as folder_name,
    '/' as path,
    ws.workflow_enabled as workflow_status,
    u.name as configured_by_name,
    ws.configuration_reason,
    'Tenant Default' as source_type
FROM workflow_settings ws
LEFT JOIN users u ON ws.configured_by_user_id = u.id
WHERE ws.tenant_id = ?
  AND ws.scope_type = 'tenant'
  AND ws.deleted_at IS NULL;
*/

-- ============================================
-- Pattern 8: List all workflow configurations (admin dashboard)
-- ============================================
/*
Usage: Admin page showing all active workflow configurations

SELECT
    ws.id,
    ws.tenant_id,
    t.name as tenant_name,
    ws.scope_type,
    CASE ws.scope_type
        WHEN 'tenant' THEN 'Entire Tenant'
        WHEN 'folder' THEN CONCAT('Folder: ', f.name, ' (', f.path, ')')
    END as scope_description,
    ws.workflow_enabled,
    ws.auto_create_workflow,
    ws.require_validation,
    ws.require_approval,
    ws.auto_approve_on_validation,
    u.name as configured_by_name,
    ws.configuration_reason,
    ws.created_at,
    ws.updated_at
FROM workflow_settings ws
INNER JOIN tenants t ON ws.tenant_id = t.id
LEFT JOIN folders f ON ws.folder_id = f.id
LEFT JOIN users u ON ws.configured_by_user_id = u.id
WHERE ws.deleted_at IS NULL
  AND (? = 'super_admin' OR ws.tenant_id = ?)  -- Super admin sees all, others see only their tenant
ORDER BY ws.tenant_id, ws.scope_type, ws.created_at DESC;
*/

-- ============================================
-- MIGRATION NOTES
-- ============================================

/*
IMPORTANT CONSIDERATIONS:

1. INHERITANCE LOGIC:
   - Folder checks: Specific folder → Parent folders (recursive) → Tenant-wide → Default (0)
   - Use helper function `get_workflow_enabled_for_folder(tenant_id, folder_id)`
   - Max recursion depth: 10 levels (prevents infinite loops)

2. TENANT-WIDE VS FOLDER-SPECIFIC:
   - Tenant-wide: Applies to ALL folders unless overridden
   - Folder-specific: Explicit override (override_parent = 1)
   - Unique constraint prevents duplicate tenant configs
   - Unique constraint prevents duplicate folder configs

3. SCOPE CONSISTENCY CHECK:
   - scope_type='tenant' MUST have folder_id=NULL
   - scope_type='folder' MUST have folder_id NOT NULL
   - MySQL 8.0+ CHECK constraint enforces this

4. SOFT DELETE PATTERN:
   - deleted_at NULL = active configuration
   - deleted_at NOT NULL = disabled configuration (audit trail preserved)
   - Soft delete folder config → inherits from parent again

5. CONFIGURATION FIELDS:
   - workflow_enabled: Master switch (0 = no workflow, 1 = workflow active)
   - auto_create_workflow: Auto-create workflow on file upload (if enabled)
   - require_validation: Requires validator approval step
   - require_approval: Requires approver after validation
   - auto_approve_on_validation: Skip approver if validator approves (fast-track)

6. FUTURE EXTENSIONS (settings_metadata JSON):
   - allowed_file_types: ['pdf', 'docx'] - Only these types require workflow
   - max_validators: 2 - Max concurrent validators
   - min_approvers: 1 - Min approvers required
   - notification_emails: ['compliance@company.com'] - CC on workflow events
   - sla_hours: 48 - SLA for approval (hours)

7. API INTEGRATION:
   - Before file upload: Check `get_workflow_enabled_for_folder()`
   - If enabled: Create document_workflow record with state='bozza'
   - If disabled: Skip workflow, file immediately available

8. AUDIT LOGGING:
   - Configuration creation: AuditLogger::logCreate('workflow_setting', ...)
   - Configuration update: AuditLogger::logUpdate('workflow_setting', ...)
   - Configuration deletion: AuditLogger::logDelete('workflow_setting', ...)

9. PERFORMANCE:
   - Helper function uses indexes (tenant_id, folder_id, deleted_at)
   - Recursive CTE limited to depth 10
   - Caching recommended for frequently-accessed folders

10. SECURITY:
    - Only Manager/Super Admin can modify workflow settings
    - Users can VIEW workflow settings (read-only)
    - Audit trail tracks who enabled/disabled workflow
*/

-- ============================================
-- END OF MIGRATION
-- ============================================
