-- Migration 38: Compliance blueprint persistence for consulting plans (tenant 28 module)
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS `consulting_plan_blueprints` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `plan_id` INT NOT NULL,
  `standards_json` JSON NOT NULL,
  `blueprint_json` JSON NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

