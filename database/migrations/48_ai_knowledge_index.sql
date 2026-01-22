-- Migration 48: AI Knowledge Index (tenant-scoped)
--
-- Goal:
-- - Provide a lightweight knowledge base for AI answers based on tenant documents.
-- - Store extracted text chunks per file and tenant.
--
-- Notes:
-- - Schema drift safe: CREATE TABLE IF NOT EXISTS only.
-- - No FULLTEXT index by default (max compatibility). Search can use LIKE (best-effort).

CREATE TABLE IF NOT EXISTS `ai_knowledge_sources` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `folder_id` INT NOT NULL,
  `folder_label` VARCHAR(180) NULL,
  `is_active` TINYINT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_active` (`tenant_id`, `is_active`),
  KEY `idx_tenant_folder` (`tenant_id`, `folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_chunks` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `source_id` INT NOT NULL,
  `file_id` INT NOT NULL,
  `file_hash` CHAR(32) NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `logical_path` VARCHAR(600) NULL,
  `chunk_index` INT NOT NULL DEFAULT 0,
  `chunk_text` LONGTEXT NOT NULL,
  `chunk_len` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_file_chunk` (`tenant_id`, `file_id`, `chunk_index`),
  KEY `idx_tenant_source` (`tenant_id`, `source_id`),
  KEY `idx_tenant_file` (`tenant_id`, `file_id`),
  KEY `idx_source_filehash` (`source_id`, `file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_index_state` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `source_id` INT NOT NULL,
  `last_indexed_at` TIMESTAMP NULL,
  `last_file_count` INT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `updated_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`, `source_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

