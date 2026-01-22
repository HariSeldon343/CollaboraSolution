-- Migration 42: Compliance mapping (tasks + optional calendar events) - Leva 3
--
-- Creates:
-- - compliance_task_links
-- - compliance_event_links (recommended for idempotent milestones)
--
-- Notes:
-- - Avoid hard FKs to reduce failures on drifted schemas; use indexes instead.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `compliance_task_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `artifact_id` INT NOT NULL,
  `tenant_id` INT NOT NULL,               -- client tenant (redundant but useful for filtering)
  `task_id` INT NOT NULL,                 -- tasks.id in client tenant
  `task_type` VARCHAR(20) NOT NULL DEFAULT 'deliverable', -- deliverable|question|milestone
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_task` (`artifact_id`, `task_id`),
  KEY `idx_tenant_task` (`tenant_id`, `task_id`),
  KEY `idx_artifact` (`artifact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_event_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `tenant_id` INT NOT NULL,               -- client tenant
  `event_id` INT NOT NULL,                -- events.id in client tenant
  `event_key` VARCHAR(80) NOT NULL,       -- stable key for idempotency (e.g. ISO9001_MILESTONE_KICKOFF)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_program_event_key` (`program_id`, `event_key`),
  KEY `idx_tenant_event` (`tenant_id`, `event_id`),
  KEY `idx_program` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

