-- Migration 57: Planning Estimate Wizard sessions (tenant 28) — server-side drafts
-- Additive + drift-safe: CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS consulting_plan_estimates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  client_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  services_json MEDIUMTEXT NULL,
  profile_json MEDIUMTEXT NULL,
  estimate_json MEDIUMTEXT NULL,
  linked_plan_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cpe_tenant_client_user (tenant_id, client_id, created_by_user_id, updated_at),
  INDEX idx_cpe_linked_plan (linked_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

