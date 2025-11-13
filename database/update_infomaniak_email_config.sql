-- Module: Infomaniak Email Configuration Update
-- Version: 2025-10-05
-- Author: Database Architect
-- Description: Updates system_settings table with Infomaniak SMTP configuration
--              for email delivery via info@fortibyte.it

USE collaboranexio;

-- ============================================
-- ENSURE TABLE EXISTS
-- ============================================
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

-- ============================================
-- INFOMANIAK EMAIL SETTINGS
-- ============================================
-- Infomaniak SMTP Configuration for info@fortibyte.it
-- Provider: Infomaniak (https://www.infomaniak.com)
-- Server: mail.infomaniak.com
-- Port: 465 (SSL/TLS encrypted connection)
-- Authentication: Required
--
-- IMPORTANT NOTES:
-- - Port 465 requires SSL encryption (smtp_secure = 'ssl')
-- - smtp_encryption should be set to 'tls' for the encryption flag
-- - These settings will override any existing email configuration
-- - Password is stored in plain text - consider encryption for production
-- ============================================

INSERT INTO `system_settings`
    (`setting_key`, `setting_value`, `setting_type`, `description`, `category`)
VALUES
    ('smtp_host', 'mail.infomaniak.com', 'string', 'SMTP server hostname (Infomaniak)', 'email'),
    ('smtp_port', '465', 'integer', 'SMTP server port (SSL)', 'email'),
    ('smtp_encryption', 'tls', 'string', 'SMTP encryption enabled flag', 'email'),
    ('smtp_secure', 'ssl', 'string', 'SMTP security protocol (ssl for port 465)', 'email'),
    ('smtp_username', 'info@fortibyte.it', 'string', 'SMTP authentication username (Infomaniak)', 'email'),
    ('smtp_password', 'Cartesi@1987', 'string', 'SMTP authentication password (Infomaniak)', 'email'),
    ('smtp_from_email', 'info@fortibyte.it', 'string', 'Default from email address', 'email'),
    ('smtp_from_name', 'CollaboraNexio', 'string', 'Default from name', 'email'),
    ('smtp_enabled', '1', 'boolean', 'Enable/disable email functionality (enabled for Infomaniak)', 'email')
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`),
    `setting_type` = VALUES(`setting_type`),
    `description` = VALUES(`description`),
    `category` = VALUES(`category`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ============================================
-- VERIFICATION
-- ============================================
-- Display all email settings to verify configuration
SELECT
    'Infomaniak email configuration updated successfully' AS status,
    NOW() AS execution_time;

SELECT
    `setting_key`,
    CASE
        WHEN `setting_key` = 'smtp_password' THEN '***REDACTED***'
        ELSE `setting_value`
    END AS `setting_value`,
    `setting_type`,
    `description`,
    `updated_at`
FROM `system_settings`
WHERE `category` = 'email'
ORDER BY
    CASE `setting_key`
        WHEN 'smtp_host' THEN 1
        WHEN 'smtp_port' THEN 2
        WHEN 'smtp_secure' THEN 3
        WHEN 'smtp_encryption' THEN 4
        WHEN 'smtp_username' THEN 5
        WHEN 'smtp_password' THEN 6
        WHEN 'smtp_from_email' THEN 7
        WHEN 'smtp_from_name' THEN 8
        WHEN 'smtp_enabled' THEN 9
        ELSE 99
    END;

-- Summary statistics
SELECT
    COUNT(*) as total_settings,
    COUNT(CASE WHEN category = 'email' THEN 1 END) as email_settings,
    COUNT(CASE WHEN category = 'email' AND setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_from_email') THEN 1 END) as infomaniak_settings
FROM `system_settings`;

-- ============================================
-- MIGRATION NOTES
-- ============================================
--
-- TESTING CHECKLIST:
-- 1. Verify SMTP connection to mail.infomaniak.com:465
-- 2. Test email sending via info@fortibyte.it
-- 3. Check email delivery and spam folder
-- 4. Verify SSL/TLS encryption is working
--
-- SECURITY RECOMMENDATIONS:
-- 1. Consider encrypting smtp_password in production
-- 2. Restrict database access to this table
-- 3. Use environment variables for sensitive data
-- 4. Enable audit logging for setting changes
--
-- ROLLBACK PROCEDURE:
-- To revert to previous settings:
-- UPDATE system_settings SET setting_value = 'previous_value' WHERE setting_key = 'setting_name';
--
-- ============================================
