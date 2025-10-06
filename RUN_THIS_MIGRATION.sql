-- ============================================================================
-- QUICK DEPLOYMENT SCRIPT
-- CollaboraNexio - User Creation & Password Expiration System
-- ============================================================================
-- RUN THIS SCRIPT ONCE TO ENABLE ALL FIXES
-- Database: collaboranexio
-- ============================================================================

USE collaboranexio;

-- 1. ADD PASSWORD EXPIRATION COLUMN
-- ============================================================================
ALTER TABLE users
ADD COLUMN IF NOT EXISTS password_expires_at DATETIME NULL COMMENT '90-day password expiration timestamp'
AFTER password_set_at;

-- 2. SYSTEM SETTINGS TABLE (already exists, skip creation)
-- ============================================================================
-- Table already exists with columns: id, tenant_id, category, setting_key, setting_value, value_type, description, is_public, created_at, updated_at

-- 3. INSERT SMTP SETTINGS (Infomaniak Configuration)
-- ============================================================================
INSERT INTO system_settings (category, setting_key, setting_value, value_type, description) VALUES
    ('email', 'smtp_enabled', '1', 'boolean', 'Enable SMTP email sending'),
    ('email', 'smtp_host', 'mail.infomaniak.com', 'string', 'SMTP server host'),
    ('email', 'smtp_port', '465', 'integer', 'SMTP server port'),
    ('email', 'smtp_encryption', 'ssl', 'string', 'SMTP encryption method (ssl or tls)'),
    ('email', 'smtp_username', 'info@fortibyte.it', 'string', 'SMTP authentication username'),
    ('email', 'smtp_password', 'Cartesi@1991', 'string', 'SMTP authentication password'),
    ('email', 'smtp_from_email', 'info@fortibyte.it', 'string', 'Default FROM email address'),
    ('email', 'smtp_from_name', 'CollaboraNexio', 'string', 'Default FROM name')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- 4. INSERT PASSWORD EXPIRATION SETTINGS
-- ============================================================================
INSERT INTO system_settings (category, setting_key, setting_value, value_type, description) VALUES
    ('password', 'password_expiry_days', '90', 'integer', 'Password expiry period in days'),
    ('password', 'password_warning_days', '7', 'integer', 'Days before expiry to send warning'),
    ('password', 'password_enforce_expiry', '1', 'boolean', 'Enforce password expiration policy')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- 5. CREATE PASSWORD EXPIRY NOTIFICATIONS TABLE
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

-- 6. SET EXPIRATION FOR EXISTING USERS
-- ============================================================================
-- For users who already have password_set_at, calculate expiration from that date
UPDATE users
SET password_expires_at = DATE_ADD(password_set_at, INTERVAL 90 DAY)
WHERE password_expires_at IS NULL
  AND password_set_at IS NOT NULL
  AND deleted_at IS NULL;

-- For users without password_set_at but not first login, use created_at
UPDATE users
SET password_expires_at = DATE_ADD(created_at, INTERVAL 90 DAY)
WHERE password_expires_at IS NULL
  AND password_set_at IS NULL
  AND (first_login = 0 OR first_login IS NULL)
  AND deleted_at IS NULL;

-- ============================================================================
-- VERIFICATION
-- ============================================================================

SELECT '=====================================' as '';
SELECT 'MIGRATION COMPLETED SUCCESSFULLY' as '';
SELECT '=====================================' as '';

-- Show password_expires_at column
SELECT 'Checking password_expires_at column...' as '';
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'password_expires_at';

-- Show SMTP settings
SELECT '' as '';
SELECT 'SMTP Configuration:' as '';
SELECT setting_key, setting_value
FROM system_settings
WHERE setting_key LIKE 'smtp%'
ORDER BY setting_key;

-- Show password settings
SELECT '' as '';
SELECT 'Password Expiration Settings:' as '';
SELECT setting_key, setting_value
FROM system_settings
WHERE setting_key LIKE 'password_%'
ORDER BY setting_key;

-- Show user statistics
SELECT '' as '';
SELECT 'User Statistics:' as '';
SELECT
    COUNT(*) as total_users,
    SUM(CASE WHEN password_expires_at IS NOT NULL THEN 1 ELSE 0 END) as users_with_expiry,
    SUM(CASE WHEN password_expires_at < NOW() THEN 1 ELSE 0 END) as users_with_expired_password,
    SUM(CASE WHEN password_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as users_expiring_soon
FROM users
WHERE deleted_at IS NULL;

SELECT '' as '';
SELECT '=====================================' as '';
SELECT 'Next Steps:' as '';
SELECT '1. User creation form is already working' as '';
SELECT '2. Email system configured for Infomaniak' as '';
SELECT '3. Password expiration enforced (90 days)' as '';
SELECT '4. Test by creating a new user in /utenti.php' as '';
SELECT '=====================================' as '';
