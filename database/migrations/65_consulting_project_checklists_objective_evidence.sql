-- Migration 65: Consulting Project Checklists — objective + evidence table (additive)
--
-- Adds:
-- - consulting_project_checklists.objective_text (TEXT NULL)
-- - consulting_project_checklist_evidence table (normalized evidence links)
--
-- Safe to run multiple times (information_schema checks + DO 0 fallback).
-- No foreign keys (avoid failures on drifted schemas).

-- Evidence table (new)
CREATE TABLE IF NOT EXISTS `consulting_project_checklist_evidence` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,                 -- tenant 28 (S.CO)
  `checklist_item_id` INT NOT NULL,
  `file_tenant_id` INT NOT NULL,            -- tenant id where the file lives (client tenant)
  `file_id` INT NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `file_path` VARCHAR(600) NULL,
  `meta_json` TEXT NULL,                    -- optional: tags/matched_keywords/match_score (best-effort)
  `created_by_user_id` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_file` (`checklist_item_id`, `file_tenant_id`, `file_id`),
  KEY `idx_item` (`checklist_item_id`),
  KEY `idx_file` (`file_tenant_id`, `file_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add objective_text to checklist header (schema drift safe)
START TRANSACTION;

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_project_checklists'
);

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_project_checklists'
    AND COLUMN_NAME = 'objective_text'
);

SET @sql := IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE consulting_project_checklists ADD COLUMN objective_text TEXT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

