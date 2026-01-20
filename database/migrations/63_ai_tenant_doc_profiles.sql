-- Migration 63: AI Tenant Document Profiles (planning-oriented cache)
--
-- Goal:
-- - Cache a structured AI analysis ("document intelligence") of a client tenant document set
--   for Planning/Wizard (tenant 28 internal tools).
-- - Never store long copied excerpts here; only structured output.
--
-- Notes:
-- - Schema drift safe: CREATE TABLE IF NOT EXISTS only.
-- - Idempotent caching is handled by UNIQUE (tenant_id, scope) and upsert logic in API.

CREATE TABLE IF NOT EXISTS `ai_tenant_doc_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `scope` VARCHAR(60) NOT NULL,
  `computed_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `source_fingerprint` VARCHAR(128) NULL,
  `payload_json` MEDIUMTEXT NULL,
  `created_by_user_id` INT NULL,
  `error_last` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_scope` (`tenant_id`, `scope`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_scope` (`scope`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

