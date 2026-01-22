-- Migration 45: Compliance Standards + Artifact Templates + Program Standards (IMS multi-standard v2)
--
-- Creates:
-- - compliance_standards
-- - compliance_artifact_templates
-- - compliance_program_standards
--
-- Notes:
-- - Idempotent: CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE / INSERT IGNORE
-- - No ISO/UNI text: only metadata (edition_label) + clause_refs + operational template metadata.
-- - Avoid hard FKs to reduce failures on drifted schemas; use indexes instead.

CREATE TABLE IF NOT EXISTS `compliance_standards` (
  `code` VARCHAR(32) NOT NULL,                 -- e.g. ISO9001, ISO14001, ISO27001
  `name` VARCHAR(120) NOT NULL,                -- human label, e.g. ISO 9001
  `edition_label` VARCHAR(120) NOT NULL,       -- metadata only (no text), e.g. 2015+Amd1:2024
  `family` VARCHAR(20) NOT NULL DEFAULT 'HLS', -- HLS|nonHLS|unknown
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  KEY `idx_family_active` (`family`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_artifact_templates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(80) NOT NULL,          -- stable slug key (idempotency)
  `title` VARCHAR(255) NOT NULL,
  `doc_type` VARCHAR(40) NOT NULL,              -- manual|policy|procedure|instruction|form|register|record|plan
  `file_kind` VARCHAR(10) NOT NULL,             -- docx|xlsx|pptx|txt
  `folder_path` VARCHAR(255) NOT NULL,          -- relative to /IMS, e.g. /IMS/04-Support/01-Documents
  `filename_template` VARCHAR(255) NOT NULL,    -- suggested filename (without ISO text)
  -- NOTE: use LONGTEXT for maximum compatibility across MySQL/MariaDB variants.
  -- These fields store JSON strings but are not typed as SQL JSON to avoid migration failures.
  `tags_json` LONGTEXT NULL,                    -- ["HLS","QMS","Core"]
  `clause_refs_json` LONGTEXT NULL,             -- object: {"ISO9001":["7.5"],"ISO14001":["7.5"]}
  `is_common_hls` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_template_key` (`template_key`),
  KEY `idx_active_common` (`is_active`, `is_common_hls`),
  KEY `idx_doc_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_program_standards` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `standard_code` VARCHAR(32) NOT NULL,
  `edition_label` VARCHAR(120) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_program_standard` (`program_id`, `standard_code`),
  KEY `idx_standard` (`standard_code`, `edition_label`),
  KEY `idx_program` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed standards (minimal set; editable later via tenant 28 UI)
INSERT INTO `compliance_standards` (`code`,`name`,`edition_label`,`family`,`is_active`)
VALUES
  ('ISO9001','ISO 9001','2015+Amd1:2024','HLS',1),
  ('ISO14001','ISO 14001','2015','HLS',1),
  ('ISO45001','ISO 45001','2018','HLS',1),
  ('ISO27001','ISO/IEC 27001','2022','HLS',1),
  ('ISO42001','ISO/IEC 42001','2023','HLS',1),
  ('ISO13485','ISO 13485','2016','nonHLS',1),
  ('ISO7101','ISO 7101','2023','unknown',1),
  ('UNI10881','UNI 10881','—','unknown',1)
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `edition_label`=VALUES(`edition_label`),
  `family`=VALUES(`family`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

-- Seed templates (base “complete-ish” IMS set; no ISO text; clause refs are references only)
-- HLS common core (shared across HLS standards)
INSERT INTO `compliance_artifact_templates`
(`template_key`,`title`,`doc_type`,`file_kind`,`folder_path`,`filename_template`,`tags_json`,`clause_refs_json`,`is_common_hls`,`is_active`)
VALUES
  ('IMS_MANUAL','Manuale IMS','manual','docx','/IMS/00-IMS','Manuale IMS','[\"IMS\",\"HLS\",\"Core\"]', '{\"ISO9001\":[\"4.4\",\"7.5\"],\"ISO14001\":[\"4.4\",\"7.5\"],\"ISO45001\":[\"4.4\",\"7.5\"],\"ISO27001\":[\"4.4\",\"7.5\"],\"ISO42001\":[\"4.4\",\"7.5\"]}', 1, 1),
  ('POLICY_IMS','Politica IMS (Qualità/Ambiente/Sicurezza/InfoSec/AI)','policy','docx','/IMS/02-Leadership','Politica IMS','[\"IMS\",\"HLS\",\"Core\"]', '{\"ISO9001\":[\"5.2\"],\"ISO14001\":[\"5.2\"],\"ISO45001\":[\"5.2\"],\"ISO27001\":[\"5.2\"],\"ISO42001\":[\"5.2\"]}', 1, 1),
  ('PROC_DOC_CONTROL','Procedura gestione informazioni documentate','procedure','docx','/IMS/04-Support/01-Documents','Procedura Gestione Documenti','[\"HLS\",\"Core\",\"Documents\"]', '{\"ISO9001\":[\"7.5\"],\"ISO14001\":[\"7.5\"],\"ISO45001\":[\"7.5\"],\"ISO27001\":[\"7.5\"],\"ISO42001\":[\"7.5\"]}', 1, 1),
  ('PROC_RISK_OPP','Procedura rischi e opportunità (IMS)','procedure','docx','/IMS/03-Planning','Procedura Rischi e Opportunità','[\"HLS\",\"Core\",\"Risk\"]', '{\"ISO9001\":[\"6.1\"],\"ISO14001\":[\"6.1\"],\"ISO45001\":[\"6.1\"],\"ISO27001\":[\"6.1\"],\"ISO42001\":[\"6.1\"]}', 1, 1),
  ('PROC_COMPETENCE_TRAINING','Procedura competenze e formazione','procedure','docx','/IMS/04-Support','Procedura Competenze e Formazione','[\"HLS\",\"Core\",\"People\"]', '{\"ISO9001\":[\"7.2\"],\"ISO14001\":[\"7.2\"],\"ISO45001\":[\"7.2\"],\"ISO27001\":[\"7.2\"],\"ISO42001\":[\"7.2\"]}', 1, 1),
  ('PROC_SUPPLIERS','Procedura qualifica e valutazione fornitori','procedure','docx','/IMS/05-Operation','Procedura Gestione Fornitori','[\"HLS\",\"Core\",\"Suppliers\"]', '{\"ISO9001\":[\"8.4\"],\"ISO14001\":[\"8.1\"],\"ISO45001\":[\"8.1\"],\"ISO27001\":[\"8.1\"],\"ISO42001\":[\"8.1\"]}', 1, 1),
  ('PROC_INTERNAL_AUDIT','Procedura audit interno','procedure','docx','/IMS/06-Performance','Procedura Audit Interno','[\"HLS\",\"Core\",\"Audit\"]', '{\"ISO9001\":[\"9.2\"],\"ISO14001\":[\"9.2\"],\"ISO45001\":[\"9.2\"],\"ISO27001\":[\"9.2\"],\"ISO42001\":[\"9.2\"]}', 1, 1),
  ('PROC_MGMT_REVIEW','Procedura riesame di direzione','procedure','docx','/IMS/06-Performance','Procedura Riesame Direzione','[\"HLS\",\"Core\",\"Management\"]', '{\"ISO9001\":[\"9.3\"],\"ISO14001\":[\"9.3\"],\"ISO45001\":[\"9.3\"],\"ISO27001\":[\"9.3\"],\"ISO42001\":[\"9.3\"]}', 1, 1),
  ('PROC_NC_CA','Procedura non conformità e azioni correttive','procedure','docx','/IMS/07-Improvement','Procedura Non Conformità e Azioni Correttive','[\"HLS\",\"Core\",\"Improvement\"]', '{\"ISO9001\":[\"10.2\"],\"ISO14001\":[\"10.2\"],\"ISO45001\":[\"10.2\"],\"ISO27001\":[\"10.2\"],\"ISO42001\":[\"10.2\"]}', 1, 1),
  ('REG_KPI_MONITORING','Registro KPI e monitoraggi','register','xlsx','/IMS/06-Performance/03-Records','Registro KPI e Monitoraggi','[\"HLS\",\"Core\",\"KPI\"]', '{\"ISO9001\":[\"9.1\"],\"ISO14001\":[\"9.1\"],\"ISO45001\":[\"9.1\"],\"ISO27001\":[\"9.1\"],\"ISO42001\":[\"9.1\"]}', 1, 1),
  ('REG_NC_LOG','Registro non conformità e azioni','register','xlsx','/IMS/07-Improvement/03-Records','Registro NC e Azioni','[\"HLS\",\"Core\",\"NC\"]', '{\"ISO9001\":[\"10.2\"],\"ISO14001\":[\"10.2\"],\"ISO45001\":[\"10.2\"],\"ISO27001\":[\"10.2\"],\"ISO42001\":[\"10.2\"]}', 1, 1),
  ('FORM_TRAINING_MATRIX','Matrice competenze e formazione','form','xlsx','/IMS/04-Support/03-Records','Matrice Competenze e Formazione','[\"HLS\",\"People\"]', '{\"ISO9001\":[\"7.2\"],\"ISO14001\":[\"7.2\"],\"ISO45001\":[\"7.2\"],\"ISO27001\":[\"7.2\"],\"ISO42001\":[\"7.2\"]}', 1, 1),
  ('FORM_SUPPLIER_EVALUATION','Scheda valutazione fornitori','form','xlsx','/IMS/05-Operation/03-Records','Valutazione Fornitori','[\"HLS\",\"Suppliers\"]', '{\"ISO9001\":[\"8.4\"],\"ISO14001\":[\"8.1\"],\"ISO45001\":[\"8.1\"],\"ISO27001\":[\"8.1\"],\"ISO42001\":[\"8.1\"]}', 1, 1),
  ('REC_CONTEXT_ANALYSIS','Analisi del contesto','record','docx','/IMS/01-Context/03-Records','Analisi del Contesto','[\"HLS\",\"Context\"]', '{\"ISO9001\":[\"4.1\"],\"ISO14001\":[\"4.1\"],\"ISO45001\":[\"4.1\"],\"ISO27001\":[\"4.1\"],\"ISO42001\":[\"4.1\"]}', 1, 1),
  ('REC_INTERESTED_PARTIES','Registro parti interessate e requisiti','register','xlsx','/IMS/01-Context/03-Records','Parti Interessate e Requisiti','[\"HLS\",\"Context\"]', '{\"ISO9001\":[\"4.2\"],\"ISO14001\":[\"4.2\"],\"ISO45001\":[\"4.2\"],\"ISO27001\":[\"4.2\"],\"ISO42001\":[\"4.2\"]}', 1, 1),
  ('REC_SCOPE','Scopo IMS','record','docx','/IMS/01-Context','Scopo IMS','[\"HLS\",\"Scope\"]', '{\"ISO9001\":[\"4.3\"],\"ISO14001\":[\"4.3\"],\"ISO45001\":[\"4.3\"],\"ISO27001\":[\"4.3\"],\"ISO42001\":[\"4.3\"]}', 1, 1),
  ('FORM_INTERNAL_AUDIT_PLAN','Piano audit interno','plan','xlsx','/IMS/06-Performance/03-Records','Piano Audit Interno','[\"HLS\",\"Audit\"]', '{\"ISO9001\":[\"9.2\"],\"ISO14001\":[\"9.2\"],\"ISO45001\":[\"9.2\"],\"ISO27001\":[\"9.2\"],\"ISO42001\":[\"9.2\"]}', 1, 1),
  ('FORM_INTERNAL_AUDIT_REPORT','Rapporto audit interno','record','docx','/IMS/06-Performance/03-Records','Rapporto Audit Interno','[\"HLS\",\"Audit\"]', '{\"ISO9001\":[\"9.2\"],\"ISO14001\":[\"9.2\"],\"ISO45001\":[\"9.2\"],\"ISO27001\":[\"9.2\"],\"ISO42001\":[\"9.2\"]}', 1, 1),
  ('FORM_MGMT_REVIEW_MINUTES','Verbale riesame direzione','record','docx','/IMS/06-Performance/03-Records','Verbale Riesame Direzione','[\"HLS\",\"Management\"]', '{\"ISO9001\":[\"9.3\"],\"ISO14001\":[\"9.3\"],\"ISO45001\":[\"9.3\"],\"ISO27001\":[\"9.3\"],\"ISO42001\":[\"9.3\"]}', 1, 1)
ON DUPLICATE KEY UPDATE
  `title`=VALUES(`title`),
  `doc_type`=VALUES(`doc_type`),
  `file_kind`=VALUES(`file_kind`),
  `folder_path`=VALUES(`folder_path`),
  `filename_template`=VALUES(`filename_template`),
  `tags_json`=VALUES(`tags_json`),
  `clause_refs_json`=VALUES(`clause_refs_json`),
  `is_common_hls`=VALUES(`is_common_hls`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

-- ISO 14001 extras (environment-specific)
INSERT INTO `compliance_artifact_templates`
(`template_key`,`title`,`doc_type`,`file_kind`,`folder_path`,`filename_template`,`tags_json`,`clause_refs_json`,`is_common_hls`,`is_active`)
VALUES
  ('REG_ENV_ASPECTS','Registro aspetti ambientali','register','xlsx','/IMS/03-Planning/03-Records','Registro Aspetti Ambientali','[\"ISO14001\",\"Environment\"]', '{\"ISO14001\":[\"6.1.2\"]}', 0, 1),
  ('REG_LEGAL_REQUIREMENTS_ENV','Registro requisiti legali ambientali','register','xlsx','/IMS/04-Support/03-Records','Registro Requisiti Legali Ambientali','[\"ISO14001\",\"Compliance\"]', '{\"ISO14001\":[\"6.1.3\"]}', 0, 1),
  ('PLAN_ENV_OBJECTIVES','Piano obiettivi ambientali','plan','xlsx','/IMS/03-Planning','Piano Obiettivi Ambientali','[\"ISO14001\",\"Planning\"]', '{\"ISO14001\":[\"6.2\"]}', 0, 1)
ON DUPLICATE KEY UPDATE
  `title`=VALUES(`title`),
  `folder_path`=VALUES(`folder_path`),
  `filename_template`=VALUES(`filename_template`),
  `tags_json`=VALUES(`tags_json`),
  `clause_refs_json`=VALUES(`clause_refs_json`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

-- ISO 45001 extras (OH&S)
INSERT INTO `compliance_artifact_templates`
(`template_key`,`title`,`doc_type`,`file_kind`,`folder_path`,`filename_template`,`tags_json`,`clause_refs_json`,`is_common_hls`,`is_active`)
VALUES
  ('REG_HS_HAZARDS','Registro pericoli e rischi SSL','register','xlsx','/IMS/03-Planning/03-Records','Registro Pericoli e Rischi SSL','[\"ISO45001\",\"HSE\"]', '{\"ISO45001\":[\"6.1.2\"]}', 0, 1),
  ('PLAN_HS_OBJECTIVES','Piano obiettivi SSL','plan','xlsx','/IMS/03-Planning','Piano Obiettivi SSL','[\"ISO45001\",\"Planning\"]', '{\"ISO45001\":[\"6.2\"]}', 0, 1)
ON DUPLICATE KEY UPDATE
  `title`=VALUES(`title`),
  `folder_path`=VALUES(`folder_path`),
  `filename_template`=VALUES(`filename_template`),
  `tags_json`=VALUES(`tags_json`),
  `clause_refs_json`=VALUES(`clause_refs_json`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

-- ISO/IEC 27001 extras (InfoSec) - minimal set
INSERT INTO `compliance_artifact_templates`
(`template_key`,`title`,`doc_type`,`file_kind`,`folder_path`,`filename_template`,`tags_json`,`clause_refs_json`,`is_common_hls`,`is_active`)
VALUES
  ('REG_ASSET_INVENTORY','Inventario asset informativi','register','xlsx','/IMS/04-Support/03-Records','Inventario Asset','[\"ISO27001\",\"InfoSec\"]', '{\"ISO27001\":[\"8.1\"]}', 0, 1),
  ('PROC_INCIDENT_MGMT','Procedura gestione incidenti','procedure','docx','/IMS/07-Improvement','Procedura Gestione Incidenti','[\"ISO27001\",\"InfoSec\"]', '{\"ISO27001\":[\"10.1\"]}', 0, 1)
ON DUPLICATE KEY UPDATE
  `title`=VALUES(`title`),
  `folder_path`=VALUES(`folder_path`),
  `filename_template`=VALUES(`filename_template`),
  `tags_json`=VALUES(`tags_json`),
  `clause_refs_json`=VALUES(`clause_refs_json`),
  `is_active`=VALUES(`is_active`),
  `updated_at`=CURRENT_TIMESTAMP;

