-- Migration 51: Consulting (tenant 28) - Atomic services support (standard_codes_json + legacy/composite flag)
-- Goals:
--  - Add standard_codes_json to consulting_activity_types (maps a service to 1..N standard codes)
--  - Add is_legacy_composite flag to mark deprecated "combo" services (kept for historical plans)
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

-- standard_codes_json (TEXT/LONGTEXT, nullable) - JSON string array of standard codes
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'standard_codes_json'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN standard_codes_json TEXT NULL AFTER aliases_json',
  'SELECT \"consulting_activity_types.standard_codes_json exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- is_legacy_composite (TINYINT, not null) - 1 when this record is a deprecated combo/legacy service
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'is_legacy_composite'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN is_legacy_composite TINYINT(1) NOT NULL DEFAULT 0 AFTER standard_codes_json',
  'SELECT \"consulting_activity_types.is_legacy_composite exists or table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

