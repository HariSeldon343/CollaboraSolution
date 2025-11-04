-- ============================================
-- Migration: Add Assignment Expiration Warning Flag
-- Date: 2025-10-29
-- Description: Adds columns to track when expiration warning emails have been sent
-- ============================================

-- Add expiration warning flag and timestamp to file_assignments table
ALTER TABLE file_assignments
ADD COLUMN expiration_warning_sent TINYINT(1) DEFAULT 0
    COMMENT 'Flag: expiration warning email sent' AFTER expires_at,
ADD COLUMN expiration_warning_sent_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When expiration warning was sent' AFTER expiration_warning_sent;

-- Add index for efficient querying of assignments needing warnings
CREATE INDEX idx_assignments_expiration_warning
ON file_assignments(expires_at, expiration_warning_sent, deleted_at)
WHERE expires_at IS NOT NULL AND deleted_at IS NULL;

-- Optional: Add a second warning flag for critical expiration (1 day before)
ALTER TABLE file_assignments
ADD COLUMN critical_warning_sent TINYINT(1) DEFAULT 0
    COMMENT 'Flag: critical expiration warning sent (1 day before)' AFTER expiration_warning_sent_at,
ADD COLUMN critical_warning_sent_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When critical warning was sent' AFTER critical_warning_sent;

-- Update audit log CHECK constraints to include new action type
ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS chk_audit_action;
ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_action CHECK (action IN (
    'create', 'update', 'delete', 'restore',
    'login', 'logout', 'login_failed', 'session_expired',
    'download', 'upload', 'view', 'export', 'import',
    'approve', 'reject', 'submit', 'cancel',
    'share', 'unshare', 'permission_grant', 'permission_revoke',
    'password_change', 'password_reset', 'email_change',
    'tenant_switch', 'system_update', 'backup', 'restore_backup',
    'access',
    'document_opened', 'document_closed', 'document_saved',
    'assign', 'unassign', 'complete', 'reopen', 'close',
    'comment', 'reply', 'archive', 'unarchive', 'duplicate',
    'merge', 'move', 'rename', 'config_change', 'setting_change',
    'expiration_warning', 'critical_warning'  -- NEW: Assignment warning actions
));

-- Verify the new columns were added
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'file_assignments'
  AND COLUMN_NAME IN (
    'expiration_warning_sent',
    'expiration_warning_sent_at',
    'critical_warning_sent',
    'critical_warning_sent_at'
  )
ORDER BY ORDINAL_POSITION;