-- Migration 59: Tenants - Permissions for managing custom business roles (tenant roles)
-- Goal:
-- - Add tenants.tenant_role_management_roles (JSON array stored as TEXT) to control
--   which system roles (admin/manager/user) can CREATE/UPDATE/DELETE tenant_roles.
-- - Default behavior: only super_admin can manage tenant roles unless explicitly enabled per-tenant.
--
-- Schema-drift safe: checks information_schema and conditionally ALTERs. Safe to run multiple times.

START TRANSACTION;

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
);

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'tenant_role_management_roles'
);

SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE tenants ADD COLUMN tenant_role_management_roles TEXT NULL AFTER tenant_role_assignment_roles',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

