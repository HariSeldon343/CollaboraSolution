-- ============================================================================
-- PASSWORD EXPIRATION SYSTEM & EMAIL CONFIGURATION
-- CollaboraNexio - Database Update Script
-- ============================================================================
-- This script adds password expiration functionality and configures SMTP
-- ============================================================================

-- 1. Add password_expires_at column to users table
-- ============================================================================
ALTER TABLE users
ADD COLUMN IF NOT EXISTS password_expires_at DATETIME NULL
AFTER password_set_at
COMMENT '90-day password expiration timestamp';

-- 2. Update SMTP settings in system_settings table
-- ============================================================================
-- Infomaniak SMTP Configuration for info@fortibyte.it

-- Check if system_settings table exists, create if not
CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert or update SMTP settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
    ('smtp_enabled', '1', 'boolean', 'Enable SMTP email sending'),
    ('smtp_host', 'mail.infomaniak.com', 'string', 'SMTP server host'),
    ('smtp_port', '465', 'integer', 'SMTP server port'),
    ('smtp_encryption', 'ssl', 'string', 'SMTP encryption method (ssl or tls)'),
    ('smtp_username', 'info@fortibyte.it', 'string', 'SMTP authentication username'),
    ('smtp_password', 'Cartesi@1991', 'string', 'SMTP authentication password'),
    ('smtp_from_email', 'info@fortibyte.it', 'string', 'Default FROM email address'),
    ('smtp_from_name', 'CollaboraNexio', 'string', 'Default FROM name')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- 3. Set password expiration for existing users (90 days from password_set_at)
-- ============================================================================
-- For users who already have a password set, calculate expiration
UPDATE users
SET password_expires_at = DATE_ADD(IFNULL(password_set_at, created_at), INTERVAL 90 DAY)
WHERE password_expires_at IS NULL
  AND password_set_at IS NOT NULL
  AND deleted_at IS NULL;

-- For users without password_set_at but not first login, use created_at
UPDATE users
SET password_expires_at = DATE_ADD(created_at, INTERVAL 90 DAY)
WHERE password_expires_at IS NULL
  AND password_set_at IS NULL
  AND first_login = 0
  AND deleted_at IS NULL;

-- 4. Create notification settings for password expiration warnings
-- ============================================================================
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
    ('password_expiry_days', '90', 'integer', 'Password expiry period in days'),
    ('password_warning_days', '7', 'integer', 'Days before expiry to send warning'),
    ('password_enforce_expiry', '1', 'boolean', 'Enforce password expiration policy')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- 5. Create password_expiry_notifications table for tracking warnings
-- ============================================================================
CREATE TABLE IF NOT EXISTS password_expiry_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type ENUM('warning_7days', 'warning_3days', 'warning_1day', 'expired') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    password_expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_notification (user_id, notification_type),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Tracks password expiration notifications sent to users';

-- 6. Update audit_logs to track password changes
-- ============================================================================
-- This will help track when users change passwords
-- (No changes needed, existing audit_logs should handle this)

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify password_expires_at column was added
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'password_expires_at';

-- Verify SMTP settings
SELECT setting_key, setting_value, setting_type
FROM system_settings
WHERE setting_key LIKE 'smtp%' OR setting_key LIKE 'password_%'
ORDER BY setting_key;

-- Count users with password expiration set
SELECT
    COUNT(*) as total_users,
    SUM(CASE WHEN password_expires_at IS NOT NULL THEN 1 ELSE 0 END) as with_expiry,
    SUM(CASE WHEN password_expires_at < NOW() THEN 1 ELSE 0 END) as expired,
    SUM(CASE WHEN password_expires_at < DATE_ADD(NOW(), INTERVAL 7 DAY) AND password_expires_at >= NOW() THEN 1 ELSE 0 END) as expiring_soon
FROM users
WHERE deleted_at IS NULL;

-- ============================================================================
-- ROLLBACK SCRIPT (if needed)
-- ============================================================================
/*
-- To rollback these changes, run:

-- Remove password_expires_at column
ALTER TABLE users DROP COLUMN password_expires_at;

-- Remove password expiry notifications table
DROP TABLE IF EXISTS password_expiry_notifications;

-- Remove system settings (optional - only if you want to completely remove)
DELETE FROM system_settings WHERE setting_key LIKE 'password_%';
DELETE FROM system_settings WHERE setting_key LIKE 'smtp%';
*/
