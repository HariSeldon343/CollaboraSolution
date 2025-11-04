-- ============================================
-- BUG-046 FINAL FIX: Stored Procedure Creation
-- Root Cause: Missing stored procedure + nested transaction conflict
-- Date: 2025-10-28
-- Priority: CRITICAL
-- ============================================

USE collaboranexio;

-- Step 1: Drop existing procedure (if any)
-- ============================================
DROP PROCEDURE IF EXISTS record_audit_log_deletion;

-- Step 2: Create Stored Procedure WITHOUT Internal Transaction
-- ============================================
-- CRITICAL FIX: Removed START TRANSACTION/COMMIT from procedure
-- Reason: delete.php wraps call in external transaction (line 176)
-- Nested transactions cause commit() to fail (no active transaction after inner COMMIT)

DELIMITER $$

CREATE PROCEDURE record_audit_log_deletion(
    IN p_tenant_id INT UNSIGNED,
    IN p_deleted_by INT UNSIGNED,
    IN p_deletion_reason TEXT,
    IN p_period_start DATETIME,
    IN p_period_end DATETIME,
    IN p_mode ENUM('all', 'range')
)
BEGIN
    -- This stored procedure creates IMMUTABLE deletion record
    -- and soft-deletes audit logs by setting deleted_at timestamp
    --
    -- TRANSACTION MANAGEMENT: Caller is responsible for transaction management
    -- (delete.php manages external transaction at line 176)

    DECLARE v_deletion_id VARCHAR(64);
    DECLARE v_deleted_count INT DEFAULT 0;
    DECLARE v_ids_json LONGTEXT DEFAULT '[]';
    DECLARE v_snapshot_json LONGTEXT DEFAULT '[]';
    DECLARE v_ids_valid INT DEFAULT 0;
    DECLARE v_snapshot_valid INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        -- Re-signal error to caller (will be caught by delete.php)
        RESIGNAL;
    END;

    -- Generate unique deletion ID
    SET v_deletion_id = CONCAT(
        'AUDIT_DEL_',
        DATE_FORMAT(NOW(), '%Y%m%d'),
        '_',
        SUBSTRING(MD5(CONCAT(UUID(), RAND())), 1, 6)
    );

    -- Build JSON arrays using GROUP_CONCAT (MariaDB compatible)
    IF p_mode = 'all' THEN
        -- Mode: ALL logs for tenant
        SELECT
            CONCAT('[', IFNULL(GROUP_CONCAT(id SEPARATOR ','), ''), ']'),
            COUNT(*)
        INTO v_ids_json, v_deleted_count
        FROM audit_logs
        WHERE tenant_id = p_tenant_id
          AND deleted_at IS NULL;

        -- Build snapshot JSON
        SELECT
            CONCAT('[',
                IFNULL(GROUP_CONCAT(
                    CONCAT('{"id":', id,
                           ',"action":"', IFNULL(action, ''), '"',
                           ',"entity_type":"', IFNULL(entity_type, ''), '"',
                           ',"user_id":', IFNULL(user_id, 'null'),
                           ',"created_at":"', IFNULL(created_at, ''), '"',
                           '}')
                    SEPARATOR ','
                ), ''),
            ']')
        INTO v_snapshot_json
        FROM audit_logs
        WHERE tenant_id = p_tenant_id
          AND deleted_at IS NULL;

    ELSE
        -- Mode: RANGE (date filter)
        SELECT
            CONCAT('[', IFNULL(GROUP_CONCAT(id SEPARATOR ','), ''), ']'),
            COUNT(*)
        INTO v_ids_json, v_deleted_count
        FROM audit_logs
        WHERE tenant_id = p_tenant_id
          AND deleted_at IS NULL
          AND created_at BETWEEN p_period_start AND p_period_end;

        -- Build snapshot JSON
        SELECT
            CONCAT('[',
                IFNULL(GROUP_CONCAT(
                    CONCAT('{"id":', id,
                           ',"action":"', IFNULL(action, ''), '"',
                           ',"entity_type":"', IFNULL(entity_type, ''), '"',
                           ',"user_id":', IFNULL(user_id, 'null'),
                           ',"created_at":"', IFNULL(created_at, ''), '"',
                           '}')
                    SEPARATOR ','
                ), ''),
            ']')
        INTO v_snapshot_json
        FROM audit_logs
        WHERE tenant_id = p_tenant_id
          AND deleted_at IS NULL
          AND created_at BETWEEN p_period_start AND p_period_end;
    END IF;

    -- Validate JSON (basic check)
    SET v_ids_valid = (v_ids_json LIKE '[%]');
    SET v_snapshot_valid = (v_snapshot_json LIKE '[%]');

    IF v_ids_valid = 0 OR v_snapshot_valid = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Failed to generate valid JSON arrays';
    END IF;

    -- Only proceed if there are logs to delete
    IF v_deleted_count > 0 THEN
        -- Insert deletion record (IMMUTABLE)
        INSERT INTO audit_log_deletions (
            tenant_id,
            deletion_id,
            deleted_by,
            deleted_at,
            deletion_reason,
            deleted_count,
            period_start,
            period_end,
            deleted_log_ids,
            deleted_logs_snapshot,
            notification_sent,
            created_at
        ) VALUES (
            p_tenant_id,
            v_deletion_id,
            p_deleted_by,
            NOW(),
            p_deletion_reason,
            v_deleted_count,
            IF(p_mode = 'range', p_period_start, NULL),
            IF(p_mode = 'range', p_period_end, NULL),
            v_ids_json,
            v_snapshot_json,
            0,
            NOW()
        );

        -- Soft delete logs (SET deleted_at)
        IF p_mode = 'all' THEN
            UPDATE audit_logs
            SET deleted_at = NOW()
            WHERE tenant_id = p_tenant_id
              AND deleted_at IS NULL;
        ELSE
            UPDATE audit_logs
            SET deleted_at = NOW()
            WHERE tenant_id = p_tenant_id
              AND deleted_at IS NULL
              AND created_at BETWEEN p_period_start AND p_period_end;
        END IF;
    END IF;

    -- Return result (delete.php expects deletion_id and deleted_count)
    SELECT
        v_deletion_id as deletion_id,
        v_deleted_count as deleted_count;

END$$

DELIMITER ;

-- Step 3: Restore Deleted Audit Logs
-- ============================================
-- All 8 audit logs have deleted_at set, making them invisible
-- Restore by setting deleted_at = NULL

UPDATE audit_logs
SET deleted_at = NULL
WHERE deleted_at IS NOT NULL;

-- Step 4: Verification Queries
-- ============================================

-- Check procedure exists
SELECT 'Stored procedure check:' as status;
SELECT COUNT(*) as procedure_exists
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME = 'record_audit_log_deletion';
-- Expected: 1

-- Check active audit logs
SELECT 'Active audit logs check:' as status;
SELECT COUNT(*) as active_logs_count
FROM audit_logs
WHERE deleted_at IS NULL;
-- Expected: 8 (all restored)

-- Check total audit logs
SELECT 'Total audit logs:' as status;
SELECT
    COUNT(*) as total_logs,
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as active_logs,
    COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END) as deleted_logs
FROM audit_logs;
-- Expected: 8 total, 8 active, 0 deleted

-- Check deletion records
SELECT 'Deletion records:' as status;
SELECT COUNT(*) as deletion_records_count
FROM audit_log_deletions;
-- Expected: 14 (existing records)

-- ============================================
-- FIX COMPLETE
-- ============================================

SELECT '✓ BUG-046 FIX COMPLETE' as status,
       'Stored procedure created WITHOUT nested transaction' as fix,
       'All 8 audit logs restored (deleted_at = NULL)' as restoration,
       NOW() as timestamp;
