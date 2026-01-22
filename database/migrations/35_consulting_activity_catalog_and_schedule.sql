-- ============================================
-- Module: Consulting Planning - Activity Catalog + Schedule Drafts (Tenant 28)
-- Version: 2026-01-01
-- Description:
--  - Adds activity catalog with weights and call cadence defaults
--  - Adds per-client overrides (hybrid catalog)
--  - Links plans to selected S.CO consultants
--  - Adds schedule draft rows (proposal before creating real calendar events)
--  - Extends consulting_plan_items with domain activity type + overrides
-- ============================================

USE collaboranexio;

-- ----------------------------
-- Activity catalog (global for S.CO tooling; tenant_id kept for future-proofing)
-- ----------------------------
CREATE TABLE IF NOT EXISTS consulting_activity_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  weight_factor DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  call_every_days_default INT NOT NULL DEFAULT 7,
  call_duration_minutes_default INT NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cat_tenant (tenant_id, deleted_at),
  UNIQUE KEY uk_cat_name_tenant (tenant_id, name, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Per-client overrides (hybrid model)
-- ----------------------------
CREATE TABLE IF NOT EXISTS consulting_activity_type_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_tenant_id INT UNSIGNED NOT NULL,
  activity_type_id INT NOT NULL,
  weight_factor_override DECIMAL(10,2) NULL,
  call_every_days_override INT NULL,
  call_duration_minutes_override INT NULL,
  is_active_override TINYINT(1) NULL,
  notes TEXT NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_catov_client (client_tenant_id, deleted_at),
  INDEX idx_catov_type (activity_type_id, deleted_at),
  UNIQUE KEY uk_catov_client_type (client_tenant_id, activity_type_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Plan consultants (selected S.CO users per plan)
-- ----------------------------
CREATE TABLE IF NOT EXISTS consulting_plan_consultants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_plan_user (plan_id, user_id),
  INDEX idx_cpc_plan (plan_id),
  INDEX idx_cpc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Schedule drafts (proposal slots)
-- ----------------------------
CREATE TABLE IF NOT EXISTS consulting_plan_schedule_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  kind ENUM('call','onsite','remote','travel','communication','other') NOT NULL DEFAULT 'call',
  title VARCHAR(255) NOT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  assigned_user_id INT UNSIGNED NULL,
  client_tenant_id INT UNSIGNED NULL,
  status ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  confirmed_event_id INT UNSIGNED NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cpsd_plan (plan_id, status, deleted_at),
  INDEX idx_cpsd_assignee (assigned_user_id, start_datetime),
  INDEX idx_cpsd_client (client_tenant_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Extend consulting_plan_items (activity domain type + call overrides)
-- MySQL safe-add via information_schema checks
-- ----------------------------

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'domain_activity_type_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN domain_activity_type_id INT NULL AFTER activity_type',
  'SELECT \"domain_activity_type_id exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'call_every_days_override'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN call_every_days_override INT NULL AFTER domain_activity_type_id',
  'SELECT \"call_every_days_override exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'consulting_plan_items'
    AND COLUMN_NAME = 'call_duration_minutes_override'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE consulting_plan_items ADD COLUMN call_duration_minutes_override INT NULL AFTER call_every_days_override',
  'SELECT \"call_duration_minutes_override exists\"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


