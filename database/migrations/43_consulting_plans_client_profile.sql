-- Migration 43: Store client profile + blueprint defaults on consulting_plans (tenant 28 tool)
-- Adds optional columns to consulting_plans to prefill Compliance Blueprint modal.
-- Schema-drift safe: conditional ALTERs.

START TRANSACTION;

-- client_business_description (TEXT)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'client_business_description'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN client_business_description TEXT NULL AFTER notes',
  'SELECT \"client_business_description exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- client_desired_scope_hint (TEXT)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'client_desired_scope_hint'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN client_desired_scope_hint TEXT NULL AFTER client_business_description',
  'SELECT \"client_desired_scope_hint exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- client_employee_count (INT)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'client_employee_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN client_employee_count INT NULL AFTER client_desired_scope_hint',
  'SELECT \"client_employee_count exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- client_sites_count (INT)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'client_sites_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN client_sites_count INT NULL AFTER client_employee_count',
  'SELECT \"client_sites_count exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- blueprint_standards_json (TEXT to avoid MariaDB JSON variance)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plans'
    AND COLUMN_NAME = 'blueprint_standards_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plans ADD COLUMN blueprint_standards_json TEXT NULL AFTER client_sites_count',
  'SELECT \"blueprint_standards_json exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

