-- Module: Remove Tenant ID 1 Protection
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Removes the system tenant (ID 1) protection from sp_soft_delete_tenant_complete
--              Allows deletion of ALL tenants including the system tenant with warning message

USE collaboranexio;

-- ============================================
-- DROP EXISTING PROCEDURE
-- ============================================
DROP PROCEDURE IF EXISTS sp_soft_delete_tenant_complete;

-- ============================================
-- RECREATE PROCEDURE WITHOUT TENANT ID 1 PROTECTION
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
    DECLARE v_is_system_tenant BOOLEAN DEFAULT FALSE;

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

    -- Check if this is the system tenant
    IF p_tenant_id = 1 THEN
        SET v_is_system_tenant = TRUE;
    END IF;

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

    -- NOTE: Tenant ID 1 protection has been REMOVED as per request
    -- The procedure will now delete ANY tenant including system tenant (ID 1)
    -- A warning message will be included for system tenant deletion

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

    -- User sessions (could also hard-delete these) -- TABLE DOES NOT EXIST
    -- UPDATE user_sessions
    -- SET deleted_at = v_deleted_at
    -- WHERE tenant_id = p_tenant_id
    --   AND deleted_at IS NULL;

    -- Password resets (could also hard-delete these) -- TABLE DOES NOT EXIST
    -- UPDATE password_resets
    -- SET deleted_at = v_deleted_at
    -- WHERE tenant_id = p_tenant_id
    --   AND deleted_at IS NULL;

    -- Notifications -- TABLE DOES NOT EXIST
    -- UPDATE notifications
    -- SET deleted_at = v_deleted_at
    -- WHERE tenant_id = p_tenant_id
    --   AND deleted_at IS NULL;

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

    -- Success - with warning for system tenant
    SET p_success = TRUE;

    IF v_is_system_tenant THEN
        SET p_message = CONCAT('ATTENZIONE: Tenant di sistema (ID 1) "', v_tenant_name, '" eliminato con successo at ', v_deleted_at);
    ELSE
        SET p_message = CONCAT('Tenant "', v_tenant_name, '" soft-deleted successfully at ', v_deleted_at);
    END IF;

    SET p_records_affected = JSON_OBJECT(
        'tenant_id', p_tenant_id,
        'tenant_name', v_tenant_name,
        'is_system_tenant', v_is_system_tenant,
        'deleted_at', v_deleted_at,
        'deleted_by', p_deleted_by_user_id,
        'counts_before', v_counts_before,
        'counts_after', v_counts_after
    );

END$$

DELIMITER ;

-- ============================================
-- VERIFICATION
-- ============================================

-- Verify procedure was recreated
SELECT
    'sp_soft_delete_tenant_complete' as procedure_name,
    ROUTINE_TYPE as type,
    CREATED as created_at,
    LAST_ALTERED as last_modified,
    'Tenant ID 1 protection REMOVED' as status
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME = 'sp_soft_delete_tenant_complete';

-- ============================================
-- MIGRATION SUMMARY
-- ============================================

SELECT '========================================' as '';
SELECT 'TENANT ID 1 PROTECTION REMOVED' as status;
SELECT '========================================' as '';
SELECT '' as '';

SELECT 'Changes Applied:' as info, '' as value
UNION ALL
SELECT '  - Protection check removed', 'IF p_tenant_id = 1 THEN block deleted'
UNION ALL
SELECT '  - Warning message added', 'System tenant deletion shows ATTENZIONE message'
UNION ALL
SELECT '  - is_system_tenant flag', 'Added to output JSON for tracking'
UNION ALL
SELECT '' as '', ''
UNION ALL
SELECT 'WARNING:', 'Tenant ID 1 can now be deleted!'
UNION ALL
SELECT 'Impact:', 'All tenants including system tenant can be soft-deleted'
UNION ALL
SELECT 'Recommendation:', 'Implement application-level protection if needed'
UNION ALL
SELECT 'Completed At:', CAST(NOW() AS CHAR);

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Migration completed successfully!' as status;
SELECT '========================================' as '';
