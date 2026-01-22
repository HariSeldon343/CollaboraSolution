-- Migration 56: Consulting (tenant 28) - Plan scopes (multi-service / multi-norma)
-- Goals:
--  - Allow a plan to have 1..N selected atomic services/norms as “scope”
--  - Keep estimate/override per scope
-- Notes:
--  - Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
--  - No foreign keys (schema-drift safe).

START TRANSACTION;

CREATE TABLE IF NOT EXISTS consulting_plan_scopes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  activity_type_id INT NOT NULL,
  estimated_days DECIMAL(10,2) NULL,
  planned_days_override DECIMAL(10,2) NULL,
  notes TEXT NULL,
  sort_order INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_plan_scope (plan_id, activity_type_id),
  INDEX idx_scope_plan (plan_id),
  INDEX idx_scope_type (activity_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

