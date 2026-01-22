-- Migration 66: AI Assistant sessions + messages (additive)
--
-- Adds:
-- - ai_assistant_sessions
-- - ai_assistant_messages
--
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
-- Uses LONGTEXT for JSON payloads (schema-drift friendly).

CREATE TABLE IF NOT EXISTS `ai_assistant_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,                 -- owner tenant (planning: 28)
  `client_tenant_id` INT NULL,              -- target tenant (client)
  `plan_id` INT NULL,
  `checklist_id` INT NULL,
  `template_key` VARCHAR(80) NULL,
  `created_by` INT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_client` (`client_tenant_id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_checklist` (`checklist_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_assistant_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `session_id` INT NOT NULL,
  `role` VARCHAR(20) NOT NULL,             -- user|assistant|system
  `content` LONGTEXT NOT NULL,
  `meta_json` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
