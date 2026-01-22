-- Migration 50: Consulting (tenant 28) - Service/Norms catalog extensions + estimate persistence + per-item assignee
-- Goals:
--  - Extend consulting_activity_types with service/norm metadata and estimation hints
--  - Add consulting_plans.estimate_json to persist estimation and allocation (optional)
--  - Add consulting_plan_items.assignee_user_id to support post-plan allocation (optional)
-- Schema-drift safe: checks information_schema and conditionally ALTERs. Safe to run multiple times.

START TRANSACTION;

-- --------------------------------------------
-- consulting_activity_types extensions (requires migration 35)
-- --------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
);

-- service_code (VARCHAR, nullable, unique per tenant when present)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'service_code'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN service_code VARCHAR(64) NULL AFTER name',
  'SELECT \"consulting_activity_types.service_code exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- category (VARCHAR, nullable)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'category'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN category VARCHAR(64) NULL AFTER service_code',
  'SELECT \"consulting_activity_types.category exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- aliases_json (LONGTEXT, nullable) - JSON string array
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'aliases_json'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN aliases_json LONGTEXT NULL AFTER category',
  'SELECT \"consulting_activity_types.aliases_json exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- base_days_min (INT, nullable)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'base_days_min'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN base_days_min INT NULL AFTER aliases_json',
  'SELECT \"consulting_activity_types.base_days_min exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- base_days_max (INT, nullable)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'base_days_max'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN base_days_max INT NULL AFTER base_days_min',
  'SELECT \"consulting_activity_types.base_days_max exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- complexity_score (TINYINT, nullable, 1-5)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'complexity_score'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN complexity_score TINYINT NULL AFTER base_days_max',
  'SELECT \"consulting_activity_types.complexity_score exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- default_phases_json (LONGTEXT, nullable) - JSON array of phases template
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'default_phases_json'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN default_phases_json LONGTEXT NULL AFTER complexity_score',
  'SELECT \"consulting_activity_types.default_phases_json exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- default_deliverables_json (LONGTEXT, nullable) - JSON array of deliverables
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'default_deliverables_json'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN default_deliverables_json LONGTEXT NULL AFTER default_phases_json',
  'SELECT \"consulting_activity_types.default_deliverables_json exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique index on (tenant_id, service_code, deleted_at) (soft-delete-safe)
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND INDEX_NAME = 'uk_cat_service_code_tenant'
);
SET @sql := IF(@tbl_exists = 1 AND @idx_exists = 0,
  'CREATE UNIQUE INDEX uk_cat_service_code_tenant ON consulting_activity_types (tenant_id, service_code, deleted_at)',
  'SELECT \"consulting_activity_types.uk_cat_service_code_tenant exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- consulting_plans extension: estimate_json (requires migration 34)
-- --------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
);
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'estimate_json'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN estimate_json LONGTEXT NULL AFTER notes',
  'SELECT \"consulting_plans.estimate_json exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- consulting_plan_items extension: assignee_user_id (requires migration 34)
-- --------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
);
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'assignee_user_id'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN assignee_user_id INT UNSIGNED NULL AFTER domain_activity_type_id',
  'SELECT \"consulting_plan_items.assignee_user_id exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional index for allocation/schedule (best-effort)
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND INDEX_NAME = 'idx_cpi_assignee'
);
SET @sql := IF(@tbl_exists = 1 AND @idx_exists = 0,
  'CREATE INDEX idx_cpi_assignee ON consulting_plan_items (assignee_user_id, deleted_at)',
  'SELECT \"consulting_plan_items.idx_cpi_assignee exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

