-- Module: Database Structure Check
-- Version: 2025-09-25
-- Author: Database Architect
-- Description: Comprehensive script to check current database structure and foreign key relationships

USE collaboranexio;

-- ============================================
-- CHECK DATABASE EXISTS
-- ============================================
SELECT
    'Database Status' as Component,
    SCHEMA_NAME as Name,
    DEFAULT_CHARACTER_SET_NAME as Charset,
    DEFAULT_COLLATION_NAME as Collation
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'collaboranexio';

-- ============================================
-- LIST ALL TABLES WITH ROW COUNTS
-- ============================================
SELECT
    'Table Overview' as Component,
    TABLE_NAME as TableName,
    TABLE_ROWS as EstimatedRows,
    ROUND(DATA_LENGTH/1024/1024, 2) as DataSizeMB,
    ROUND(INDEX_LENGTH/1024/1024, 2) as IndexSizeMB,
    CREATE_TIME as Created,
    UPDATE_TIME as LastUpdated
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
ORDER BY TABLE_NAME;

-- ============================================
-- LIST ALL FOREIGN KEY CONSTRAINTS
-- ============================================
SELECT
    'Foreign Key Constraints' as Component,
    CONSTRAINT_NAME as ConstraintName,
    TABLE_NAME as ChildTable,
    COLUMN_NAME as ChildColumn,
    REFERENCED_TABLE_NAME as ParentTable,
    REFERENCED_COLUMN_NAME as ParentColumn,
    DELETE_RULE as OnDelete,
    UPDATE_RULE as OnUpdate
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- ============================================
-- TABLE DEPENDENCIES (Parent -> Child relationships)
-- ============================================
SELECT
    'Table Dependencies' as Component,
    REFERENCED_TABLE_NAME as ParentTable,
    GROUP_CONCAT(DISTINCT TABLE_NAME ORDER BY TABLE_NAME SEPARATOR ', ') as ChildTables,
    COUNT(DISTINCT TABLE_NAME) as ChildTableCount
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY REFERENCED_TABLE_NAME
ORDER BY ChildTableCount DESC, ParentTable;

-- ============================================
-- TABLES WITHOUT FOREIGN KEY DEPENDENCIES
-- ============================================
SELECT
    'Independent Tables' as Component,
    t.TABLE_NAME as TableName,
    'No Dependencies' as Status
FROM information_schema.TABLES t
LEFT JOIN information_schema.KEY_COLUMN_USAGE k
    ON t.TABLE_NAME = k.TABLE_NAME
    AND t.TABLE_SCHEMA = k.TABLE_SCHEMA
    AND k.REFERENCED_TABLE_NAME IS NOT NULL
WHERE t.TABLE_SCHEMA = 'collaboranexio'
    AND t.TABLE_TYPE = 'BASE TABLE'
    AND k.CONSTRAINT_NAME IS NULL
ORDER BY t.TABLE_NAME;

-- ============================================
-- CHECK MULTI-TENANCY SUPPORT
-- ============================================
SELECT
    'Multi-Tenancy Check' as Component,
    TABLE_NAME as TableName,
    IF(COLUMN_NAME IS NULL, 'MISSING tenant_id', 'Has tenant_id') as TenantSupport
FROM information_schema.TABLES t
LEFT JOIN information_schema.COLUMNS c
    ON t.TABLE_NAME = c.TABLE_NAME
    AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
    AND c.COLUMN_NAME = 'tenant_id'
WHERE t.TABLE_SCHEMA = 'collaboranexio'
    AND t.TABLE_TYPE = 'BASE TABLE'
    AND t.TABLE_NAME NOT IN ('tenants', 'migrations', 'cache')
ORDER BY TenantSupport DESC, TABLE_NAME;

-- ============================================
-- CHECK INDEXES
-- ============================================
SELECT
    'Index Analysis' as Component,
    TABLE_NAME as TableName,
    INDEX_NAME as IndexName,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as Columns,
    IF(NON_UNIQUE = 0, 'UNIQUE', 'NON-UNIQUE') as IndexType,
    CARDINALITY as Cardinality
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================
-- CHECK FOR ORPHANED RECORDS
-- ============================================
-- This will check for records that reference non-existent parents
-- (Run these queries individually if needed)

-- Example: Check orphaned users (no tenant)
SELECT
    'Orphaned Records Check' as Component,
    'users without valid tenant' as Issue,
    COUNT(*) as Count
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE t.id IS NULL;

-- ============================================
-- SUGGESTED DROP ORDER (Children before Parents)
-- ============================================
SELECT
    'Drop Order Suggestion' as Component,
    CASE
        WHEN level = 0 THEN 'Level 0 - No children (drop first)'
        WHEN level = 1 THEN 'Level 1 - Has level 0 children'
        WHEN level = 2 THEN 'Level 2 - Has level 1 children'
        WHEN level = 3 THEN 'Level 3 - Core tables (drop last)'
        ELSE 'Level 4+'
    END as DropLevel,
    GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', ') as Tables
FROM (
    SELECT
        t.TABLE_NAME as table_name,
        CASE
            WHEN t.TABLE_NAME = 'tenants' THEN 3
            WHEN t.TABLE_NAME = 'users' THEN 2
            WHEN t.TABLE_NAME IN (SELECT DISTINCT REFERENCED_TABLE_NAME
                                  FROM information_schema.KEY_COLUMN_USAGE
                                  WHERE TABLE_SCHEMA = 'collaboranexio'
                                  AND REFERENCED_TABLE_NAME IS NOT NULL) THEN 1
            ELSE 0
        END as level
    FROM information_schema.TABLES t
    WHERE t.TABLE_SCHEMA = 'collaboranexio'
        AND t.TABLE_TYPE = 'BASE TABLE'
) as categorized
GROUP BY level
ORDER BY level;

-- ============================================
-- FINAL STATUS SUMMARY
-- ============================================
SELECT
    'Database Summary' as Component,
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'collaboranexio') as TotalTables,
    (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = 'collaboranexio' AND REFERENCED_TABLE_NAME IS NOT NULL) as TotalForeignKeys,
    (SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND INDEX_NAME != 'PRIMARY') as TablesWithIndexes,
    NOW() as CheckTime;