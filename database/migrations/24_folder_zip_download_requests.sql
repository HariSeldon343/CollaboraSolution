-- Migration 24: folder zip download requests (approval workflow)
-- Purpose: allow non-privileged users to request folder ZIP download approval
-- Author: CollaboraNexio

START TRANSACTION;

CREATE TABLE IF NOT EXISTS folder_zip_download_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  folder_id INT NOT NULL,
  requester_id INT NOT NULL,
  status ENUM('pending','approved','rejected','consumed') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  decided_by INT NULL,
  used_at DATETIME NULL,
  note TEXT NULL,
  INDEX idx_fzdr_tenant_folder (tenant_id, folder_id),
  INDEX idx_fzdr_status (status),
  INDEX idx_fzdr_requester_status (requester_id, status),
  INDEX idx_fzdr_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

