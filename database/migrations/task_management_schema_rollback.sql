-- ============================================
-- ROLLBACK: Task Management System
-- Version: 2025-10-24
-- Author: Database Architect
-- Description: Rollback script for task management schema
-- WARNING: This will delete all task data!
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP DATA BEFORE ROLLBACK (RECOMMENDED)
-- ============================================

-- Uncomment these lines to create backup tables before rollback
/*
CREATE TABLE IF NOT EXISTS tasks_backup_20251024 AS SELECT * FROM tasks;
CREATE TABLE IF NOT EXISTS task_assignments_backup_20251024 AS SELECT * FROM task_assignments;
CREATE TABLE IF NOT EXISTS task_comments_backup_20251024 AS SELECT * FROM task_comments;
CREATE TABLE IF NOT EXISTS task_history_backup_20251024 AS SELECT * FROM task_history;

SELECT 'Backup completed' as Status,
       (SELECT COUNT(*) FROM tasks_backup_20251024) as tasks_backed_up,
       (SELECT COUNT(*) FROM task_assignments_backup_20251024) as assignments_backed_up,
       (SELECT COUNT(*) FROM task_comments_backup_20251024) as comments_backed_up,
       (SELECT COUNT(*) FROM task_history_backup_20251024) as history_backed_up;
*/

-- ============================================
-- DROP STORED PROCEDURES/FUNCTIONS
-- ============================================

SELECT 'Dropping stored procedures and functions...' as Status;

DROP FUNCTION IF EXISTS get_orphaned_tasks_count;
DROP FUNCTION IF EXISTS assign_task_to_user;

-- ============================================
-- DROP VIEWS
-- ============================================

SELECT 'Dropping views...' as Status;

DROP VIEW IF EXISTS view_my_tasks;
DROP VIEW IF EXISTS view_task_summary_by_status;
DROP VIEW IF EXISTS view_orphaned_tasks;

-- ============================================
-- DROP TABLES (In reverse dependency order)
-- ============================================

SELECT 'Dropping tables...' as Status;

-- Disable foreign key checks temporarily for clean drop
SET FOREIGN_KEY_CHECKS = 0;

-- Drop in reverse order of dependencies
DROP TABLE IF EXISTS task_history;
DROP TABLE IF EXISTS task_comments;
DROP TABLE IF EXISTS task_assignments;
DROP TABLE IF EXISTS tasks;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Rollback completed' as Status;

-- Verify tables are dropped
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'SUCCESS: All task tables dropped'
        ELSE CONCAT('WARNING: ', COUNT(*), ' task tables still exist')
    END as Verification
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('tasks', 'task_assignments', 'task_comments', 'task_history');

-- Verify views are dropped
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'SUCCESS: All task views dropped'
        ELSE CONCAT('WARNING: ', COUNT(*), ' task views still exist')
    END as Verification
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('view_orphaned_tasks', 'view_task_summary_by_status', 'view_my_tasks');

-- Verify functions are dropped
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'SUCCESS: All task functions dropped'
        ELSE CONCAT('WARNING: ', COUNT(*), ' task functions still exist')
    END as Verification
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME IN ('assign_task_to_user', 'get_orphaned_tasks_count');

SELECT NOW() as rollback_executed_at;

-- ============================================
-- RESTORE FROM BACKUP (If backup was created)
-- ============================================

/*
-- Uncomment to restore from backup:

CREATE TABLE tasks AS SELECT * FROM tasks_backup_20251024;
CREATE TABLE task_assignments AS SELECT * FROM task_assignments_backup_20251024;
CREATE TABLE task_comments AS SELECT * FROM task_comments_backup_20251024;
CREATE TABLE task_history AS SELECT * FROM task_history_backup_20251024;

-- Recreate foreign keys after restore
ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_tasks_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tasks_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE;

-- Add similar for other tables...

SELECT 'Restore from backup completed' as Status;
*/
