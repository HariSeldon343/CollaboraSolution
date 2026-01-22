-- Module: Fix User Sessions and Password Resets Error in Stored Procedures
-- Version: 2025-11-21
-- Author: AI Assistant
-- Description: Update sp_soft_delete_tenant_complete and sp_restore_tenant to remove references 
--              to non-existent tables 'user_sessions' and 'password_resets'.

USE collaboranexio;

DROP PROCEDURE IF EXISTS sp_soft_delete_tenant_complete;
DROP PROCEDURE IF EXISTS sp_restore_tenant;

DELIMITER $$

-- ============================================
-- STORED PROCEDURE: Complete Tenant Soft-Delete
-- ============================================
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
    DECLARE v_allow_system_delete BOOLEAN DEFAULT FALSE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 @sql_message = MESSAGE_TEXT;
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = CONCAT('Error during tenant soft-delete: ', @sql_message);
        SET p_records_affected = JSON_OBJECT('error', 'Transaction rolled back', 'sql_message', @sql_message);
    END;

    SET v_deleted_at = NOW();
    SET p_success = FALSE;
    SET v_allow_system_delete = COALESCE(@ALLOW_SYSTEM_TENANT_DELETE, FALSE);

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

    -- Allow system tenant deletion only when API sets @ALLOW_SYSTEM_TENANT_DELETE = TRUE
    IF p_tenant_id = 1 AND v_allow_system_delete = FALSE THEN
        SET p_message = 'Cannot delete system tenant (ID 1) without explicit confirmation';
        SET p_records_affected = JSON_OBJECT('error', 'System tenant protected');
        LEAVE sp_soft_delete_tenant_complete;
    END IF;

    SET v_counts_before = fn_count_tenant_records(p_tenant_id);

    START TRANSACTION;

    UPDATE tenants SET deleted_at = v_deleted_at WHERE id = p_tenant_id AND deleted_at IS NULL;
    UPDATE users SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE user_permissions SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    
    -- REMOVED: UPDATE user_sessions ... (Table does not exist)
    -- REMOVED: UPDATE password_resets ... (Table does not exist)
    
    UPDATE notifications SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE folders SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE files SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE file_versions SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE file_shares SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE document_approvals SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE approval_notifications SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE projects SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE project_members SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE project_milestones SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE tasks SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE task_assignments SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE task_comments SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE event_reminders SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE event_participants SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE events SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE calendar_permissions SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE calendars SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE chat_channels SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE chat_channel_members SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE chat_messages SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE chat_message_reads SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE tenant_locations SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE system_settings SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE sessions SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE rate_limits SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE audit_logs SET tenant_deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND tenant_deleted_at IS NULL;
    DELETE FROM user_tenant_access WHERE tenant_id = p_tenant_id;

    SET v_counts_after = fn_count_tenant_records(p_tenant_id);

    COMMIT;

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

-- ============================================
-- STORED PROCEDURE: Restore Soft-Deleted Tenant
-- ============================================
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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 @sql_message = MESSAGE_TEXT;
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = CONCAT('Error during tenant restore: ', @sql_message);
        SET p_records_affected = JSON_OBJECT('error', 'Transaction rolled back', 'sql_message', @sql_message);
    END;

    SET p_success = FALSE;

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

    SET v_counts_before = fn_count_tenant_records(p_tenant_id);

    START TRANSACTION;

    UPDATE sessions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE rate_limits SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE system_settings SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE tenant_locations SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_message_reads SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_messages SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_channel_members SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE chat_channels SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE calendars SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE calendar_permissions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE events SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE event_participants SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE event_reminders SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE task_comments SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE task_assignments SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE tasks SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE project_milestones SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE project_members SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE projects SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE approval_notifications SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE document_approvals SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE file_shares SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE file_versions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE files SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE folders SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE notifications SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    
    -- REMOVED: UPDATE password_resets ...
    -- REMOVED: UPDATE user_sessions ...
    
    UPDATE user_permissions SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE users SET deleted_at = NULL WHERE tenant_id = p_tenant_id AND deleted_at = v_deleted_at;
    UPDATE tenants SET deleted_at = NULL WHERE id = p_tenant_id;
    UPDATE audit_logs SET tenant_deleted_at = NULL WHERE tenant_id = p_tenant_id AND tenant_deleted_at = v_deleted_at;

    SET v_counts_after = fn_count_tenant_records(p_tenant_id);

    COMMIT;

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
