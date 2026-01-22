-- Migration 49: Tenant logo reference (for DOCX header embedding)
--
-- Adds:
-- - tenants.logo_file_id (files.id in the same tenant)
--
-- Notes:
-- - Schema-drift safe: conditional ALTER via information_schema + dynamic SQL.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'logo_file_id'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE tenants ADD COLUMN logo_file_id INT NULL AFTER denominazione',
  'DO 0'
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

