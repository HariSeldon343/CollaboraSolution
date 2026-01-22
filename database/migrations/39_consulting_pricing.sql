-- Migration 39: Consulting pricing defaults + fixed amount per plan item (tenant 28 tools)
-- Adds:
--  - consulting_activity_types.default_day_rate, consulting_activity_types.default_fixed_amount
--  - consulting_plan_items.fixed_amount
-- Schema-drift safe: uses information_schema checks and conditional ALTERs

START TRANSACTION;

-- consulting_activity_types.default_day_rate
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'default_day_rate'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN default_day_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER weight_factor',
  'SELECT \"default_day_rate exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_activity_types.default_fixed_amount
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_activity_types'
    AND COLUMN_NAME = 'default_fixed_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_activity_types ADD COLUMN default_fixed_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER default_day_rate',
  'SELECT \"default_fixed_amount exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- consulting_plan_items.fixed_amount
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'fixed_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN fixed_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER day_rate',
  'SELECT \"fixed_amount exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

