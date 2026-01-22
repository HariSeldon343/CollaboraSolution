-- Migration 52: Consulting plans - consultants home city (travel optimization)
-- Adds optional columns to consulting_plan_consultants:
-- - home_city (VARCHAR): city of departure for travel clustering
-- - home_lat/home_lng (DECIMAL): optional cached coordinates
--
-- Safe to run multiple times (information_schema checks).

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND COLUMN_NAME = 'home_city'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_consultants ADD COLUMN home_city VARCHAR(120) NULL AFTER user_id',
  'SELECT \"home_city exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND COLUMN_NAME = 'home_lat'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_consultants ADD COLUMN home_lat DECIMAL(9,6) NULL AFTER home_city',
  'SELECT \"home_lat exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_consultants'
    AND COLUMN_NAME = 'home_lng'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_consultants ADD COLUMN home_lng DECIMAL(9,6) NULL AFTER home_lat',
  'SELECT \"home_lng exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

