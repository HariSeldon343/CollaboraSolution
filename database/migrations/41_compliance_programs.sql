-- Migration 41: Compliance Programs (ISO pilot) - Leva 2/3/4 foundation
--
-- Creates:
-- - compliance_programs
-- - compliance_artifacts
-- - compliance_provisioning_runs
--
-- Notes:
-- - Keep schema drift safe by using CREATE TABLE IF NOT EXISTS.
-- - Avoid hard FKs to reduce failures on drifted schemas; use indexes instead.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `compliance_programs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `standard_code` VARCHAR(32) NOT NULL,          -- e.g. ISO9001
  `standard_edition` VARCHAR(80) NOT NULL,       -- e.g. ISO 9001:2015/Amd 1:2024 (metadata)
  `scope_text` TEXT NULL,
  `sector_text` TEXT NULL,
  `size_text` TEXT NULL,
  `source_consulting_plan_id` INT NULL,          -- consulting_plans.id (tenant 28 plan used as source)
  `created_by_user_id` INT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft|active|archived
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_program` (`tenant_id`, `standard_code`, `source_consulting_plan_id`),
  KEY `idx_tenant_status` (`tenant_id`, `status`, `updated_at`),
  KEY `idx_standard` (`standard_code`, `standard_edition`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_artifacts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `artifact_key` VARCHAR(80) NOT NULL,           -- stable key for idempotency, e.g. DOC_QMS-01 or REC_xxx
  `title` VARCHAR(255) NOT NULL,
  `artifact_type` VARCHAR(40) NOT NULL,          -- policy|procedure|record|register|plan|...
  `clause_refs_json` JSON NULL,                  -- ["7.5","8.5.1"]
  `owner_label` VARCHAR(255) NULL,               -- free-text label (no tenant roles)
  `owner_user_id` INT NULL,                      -- optional user in tenant (when resolvable)
  `due_date` DATETIME NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'todo',  -- todo|draft|in_review|approved|obsolete
  `file_id` INT NULL,                            -- files.id in client tenant
  `folder_id` INT NULL,                          -- files.id folder in client tenant
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact` (`program_id`, `artifact_key`),
  KEY `idx_program_status` (`program_id`, `status`, `due_date`),
  KEY `idx_program_type` (`program_id`, `artifact_type`),
  KEY `idx_file` (`file_id`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_provisioning_runs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `triggered_by_user_id` INT NOT NULL,
  `source` VARCHAR(40) NOT NULL DEFAULT 'planning', -- planning
  `blueprint_hash` CHAR(64) NULL,                   -- sha256 of normalized blueprint json
  `created_folders_count` INT NOT NULL DEFAULT 0,
  `created_files_count` INT NOT NULL DEFAULT 0,
  `created_tasks_count` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ok',       -- ok|partial|failed
  `notes_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_program_created` (`program_id`, `created_at`),
  KEY `idx_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

