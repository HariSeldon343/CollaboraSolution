# DATABASE INTEGRITY - QUICK REFERENCE GUIDE

**Last Updated:** 2025-10-12
**Database:** collaboranexio (MariaDB 10.4.32)

---

## QUICK STATUS CHECK

```bash
# Run comprehensive verification
php verify_database_integrity_fixed.php

# Quick stats
mysql collaboranexio -e "
SELECT
    (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='collaboranexio' AND REFERENCED_TABLE_NAME IS NOT NULL) as foreign_keys,
    (SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='collaboranexio' AND COLUMN_NAME='deleted_at') as tables_with_soft_delete,
    (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) as active_tenants,
    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as active_users;
"
```

---

## CURRENT STATE (2025-10-12)

| Metric | Value | Grade |
|--------|-------|-------|
| Overall Health | 97% | A |
| Foreign Keys | 134 | A+ |
| Soft Delete Coverage | 85% | B+ |
| Multi-Tenancy | 100% | A+ |
| Data Integrity | 100% | A+ |

**Status:** :green_circle: PRODUCTION READY

---

## FILES REFERENCE

### Verification Scripts

```bash
# Main verification script
/verify_database_integrity_fixed.php

# Check actual database state
/check_actual_database.php
```

### Fix Scripts

```bash
# Phase 1 fixes (SQL)
/database/fix_database_integrity_issues.sql

# Phase 1 executor (PHP)
/run_integrity_fixes.php

# Phase 2 fixes (SQL)
/database/fix_remaining_integrity_issues.sql

# Phase 2 executor (PHP)
/run_remaining_fixes.php
```

### Reports

```bash
# Comprehensive report
/DATABASE_INTEGRITY_REPORT_COMPLETE.md

# This quick reference
/DATABASE_INTEGRITY_QUICK_REFERENCE.md
```

---

## COMMON TASKS

### 1. Run Full Verification

```bash
php verify_database_integrity_fixed.php
```

**Output:** Colored console output + report saved to `DATABASE_INTEGRITY_REPORT_COMPLETE.md`

### 2. Check Foreign Keys

```sql
-- Count all foreign keys
SELECT COUNT(*) as total_fks
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND REFERENCED_TABLE_NAME IS NOT NULL;

-- List all foreign keys by table
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
```

### 3. Check for Orphaned Records

```sql
-- Check files with invalid tenant_id
SELECT COUNT(*) as orphaned_files
FROM files f
LEFT JOIN tenants t ON f.tenant_id = t.id
WHERE f.tenant_id IS NOT NULL AND t.id IS NULL;

-- Check users with invalid tenant_id
SELECT COUNT(*) as orphaned_users
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.tenant_id IS NOT NULL AND t.id IS NULL;

-- Generic check for any table
SELECT COUNT(*) as orphaned_count
FROM {table_name} t
LEFT JOIN tenants tn ON t.tenant_id = tn.id
WHERE t.tenant_id IS NOT NULL AND tn.id IS NULL;
```

### 4. Verify Soft Delete

```sql
-- Count soft-deleted records by table
SELECT
    'tenants' as table_name,
    COUNT(*) as total,
    SUM(deleted_at IS NOT NULL) as deleted,
    SUM(deleted_at IS NULL) as active
FROM tenants
UNION ALL
SELECT
    'users' as table_name,
    COUNT(*) as total,
    SUM(deleted_at IS NOT NULL) as deleted,
    SUM(deleted_at IS NULL) as active
FROM users
UNION ALL
SELECT
    'files' as table_name,
    COUNT(*) as total,
    SUM(deleted_at IS NOT NULL) as deleted,
    SUM(deleted_at IS NULL) as active
FROM files;
```

### 5. Monitor Table Growth

```sql
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
    ROUND(INDEX_LENGTH / DATA_LENGTH, 2) AS index_ratio
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_TYPE = 'BASE TABLE'
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
LIMIT 20;
```

---

## KNOWN ISSUES & SOLUTIONS

### Issue 1: Nullable tenant_id in files/folders

**Problem:** files and folders tables have nullable tenant_id due to existing FK constraints

**Solution:**
```sql
-- For files table
ALTER TABLE files DROP FOREIGN KEY fk_files_tenant;
UPDATE files SET tenant_id = 1 WHERE tenant_id IS NULL;
ALTER TABLE files MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;
ALTER TABLE files ADD CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- For folders table
ALTER TABLE folders DROP FOREIGN KEY fk_folders_tenant;
UPDATE folders SET tenant_id = 1 WHERE tenant_id IS NULL;
ALTER TABLE folders MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;
ALTER TABLE folders ADD CONSTRAINT fk_folders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
```

### Issue 2: Missing audit fields in project_members and user_tenant_access

**Problem:** Tables lack created_at/updated_at for complete audit trail

**Solution:**
```sql
-- Add to project_members
ALTER TABLE project_members
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER role,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add to user_tenant_access
ALTER TABLE user_tenant_access
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER tenant_id,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
```

---

## MAINTENANCE SCHEDULE

### Daily
- Monitor error logs for FK violations
- Check for unusual growth in audit_logs

### Weekly
- Review soft-deleted records
- Check for orphaned records
- Monitor table sizes

### Monthly
- Run full integrity verification
- Review and optimize slow queries
- Archive old audit logs

### Quarterly
- Full database health review
- Update documentation
- Plan for schema changes

---

## PERFORMANCE TIPS

### 1. Multi-Tenant Queries

Always include `tenant_id` first in WHERE clause:

```sql
-- GOOD (uses index efficiently)
SELECT * FROM files
WHERE tenant_id = 1
  AND deleted_at IS NULL
  AND folder_id = 123;

-- BAD (full table scan)
SELECT * FROM files
WHERE folder_id = 123
  AND tenant_id = 1;
```

### 2. Soft Delete Queries

Always filter out deleted records:

```sql
-- GOOD
SELECT * FROM projects
WHERE tenant_id = 1
  AND deleted_at IS NULL;

-- BAD (includes deleted)
SELECT * FROM projects
WHERE tenant_id = 1;
```

### 3. Composite Indexes

Use composite indexes in the right order:

```sql
-- Index: idx_files_tenant_folder (tenant_id, folder_id, deleted_at)

-- GOOD (uses index)
WHERE tenant_id = 1 AND folder_id = 123 AND deleted_at IS NULL

-- OK (uses index partially)
WHERE tenant_id = 1 AND deleted_at IS NULL

-- BAD (doesn't use index)
WHERE folder_id = 123 AND deleted_at IS NULL
```

---

## BACKUP & RECOVERY

### Before Major Changes

```bash
# Backup entire database
mysqldump collaboranexio > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup specific table
mysqldump collaboranexio table_name > table_name_backup.sql

# Backup schema only
mysqldump --no-data collaboranexio > schema_backup.sql
```

### Restore from Backup

```bash
# Restore entire database
mysql collaboranexio < backup_20251012_133000.sql

# Restore specific table
mysql collaboranexio < table_name_backup.sql
```

---

## TROUBLESHOOTING

### Foreign Key Errors

```sql
-- Check if FK constraint exists
SELECT *
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'your_table'
    AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Check FK violations
SHOW ENGINE INNODB STATUS;
```

### Orphaned Records

```sql
-- Find all orphaned records
SELECT t.*
FROM your_table t
LEFT JOIN tenants tn ON t.tenant_id = tn.id
WHERE t.tenant_id IS NOT NULL AND tn.id IS NULL;

-- Fix by assigning to default tenant
UPDATE your_table
SET tenant_id = 1
WHERE tenant_id IS NULL OR tenant_id NOT IN (SELECT id FROM tenants);
```

### Performance Issues

```sql
-- Analyze table statistics
ANALYZE TABLE your_table;

-- Check index usage
SHOW INDEX FROM your_table;

-- Explain query performance
EXPLAIN SELECT * FROM your_table WHERE tenant_id = 1;
```

---

## CONTACTS & RESOURCES

- **Database Architect:** Database Architect Agent
- **Project:** CollaboraNexio Multi-Tenant Platform
- **Documentation:** /mnt/c/xampp/htdocs/CollaboraNexio/database/
- **Verification Script:** verify_database_integrity_fixed.php
- **Comprehensive Report:** DATABASE_INTEGRITY_REPORT_COMPLETE.md

---

**Last Verification:** 2025-10-12 13:30:00
**Next Verification:** 2025-11-12 (Monthly)
**Database Health:** 97% (A Grade) :green_circle:
