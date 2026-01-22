-- Migration 62: consulting_plan_items phases metadata (additive)
-- Adds:
-- - phase_key VARCHAR(40) NULL
-- - phase_order INT NULL
-- - client_blocking TINYINT(1) NULL
-- - merge_key VARCHAR(40) NULL
--
-- Safe to run multiple times (information_schema checks + DO 0 fallback).

START TRANSACTION;

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
);

-- phase_key
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'phase_key'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN phase_key VARCHAR(40) NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- phase_order
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'phase_order'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN phase_order INT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- client_blocking
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'client_blocking'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN client_blocking TINYINT(1) NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- merge_key
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'merge_key'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN merge_key VARCHAR(40) NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

