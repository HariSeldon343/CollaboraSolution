-- Migration 21: Tenant Role Assignment Permissions (per-tenant)
-- Purpose: Allow super_admin to enable which system roles can assign "Ruolo Aziendale" for a given tenant.
-- Default: NULL/empty => only super_admin can assign business roles for that tenant.
--
-- Column:
-- tenants.tenant_role_assignment_roles TEXT NULL
-- Stores JSON array of allowed system roles, e.g.: ["admin","manager","user","super_admin"]
-- Note: super_admin is always allowed even if not present.

SET @db := DATABASE();

-- Add column if it doesn't exist (BUG-156 pattern)
SET @colExists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'tenant_role_assignment_roles'
);

SET @sql := IF(
  @colExists = 0,
  'ALTER TABLE tenants ADD COLUMN tenant_role_assignment_roles TEXT NULL DEFAULT NULL AFTER has_custom_roles',
  'SELECT \"Column tenants.tenant_role_assignment_roles already exists\" as status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Optional index not required (read per-tenant by PK)
SELECT 'Migration 21 completed' AS status;

