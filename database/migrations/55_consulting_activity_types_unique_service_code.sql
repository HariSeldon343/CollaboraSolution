-- Migration 55: Consulting (tenant 28) - Unique service_code (active rows) in activity catalog
-- Goals:
--  - Enforce unique consulting_activity_types.service_code per tenant for NON-deleted rows.
-- Implementation:
--  - Adds a generated column service_code_active = service_code when deleted_at IS NULL, else NULL
--  - Adds a UNIQUE index on (tenant_id, service_code_active)
-- Notes:
--  - Safe to run multiple times (information_schema checks).
--  - Does not modify existing data.

START TRANSACTION;

-- --------------------------------------------
-- consulting_activity_types unique service_code (requires migration 50)
-- --------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
);

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'service_code'
);

-- Add generated helper column for partial-unique semantics (active rows only)
SET @col_active_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'service_code_active'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 1 AND @col_active_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN service_code_active VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN service_code ELSE NULL END) STORED',
  'SELECT \"consulting_activity_types.service_code_active exists or table/column missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique index (tenant_id, service_code_active) - allows reuse after soft-delete
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND INDEX_NAME = 'uk_cat_tenant_service_code_active'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 1 AND @idx_exists = 0,
  'ALTER TABLE consulting_activity_types ADD UNIQUE KEY uk_cat_tenant_service_code_active (tenant_id, service_code_active)',
  'SELECT \"consulting_activity_types.uk_cat_tenant_service_code_active exists or table/column missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

