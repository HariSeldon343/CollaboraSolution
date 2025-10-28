-- ============================================
-- Module: Audit Log Deletion Tracking System
-- Version: 2025-10-27
-- Author: Database Architect
-- Description: Complete audit log deletion tracking with immutable records
--              for compliance and accountability
--
-- Purpose: Track when super admins delete audit logs, create immutable
--          records of deletions, and enable email notifications
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFY AUDIT_LOGS TABLE EXISTS
-- ============================================
SELECT 'Verifying audit_logs table exists...' as status;

-- Check if audit_logs table exists
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'PASS: audit_logs table exists'
        ELSE 'FAIL: audit_logs table not found - run database/06_audit_logs.sql first'
    END as audit_logs_check
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs';

-- ============================================
-- ADD deleted_at COLUMN TO audit_logs (Soft Delete)
-- ============================================
-- Note: audit_logs table originally did not have deleted_at column
-- We're adding it now to support soft delete functionality for audit log management

SELECT 'Adding deleted_at column to audit_logs table...' as status;

-- Check if deleted_at column already exists
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'Adding deleted_at column...'
        ELSE 'Column deleted_at already exists, skipping...'
    END as column_status
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'deleted_at';

-- Add deleted_at column if it doesn't exist
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 0 THEN
                'ALTER TABLE audit_logs ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER created_at'
            ELSE
                'SELECT "Column deleted_at already exists" as info'
        END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'deleted_at'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for deleted_at if column was just added
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 0 THEN
                'CREATE INDEX idx_audit_deleted ON audit_logs(deleted_at, created_at DESC)'
            ELSE
                'SELECT "Index idx_audit_deleted already exists" as info'
        END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_deleted'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add composite index for tenant filtering with soft delete
SET @sql = (
    SELECT
        CASE
            WHEN COUNT(*) = 0 THEN
                'CREATE INDEX idx_audit_tenant_deleted ON audit_logs(tenant_id, deleted_at, created_at DESC)'
            ELSE
                'SELECT "Index idx_audit_tenant_deleted already exists" as info'
        END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_tenant_deleted'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- TABLE: audit_log_deletions (IMMUTABLE)
-- ============================================
-- This table records EVERY deletion of audit logs
-- CRITICAL: NO deleted_at column - records are PERMANENT and IMMUTABLE
-- Purpose: Compliance, accountability, audit trail for audit log management

SELECT 'Creating audit_log_deletions table...' as status;

CREATE TABLE IF NOT EXISTS audit_log_deletions (
    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy support
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant whose logs were deleted',

    -- Deletion tracking (UNIQUE identifier for this deletion operation)
    deletion_id VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique deletion identifier (UUID)',

    -- Who deleted the logs
    deleted_by INT UNSIGNED NULL COMMENT 'User ID of super admin who deleted logs (SET NULL if user deleted)',

    -- When deletion occurred
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When logs were deleted',

    -- Why deletion occurred
    deletion_reason TEXT NULL COMMENT 'Reason provided by admin for deletion',

    -- What was deleted - Summary
    deleted_count INT UNSIGNED NOT NULL COMMENT 'Total number of logs deleted in this operation',

    -- Period of deleted logs
    period_start TIMESTAMP NULL COMMENT 'Start date of deleted log period (NULL = no date filter)',
    period_end TIMESTAMP NULL COMMENT 'End date of deleted log period (NULL = no date filter)',

    -- Filters applied during deletion
    filter_action VARCHAR(50) NULL COMMENT 'Action filter applied (NULL = all actions)',
    filter_entity_type VARCHAR(50) NULL COMMENT 'Entity type filter applied (NULL = all types)',
    filter_user_id INT UNSIGNED NULL COMMENT 'User ID filter applied (NULL = all users)',
    filter_severity ENUM('info', 'warning', 'error', 'critical') NULL COMMENT 'Severity filter applied (NULL = all severities)',

    -- What was deleted - IDs (for reference)
    deleted_log_ids JSON NOT NULL COMMENT 'Array of deleted audit_logs.id values',

    -- What was deleted - Full snapshot (IMMUTABLE RECORD)
    deleted_logs_snapshot JSON NOT NULL COMMENT 'Complete snapshot of ALL deleted log records',

    -- Notification tracking
    notification_sent BOOLEAN DEFAULT FALSE COMMENT 'Whether email notification was sent',
    notification_sent_at TIMESTAMP NULL COMMENT 'When notification email was sent',
    notified_users JSON NULL COMMENT 'Array of user IDs who received notification email',
    notification_error TEXT NULL COMMENT 'Error message if notification failed',

    -- Request context
    ip_address VARCHAR(45) NULL COMMENT 'IP address of admin who performed deletion',
    user_agent TEXT NULL COMMENT 'Browser/client information',

    -- Metadata
    metadata JSON NULL COMMENT 'Additional metadata (request details, system info, etc.)',

    -- Timestamp (NO updated_at - IMMUTABLE!)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created (IMMUTABLE)',

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys
    INDEX idx_fk_tenant (tenant_id),
    INDEX idx_fk_deleted_by (deleted_by),

    CONSTRAINT fk_audit_deletion_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_audit_deletion_user
        FOREIGN KEY (deleted_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Check constraints
    CONSTRAINT chk_deletion_count_positive
        CHECK (deleted_count > 0),

    CONSTRAINT chk_period_order
        CHECK (period_start IS NULL OR period_end IS NULL OR period_start <= period_end)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='IMMUTABLE audit log deletion tracking - NO soft delete, records are permanent';

-- ============================================
-- INDEXES FOR audit_log_deletions
-- ============================================
SELECT 'Creating indexes for audit_log_deletions...' as status;

-- Composite index for tenant filtering
CREATE INDEX IF NOT EXISTS idx_deletion_tenant_date
    ON audit_log_deletions(tenant_id, deleted_at DESC);

-- Index for lookup by deletion_id (already UNIQUE but explicit index)
CREATE INDEX IF NOT EXISTS idx_deletion_id
    ON audit_log_deletions(deletion_id);

-- Index for filtering by who deleted
CREATE INDEX IF NOT EXISTS idx_deletion_deleted_by
    ON audit_log_deletions(deleted_by, deleted_at DESC);

-- Index for time-based queries
CREATE INDEX IF NOT EXISTS idx_deletion_deleted_at
    ON audit_log_deletions(deleted_at DESC);

-- Index for notification tracking
CREATE INDEX IF NOT EXISTS idx_deletion_notification
    ON audit_log_deletions(notification_sent, notification_sent_at);

-- Index for period searches
CREATE INDEX IF NOT EXISTS idx_deletion_period
    ON audit_log_deletions(tenant_id, period_start, period_end);

-- ============================================
-- STORED PROCEDURES
-- ============================================
DELIMITER $$

-- Procedure: Record audit log deletion with full snapshot
DROP PROCEDURE IF EXISTS record_audit_log_deletion$$

CREATE PROCEDURE record_audit_log_deletion(
    IN p_tenant_id INT,
    IN p_deleted_by INT,
    IN p_deletion_reason TEXT,
    IN p_period_start TIMESTAMP,
    IN p_period_end TIMESTAMP,
    IN p_filter_action VARCHAR(50),
    IN p_filter_entity_type VARCHAR(50),
    IN p_filter_user_id INT,
    IN p_filter_severity VARCHAR(20),
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT,
    OUT p_deletion_id VARCHAR(64),
    OUT p_deleted_count INT
)
BEGIN
    DECLARE v_deleted_log_ids JSON;
    DECLARE v_deleted_logs_snapshot JSON;
    DECLARE v_count INT;

    -- Generate unique deletion ID
    SET p_deletion_id = CONCAT(
        'DEL-',
        DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'),
        '-',
        SUBSTRING(MD5(CONCAT(p_tenant_id, NOW(), RAND())), 1, 12)
    );

    -- Build query to get IDs of logs to delete
    SET @sql_ids = CONCAT(
        'SELECT JSON_ARRAYAGG(id) INTO @temp_ids ',
        'FROM audit_logs ',
        'WHERE tenant_id = ', p_tenant_id,
        ' AND deleted_at IS NULL'
    );

    -- Add period filters
    IF p_period_start IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND created_at >= ''', p_period_start, '''');
    END IF;

    IF p_period_end IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND created_at <= ''', p_period_end, '''');
    END IF;

    -- Add other filters
    IF p_filter_action IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND action = ''', p_filter_action, '''');
    END IF;

    IF p_filter_entity_type IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND entity_type = ''', p_filter_entity_type, '''');
    END IF;

    IF p_filter_user_id IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND user_id = ', p_filter_user_id);
    END IF;

    IF p_filter_severity IS NOT NULL THEN
        SET @sql_ids = CONCAT(@sql_ids, ' AND severity = ''', p_filter_severity, '''');
    END IF;

    -- Execute query to get IDs
    PREPARE stmt FROM @sql_ids;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    SET v_deleted_log_ids = @temp_ids;

    -- Build query to get full snapshot
    SET @sql_snapshot = REPLACE(@sql_ids, 'JSON_ARRAYAGG(id) INTO @temp_ids',
                                          'JSON_ARRAYAGG(JSON_OBJECT(
                                              ''id'', id,
                                              ''tenant_id'', tenant_id,
                                              ''user_id'', user_id,
                                              ''action'', action,
                                              ''entity_type'', entity_type,
                                              ''entity_id'', entity_id,
                                              ''description'', description,
                                              ''old_values'', old_values,
                                              ''new_values'', new_values,
                                              ''ip_address'', ip_address,
                                              ''severity'', severity,
                                              ''status'', status,
                                              ''created_at'', created_at
                                          )) INTO @temp_snapshot');

    -- Execute query to get snapshot
    PREPARE stmt FROM @sql_snapshot;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    SET v_deleted_logs_snapshot = @temp_snapshot;

    -- Get count
    SET v_count = JSON_LENGTH(v_deleted_log_ids);
    SET p_deleted_count = v_count;

    -- Only proceed if there are logs to delete
    IF v_count > 0 THEN
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
            filter_action,
            filter_entity_type,
            filter_user_id,
            filter_severity,
            deleted_log_ids,
            deleted_logs_snapshot,
            ip_address,
            user_agent,
            notification_sent
        ) VALUES (
            p_tenant_id,
            p_deletion_id,
            p_deleted_by,
            NOW(),
            p_deletion_reason,
            v_count,
            p_period_start,
            p_period_end,
            p_filter_action,
            p_filter_entity_type,
            p_filter_user_id,
            p_filter_severity,
            v_deleted_log_ids,
            v_deleted_logs_snapshot,
            p_ip_address,
            p_user_agent,
            FALSE
        );

        -- Soft delete the audit logs
        UPDATE audit_logs
        SET deleted_at = NOW()
        WHERE tenant_id = p_tenant_id
          AND id IN (
              SELECT id FROM (
                  SELECT id FROM audit_logs
                  WHERE tenant_id = p_tenant_id
                    AND deleted_at IS NULL
                    AND (p_period_start IS NULL OR created_at >= p_period_start)
                    AND (p_period_end IS NULL OR created_at <= p_period_end)
                    AND (p_filter_action IS NULL OR action = p_filter_action)
                    AND (p_filter_entity_type IS NULL OR entity_type = p_filter_entity_type)
                    AND (p_filter_user_id IS NULL OR user_id = p_filter_user_id)
                    AND (p_filter_severity IS NULL OR severity = p_filter_severity)
              ) AS temp_ids
          );
    END IF;
END$$

-- Procedure: Mark notification as sent
DROP PROCEDURE IF EXISTS mark_deletion_notification_sent$$

CREATE PROCEDURE mark_deletion_notification_sent(
    IN p_deletion_id VARCHAR(64),
    IN p_notified_users JSON,
    IN p_error TEXT
)
BEGIN
    UPDATE audit_log_deletions
    SET
        notification_sent = IF(p_error IS NULL, TRUE, FALSE),
        notification_sent_at = IF(p_error IS NULL, NOW(), NULL),
        notified_users = p_notified_users,
        notification_error = p_error
    WHERE deletion_id = p_deletion_id;
END$$

-- Function: Get deletion statistics
DROP FUNCTION IF EXISTS get_deletion_stats$$

CREATE FUNCTION get_deletion_stats(p_tenant_id INT)
RETURNS JSON
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE result JSON;

    SELECT JSON_OBJECT(
        'total_deletions', COUNT(*),
        'total_logs_deleted', COALESCE(SUM(deleted_count), 0),
        'last_deletion_date', MAX(deleted_at),
        'notifications_sent', SUM(CASE WHEN notification_sent = TRUE THEN 1 ELSE 0 END),
        'notifications_failed', SUM(CASE WHEN notification_sent = FALSE AND notification_error IS NOT NULL THEN 1 ELSE 0 END)
    ) INTO result
    FROM audit_log_deletions
    WHERE tenant_id = p_tenant_id;

    RETURN result;
END$$

DELIMITER ;

-- ============================================
-- VIEWS
-- ============================================

-- View: Recent deletions with admin info
CREATE OR REPLACE VIEW v_recent_audit_deletions AS
SELECT
    adl.id,
    adl.deletion_id,
    adl.tenant_id,
    t.name AS tenant_name,
    adl.deleted_by,
    u.name AS deleted_by_name,
    u.email AS deleted_by_email,
    adl.deleted_at,
    adl.deletion_reason,
    adl.deleted_count,
    adl.period_start,
    adl.period_end,
    adl.filter_action,
    adl.filter_entity_type,
    adl.notification_sent,
    adl.notification_sent_at,
    CASE
        WHEN adl.notification_sent = TRUE THEN 'Sent'
        WHEN adl.notification_error IS NOT NULL THEN 'Failed'
        ELSE 'Pending'
    END AS notification_status
FROM audit_log_deletions adl
LEFT JOIN tenants t ON adl.tenant_id = t.id
LEFT JOIN users u ON adl.deleted_by = u.id
ORDER BY adl.deleted_at DESC;

-- View: Deletion summary by tenant
CREATE OR REPLACE VIEW v_audit_deletion_summary AS
SELECT
    adl.tenant_id,
    t.name AS tenant_name,
    COUNT(*) AS total_deletions,
    SUM(adl.deleted_count) AS total_logs_deleted,
    MAX(adl.deleted_at) AS last_deletion_date,
    MIN(adl.deleted_at) AS first_deletion_date,
    SUM(CASE WHEN adl.notification_sent = TRUE THEN 1 ELSE 0 END) AS notifications_sent,
    SUM(CASE WHEN adl.notification_sent = FALSE THEN 1 ELSE 0 END) AS notifications_pending
FROM audit_log_deletions adl
LEFT JOIN tenants t ON adl.tenant_id = t.id
GROUP BY adl.tenant_id, t.name
ORDER BY total_deletions DESC;

-- ============================================
-- DEMO DATA (Optional)
-- ============================================
SELECT 'Creating demo deletion record...' as status;

-- Only insert demo data if there are super admins
SET @has_super_admin = (
    SELECT COUNT(*)
    FROM users
    WHERE role = 'super_admin'
      AND deleted_at IS NULL
    LIMIT 1
);

SET @demo_tenant = (
    SELECT id
    FROM tenants
    WHERE deleted_at IS NULL
    LIMIT 1
);

-- Insert demo deletion record if conditions are met
INSERT INTO audit_log_deletions (
    tenant_id,
    deletion_id,
    deleted_by,
    deleted_at,
    deletion_reason,
    deleted_count,
    period_start,
    period_end,
    filter_action,
    filter_entity_type,
    filter_user_id,
    filter_severity,
    deleted_log_ids,
    deleted_logs_snapshot,
    ip_address,
    user_agent,
    notification_sent,
    notification_sent_at,
    notified_users
)
SELECT
    @demo_tenant,
    'DEL-20251027120000-DEMO123456',
    u.id,
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    'Scheduled cleanup of old audit logs older than 90 days per data retention policy',
    125,
    DATE_SUB(NOW(), INTERVAL 120 DAY),
    DATE_SUB(NOW(), INTERVAL 90 DAY),
    NULL,
    NULL,
    NULL,
    NULL,
    JSON_ARRAY(1001, 1002, 1003, 1004, 1005),
    JSON_ARRAY(
        JSON_OBJECT(
            'id', 1001,
            'tenant_id', @demo_tenant,
            'user_id', u.id,
            'action', 'login',
            'entity_type', 'user',
            'entity_id', u.id,
            'description', 'User logged in',
            'severity', 'info',
            'status', 'success',
            'created_at', DATE_SUB(NOW(), INTERVAL 95 DAY)
        )
    ),
    '192.168.1.100',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
    TRUE,
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    JSON_ARRAY(u.id)
FROM users u
WHERE u.role = 'super_admin'
  AND u.deleted_at IS NULL
  AND @has_super_admin > 0
  AND @demo_tenant IS NOT NULL
LIMIT 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Migration completed successfully!' as status;

-- Verify audit_logs has deleted_at column
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'PASS: audit_logs.deleted_at column exists'
        ELSE 'FAIL: audit_logs.deleted_at column missing'
    END as deleted_at_check
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'deleted_at';

-- Verify audit_log_deletions table created
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'PASS: audit_log_deletions table created'
        ELSE 'FAIL: audit_log_deletions table not created'
    END as table_check
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name = 'audit_log_deletions';

-- Show table structure
SELECT 'audit_log_deletions table structure:' as info;
DESCRIBE audit_log_deletions;

-- Show indexes
SELECT 'audit_log_deletions indexes:' as info;
SHOW INDEX FROM audit_log_deletions;

-- Show foreign keys
SELECT 'audit_log_deletions foreign keys:' as info;
SELECT
    constraint_name,
    column_name,
    referenced_table_name,
    referenced_column_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE table_schema = DATABASE()
  AND table_name = 'audit_log_deletions'
  AND referenced_table_name IS NOT NULL;

-- Show procedures and functions
SELECT 'Stored procedures and functions:' as info;
SELECT
    routine_type,
    routine_name,
    routine_definition
FROM information_schema.ROUTINES
WHERE routine_schema = DATABASE()
  AND routine_name LIKE '%deletion%';

-- Final summary
SELECT
    'Audit Log Deletion Tracking System Ready' as status,
    (SELECT COUNT(*) FROM audit_log_deletions) as deletion_records,
    NOW() as executed_at;
