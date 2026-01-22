-- Migration 68: AI Copilot RAG + Conversations (multi-tenant)
-- Additive, idempotent.

CREATE TABLE IF NOT EXISTS `ai_doc_index_jobs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `finished_at` TIMESTAMP NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'running',
  `stats_json` TEXT NULL,
  `error_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_doc_chunks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `file_id` INT NOT NULL,
  `file_hash` VARCHAR(64) NOT NULL,
  `chunk_index` INT NOT NULL,
  `chunk_text` MEDIUMTEXT NOT NULL,
  `chunk_hash` VARCHAR(64) NOT NULL,
  `embedding_json` MEDIUMTEXT NULL,
  `embedding_dim` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_file` (`tenant_id`, `file_id`),
  KEY `idx_hash` (`tenant_id`, `file_id`, `file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `company_id` INT NULL,
  `created_by` INT NULL,
  `mode` VARCHAR(40) NOT NULL DEFAULT 'onboarding',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_mode` (`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversation_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conversation_id` INT NOT NULL,
  `role` VARCHAR(20) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `meta_json` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_tenant_settings` (
  `tenant_id` INT NOT NULL,
  `ai_indexing_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `ai_external_provider_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `allowed_index_paths_json` TEXT NULL,
  `exclude_patterns_json` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
