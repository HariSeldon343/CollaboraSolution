-- Create system_settings table for configuration storage
-- This table stores all system-wide settings (email, backup, security, etc.)

USE collaboranexio;

-- Create table if it doesn't exist
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `setting_type` ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of the setting',
    `category` VARCHAR(50) DEFAULT 'general' COMMENT 'Setting category (email, backup, security, etc.)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System-wide configuration settings';

-- Insert default email settings if they don't exist
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`) VALUES
('smtp_host', 'smtp.gmail.com', 'string', 'SMTP server hostname', 'email'),
('smtp_port', '587', 'integer', 'SMTP server port', 'email'),
('smtp_encryption', 'tls', 'string', 'SMTP encryption type (tls/ssl/none)', 'email'),
('smtp_username', '', 'string', 'SMTP authentication username', 'email'),
('smtp_password', '', 'string', 'SMTP authentication password', 'email'),
('smtp_from_email', 'noreply@collaboranexio.com', 'string', 'Default from email address', 'email'),
('smtp_from_name', 'CollaboraNexio', 'string', 'Default from name', 'email'),
('smtp_enabled', '0', 'boolean', 'Enable/disable email functionality', 'email'),
('backup_enabled', '1', 'boolean', 'Enable/disable automatic backups', 'backup'),
('backup_retention_days', '30', 'integer', 'Number of days to keep backups', 'backup'),
('session_lifetime', '7200', 'integer', 'Session lifetime in seconds', 'security'),
('max_login_attempts', '5', 'integer', 'Maximum login attempts before lockout', 'security');

-- Verification query
SELECT
    COUNT(*) as total_settings,
    COUNT(CASE WHEN category = 'email' THEN 1 END) as email_settings,
    COUNT(CASE WHEN category = 'backup' THEN 1 END) as backup_settings,
    COUNT(CASE WHEN category = 'security' THEN 1 END) as security_settings
FROM system_settings;
