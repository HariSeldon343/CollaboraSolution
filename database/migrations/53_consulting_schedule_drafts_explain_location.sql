-- Migration 53: Consulting schedule drafts - location + explain metadata
-- Adds optional columns to consulting_plan_schedule_drafts:
-- - location_city (VARCHAR): city for onsite/travel clustering
-- - explain_json (TEXT): JSON metadata about slot placement decisions (conflict avoidance / travel heuristic)
--
-- Safe to run multiple times (information_schema checks).

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND COLUMN_NAME = 'location_city'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_schedule_drafts ADD COLUMN location_city VARCHAR(120) NULL AFTER client_tenant_id',
  'SELECT \"location_city exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_schedule_drafts'
    AND COLUMN_NAME = 'explain_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_schedule_drafts ADD COLUMN explain_json TEXT NULL AFTER location_city',
  'SELECT \"explain_json exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

