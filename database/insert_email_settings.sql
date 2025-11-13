-- Insert default email settings for system_settings table
USE collaboranexio;

-- Insert default email settings if they don't exist
INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `value_type`, `description`) VALUES
('email', 'smtp_host', 'smtp.gmail.com', 'string', 'SMTP server hostname'),
('email', 'smtp_port', '587', 'integer', 'SMTP server port'),
('email', 'smtp_encryption', 'tls', 'string', 'SMTP encryption type (tls/ssl/none)'),
('email', 'smtp_username', '', 'string', 'SMTP authentication username'),
('email', 'smtp_password', '', 'string', 'SMTP authentication password'),
('email', 'smtp_from_email', 'noreply@collaboranexio.com', 'string', 'Default from email address'),
('email', 'smtp_from_name', 'CollaboraNexio', 'string', 'Default from name'),
('email', 'smtp_enabled', '0', 'boolean', 'Enable/disable email functionality');

-- Verification
SELECT * FROM system_settings WHERE category = 'email' ORDER BY setting_key;
