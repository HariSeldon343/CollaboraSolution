-- =====================================================
-- Aggiornamento Configurazione Email Nexio Solution
-- =====================================================
--
-- Configurazione:
-- Server: mail.nexiosolution.it
-- Porta: 465 (SSL)
-- Username: info@nexiosolution.it
-- Password: Ricord@1991
--
-- Eseguire con:
-- mysql -uroot collaboranexio < database/update_nexio_email_config.sql
-- oppure:
-- php update_nexio_email_config.php
--
-- =====================================================

USE collaboranexio;

-- Aggiorna o inserisci configurazione email Nexio Solution
INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `value_type`, `description`, `updated_at`)
VALUES
    ('email', 'smtp_host', 'mail.nexiosolution.it', 'string', 'SMTP server hostname', NOW()),
    ('email', 'smtp_port', '465', 'integer', 'SMTP server port (465 for SSL)', NOW()),
    ('email', 'smtp_encryption', 'ssl', 'string', 'SMTP encryption type (ssl for port 465)', NOW()),
    ('email', 'smtp_username', 'info@nexiosolution.it', 'string', 'SMTP authentication username', NOW()),
    ('email', 'smtp_password', 'Ricord@1991', 'string', 'SMTP authentication password', NOW()),
    ('email', 'from_email', 'info@nexiosolution.it', 'string', 'Default from email address', NOW()),
    ('email', 'from_name', 'Nexio Solution', 'string', 'Default from name', NOW()),
    ('email', 'reply_to', 'info@nexiosolution.it', 'string', 'Reply-to email address', NOW()),
    ('email', 'smtp_enabled', '1', 'boolean', 'Enable/disable email functionality', NOW())
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`),
    `value_type` = VALUES(`value_type`),
    `description` = VALUES(`description`),
    `updated_at` = NOW();

-- Verifica configurazione applicata
SELECT
    setting_key,
    CASE
        WHEN setting_key = 'smtp_password' THEN CONCAT(REPEAT('*', CHAR_LENGTH(setting_value)))
        ELSE setting_value
    END AS setting_value,
    value_type,
    updated_at
FROM system_settings
WHERE category = 'email'
ORDER BY setting_key;

-- Messaggio di conferma
SELECT '✓ Configurazione email Nexio Solution aggiornata con successo!' AS status;
