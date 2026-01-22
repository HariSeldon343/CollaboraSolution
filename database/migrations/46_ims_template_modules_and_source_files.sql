-- Migration 46: IMS Template Modules + Source Master Files (tenant 28)
--
-- Adds to compliance_artifact_templates (migration 45):
-- - source_file_id INT NULL
-- - source_tenant_id INT NOT NULL DEFAULT 28
-- - content_mode VARCHAR(20) NOT NULL DEFAULT 'placeholder'  (placeholder|copy_source)
-- - doc_code_template VARCHAR(64) NULL
--
-- Adds:
-- - compliance_template_modules
-- - compliance_template_module_items
--
-- Notes:
-- - Schema-drift safe: conditional ALTERs via information_schema + dynamic SQL.
-- - JSON-ish columns are LONGTEXT for MySQL/MariaDB compatibility.
-- - No ISO/UNI text stored here; only metadata + keys.

-- source_file_id
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'source_file_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN source_file_id INT NULL AFTER clause_refs_json',
  'SELECT \"source_file_id exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- source_tenant_id
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'source_tenant_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN source_tenant_id INT NOT NULL DEFAULT 28 AFTER source_file_id',
  'SELECT \"source_tenant_id exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- content_mode
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'content_mode'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN content_mode VARCHAR(20) NOT NULL DEFAULT \"placeholder\" AFTER source_tenant_id',
  'SELECT \"content_mode exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- doc_code_template
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'doc_code_template'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN doc_code_template VARCHAR(64) NULL AFTER content_mode',
  'SELECT \"doc_code_template exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Modules table
CREATE TABLE IF NOT EXISTS `compliance_template_modules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `standards_json` LONGTEXT NULL, -- JSON string array of standard codes (e.g. ["ISO9001","ISO14001"])
  `tags_json` LONGTEXT NULL,      -- JSON string array
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_key` (`module_key`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module items table
CREATE TABLE IF NOT EXISTS `compliance_template_module_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `module_id` INT NOT NULL,
  `template_key` VARCHAR(80) NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_template` (`module_id`, `template_key`),
  KEY `idx_module_sort` (`module_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: minimal modules (idempotent)
INSERT INTO `compliance_template_modules`
  (`module_key`,`title`,`description`,`standards_json`,`tags_json`,`is_active`)
VALUES
  ('IMS_CORE_HLS','IMS Core (HLS)','Core comune HLS (manuale/politica/procedure/registri).','[\"ISO9001\",\"ISO14001\",\"ISO45001\",\"ISO27001\",\"ISO42001\"]','[\"IMS\",\"HLS\",\"Core\"]',1),
  ('ISO9001_CORE','ISO 9001 Core','Set base qualità (integra il core HLS).','[\"ISO9001\"]','[\"ISO9001\",\"QMS\"]',1)
ON DUPLICATE KEY UPDATE
  `title`=VALUES(`title`),
  `description`=VALUES(`description`),
  `standards_json`=VALUES(`standards_json`),
  `tags_json`=VALUES(`tags_json`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

-- Seed module items (idempotent). Uses module_key lookup; items are inserted only if template exists.
-- IMS_CORE_HLS
INSERT IGNORE INTO `compliance_template_module_items` (`module_id`,`template_key`,`is_required`,`sort_order`)
SELECT m.id, t.template_key, 1, x.sort_order
FROM `compliance_template_modules` m
JOIN (
  SELECT 'IMS_MANUAL' AS template_key, 10 AS sort_order UNION ALL
  SELECT 'POLICY_IMS', 20 UNION ALL
  SELECT 'PROC_DOC_CONTROL', 30 UNION ALL
  SELECT 'PROC_INTERNAL_AUDIT', 40 UNION ALL
  SELECT 'PROC_MGMT_REVIEW', 50 UNION ALL
  SELECT 'PROC_NC_CA', 60 UNION ALL
  SELECT 'REG_KPI_MONITORING', 70 UNION ALL
  SELECT 'FORM_INTERNAL_AUDIT_PLAN', 80
) x ON 1=1
JOIN `compliance_artifact_templates` t ON t.template_key = x.template_key
WHERE m.module_key = 'IMS_CORE_HLS';

-- ISO9001_CORE (can be expanded later; currently links to common items)
INSERT IGNORE INTO `compliance_template_module_items` (`module_id`,`template_key`,`is_required`,`sort_order`)
SELECT m.id, t.template_key, 1, x.sort_order
FROM `compliance_template_modules` m
JOIN (
  SELECT 'REC_CONTEXT_ANALYSIS' AS template_key, 10 AS sort_order UNION ALL
  SELECT 'REC_INTERESTED_PARTIES', 20 UNION ALL
  SELECT 'REC_SCOPE', 30
) x ON 1=1
JOIN `compliance_artifact_templates` t ON t.template_key = x.template_key
WHERE m.module_key = 'ISO9001_CORE';

