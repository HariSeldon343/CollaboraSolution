-- Migration 58: Users - Optional professional profile fields (job title / skills / certifications)
-- Goal:
-- - Add optional columns to users to store consultant capabilities and profile details.
-- - These fields are global per user (valid for all tenants) and are used to improve planning allocation (AI + heuristics).
--
-- Columns (all optional):
-- - job_title VARCHAR(160)
-- - skills_text TEXT
-- - certifications_text TEXT
--
-- Schema-drift safe: checks information_schema and conditionally ALTERs. Safe to run multiple times.

START TRANSACTION;

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
);

-- job_title
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'job_title'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE users ADD COLUMN job_title VARCHAR(160) NULL AFTER home_city',
  'SELECT \"users.job_title exists or users table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- skills_text
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'skills_text'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE users ADD COLUMN skills_text TEXT NULL AFTER job_title',
  'SELECT \"users.skills_text exists or users table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- certifications_text
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'certifications_text'
);
SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE users ADD COLUMN certifications_text TEXT NULL AFTER skills_text',
  'SELECT \"users.certifications_text exists or users table missing\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

