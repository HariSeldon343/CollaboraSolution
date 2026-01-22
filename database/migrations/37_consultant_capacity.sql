-- Migration 37: Consultant default capacity (available days) for Planning (tenant 28)
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS `consultant_capacities` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `available_days` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

