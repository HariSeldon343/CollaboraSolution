-- Migration 60: Consulting (tenant 28) - Service/Norms catalog: scheme_type + legacy_combo
-- Goals:
--  - Add scheme_type (logical enum) to consulting_activity_types
--  - Add legacy_combo flag to mark deprecated composite/legacy services (kept for historical plans)
-- DB safety:
--  - additive only
--  - schema-drift safe via information_schema checks
--  - safe to run multiple times
-- Note: uses DO 0 as fallback to avoid result-sets (PDO/MySQL unbuffered queries issues).

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

-- scheme_type (VARCHAR(30), nullable)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'scheme_type'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN scheme_type VARCHAR(30) NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- legacy_combo (TINYINT(1), not null, default 0)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'legacy_combo'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN legacy_combo TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

