-- Migration 54: Users - Optional home_city (Città di residenza)
-- Goal:
-- - Add users.home_city (VARCHAR) to store a default city for a user (non obbligatoria)
--   Used as default for Planning travel optimization (per-plan override remains in consulting_plan_consultants.home_city)
--
-- Schema-drift safe: checks information_schema and conditionally ALTERs. Safe to run multiple times.

START TRANSACTION;

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
);

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'home_city'
);

SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE users ADD COLUMN home_city VARCHAR(120) NULL AFTER email',
  'SELECT \"users.home_city exists or users table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

