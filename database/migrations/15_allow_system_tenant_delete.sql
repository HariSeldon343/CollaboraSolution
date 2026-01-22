-- Module: Allow System Tenant Deletion with Explicit Confirmation (BUG-131)
-- Version: 2025-11-21
-- Author: Database Architect
-- Description: Update sp_soft_delete_tenant_complete so tenant ID 1 can be
--              soft-deleted when API sets @ALLOW_SYSTEM_TENANT_DELETE = TRUE

USE collaboranexio;

DROP PROCEDURE IF EXISTS sp_soft_delete_tenant_complete;

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
    UPDATE user_sessions SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
    UPDATE password_resets SET deleted_at = v_deleted_at WHERE tenant_id = p_tenant_id AND deleted_at IS NULL;
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

DELIMITER ;

