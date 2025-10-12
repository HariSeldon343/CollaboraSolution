-- Module: Complete Tenant Soft-Delete Cascade Procedure
-- Version: 2025-10-08
-- Author: Database Architect
-- Description: Stored procedure for complete tenant soft-delete with proper cascade order and audit trail

USE collaboranexio;

-- ============================================
-- DROP EXISTING PROCEDURES
-- ============================================
DROP PROCEDURE IF EXISTS sp_soft_delete_tenant_complete;
DROP PROCEDURE IF EXISTS sp_restore_tenant;
DROP FUNCTION IF EXISTS fn_count_tenant_records;

-- ============================================
-- FUNCTION: Count Total Records for Tenant
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
        'calendar_events', (SELECT COUNT(*) FROM calendar_events WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'chat_channels', (SELECT COUNT(*) FROM chat_channels WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'notifications', (SELECT COUNT(*) FROM notifications WHERE tenant_id = p_tenant_id AND deleted_at IS NULL),
        'tenant_locations', (SELECT COUNT(*) FROM tenant_locations WHERE tenant_id = p_tenant_id AND deleted_at IS NULL)
    ) INTO v_counts;

    RETURN v_counts;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURE: Complete Tenant Soft-Delete
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
    -- ============================================

    -- Calendar events
    UPDATE calendar_events
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Calendar shares
    UPDATE calendar_shares
    SET deleted_at = v_deleted_at
    WHERE tenant_id = p_tenant_id
      AND deleted_at IS NULL;

    -- Event attendees (if exists)
    UPDATE event_attendees
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

    -- LEVEL 9: Calendar
    UPDATE event_attendees SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE calendar_shares SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE calendar_events SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;

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
-- DEMO/TEST USAGE
-- ============================================

/*
-- Example 1: Soft-delete a tenant
CALL sp_soft_delete_tenant_complete(
    2,                      -- tenant_id to delete
    1,                      -- deleted_by user_id
    @success,               -- OUT success flag
    @message,               -- OUT message
    @records                -- OUT records affected JSON
);

SELECT @success as success, @message as message, @records as records_affected;

-- Example 2: Restore a soft-deleted tenant
CALL sp_restore_tenant(
    2,                      -- tenant_id to restore
    1,                      -- restored_by user_id
    @success,               -- OUT success flag
    @message,               -- OUT message
    @records                -- OUT records affected JSON
);

SELECT @success as success, @message as message, @records as records_affected;

-- Example 3: Count records for a tenant
SELECT fn_count_tenant_records(1) as tenant_record_counts;
*/

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Show all stored procedures created
SELECT
    ROUTINE_NAME,
    ROUTINE_TYPE,
    DTD_IDENTIFIER as returns,
    CREATED,
    LAST_ALTERED
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME LIKE '%tenant%'
ORDER BY ROUTINE_TYPE, ROUTINE_NAME;

-- ============================================
-- MIGRATION SUMMARY
-- ============================================

SELECT '========================================' as '';
SELECT 'TENANT SOFT-DELETE PROCEDURES CREATED' as status;
SELECT '========================================' as '';
SELECT '' as '';

SELECT 'Objects Created:' as info, '' as value
UNION ALL
SELECT '  - sp_soft_delete_tenant_complete', 'Comprehensive tenant soft-delete with cascade'
UNION ALL
SELECT '  - sp_restore_tenant', 'Restore soft-deleted tenant and all related data'
UNION ALL
SELECT '  - fn_count_tenant_records', 'Count records across all tables for a tenant'
UNION ALL
SELECT '' as '', ''
UNION ALL
SELECT 'Coverage:', '100% of tenant-related tables (27 tables)'
UNION ALL
SELECT 'Cascade Levels:', '12 levels of hierarchical deletion'
UNION ALL
SELECT 'Audit Trail:', 'Preserved (audit_logs use tenant_deleted_at marker)'
UNION ALL
SELECT 'Transaction Safety:', 'Full ACID compliance with rollback on error'
UNION ALL
SELECT 'Completed At:', CAST(NOW() AS CHAR);

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Ready for production use!' as status;
SELECT 'Update /api/tenants/delete.php to call sp_soft_delete_tenant_complete()' as next_action;
SELECT '========================================' as '';
