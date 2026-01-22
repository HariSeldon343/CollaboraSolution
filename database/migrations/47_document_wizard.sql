-- Migration 47: Document Wizard (profiles + artifact inputs + visible versions) + template schema/hint
--
-- Goals:
-- - Enable guided compilation (profile + per-artifact inputs)
-- - Enable "visible versions" (snapshot copies) with retention handled in app layer
-- - Extend template catalog with input_schema_json + ai_hint
--
-- Notes:
-- - Schema-drift safe: conditional ALTERs via information_schema + dynamic SQL.
-- - JSON-ish columns are LONGTEXT for MySQL/MariaDB compatibility.
-- - No ISO/UNI text stored here; only user-entered content + metadata.

-- 1) compliance_program_profiles
CREATE TABLE IF NOT EXISTS `compliance_program_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `program_id` INT NOT NULL,
  `profile_json` LONGTEXT NULL,
  `updated_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_program` (`tenant_id`, `program_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_program` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) compliance_artifact_inputs
CREATE TABLE IF NOT EXISTS `compliance_artifact_inputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `program_id` INT NOT NULL,
  `artifact_id` INT NOT NULL,
  `template_key` VARCHAR(80) NOT NULL,
  `input_json` LONGTEXT NULL,
  `updated_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_artifact` (`tenant_id`, `artifact_id`),
  KEY `idx_tenant_program` (`tenant_id`, `program_id`),
  KEY `idx_template_key` (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) compliance_file_versions (links: current file -> version snapshot file_id)
CREATE TABLE IF NOT EXISTS `compliance_file_versions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `file_id` INT NOT NULL,
  `version_file_id` INT NOT NULL,
  `reason` VARCHAR(80) NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_version_file` (`tenant_id`, `version_file_id`),
  KEY `idx_tenant_file_created` (`tenant_id`, `file_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Extend compliance_artifact_templates: input_schema_json + ai_hint
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'input_schema_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN input_schema_json LONGTEXT NULL AFTER clause_refs_json',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_hint
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_artifact_templates'
    AND COLUMN_NAME = 'ai_hint'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE compliance_artifact_templates ADD COLUMN ai_hint TEXT NULL AFTER input_schema_json',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Seed: ISO 9001 “SGQ Completo” module + minimal schemas for a few templates (idempotent)

-- (a) Update a few templates with input_schema_json + ai_hint (only if columns exist; rows must exist)
-- IMS_MANUAL
UPDATE compliance_artifact_templates
SET
  input_schema_json = COALESCE(input_schema_json,
    '{"sections":[{"title":"Dati organizzazione","fields":[{"key":"company_name","label":"Ragione sociale","type":"text","required":true,"ai":false},{"key":"scope","label":"Scopo del sistema (testo sintetico)","type":"textarea","required":true,"ai":true},{"key":"sites","label":"Sedi / siti (1 per riga)","type":"textarea","required":false,"ai":false},{"key":"products_services","label":"Prodotti/Servizi (1 per riga)","type":"textarea","required":false,"ai":false},{"key":"processes","label":"Processi principali (1 per riga)","type":"textarea","required":false,"ai":true}]}]}'
  ),
  ai_hint = COALESCE(ai_hint,
    'Scrivi contenuti originali e operativi per un manuale aziendale. Niente citazioni o testo di norme ISO/UNI. Linguaggio semplice, orientato a come lavora l''organizzazione.'
  ),
  updated_at = CURRENT_TIMESTAMP
WHERE template_key = 'IMS_MANUAL';

-- PROC_DOC_CONTROL
UPDATE compliance_artifact_templates
SET
  input_schema_json = COALESCE(input_schema_json,
    '{"sections":[{"title":"Gestione informazioni documentate","fields":[{"key":"doc_control_scope","label":"Campo di applicazione","type":"textarea","required":true,"ai":true},{"key":"doc_control_roles","label":"Ruoli e responsabilità (1 per riga)","type":"textarea","required":true,"ai":true},{"key":"doc_control_rules","label":"Regole operative (creazione, revisione, approvazione, distribuzione)","type":"textarea","required":true,"ai":true},{"key":"doc_control_records","label":"Registrazioni gestite (1 per riga)","type":"textarea","required":false,"ai":true}]}]}'
  ),
  ai_hint = COALESCE(ai_hint,
    'Genera una procedura operativa: passi chiari, chi fa cosa, regole pratiche e controlli. Non citare norme, clausole o testo ISO/UNI.'
  ),
  updated_at = CURRENT_TIMESTAMP
WHERE template_key = 'PROC_DOC_CONTROL';

-- PROC_INTERNAL_AUDIT
UPDATE compliance_artifact_templates
SET
  input_schema_json = COALESCE(input_schema_json,
    '{"sections":[{"title":"Audit interno","fields":[{"key":"audit_objectives","label":"Obiettivi audit","type":"textarea","required":true,"ai":true},{"key":"audit_scope","label":"Campo di applicazione e criteri","type":"textarea","required":true,"ai":true},{"key":"audit_roles","label":"Ruoli e responsabilità (1 per riga)","type":"textarea","required":true,"ai":true},{"key":"audit_steps","label":"Fasi operative (pianificazione, esecuzione, reporting, follow-up)","type":"textarea","required":true,"ai":true}]}]}'
  ),
  ai_hint = COALESCE(ai_hint,
    'Scrivi una procedura audit interna pratica: pianificazione, conduzione, gestione evidenze, report, follow-up. Niente testo norma.'
  ),
  updated_at = CURRENT_TIMESTAMP
WHERE template_key = 'PROC_INTERNAL_AUDIT';

-- REG_KPI_MONITORING
UPDATE compliance_artifact_templates
SET
  input_schema_json = COALESCE(input_schema_json,
    '{"sections":[{"title":"Registro KPI","fields":[{"key":"kpi_year","label":"Anno di riferimento","type":"text","required":true,"ai":false},{"key":"kpi_owner","label":"Responsabile monitoraggi","type":"text","required":false,"ai":true},{"key":"kpi_list","label":"Elenco KPI (1 per riga: nome - unità - frequenza)","type":"textarea","required":false,"ai":true}]}]}'
  ),
  ai_hint = COALESCE(ai_hint,
    'Compila campi sintetici per un registro KPI: elenco KPI realistici, misurabili, con frequenza e owner. Niente citazioni norme.'
  ),
  updated_at = CURRENT_TIMESTAMP
WHERE template_key = 'REG_KPI_MONITORING';

-- (b) Create module ISO9001_SGQ_COMPLETO if modules table exists
SET @has_modules := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_template_modules'
);
SET @sql := IF(@has_modules = 1,
  'INSERT INTO compliance_template_modules (module_key,title,description,standards_json,tags_json,is_active)
   VALUES (''ISO9001_SGQ_COMPLETO'',''ISO 9001 – SGQ Completo'',''Pacchetto base SGQ (documenti e registri) senza testo ISO/UNI.'',''[\"ISO9001\"]'',''[\"ISO9001\",\"QMS\",\"SGQ\"]'',1)
   ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), standards_json=VALUES(standards_json), tags_json=VALUES(tags_json), is_active=VALUES(is_active), updated_at=CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (c) Seed module items (only if module items table exists and templates exist)
SET @has_items := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'compliance_template_module_items'
);

-- Insert ordered items by joining existing templates (idempotent via INSERT IGNORE)
SET @sql := IF(@has_items = 1,
  'INSERT IGNORE INTO compliance_template_module_items (module_id, template_key, is_required, sort_order)
   SELECT m.id, t.template_key, 1, x.sort_order
   FROM compliance_template_modules m
   JOIN (
     SELECT ''IMS_MANUAL'' AS template_key, 10 AS sort_order UNION ALL
     SELECT ''POLICY_IMS'', 20 UNION ALL
     SELECT ''REC_SCOPE'', 30 UNION ALL
     SELECT ''REC_CONTEXT_ANALYSIS'', 40 UNION ALL
     SELECT ''REC_INTERESTED_PARTIES'', 50 UNION ALL
     SELECT ''PROC_DOC_CONTROL'', 60 UNION ALL
     SELECT ''PROC_RISK_OPP'', 70 UNION ALL
     SELECT ''PROC_COMPETENCE_TRAINING'', 80 UNION ALL
     SELECT ''PROC_SUPPLIERS'', 90 UNION ALL
     SELECT ''PROC_INTERNAL_AUDIT'', 100 UNION ALL
     SELECT ''PROC_MGMT_REVIEW'', 110 UNION ALL
     SELECT ''PROC_NC_CA'', 120 UNION ALL
     SELECT ''REG_KPI_MONITORING'', 130 UNION ALL
     SELECT ''REG_NC_LOG'', 140 UNION ALL
     SELECT ''FORM_TRAINING_MATRIX'', 150 UNION ALL
     SELECT ''FORM_SUPPLIER_EVALUATION'', 160 UNION ALL
     SELECT ''FORM_INTERNAL_AUDIT_PLAN'', 170 UNION ALL
     SELECT ''FORM_INTERNAL_AUDIT_REPORT'', 180 UNION ALL
     SELECT ''FORM_MGMT_REVIEW_MINUTES'', 190
   ) x ON 1=1
   JOIN compliance_artifact_templates t ON t.template_key = x.template_key
   WHERE m.module_key = ''ISO9001_SGQ_COMPLETO''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

