-- ============================================
-- Fix Email Database Configuration Issues
-- ============================================
-- Module: Email Configuration Fix
-- Version: 2025-10-05
-- Author: Database Architect
-- Description: Adds missing smtp_secure setting

USE collaboranexio;

-- Add smtp_secure setting (missing from current configuration)
INSERT INTO system_settings (tenant_id, category, setting_key, setting_value, value_type, description, is_public)
VALUES (
    NULL,
    'email',
    'smtp_secure',
    'ssl',
    'string',
    'SMTP security method (ssl or tls)',
    0
)
ON DUPLICATE KEY UPDATE
    setting_value = 'ssl',
    updated_at = CURRENT_TIMESTAMP;

-- Verify all email settings
SELECT
    setting_key,
    setting_value,
    value_type,
    is_public,
    updated_at
FROM system_settings
WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email')
ORDER BY setting_key;

-- Success message
SELECT 'Email configuration fix completed successfully' as status,
       COUNT(*) as total_email_settings,
       NOW() as execution_time
FROM system_settings
WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email');
