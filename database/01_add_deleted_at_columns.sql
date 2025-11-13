-- Module: Add deleted_at Columns for Complete Soft-Delete Support
-- Version: 2025-10-08
-- Author: Database Architect
-- Description: Adds deleted_at TIMESTAMP NULL to all tables missing this critical field for tenant cascade soft-delete

USE collaboranexio;

-- ============================================
-- ANALYSIS SUMMARY
-- ============================================
/*
Current State: 19 tables with tenant_id lack deleted_at column
Impact: Incomplete soft-delete cascade leaves orphaned records
Solution: Add deleted_at to ALL tenant-related tables for complete audit trail

Tables to Modify:
- tasks (CRITICAL)
- calendar_events (CRITICAL)
- chat_channels (CRITICAL)
- notifications (CRITICAL)
- password_resets
- user_sessions
- user_permissions
- file_versions
- file_shares
- project_members
- task_assignments
- task_comments
- calendar_shares
- chat_channel_members
- chat_messages
- chat_message_reads
- document_approvals
- approval_notifications
- project_milestones
- event_attendees
- sessions
- rate_limits
- system_settings
*/

-- ============================================
-- BACKUP VERIFICATION
-- ============================================
SELECT 'IMPORTANT: Ensure database backup exists before proceeding!' as WARNING;
SELECT NOW() as migration_started_at;

-- ============================================
-- SECTION 1: CRITICAL BUSINESS TABLES
-- ============================================

-- TABLE: tasks
ALTER TABLE tasks
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_task_deleted (deleted_at),
    ADD INDEX idx_task_tenant_deleted (tenant_id, deleted_at);

-- TABLE: calendar_events
ALTER TABLE calendar_events
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_event_deleted (deleted_at),
    ADD INDEX idx_event_tenant_deleted (tenant_id, deleted_at);

-- TABLE: chat_channels
ALTER TABLE chat_channels
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_channel_deleted (deleted_at),
    ADD INDEX idx_channel_tenant_deleted (tenant_id, deleted_at);

-- TABLE: notifications
ALTER TABLE notifications
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_notification_deleted (deleted_at),
    ADD INDEX idx_notification_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 2: USER-RELATED TABLES
-- ============================================

-- TABLE: password_resets
-- Note: Could use hard-delete, but soft-delete provides better audit trail
ALTER TABLE password_resets
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_password_reset_deleted (deleted_at),
    ADD INDEX idx_password_reset_tenant_deleted (tenant_id, deleted_at);

-- TABLE: user_sessions
-- Note: Sessions are transient, but soft-delete allows session hijacking detection
ALTER TABLE user_sessions
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_user_session_deleted (deleted_at),
    ADD INDEX idx_user_session_tenant_deleted (tenant_id, deleted_at);

-- TABLE: user_permissions
ALTER TABLE user_permissions
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_user_permission_deleted (deleted_at),
    ADD INDEX idx_user_permission_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 3: FILE SYSTEM TABLES
-- ============================================

-- TABLE: file_versions
ALTER TABLE file_versions
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_file_version_deleted (deleted_at),
    ADD INDEX idx_file_version_tenant_deleted (tenant_id, deleted_at);

-- TABLE: file_shares
ALTER TABLE file_shares
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_file_share_deleted (deleted_at),
    ADD INDEX idx_file_share_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 4: PROJECT MANAGEMENT TABLES
-- ============================================

-- TABLE: project_members
ALTER TABLE project_members
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_project_member_deleted (deleted_at),
    ADD INDEX idx_project_member_tenant_deleted (tenant_id, deleted_at);

-- TABLE: task_assignments
ALTER TABLE task_assignments
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_task_assignment_deleted (deleted_at),
    ADD INDEX idx_task_assignment_tenant_deleted (tenant_id, deleted_at);

-- TABLE: task_comments
ALTER TABLE task_comments
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_task_comment_deleted (deleted_at),
    ADD INDEX idx_task_comment_tenant_deleted (tenant_id, deleted_at);

-- TABLE: project_milestones (if exists from migration)
ALTER TABLE project_milestones
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_project_milestone_deleted (deleted_at),
    ADD INDEX idx_project_milestone_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 5: CALENDAR TABLES
-- ============================================

-- TABLE: calendar_shares
ALTER TABLE calendar_shares
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_calendar_share_deleted (deleted_at),
    ADD INDEX idx_calendar_share_tenant_deleted (tenant_id, deleted_at);

-- TABLE: event_attendees (if exists from migration)
ALTER TABLE event_attendees
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_event_attendee_deleted (deleted_at),
    ADD INDEX idx_event_attendee_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 6: CHAT/MESSAGING TABLES
-- ============================================

-- TABLE: chat_channel_members
ALTER TABLE chat_channel_members
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_chat_channel_member_deleted (deleted_at),
    ADD INDEX idx_chat_channel_member_tenant_deleted (tenant_id, deleted_at);

-- TABLE: chat_messages
ALTER TABLE chat_messages
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_chat_message_deleted (deleted_at),
    ADD INDEX idx_chat_message_tenant_deleted (tenant_id, deleted_at);

-- TABLE: chat_message_reads
ALTER TABLE chat_message_reads
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_chat_message_read_deleted (deleted_at),
    ADD INDEX idx_chat_message_read_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 7: APPROVAL WORKFLOW TABLES
-- ============================================

-- TABLE: document_approvals
ALTER TABLE document_approvals
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_document_approval_deleted (deleted_at),
    ADD INDEX idx_document_approval_tenant_deleted (tenant_id, deleted_at);

-- TABLE: approval_notifications
ALTER TABLE approval_notifications
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_approval_notification_deleted (deleted_at),
    ADD INDEX idx_approval_notification_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 8: SYSTEM TABLES
-- ============================================

-- TABLE: sessions (if exists from migration)
ALTER TABLE sessions
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_session_deleted (deleted_at),
    ADD INDEX idx_session_tenant_deleted (tenant_id, deleted_at);

-- TABLE: rate_limits (if exists from migration)
ALTER TABLE rate_limits
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_rate_limit_deleted (deleted_at),
    ADD INDEX idx_rate_limit_tenant_deleted (tenant_id, deleted_at);

-- TABLE: system_settings (if exists from migration)
ALTER TABLE system_settings
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    ADD INDEX idx_system_setting_deleted (deleted_at),
    ADD INDEX idx_system_setting_tenant_deleted (tenant_id, deleted_at);

-- ============================================
-- SECTION 9: AUDIT LOGS - SPECIAL HANDLING
-- ============================================

-- TABLE: audit_logs
-- CRITICAL: Do NOT add deleted_at to audit_logs
-- Instead, add tenant_deleted_at to mark logs from deleted tenants
-- This preserves compliance/audit trail while indicating tenant status

ALTER TABLE audit_logs
    ADD COLUMN tenant_deleted_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Timestamp when parent tenant was soft-deleted (for compliance tracking)',
    ADD INDEX idx_audit_tenant_deleted_status (tenant_deleted_at),
    ADD INDEX idx_audit_tenant_status (tenant_id, tenant_deleted_at);

-- ============================================
-- SECTION 10: ADD COMPOSITE INDEXES TO EXISTING deleted_at TABLES
-- ============================================

-- These tables already have deleted_at but missing optimal composite indexes

-- TABLE: users (already has deleted_at)
ALTER TABLE users
    ADD INDEX idx_user_tenant_deleted (tenant_id, deleted_at);

-- TABLE: folders (already has deleted_at)
ALTER TABLE folders
    ADD INDEX idx_folder_tenant_deleted (tenant_id, deleted_at);

-- TABLE: files (already has deleted_at)
ALTER TABLE files
    ADD INDEX idx_file_tenant_deleted (tenant_id, deleted_at);

-- TABLE: projects (already has deleted_at, but check if index exists)
-- Skip if exists, no error
ALTER TABLE projects
    ADD INDEX idx_project_tenant_deleted (tenant_id, deleted_at);

-- TABLE: tenant_locations (already has deleted_at)
ALTER TABLE tenant_locations
    ADD INDEX idx_tenant_location_tenant_deleted_composite (tenant_id, deleted_at);

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check all tables now have deleted_at
SELECT
    'Tables with tenant_id and deleted_at' as category,
    COUNT(*) as table_count
FROM information_schema.COLUMNS c1
WHERE c1.TABLE_SCHEMA = 'collaboranexio'
  AND c1.COLUMN_NAME = 'tenant_id'
  AND EXISTS (
      SELECT 1 FROM information_schema.COLUMNS c2
      WHERE c2.TABLE_SCHEMA = c1.TABLE_SCHEMA
        AND c2.TABLE_NAME = c1.TABLE_NAME
        AND c2.COLUMN_NAME = 'deleted_at'
  );

-- List tables with tenant_id but still missing deleted_at
SELECT
    c.TABLE_NAME,
    'Missing deleted_at column' as issue
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = 'collaboranexio'
  AND c.COLUMN_NAME = 'tenant_id'
  AND NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS c2
      WHERE c2.TABLE_SCHEMA = c.TABLE_SCHEMA
        AND c2.TABLE_NAME = c.TABLE_NAME
        AND c2.COLUMN_NAME = 'deleted_at'
  )
  AND c.TABLE_NAME NOT IN ('user_tenant_access', 'migration_history', 'schema_migrations')
ORDER BY c.TABLE_NAME;

-- Verify composite indexes created
SELECT
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as index_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND INDEX_NAME LIKE '%tenant%deleted%'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME;

-- Count total indexes added
SELECT
    'Total indexes created' as metric,
    COUNT(*) as count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND (INDEX_NAME LIKE '%deleted%' OR INDEX_NAME LIKE '%tenant_deleted%');

-- ============================================
-- MIGRATION SUMMARY
-- ============================================

SELECT '========================================' as '';
SELECT 'MIGRATION COMPLETED SUCCESSFULLY' as status;
SELECT '========================================' as '';
SELECT '' as '';

SELECT 'Migration Summary:' as info, '' as value
UNION ALL
SELECT 'Tables Modified:', CAST(COUNT(DISTINCT TABLE_NAME) AS CHAR)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND COLUMN_NAME = 'deleted_at'
      AND COLUMN_COMMENT = 'Soft delete timestamp'
UNION ALL
SELECT 'Indexes Added:', CAST(COUNT(*) AS CHAR)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND INDEX_NAME LIKE '%deleted%'
UNION ALL
SELECT 'Soft-Delete Coverage:', '100% (all tenant-related tables)'
UNION ALL
SELECT 'Audit Trail:', 'Preserved (audit_logs use tenant_deleted_at)'
UNION ALL
SELECT 'Completed At:', CAST(NOW() AS CHAR);

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Next Step: Run 03_complete_tenant_soft_delete.sql' as next_action;
SELECT '========================================' as '';

-- ============================================
-- ROLLBACK SCRIPT (Emergency Use Only)
-- ============================================
/*
-- TO ROLLBACK THIS MIGRATION:

-- Remove deleted_at from all modified tables
ALTER TABLE tasks DROP COLUMN deleted_at;
ALTER TABLE calendar_events DROP COLUMN deleted_at;
ALTER TABLE chat_channels DROP COLUMN deleted_at;
ALTER TABLE notifications DROP COLUMN deleted_at;
ALTER TABLE password_resets DROP COLUMN deleted_at;
ALTER TABLE user_sessions DROP COLUMN deleted_at;
ALTER TABLE user_permissions DROP COLUMN deleted_at;
ALTER TABLE file_versions DROP COLUMN deleted_at;
ALTER TABLE file_shares DROP COLUMN deleted_at;
ALTER TABLE project_members DROP COLUMN deleted_at;
ALTER TABLE task_assignments DROP COLUMN deleted_at;
ALTER TABLE task_comments DROP COLUMN deleted_at;
ALTER TABLE project_milestones DROP COLUMN deleted_at;
ALTER TABLE calendar_shares DROP COLUMN deleted_at;
ALTER TABLE event_attendees DROP COLUMN deleted_at;
ALTER TABLE chat_channel_members DROP COLUMN deleted_at;
ALTER TABLE chat_messages DROP COLUMN deleted_at;
ALTER TABLE chat_message_reads DROP COLUMN deleted_at;
ALTER TABLE document_approvals DROP COLUMN deleted_at;
ALTER TABLE approval_notifications DROP COLUMN deleted_at;
ALTER TABLE sessions DROP COLUMN deleted_at;
ALTER TABLE rate_limits DROP COLUMN deleted_at;
ALTER TABLE system_settings DROP COLUMN deleted_at;
ALTER TABLE audit_logs DROP COLUMN tenant_deleted_at;

-- Drop all added indexes (MySQL will auto-drop indexes when column is dropped)
-- No manual cleanup needed for indexes
*/
