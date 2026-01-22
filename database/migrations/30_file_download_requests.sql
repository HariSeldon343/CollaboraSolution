-- Migration 30: file download requests (approval workflow - one-time)
-- Purpose: allow non-privileged users to request a one-time download approval for assigned files
-- Author: CollaboraNexio

START TRANSACTION;

CREATE TABLE IF NOT EXISTS file_download_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  file_id INT NOT NULL,
  requester_id INT NOT NULL,
  status ENUM('pending','approved','rejected','consumed') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  decided_by INT NULL,
  used_at DATETIME NULL,
  note TEXT NULL,
  INDEX idx_fdr_tenant_file (tenant_id, file_id),
  INDEX idx_fdr_status (status),
  INDEX idx_fdr_requester_status (requester_id, status),
  INDEX idx_fdr_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


