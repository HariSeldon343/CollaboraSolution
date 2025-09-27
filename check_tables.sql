-- Check existing tables and their structure
USE collabora;

-- Show all tables in the database
SHOW TABLES;

-- Check if specific tables exist and their structure
SELECT
    table_name,
    column_name,
    data_type,
    is_nullable,
    column_key
FROM information_schema.columns
WHERE table_schema = 'collabora'
AND table_name IN ('tenants', 'users', 'teams', 'files')
ORDER BY table_name, ordinal_position;

-- Check existing foreign keys
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = 'collabora'
ORDER BY TABLE_NAME, CONSTRAINT_NAME;