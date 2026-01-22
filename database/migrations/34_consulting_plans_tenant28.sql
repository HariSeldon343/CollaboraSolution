-- Migration 34: Consulting planning (tenant 28 internal tools)
-- Purpose: planning/proposal of consulting activities, cost estimation, and links to tasks
-- Scope: data is global in DB but access is restricted server-side to tenant 28 staff (see includes/tenant28_access_check.php)
-- Author: CollaboraNexio

START TRANSACTION;

CREATE TABLE IF NOT EXISTS consulting_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_tenant_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','proposed','approved','scheduled','done','cancelled') NOT NULL DEFAULT 'draft',
  period_start DATE NULL,
  period_end DATE NULL,
  notes TEXT NULL,
  created_by_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  INDEX idx_cp_client (client_tenant_id, deleted_at),
  INDEX idx_cp_status (status, deleted_at),
  INDEX idx_cp_period (period_start, period_end),
  INDEX idx_cp_created_by (created_by_user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consulting_plan_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  activity_type ENUM('onsite','remote','call','communication','travel') NOT NULL DEFAULT 'remote',
  activity_date DATE NULL,
  days DECIMAL(10,2) NOT NULL DEFAULT 0,
  hours DECIMAL(10,2) NOT NULL DEFAULT 0,
  km DECIMAL(10,2) NOT NULL DEFAULT 0,
  day_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  km_rate DECIMAL(10,2) NOT NULL DEFAULT 0.50,
  extras_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  INDEX idx_cpi_plan (plan_id, deleted_at),
  INDEX idx_cpi_type (activity_type),
  INDEX idx_cpi_date (activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consulting_plan_task_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_item_id INT NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cptl_item_task (plan_item_id, task_id),
  INDEX idx_cptl_task (task_id),
  INDEX idx_cptl_item (plan_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


