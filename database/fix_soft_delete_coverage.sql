-- ============================================
-- Module: Soft Delete Coverage Fix
-- Version: 2025-10-23
-- Author: Database Architect
-- Description: Add deleted_at to tables missing soft delete support
-- Priority: LOW (Non-critical enhancement for consistency)
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP REMINDER
-- ============================================
-- Run this BEFORE executing migration:
-- mysqldump collaboranexio > backup_before_soft_delete_fix_$(date +%Y%m%d).sql

-- ============================================
-- VERIFICATION: Check Current State
-- ============================================
SELECT 'Verifying tables without deleted_at...' as status;

SELECT
    table_name,
    CASE
        WHEN column_name IS NULL THEN 'MISSING deleted_at'
        ELSE 'HAS deleted_at'
    END as soft_delete_status
FROM information_schema.TABLES t
LEFT JOIN information_schema.COLUMNS c
    ON c.table_schema = t.table_schema
    AND c.table_name = t.table_name
    AND c.column_name = 'deleted_at'
WHERE t.table_schema = 'collaboranexio'
  AND t.table_type = 'BASE TABLE'
  AND t.table_name IN ('projects', 'tasks', 'calendar_events', 'chat_channels', 'chat_messages')
ORDER BY t.table_name;

-- ============================================
-- ADD deleted_at TO projects
-- ============================================
SELECT 'Adding deleted_at to projects table...' as status;

ALTER TABLE projects
ADD COLUMN deleted_at TIMESTAMP NULL
COMMENT 'Soft delete timestamp - NULL means active record'
AFTER completed_at;

-- Indexes for soft delete queries
CREATE INDEX idx_projects_deleted ON projects(deleted_at);
CREATE INDEX idx_projects_tenant_deleted ON projects(tenant_id, deleted_at);

-- ============================================
-- ADD deleted_at TO tasks
-- ============================================
SELECT 'Adding deleted_at to tasks table...' as status;

ALTER TABLE tasks
ADD COLUMN deleted_at TIMESTAMP NULL
COMMENT 'Soft delete timestamp - NULL means active record'
AFTER updated_at;

-- Indexes for soft delete queries
CREATE INDEX idx_tasks_deleted ON tasks(deleted_at);
CREATE INDEX idx_tasks_tenant_deleted ON tasks(tenant_id, deleted_at);

-- ============================================
-- ADD deleted_at TO calendar_events
-- ============================================
SELECT 'Adding deleted_at to calendar_events table...' as status;

ALTER TABLE calendar_events
ADD COLUMN deleted_at TIMESTAMP NULL
COMMENT 'Soft delete timestamp - NULL means active record'
AFTER updated_at;

-- Indexes for soft delete queries
CREATE INDEX idx_calendar_deleted ON calendar_events(deleted_at);
CREATE INDEX idx_calendar_tenant_deleted ON calendar_events(tenant_id, deleted_at);

-- ============================================
-- ADD deleted_at TO chat_channels
-- ============================================
SELECT 'Adding deleted_at to chat_channels table...' as status;

-- Note: chat_channels already has is_archived boolean
-- deleted_at is complementary for true soft delete
ALTER TABLE chat_channels
ADD COLUMN deleted_at TIMESTAMP NULL
COMMENT 'Soft delete timestamp - NULL means active record'
AFTER updated_at;

-- Indexes for soft delete queries
CREATE INDEX idx_channels_deleted ON chat_channels(deleted_at);
CREATE INDEX idx_channels_tenant_deleted ON chat_channels(tenant_id, deleted_at);

-- ============================================
-- MIGRATE chat_messages (is_deleted → deleted_at)
-- ============================================
SELECT 'Migrating chat_messages from is_deleted to deleted_at...' as status;

-- Step 1: Add new column
ALTER TABLE chat_messages
ADD COLUMN deleted_at TIMESTAMP NULL
COMMENT 'Soft delete timestamp - NULL means active record'
AFTER is_deleted;

-- Step 2: Migrate existing deleted messages
UPDATE chat_messages
SET deleted_at = updated_at
WHERE is_deleted = TRUE
  AND deleted_at IS NULL;

-- Step 3: Add indexes
CREATE INDEX idx_messages_deleted ON chat_messages(deleted_at);
CREATE INDEX idx_messages_tenant_deleted ON chat_messages(tenant_id, deleted_at);

-- ============================================
-- VERIFICATION: Confirm Migration Success
-- ============================================
SELECT 'Soft delete migration completed successfully!' as status;

SELECT
    'projects' as table_name,
    COUNT(*) as total_records,
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted_records
FROM projects
UNION ALL
SELECT
    'tasks',
    COUNT(*),
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END)
FROM tasks
UNION ALL
SELECT
    'calendar_events',
    COUNT(*),
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END)
FROM calendar_events
UNION ALL
SELECT
    'chat_channels',
    COUNT(*),
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END)
FROM chat_channels
UNION ALL
SELECT
    'chat_messages',
    COUNT(*),
    SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END)
FROM chat_messages;

-- Verify indexes created
SELECT
    table_name,
    index_name,
    column_name,
    CASE non_unique
        WHEN 0 THEN 'UNIQUE'
        WHEN 1 THEN 'NON-UNIQUE'
    END as uniqueness
FROM information_schema.STATISTICS
WHERE table_schema = 'collaboranexio'
  AND table_name IN ('projects', 'tasks', 'calendar_events', 'chat_channels', 'chat_messages')
  AND (index_name LIKE '%deleted%' OR column_name = 'deleted_at')
ORDER BY table_name, index_name;

-- ============================================
-- APPLICATION CODE UPDATE CHECKLIST
-- ============================================

/*
IMPORTANT: Update PHP application code to use deleted_at pattern

FILES TO UPDATE:
================

1. projects/*.php
   BEFORE: DELETE FROM projects WHERE id = ?
   AFTER:  UPDATE projects SET deleted_at = NOW() WHERE id = ?

2. tasks/*.php
   BEFORE: DELETE FROM tasks WHERE id = ?
   AFTER:  UPDATE tasks SET deleted_at = NOW() WHERE id = ?

3. calendar/*.php
   BEFORE: DELETE FROM calendar_events WHERE id = ?
   AFTER:  UPDATE calendar_events SET deleted_at = NOW() WHERE id = ?

4. chat/*.php
   BEFORE: UPDATE chat_channels SET is_archived = TRUE WHERE id = ?
   AFTER:  UPDATE chat_channels SET deleted_at = NOW() WHERE id = ?

5. chat_messages/*.php
   BEFORE: UPDATE chat_messages SET is_deleted = TRUE WHERE id = ?
   AFTER:  UPDATE chat_messages SET deleted_at = NOW() WHERE id = ?


QUERY FILTERS (CRITICAL):
=========================

ALL queries on these tables MUST include:
WHERE deleted_at IS NULL

Examples:

-- List active projects
SELECT * FROM projects
WHERE tenant_id = ? AND deleted_at IS NULL
ORDER BY created_at DESC;

-- List active tasks
SELECT * FROM tasks
WHERE project_id = ? AND deleted_at IS NULL
ORDER BY due_date;

-- List active calendar events
SELECT * FROM calendar_events
WHERE tenant_id = ? AND deleted_at IS NULL
  AND start_datetime >= NOW()
ORDER BY start_datetime;

-- List active channels
SELECT * FROM chat_channels
WHERE tenant_id = ? AND deleted_at IS NULL
ORDER BY name;

-- List non-deleted messages
SELECT * FROM chat_messages
WHERE channel_id = ? AND deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 50;


SOFT DELETE PATTERN (STANDARD):
================================

// PHP function template
function softDelete($table, $id, $tenantId) {
    global $db;

    $db->update($table, [
        'deleted_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $id,
        'tenant_id' => $tenantId  // Tenant isolation
    ]);

    // Log audit
    logAudit('delete', $table, $id, 'Soft deleted via admin panel');
}

// Restore function
function restoreSoftDelete($table, $id, $tenantId) {
    global $db;

    $db->update($table, [
        'deleted_at' => NULL
    ], [
        'id' => $id,
        'tenant_id' => $tenantId
    ]);

    // Log audit
    logAudit('restore', $table, $id, 'Restored from soft delete');
}


DEPRECATION PLAN for is_deleted (chat_messages):
=================================================

Phase 1 (Current): Both columns present
   - deleted_at added
   - is_deleted still present
   - Code uses deleted_at

Phase 2 (After 2 weeks): Verify migration
   - Check all code uses deleted_at
   - Verify no is_deleted references

Phase 3 (After 1 month): Remove is_deleted
   - ALTER TABLE chat_messages DROP COLUMN is_deleted;

*/

-- ============================================
-- NOTES FOR DATABASE ARCHITECT
-- ============================================

/*
WHY THIS MIGRATION IS NON-CRITICAL:
====================================

1. Current system works (hard delete or is_deleted boolean)
2. No data integrity issues
3. Purely for consistency and best practices
4. Allows future GDPR "restore accidentally deleted" feature

BENEFITS AFTER MIGRATION:
=========================

1. Consistency: All tables use same soft delete pattern
2. Audit trail: deleted_at timestamp preserves when deletion occurred
3. Recovery: Can restore accidentally deleted items
4. Compliance: GDPR right to erasure with audit trail
5. Query uniformity: WHERE deleted_at IS NULL everywhere

ROLLBACK PROCEDURE:
===================

If migration causes issues:

1. Restore from backup:
   mysql collaboranexio < backup_before_soft_delete_fix_YYYYMMDD.sql

2. Or manually rollback:
   ALTER TABLE projects DROP COLUMN deleted_at;
   ALTER TABLE tasks DROP COLUMN deleted_at;
   ALTER TABLE calendar_events DROP COLUMN deleted_at;
   ALTER TABLE chat_channels DROP COLUMN deleted_at;
   ALTER TABLE chat_messages DROP COLUMN deleted_at;

   -- Drop indexes
   DROP INDEX idx_projects_deleted ON projects;
   DROP INDEX idx_tasks_deleted ON tasks;
   -- etc...

TESTING CHECKLIST:
==================

After migration, test:
□ Create project → soft delete → verify deleted_at set
□ Restore project → verify deleted_at = NULL
□ List projects → verify deleted items hidden
□ Create task → soft delete → verify deleted_at set
□ Create calendar event → delete → verify deleted_at set
□ Delete chat channel → verify deleted_at set
□ Delete chat message → verify deleted_at set (not is_deleted)
□ Verify all indexes used in EXPLAIN queries
□ Performance test: 10K records with 50% deleted

*/

SELECT '✅ Migration script ready for execution' as status,
       NOW() as script_generated_at;
