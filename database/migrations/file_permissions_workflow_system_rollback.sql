-- ============================================
-- FILE PERMISSIONS AND DOCUMENT WORKFLOW SYSTEM - ROLLBACK
-- Version: 1.0.0
-- Date: 2025-10-29
-- Author: Database Architect
-- Description: Rollback script for file assignment and workflow tables
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP DATA BEFORE ROLLBACK (OPTIONAL)
-- ============================================

-- Uncomment these lines if you want to backup data before dropping tables
/*
CREATE TABLE IF NOT EXISTS file_assignments_backup_20251029 AS SELECT * FROM file_assignments;
CREATE TABLE IF NOT EXISTS workflow_roles_backup_20251029 AS SELECT * FROM workflow_roles;
CREATE TABLE IF NOT EXISTS document_workflow_backup_20251029 AS SELECT * FROM document_workflow;
CREATE TABLE IF NOT EXISTS document_workflow_history_backup_20251029 AS SELECT * FROM document_workflow_history;

SELECT
    'Backup completed' as status,
    (SELECT COUNT(*) FROM file_assignments_backup_20251029) as file_assignments_backup_count,
    (SELECT COUNT(*) FROM workflow_roles_backup_20251029) as workflow_roles_backup_count,
    (SELECT COUNT(*) FROM document_workflow_backup_20251029) as document_workflow_backup_count,
    (SELECT COUNT(*) FROM document_workflow_history_backup_20251029) as workflow_history_backup_count,
    NOW() as backup_timestamp;
*/

-- ============================================
-- DROP TABLES IN REVERSE ORDER (FK DEPENDENCIES)
-- ============================================

-- Drop child tables first (tables with FKs referencing parent tables)

-- 1. Drop workflow history (references document_workflow)
DROP TABLE IF EXISTS document_workflow_history;
SELECT 'Dropped document_workflow_history table' as status;

-- 2. Drop current workflow (references files)
DROP TABLE IF EXISTS document_workflow;
SELECT 'Dropped document_workflow table' as status;

-- 3. Drop workflow roles (independent)
DROP TABLE IF EXISTS workflow_roles;
SELECT 'Dropped workflow_roles table' as status;

-- 4. Drop file assignments (references files and users)
DROP TABLE IF EXISTS file_assignments;
SELECT 'Dropped file_assignments table' as status;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Verify tables are dropped
SELECT
    'Rollback completed successfully' as status,
    CASE
        WHEN EXISTS (SELECT 1 FROM information_schema.tables
                     WHERE table_schema = 'collaboranexio'
                       AND table_name = 'file_assignments')
        THEN 'ERROR: file_assignments still exists'
        ELSE 'OK: file_assignments dropped'
    END as file_assignments_status,
    CASE
        WHEN EXISTS (SELECT 1 FROM information_schema.tables
                     WHERE table_schema = 'collaboranexio'
                       AND table_name = 'workflow_roles')
        THEN 'ERROR: workflow_roles still exists'
        ELSE 'OK: workflow_roles dropped'
    END as workflow_roles_status,
    CASE
        WHEN EXISTS (SELECT 1 FROM information_schema.tables
                     WHERE table_schema = 'collaboranexio'
                       AND table_name = 'document_workflow')
        THEN 'ERROR: document_workflow still exists'
        ELSE 'OK: document_workflow dropped'
    END as document_workflow_status,
    CASE
        WHEN EXISTS (SELECT 1 FROM information_schema.tables
                     WHERE table_schema = 'collaboranexio'
                       AND table_name = 'document_workflow_history')
        THEN 'ERROR: document_workflow_history still exists'
        ELSE 'OK: document_workflow_history dropped'
    END as workflow_history_status,
    NOW() as executed_at;

-- ============================================
-- POST-ROLLBACK CLEANUP (OPTIONAL)
-- ============================================

/*
-- If you created backups and want to remove them:
DROP TABLE IF EXISTS file_assignments_backup_20251029;
DROP TABLE IF EXISTS workflow_roles_backup_20251029;
DROP TABLE IF EXISTS document_workflow_backup_20251029;
DROP TABLE IF EXISTS document_workflow_history_backup_20251029;
*/

-- ============================================
-- ROLLBACK NOTES
-- ============================================

/*
IMPORTANT:

1. DATA LOSS:
   - Rolling back will DELETE all assignment and workflow data
   - Create backups if you need to restore data later
   - Audit logs in audit_logs table will remain intact

2. DEPENDENT CODE:
   - After rollback, any PHP code using these tables will fail
   - Check and update/remove:
     * api/files/assign.php (if exists)
     * api/documents/workflow.php (if exists)
     * Frontend JavaScript for assignments/workflow
     * Audit logging calls referencing these entities

3. EMAIL NOTIFICATIONS:
   - Disable any cron jobs that send workflow notifications
   - Remove email templates for workflow transitions

4. FOREIGN KEY DEPENDENCIES:
   - Tables dropped in correct order to avoid FK constraint errors
   - No impact on files, users, or tenants tables

5. SOFT DELETE DATA:
   - Both active (deleted_at IS NULL) and soft-deleted records will be lost
   - Consider exporting soft-deleted records if needed for compliance

6. AUDIT TRAIL:
   - Entries in audit_logs table for these entities will remain
   - Entity types affected: 'file_assignment', 'workflow_role', 'document_workflow'
   - Consider cleanup query if needed:
     DELETE FROM audit_logs
     WHERE entity_type IN ('file_assignment', 'workflow_role', 'document_workflow');

7. RE-MIGRATION:
   - If you need to re-run migration after rollback:
     mysql -u root collaboranexio < file_permissions_workflow_system.sql
   - Demo data will be inserted only if tables are empty

8. PRODUCTION ROLLBACK:
   - Always test rollback in development first
   - Notify users before production rollback
   - Plan downtime window
   - Export reports/analytics before rollback
*/

-- ============================================
-- END OF ROLLBACK
-- ============================================
