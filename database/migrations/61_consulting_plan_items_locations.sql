-- Migration 61: Consulting plans - plan items location reference (multi-location travel support)
-- Adds:
--  - consulting_plan_items.location_tenant_location_id (INT UNSIGNED NULL)
--
-- Purpose:
-- - Allow associating a plan item (especially 'travel'/'onsite') to a specific client location (tenant_locations.id)
-- - Enables per-location KM calculation and multi-site plans
--
-- Schema-drift safe and idempotent (information_schema checks + DO 0 fallback).

START TRANSACTION;

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
    AND COLUMN_NAME = 'location_tenant_location_id'
);

SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN location_tenant_location_id INT UNSIGNED NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

