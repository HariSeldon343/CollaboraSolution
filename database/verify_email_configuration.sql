-- ============================================
-- Email Configuration Verification Script
-- ============================================
-- Quick SQL verification of email settings
-- Run this anytime to verify configuration

USE collaboranexio;

-- ============================================
-- 1. CHECK ALL EMAIL SETTINGS
-- ============================================
SELECT '=== ALL EMAIL SETTINGS ===' as info;

SELECT
    setting_key,
    setting_value,
    value_type,
    is_public,
    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as last_updated
FROM system_settings
WHERE setting_key LIKE 'smtp_%'
   OR setting_key IN ('mail_from_name', 'mail_from_email')
ORDER BY setting_key;

-- ============================================
-- 2. VERIFY REQUIRED SETTINGS EXIST
-- ============================================
SELECT '=== REQUIRED SETTINGS CHECK ===' as info;

SELECT
    'smtp_enabled' as required_setting,
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END as status
FROM system_settings WHERE setting_key = 'smtp_enabled'
UNION ALL
SELECT
    'smtp_host',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_host'
UNION ALL
SELECT
    'smtp_port',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_port'
UNION ALL
SELECT
    'smtp_username',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_username'
UNION ALL
SELECT
    'smtp_password',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_password'
UNION ALL
SELECT
    'smtp_from_email',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_from_email'
UNION ALL
SELECT
    'smtp_encryption',
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
FROM system_settings WHERE setting_key = 'smtp_encryption';

-- ============================================
-- 3. CHECK FOR EMPTY VALUES
-- ============================================
SELECT '=== EMPTY VALUES CHECK ===' as info;

SELECT
    setting_key,
    CASE
        WHEN setting_value IS NULL THEN 'ERROR: NULL VALUE'
        WHEN setting_value = '' THEN 'ERROR: EMPTY STRING'
        ELSE 'OK'
    END as value_status
FROM system_settings
WHERE setting_key LIKE 'smtp_%'
   OR setting_key IN ('mail_from_name', 'mail_from_email');

-- ============================================
-- 4. CHECK FOR DUPLICATES
-- ============================================
SELECT '=== DUPLICATE SETTINGS CHECK ===' as info;

SELECT
    setting_key,
    COUNT(*) as occurrences,
    CASE
        WHEN COUNT(*) > 1 THEN 'ERROR: DUPLICATE'
        ELSE 'OK'
    END as status
FROM system_settings
WHERE setting_key LIKE 'smtp_%'
   OR setting_key IN ('mail_from_name', 'mail_from_email')
GROUP BY setting_key
HAVING COUNT(*) > 1;

-- If no duplicates, show success message
SELECT
    CASE
        WHEN (SELECT COUNT(DISTINCT setting_key)
              FROM system_settings
              WHERE setting_key LIKE 'smtp_%'
                 OR setting_key IN ('mail_from_name', 'mail_from_email'))
           = (SELECT COUNT(*)
              FROM system_settings
              WHERE setting_key LIKE 'smtp_%'
                 OR setting_key IN ('mail_from_name', 'mail_from_email'))
        THEN 'No duplicates found'
        ELSE 'WARNING: Duplicates detected above'
    END as duplicate_check_result;

-- ============================================
-- 5. VALIDATE INFOMANIAK CONFIGURATION
-- ============================================
SELECT '=== INFOMANIAK CONFIGURATION VALIDATION ===' as info;

SELECT
    setting_key,
    setting_value,
    CASE setting_key
        WHEN 'smtp_host' THEN
            CASE WHEN setting_value = 'mail.infomaniak.com' THEN 'OK' ELSE 'ERROR: Wrong host' END
        WHEN 'smtp_port' THEN
            CASE WHEN setting_value = '465' THEN 'OK' ELSE 'ERROR: Wrong port' END
        WHEN 'smtp_username' THEN
            CASE WHEN setting_value = 'info@fortibyte.it' THEN 'OK' ELSE 'ERROR: Wrong username' END
        WHEN 'smtp_password' THEN
            CASE WHEN setting_value = 'Cartesi@1987' THEN 'OK' ELSE 'ERROR: Wrong password' END
        WHEN 'smtp_from_email' THEN
            CASE WHEN setting_value = 'info@fortibyte.it' THEN 'OK' ELSE 'ERROR: Wrong from email' END
        WHEN 'smtp_from_name' THEN
            CASE WHEN setting_value = 'CollaboraNexio' THEN 'OK' ELSE 'ERROR: Wrong from name' END
        WHEN 'smtp_encryption' THEN
            CASE WHEN setting_value IN ('ssl', 'tls') THEN 'OK' ELSE 'ERROR: Wrong encryption' END
        WHEN 'smtp_secure' THEN
            CASE WHEN setting_value IN ('ssl', 'tls') THEN 'OK' ELSE 'ERROR: Wrong secure setting' END
        WHEN 'smtp_enabled' THEN
            CASE WHEN setting_value = '1' THEN 'OK' ELSE 'WARNING: Email disabled' END
        ELSE 'UNKNOWN SETTING'
    END as validation_status
FROM system_settings
WHERE setting_key LIKE 'smtp_%'
   OR setting_key IN ('mail_from_name', 'mail_from_email')
ORDER BY setting_key;

-- ============================================
-- 6. CONFIGURATION SUMMARY
-- ============================================
SELECT '=== CONFIGURATION SUMMARY ===' as info;

SELECT
    COUNT(*) as total_email_settings,
    SUM(CASE WHEN setting_value IS NOT NULL AND setting_value != '' THEN 1 ELSE 0 END) as settings_with_values,
    SUM(CASE WHEN setting_value IS NULL OR setting_value = '' THEN 1 ELSE 0 END) as empty_settings,
    MAX(updated_at) as most_recent_update
FROM system_settings
WHERE setting_key LIKE 'smtp_%'
   OR setting_key IN ('mail_from_name', 'mail_from_email');

-- ============================================
-- 7. FINAL STATUS
-- ============================================
SELECT '=== FINAL VERIFICATION STATUS ===' as info;

SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM system_settings
              WHERE (setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email'))
              AND (setting_value IS NULL OR setting_value = '')) > 0
        THEN 'FAILED: Some settings are empty'
        WHEN (SELECT COUNT(*) FROM system_settings
              WHERE setting_key = 'smtp_host' AND setting_value = 'mail.infomaniak.com') = 0
        THEN 'FAILED: SMTP host not configured correctly'
        WHEN (SELECT COUNT(*) FROM system_settings
              WHERE setting_key = 'smtp_port' AND setting_value = '465') = 0
        THEN 'FAILED: SMTP port not configured correctly'
        WHEN (SELECT COUNT(*) FROM system_settings
              WHERE setting_key = 'smtp_username' AND setting_value = 'info@fortibyte.it') = 0
        THEN 'FAILED: SMTP username not configured correctly'
        WHEN (SELECT COUNT(*) FROM system_settings
              WHERE setting_key = 'smtp_enabled' AND setting_value = '1') = 0
        THEN 'WARNING: SMTP is disabled'
        ELSE 'PASSED: All email settings are correctly configured for Infomaniak'
    END as verification_status,
    NOW() as verification_time;
