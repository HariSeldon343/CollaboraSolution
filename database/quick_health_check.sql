-- ============================================
-- CollaboraNexio - Quick Database Health Check
-- ============================================
-- Version: 1.0.0
-- Date: 2025-10-12
-- Author: Database Architect
--
-- Purpose: Quick verification queries for database health monitoring
-- Usage: mysql -u root collaboranexio < quick_health_check.sql
-- ============================================

USE collaboranexio;

-- ============================================
-- BASIC HEALTH METRICS
-- ============================================

SELECT '
==========================================
DATABASE HEALTH CHECK
==========================================
' as header;

-- Database version
SELECT 'Database Version:' as metric, VERSION() as value;

-- Total tables
SELECT 'Total Tables:' as metric, COUNT(*) as value
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio';

-- Total foreign keys
SELECT 'Foreign Key Constraints:' as metric, COUNT(*) as value
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Total indexes
SELECT 'Total Indexes:' as metric, COUNT(DISTINCT INDEX_NAME) as value
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio';

-- Event scheduler status
SELECT 'Event Scheduler:' as metric, @@event_scheduler as value;

-- ============================================
-- ONLYOFFICE TABLES CHECK
-- ============================================

SELECT '
==========================================
ONLYOFFICE INTEGRATION STATUS
==========================================
' as header;

-- Verify OnlyOffice tables exist
SELECT
    'document_editor_sessions' as table_name,
    IF(COUNT(*) > 0, 'EXISTS', 'MISSING') as status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'document_editor_sessions'

UNION ALL

SELECT
    'document_editor_config' as table_name,
    IF(COUNT(*) > 0, 'EXISTS', 'MISSING') as status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'document_editor_config'

UNION ALL

SELECT
    'document_editor_callbacks' as table_name,
    IF(COUNT(*) > 0, 'EXISTS', 'MISSING') as status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'document_editor_callbacks';

-- Active editor sessions
SELECT 'Active Editor Sessions:' as metric, COUNT(*) as value
FROM document_editor_sessions
WHERE closed_at IS NULL
AND deleted_at IS NULL;

-- Stale sessions (>24 hours)
SELECT 'Stale Sessions (>24h):' as metric, COUNT(*) as value
FROM document_editor_sessions
WHERE closed_at IS NULL
AND deleted_at IS NULL
AND last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Editable files count
SELECT 'Editable Files:' as metric, COUNT(*) as value
FROM files
WHERE is_editable = 1
AND deleted_at IS NULL;

-- ============================================
-- DATA INTEGRITY CHECKS
-- ============================================

SELECT '
==========================================
DATA INTEGRITY STATUS
==========================================
' as header;

-- Orphaned files (invalid tenant)
SELECT 'Orphaned Files (invalid tenant):' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') as status
FROM files f
LEFT JOIN tenants t ON f.tenant_id = t.id
WHERE t.id IS NULL
AND f.deleted_at IS NULL;

-- Orphaned files (invalid user)
SELECT 'Orphaned Files (invalid user):' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') as status
FROM files f
LEFT JOIN users u ON f.uploaded_by = u.id
WHERE u.id IS NULL
AND f.uploaded_by IS NOT NULL
AND f.deleted_at IS NULL;

-- Orphaned users (invalid tenant)
SELECT 'Orphaned Users (invalid tenant):' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') as status
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE t.id IS NULL
AND u.deleted_at IS NULL;

-- Orphaned sessions (invalid file)
SELECT 'Orphaned Sessions (invalid file):' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'WARN') as status
FROM document_editor_sessions s
LEFT JOIN files f ON s.file_id = f.id
WHERE f.id IS NULL
AND s.deleted_at IS NULL;

-- Duplicate session tokens
SELECT 'Duplicate Session Tokens:' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') as status
FROM (
    SELECT session_token
    FROM document_editor_sessions
    GROUP BY session_token
    HAVING COUNT(*) > 1
) as duplicates;

-- Invalid file sizes
SELECT 'Files with Invalid Size:' as check_name,
       COUNT(*) as count,
       IF(COUNT(*) = 0, 'PASS', 'WARN') as status
FROM files
WHERE is_folder = 0
AND (file_size IS NULL OR file_size = 0)
AND deleted_at IS NULL;

-- ============================================
-- TENANT DISTRIBUTION
-- ============================================

SELECT '
==========================================
TENANT DISTRIBUTION
==========================================
' as header;

SELECT
    t.id as tenant_id,
    t.name as tenant_name,
    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) as users,
    (SELECT COUNT(*) FROM files f WHERE f.tenant_id = t.id AND f.deleted_at IS NULL) as files,
    (SELECT COUNT(*) FROM projects p WHERE p.tenant_id = t.id AND p.deleted_at IS NULL) as projects,
    (SELECT COUNT(*) FROM tasks ta WHERE ta.tenant_id = t.id AND ta.deleted_at IS NULL) as tasks
FROM tenants t
WHERE t.deleted_at IS NULL
ORDER BY t.id;

-- ============================================
-- INDEX COVERAGE CHECK
-- ============================================

SELECT '
==========================================
INDEX COVERAGE (Critical Tables)
==========================================
' as header;

SELECT
    TABLE_NAME,
    COUNT(DISTINCT INDEX_NAME) as index_count,
    GROUP_CONCAT(DISTINCT
        CASE
            WHEN INDEX_NAME LIKE '%tenant%' THEN 'tenant_idx'
            WHEN INDEX_NAME LIKE '%deleted%' THEN 'deleted_idx'
            ELSE NULL
        END
    ) as key_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME IN (
    'files',
    'users',
    'document_editor_sessions',
    'document_editor_config',
    'document_editor_callbacks',
    'projects',
    'tasks'
)
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

-- ============================================
-- FOREIGN KEY VALIDATION
-- ============================================

SELECT '
==========================================
FOREIGN KEY CONSTRAINTS
==========================================
' as header;

SELECT
    TABLE_NAME,
    COUNT(*) as fk_count,
    SUM(CASE WHEN DELETE_RULE = 'CASCADE' THEN 1 ELSE 0 END) as cascade_count,
    SUM(CASE WHEN DELETE_RULE = 'SET NULL' THEN 1 ELSE 0 END) as set_null_count,
    SUM(CASE WHEN DELETE_RULE IN ('RESTRICT', 'NO ACTION') THEN 1 ELSE 0 END) as restrict_count
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
GROUP BY TABLE_NAME
ORDER BY fk_count DESC
LIMIT 10;

-- ============================================
-- AUTOMATED MAINTENANCE STATUS
-- ============================================

SELECT '
==========================================
AUTOMATED MAINTENANCE
==========================================
' as header;

-- Scheduled events
SELECT
    EVENT_NAME,
    STATUS,
    EVENT_TYPE,
    EXECUTE_AT,
    INTERVAL_VALUE,
    INTERVAL_FIELD,
    LAST_EXECUTED,
    ON_COMPLETION
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = 'collaboranexio';

-- Stored procedures
SELECT
    ROUTINE_NAME as procedure_name,
    ROUTINE_TYPE as type,
    CREATED as created_at,
    LAST_ALTERED as last_modified
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
ORDER BY ROUTINE_TYPE, ROUTINE_NAME;

-- ============================================
-- DATABASE SIZE METRICS
-- ============================================

SELECT '
==========================================
DATABASE SIZE METRICS
==========================================
' as header;

SELECT
    TABLE_NAME,
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb,
    TABLE_ROWS as estimated_rows,
    ROUND((INDEX_LENGTH / 1024 / 1024), 2) as index_size_mb,
    ROUND((DATA_LENGTH / 1024 / 1024), 2) as data_size_mb
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_TYPE = 'BASE TABLE'
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
LIMIT 15;

-- ============================================
-- SUMMARY
-- ============================================

SELECT '
==========================================
HEALTH CHECK COMPLETED
==========================================

Instructions:
1. Review any FAIL status items immediately
2. Investigate WARN status items when convenient
3. PASS status indicates healthy state

For detailed analysis, run:
  php comprehensive_database_integrity_verification.php

For maintenance tasks:
  - Clean stale sessions: CALL cleanup_expired_editor_sessions()
  - View active sessions: CALL get_active_editor_sessions()
  - Soft delete tenant: CALL sp_soft_delete_tenant_complete(tenant_id)

==========================================
' as summary;
