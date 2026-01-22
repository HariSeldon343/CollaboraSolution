-- Module: Fix Stored Procedure Calendar Table Names (BUG-128)
-- Version: 2025-11-20
-- Author: Database Architect
-- Description: Update sp_soft_delete_tenant_complete and fn_count_tenant_records
--              to use correct calendar table names after BUG-105A migrations

USE collaboranexio;

-- ============================================
-- DROP EXISTING PROCEDURES/FUNCTIONS
-- ============================================
DROP PROCEDURE IF EXISTS sp_soft_delete_tenant_complete;
DROP PROCEDURE IF EXISTS sp_restore_tenant;
DROP FUNCTION IF EXISTS fn_count_tenant_records;

-- ============================================
-- FUNCTION: Count Total Records for Tenant
-- BUG-128 FIX: Use correct table names
-- ============================================
DELIMITER $$

CREATE FUNCTION fn_count_tenant_records(p_tenant_id INT UNSIGNED)
RETURNS JSON
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_counts JSON;

    SELECT JSON_OBJECT(
        'users', (SELECT COUNT(*) FROM users WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'folders', (SELECT COUNT(*) FROM folders WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'files', (SELECT COUNT(*) FROM files WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'projects', (SELECT COUNT(*) FROM projects WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'tasks', (SELECT COUNT(*) FROM tasks WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'events', (SELECT COUNT(*) FROM events WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'calendars', (SELECT COUNT(*) FROM calendars WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'event_participants', (SELECT COUNT(*) FROM event_participants WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'event_reminders', (SELECT COUNT(*) FROM event_reminders WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'chat_channels', (SELECT COUNT(*) FROM chat_channels WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'notifications', (SELECT COUNT(*) FROM notifications WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'tenant_locations', (SELECT COUNT(*) FROM tenant_locations WHERE tenant_id = p_tenant_id AND deleted_at IS NULL)
    ) INTO v_counts;

    RETURN v_counts;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURE: Complete Tenant Soft-Delete
-- BUG-128 FIX: Use correct calendar table names
-- ============================================
DELIMITER $$

CREATE PROCEDURE sp_soft_delete_tenant_complete(
    IN p_tenant_id INT UNSIGNED,
    IN p_deleted_by_user_id INT UNSIGNED,
    OUT p_success BOOLEAN,
    OUT p_message VARCHAR(500),
    OUT p_records_affected JSON
)
sp_soft_delete_tenant_complete: BEGIN
    DECLARE v_deleted_at TIMESTAMP;
    DECLARE v_tenant_exists BOOLEAN DEFAULT FALSE;
    DECLARE v_tenant_name VARCHAR(255);
    DECLARE v_counts_before JSON;
    DECLARE v_counts_after JSON;
    DECLARE v_exit_handler BOOLEAN DEFAULT FALSE;

    -- Error handler
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = CONCAT('Error during tenant soft-delete: ', @@error_count);
        SET p_records_affected = JSON_OBJECT('error', 'Transaction rolled back');
    END;

    -- Initialize
    SET v_deleted_at = NOW();
    SET p_success = FALSE;

    -- Validate tenant exists and is not already deleted
    SELECT
        COUNT(*) > 0,
        COALESCE(MAX(denominazione), MAX(name))
    INTO v_tenant_exists, v_tenant_name
    FROM tenants
    WHERE id = p_tenant_id
      AND deleted_at IS NULL;

    IF NOT v_tenant_exists THEN
        SET p_message = 'Tenant not found or already deleted';
        SET p_records_affected = JSON_OBJECT('error', 'Tenant not found');
        LEAVE sp_soft_delete_tenant_complete;
    END IF;

    -- Prevent deletion of system tenant (ID 1)
    IF p_tenant_id = 1 THEN
        SET p_message = 'Cannot delete system tenant (ID 1)';
        SET p_records_affected = JSON_OBJECT('error', 'System tenant protected');
        LEAVE sp_soft_delete_tenant_complete;
    END IF;

    -- Get counts before deletion
    SET v_counts_before = fn_count_tenant_records(p_tenant_id);

    -- Begin transaction
    START TRANSACTION;

    -- ============================================
    -- LEVEL 1: TENANT (Root)
    -- ============================================
    UPDATE tenants
    SET deleted_at = v_deleted_at
    WHERE id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 2: USERS (First Tier - Critical)
    -- ============================================
    UPDATE users
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 3: USER-RELATED TABLES
    -- ============================================

    -- User permissions
    UPDATE user_permissions
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- User sessions (could also hard-delete these)
    UPDATE user_sessions
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Password resets (could also hard-delete these)
    UPDATE password_resets
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Notifications
    UPDATE notifications
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 4: FOLDERS (Before Files)
    -- ============================================
    UPDATE folders
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 5: FILES AND FILE-RELATED TABLES
    -- ============================================

    -- Files
    UPDATE files
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- File versions
    UPDATE file_versions
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- File shares
    UPDATE file_shares
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Document approvals
    UPDATE document_approvals
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Approval notifications
    UPDATE approval_notifications
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 6: PROJECTS AND PROJECT-RELATED TABLES
    -- ============================================

    -- Projects
    UPDATE projects
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Project members
    UPDATE project_members
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Project milestones (if exists)
    UPDATE project_milestones
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 7: TASKS AND TASK-RELATED TABLES
    -- ============================================

    -- Tasks
    UPDATE tasks
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Task assignments
    UPDATE task_assignments
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Task comments
    UPDATE task_comments
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 8: CALENDAR AND CALENDAR-RELATED TABLES
    -- BUG-128 FIX: Updated table names
    -- ============================================

    -- Event reminders (NEW - migration 13)
    UPDATE event_reminders
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Event participants (NEW - migration 12)
    UPDATE event_participants
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Events (was calendar_events)
    UPDATE events
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Calendar permissions (was calendar_shares)
    UPDATE calendar_permissions
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Calendars (migration 10)
    UPDATE calendars
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 9: CHAT AND CHAT-RELATED TABLES
    -- ============================================

    -- Chat channels
    UPDATE chat_channels
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Chat channel members
    UPDATE chat_channel_members
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Chat messages
    UPDATE chat_messages
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Chat message reads
    UPDATE chat_message_reads
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 10: TENANT LOCATIONS
    -- ============================================
    UPDATE tenant_locations
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 11: SYSTEM SETTINGS (if exists)
    -- ============================================
    UPDATE system_settings
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- LEVEL 12: SESSIONS AND RATE LIMITS (if exists)
    -- ============================================

    -- Sessions
    UPDATE sessions
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Rate limits
    UPDATE rate_limits
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- ============================================
    -- SPECIAL: AUDIT LOGS - Mark as deleted tenant, don't delete
    -- ============================================
    UPDATE audit_logs
    SET tenant_deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND tenant_deleted_at IS NULL;

    -- ============================================
    -- HARD DELETE: user_tenant_access (cross-tenant mapping)
    -- ============================================
    DELETE FROM user_tenant_access
    WHERE tenant_id = p_tenant_id;

    -- Get counts after deletion
    SET v_counts_after = fn_count_tenant_records(p_tenant_id);

    -- Commit transaction
    COMMIT;

    -- Success
    SET p_success = TRUE;
    SET p_message = CONCAT('Tenant "', v_tenant_name, '" soft-deleted successfully at ', v_deleted_at);
    SET p_records_affected = JSON_OBJECT(
        'tenant_id', p_tenant_id,
        'tenant_name', v_tenant_name,
        'deleted_at', v_deleted_at,
        'deleted_by', p_deleted_by_user_id,
        'counts_before', v_counts_before,
        'counts_after', v_counts_after
    );

END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURE: Restore Soft-Deleted Tenant
-- BUG-128 FIX: Updated calendar table names
-- ============================================
DELIMITER $$

CREATE PROCEDURE sp_restore_tenant(
    IN p_tenant_id INT UNSIGNED,
    IN p_restored_by_user_id INT UNSIGNED,
    OUT p_success BOOLEAN,
    OUT p_message VARCHAR(500),
    OUT p_records_affected JSON
)
sp_restore_tenant: BEGIN
    DECLARE v_tenant_exists BOOLEAN DEFAULT FALSE;
    DECLARE v_tenant_name VARCHAR(255);
    DECLARE v_deleted_at TIMESTAMP;
    DECLARE v_counts_before JSON;
    DECLARE v_counts_after JSON;

    -- Error handler
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = CONCAT('Error during tenant restore: ', @@error_count);
        SET p_records_affected = JSON_OBJECT('error', 'Transaction rolled back');
    END;

    -- Initialize
    SET p_success = FALSE;

    -- Validate tenant exists and IS deleted
    SELECT
        COUNT(*) > 0,
        COALESCE(MAX(denominazione), MAX(name)),
        MAX(deleted_at)
    INTO v_tenant_exists, v_tenant_name, v_deleted_at
    FROM tenants
    WHERE id = p_tenant_id
      AND deleted_at IS NOT NULL;

    IF NOT v_tenant_exists THEN
        SET p_message = 'Tenant not found or not deleted';
        SET p_records_affected = JSON_OBJECT('error', 'Tenant not in deleted state');
        LEAVE sp_restore_tenant;
    END IF;

    -- Get counts before restoration
    SET v_counts_before = fn_count_tenant_records(p_tenant_id);

    -- Begin transaction
    START TRANSACTION;

    -- Restore in reverse cascade order
    -- LEVEL 12: System tables
    UPDATE sessions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE rate_limits SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE system_settings SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 11: Tenant locations
    UPDATE tenant_locations SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 10: Chat
    UPDATE chat_message_reads SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_messages SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_channel_members SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_channels SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 9: Calendar (BUG-128 FIX: Correct table names)
    UPDATE calendars SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE calendar_permissions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE events SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE event_participants SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE event_reminders SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 8: Tasks
    UPDATE task_comments SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE task_assignments SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE tasks SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 7: Projects
    UPDATE project_milestones SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE project_members SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE projects SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 6: Files
    UPDATE approval_notifications SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE document_approvals SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE file_shares SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE file_versions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE files SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 5: Folders
    UPDATE folders SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 4: User-related
    UPDATE notifications SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE password_resets SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE user_sessions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE user_permissions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 3: Users
    UPDATE users SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

    -- LEVEL 2: Tenant
    UPDATE tenants SET deleted_at = NULL WHERE id = p_tenant_id;

    -- SPECIAL: Audit logs - unmark tenant_deleted_at
    UPDATE audit_logs SET tenant_deleted_at = NULL WHERE tenant_id = p_tenant_id AND tenant_deleted_at = v_deleted_at;

    -- Get counts after restoration
    SET v_counts_after = fn_count_tenant_records(p_tenant_id);

    -- Commit transaction
    COMMIT;

    -- Success
    SET p_success = TRUE;
    SET p_message = CONCAT('Tenant "', v_tenant_name, '" restored successfully');
    SET p_records_affected = JSON_OBJECT(
        'tenant_id', p_tenant_id,
        'tenant_name', v_tenant_name,
        'was_deleted_at', v_deleted_at,
        'restored_by', p_restored_by_user_id,
        'restored_at', NOW(),
        'counts_before', v_counts_before,
        'counts_after', v_counts_after
    );

END$$

DELIMITER ;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Show all stored procedures created
SELECT '========================================' as '';
SELECT 'BUG-128: STORED PROCEDURES UPDATED' as status;
SELECT '========================================' as '';

SELECT
    ROUTINE_NAME,
    ROUTINE_TYPE,
    DTD_IDENTIFIER as returns,
    CREATED,
    LAST_ALTERED
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME IN ('sp_soft_delete_tenant_complete', 'sp_restore_tenant', 'fn_count_tenant_records')
ORDER BY ROUTINE_TYPE, ROUTINE_NAME;

SELECT '' as '';
SELECT 'Changes Applied:' as info;
SELECT '  - calendar_events → events' as change_1;
SELECT '  - calendar_shares → calendar_permissions' as change_2;
SELECT '  - event_attendees → event_participants (NEW)' as change_3;
SELECT '  - Added event_reminders (NEW)' as change_4;
SELECT '  - Added calendars table' as change_5;
SELECT '' as '';
SELECT 'Tables Now Covered:' as coverage;
SELECT '  - calendars (migration 10)' as table_1;
SELECT '  - calendar_permissions (migration 10)' as table_2;
SELECT '  - events (migration 11, renamed)' as table_3;
SELECT '  - event_participants (migration 12)' as table_4;
SELECT '  - event_reminders (migration 13)' as table_5;
SELECT '' as '';
SELECT 'Ready for tenant deletion!' as status;
SELECT '========================================' as '';
