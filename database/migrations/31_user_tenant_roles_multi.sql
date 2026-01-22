-- CollaboraNexio Migration 31
-- Purpose:
-- - Allow assigning MULTIPLE "Ruoli Aziendali" (tenant_roles) to the same user within the same tenant.
-- - Keep backward compatibility with existing single column user_tenant_access.tenant_role_id.
--
-- Date: 2025-12-29
--
-- Notes:
-- - This is a membership table with soft delete (deleted_at) to align with platform patterns.
-- - Existing code can keep reading user_tenant_access.tenant_role_id as a "primary" role.
-- - New code should prefer user_tenant_roles for membership checks.

SET @db := DATABASE();

-- 1) Create table if missing
SET @table_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_tenant_roles'
);

SET @sql_create := IF(
  @table_exists = 0,
  "CREATE TABLE user_tenant_roles (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      tenant_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      tenant_role_id INT UNSIGNED NOT NULL,
      deleted_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uk_user_tenant_roles_unique (tenant_id, user_id, tenant_role_id, deleted_at),
      INDEX idx_user_tenant_roles_lookup (tenant_id, user_id, deleted_at),
      INDEX idx_user_tenant_roles_role (tenant_id, tenant_role_id, deleted_at),
      CONSTRAINT fk_user_tenant_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_tenant_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_tenant_roles_role FOREIGN KEY (tenant_role_id) REFERENCES tenant_roles(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "SELECT 1"
);
PREPARE stmt FROM @sql_create; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Best-effort backfill from legacy user_tenant_access.tenant_role_id (if column exists)
SET @uta_has_role_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_tenant_access'
    AND COLUMN_NAME = 'tenant_role_id'
);

SET @sql_backfill := IF(
  @uta_has_role_col > 0,
  "INSERT INTO user_tenant_roles (tenant_id, user_id, tenant_role_id, created_at)
   SELECT uta.tenant_id, uta.user_id, uta.tenant_role_id, NOW()
   FROM user_tenant_access uta
   WHERE (uta.deleted_at IS NULL OR uta.deleted_at = '')
     AND uta.tenant_role_id IS NOT NULL
     AND uta.tenant_role_id > 0
     AND NOT EXISTS (
       SELECT 1
       FROM user_tenant_roles utr
       WHERE utr.tenant_id = uta.tenant_id
         AND utr.user_id = uta.user_id
         AND utr.tenant_role_id = uta.tenant_role_id
         AND utr.deleted_at IS NULL
       LIMIT 1
     )",
  "SELECT 1"
);
PREPARE stmt FROM @sql_backfill; EXECUTE stmt; DEALLOCATE PREPARE stmt;


