-- Migration 44: Consulting plan -> client tenant compliance artifact mapping (Leva 2/3/4)
-- Schema-drift safe: can be applied idempotently by the tool (CREATE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS consulting_plan_compliance_artifacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  client_tenant_id INT NOT NULL,
  artifact_type VARCHAR(20) NOT NULL, -- folder|document|task|event
  artifact_key VARCHAR(190) NOT NULL,
  target_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_plan_artifact (plan_id, client_tenant_id, artifact_type, artifact_key),
  INDEX idx_plan (plan_id, client_tenant_id),
  INDEX idx_target (artifact_type, target_id)
);

