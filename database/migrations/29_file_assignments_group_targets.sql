-- CollaboraNexio Migration 29
-- Purpose:
-- - Extend file_assignments to support assignments to a tenant_role (group) in a dynamic way
-- - Make assigned_to_user_id nullable (when assigning to a role)
-- - Add assigned_to_tenant_role_id nullable
-- - Add unique constraints for both target types
--
-- Date: 2025-12-28

SET @db := DATABASE();

-- 1) Add column assigned_to_tenant_role_id if missing
SET @has_role_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND COLUMN_NAME = 'assigned_to_tenant_role_id'
);

SET @sql_add_role_col := IF(
  @has_role_col = 0,
  "ALTER TABLE file_assignments ADD COLUMN assigned_to_tenant_role_id INT UNSIGNED NULL AFTER assigned_to_user_id",
  "SELECT 1"
);
PREPARE stmt FROM @sql_add_role_col; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Ensure assigned_to_user_id is nullable (so role-target rows can exist)
-- (If already nullable, this is a no-op)
SET @sql_make_user_nullable := "ALTER TABLE file_assignments MODIFY COLUMN assigned_to_user_id INT UNSIGNED NULL";
PREPARE stmt FROM @sql_make_user_nullable; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Add FK to tenant_roles (if table exists)
SET @tenant_roles_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'tenant_roles'
);

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND CONSTRAINT_NAME = 'fk_file_assignments_assigned_to_tenant_role'
);

SET @sql_add_fk := IF(
  @tenant_roles_exists > 0 AND @fk_exists = 0,
  "ALTER TABLE file_assignments ADD CONSTRAINT fk_file_assignments_assigned_to_tenant_role FOREIGN KEY (assigned_to_tenant_role_id) REFERENCES tenant_roles(id) ON DELETE CASCADE",
  "SELECT 1"
);
PREPARE stmt FROM @sql_add_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Replace the old unique key (file_id, assigned_to_user_id, deleted_at)
--    with two unique keys, one for user targets and one for role targets.
SET @uk_old_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND INDEX_NAME = 'uk_file_assignments_unique'
);

SET @sql_drop_old_uk := IF(
  @uk_old_exists > 0,
  "ALTER TABLE file_assignments DROP INDEX uk_file_assignments_unique",
  "SELECT 1"
);
PREPARE stmt FROM @sql_drop_old_uk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add new unique keys if missing
SET @uk_user_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND INDEX_NAME = 'uk_file_assignments_user_unique'
);

SET @sql_add_uk_user := IF(
  @uk_user_exists = 0,
  "ALTER TABLE file_assignments ADD UNIQUE KEY uk_file_assignments_user_unique (tenant_id, file_id, assigned_to_user_id, deleted_at)",
  "SELECT 1"
);
PREPARE stmt FROM @sql_add_uk_user; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uk_role_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND INDEX_NAME = 'uk_file_assignments_role_unique'
);

SET @sql_add_uk_role := IF(
  @uk_role_exists = 0,
  "ALTER TABLE file_assignments ADD UNIQUE KEY uk_file_assignments_role_unique (tenant_id, file_id, assigned_to_tenant_role_id, deleted_at)",
  "SELECT 1"
);
PREPARE stmt FROM @sql_add_uk_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful indexes
SET @idx_role_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'file_assignments'
    AND INDEX_NAME = 'idx_file_assignments_tenant_role'
);
SET @sql_add_idx_role := IF(
  @idx_role_exists = 0,
  "ALTER TABLE file_assignments ADD INDEX idx_file_assignments_tenant_role (tenant_id, assigned_to_tenant_role_id, deleted_at)",
  "SELECT 1"
);
PREPARE stmt FROM @sql_add_idx_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;


