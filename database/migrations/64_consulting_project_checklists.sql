-- Migration 64: Consulting Project Checklists (SGQ project checklist add-on)
--
-- Goal:
-- - Add an additive, opt-in "Checklist Progetto SGQ" layer for Planning (tenant 28).
-- - Store checklist instances per plan + template, and checklist items with answers/evidence.
--
-- Notes:
-- - Schema drift safe: CREATE TABLE IF NOT EXISTS only.
-- - No foreign keys (avoid failures on drifted schemas); use logical references + indexes.
-- - Do NOT store ISO/UNI text; only generic titles, questions, TODO, clause numbers.

CREATE TABLE IF NOT EXISTS `consulting_project_checklists` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,              -- tenant 28 (S.CO)
  `plan_id` INT NOT NULL,                -- consulting_plans.id (tenant 28)
  `company_id` INT NULL,                 -- logical FK (optional) for future mapping
  `client_tenant_id` INT NOT NULL,       -- tenant id of the client (documents live there)
  `template_key` VARCHAR(80) NOT NULL,   -- e.g. SGQ_ISO9001_BANDO
  `title` VARCHAR(255) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'draft', -- draft|in_progress|done
  `last_docs_snapshot_at` DATETIME NULL,
  `last_docs_analyze_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_plan_template` (`tenant_id`, `plan_id`, `template_key`),
  KEY `idx_checklist_plan_company` (`plan_id`, `company_id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_client_tenant` (`client_tenant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consulting_project_checklist_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `checklist_id` INT NOT NULL,
  `section_key` VARCHAR(80) NOT NULL,
  `item_key` VARCHAR(120) NOT NULL,      -- unique per checklist_id
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `question_type` VARCHAR(30) NOT NULL,  -- bool|text|multiline|select|file|multi_file
  `required` TINYINT NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL DEFAULT 'missing', -- missing|present|to_review|done|not_applicable
  `answer_text` TEXT NULL,
  `answer_json` TEXT NULL,
  `evidence_json` TEXT NULL,
  `linked_artifacts_json` TEXT NULL,
  `assigned_to_user_id` INT NULL,
  `due_date` DATE NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_checklist_item_key` (`checklist_id`, `item_key`),
  KEY `idx_checklist_id` (`checklist_id`),
  KEY `idx_status` (`status`),
  KEY `idx_section_order` (`checklist_id`, `section_key`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

