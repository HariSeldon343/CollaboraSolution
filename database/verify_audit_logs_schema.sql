-- Verify audit_logs table schema
-- This script checks if the audit_logs table has the correct columns

USE collaboranexio;

SELECT 'Checking audit_logs table schema...' as status;

-- Show current table structure
DESCRIBE audit_logs;

-- Check for required columns
SELECT
    'description' as column_name,
    IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'description'
UNION ALL
SELECT
    'old_values' as column_name,
    IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'old_values'
UNION ALL
SELECT
    'new_values' as column_name,
    IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'new_values'
UNION ALL
SELECT
    'severity' as column_name,
    IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'severity'
UNION ALL
SELECT
    'status' as column_name,
    IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') as status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'status';

-- If you see MISSING columns, you need to run the migration
