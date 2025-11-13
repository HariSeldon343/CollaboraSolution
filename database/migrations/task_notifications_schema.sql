-- ============================================================================
-- TASK NOTIFICATION SYSTEM SCHEMA
-- ============================================================================
-- Version: 1.0.0
-- Date: 2025-10-25
-- Author: Claude Code - Staff Engineer
-- Description: Database schema for task email notification system
--
-- FEATURES:
-- - Email notification tracking (who was notified, when, delivery status)
-- - User notification preferences (granular email controls)
-- - Multi-tenant architecture compliant
-- - Soft delete pattern on preferences
-- - Audit trail with delivery logs
-- ============================================================================

USE collaboranexio;

-- ============================================================================
-- TABLE: task_notifications
-- PURPOSE: Track all email notifications sent for task events
-- ============================================================================

CREATE TABLE IF NOT EXISTS task_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Notification log ID',

    -- Multi-tenant isolation
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant ID for isolation',

    -- Task reference
    task_id INT UNSIGNED NOT NULL COMMENT 'Task that triggered notification',

    -- Recipient
    user_id INT UNSIGNED NOT NULL COMMENT 'User who received notification',

    -- Notification metadata
    notification_type ENUM(
        'task_created',
        'task_assigned',
        'task_removed',
        'task_updated',
        'task_status_changed',
        'task_comment_added',
        'task_due_soon',
        'task_overdue',
        'task_priority_changed',
        'task_completed'
    ) NOT NULL COMMENT 'Type of notification',

    -- Email delivery
    recipient_email VARCHAR(255) NOT NULL COMMENT 'Email address notification was sent to',
    email_subject VARCHAR(500) NOT NULL COMMENT 'Email subject line',
    email_sent_at TIMESTAMP NULL COMMENT 'When email was sent (NULL if pending)',
    delivery_status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'pending' COMMENT 'Email delivery status',
    delivery_error TEXT NULL COMMENT 'Error message if delivery failed',

    -- Change details (JSON for task_updated notifications)
    change_details JSON NULL COMMENT 'Details of changes that triggered notification',

    -- Metadata
    sent_by INT UNSIGNED NULL COMMENT 'User who triggered the notification',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of user who triggered action',
    user_agent VARCHAR(500) NULL COMMENT 'Browser/client that triggered action',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When notification record created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',

    -- Foreign Keys
    CONSTRAINT fk_task_notifications_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_notifications_task
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_notifications_sent_by
        FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes for performance
    INDEX idx_task_notifications_tenant_created (tenant_id, created_at),
    INDEX idx_task_notifications_task (task_id),
    INDEX idx_task_notifications_user (user_id),
    INDEX idx_task_notifications_type (notification_type),
    INDEX idx_task_notifications_status (delivery_status),
    INDEX idx_task_notifications_sent_at (email_sent_at),

    -- Composite indexes for common queries
    INDEX idx_task_notifications_tenant_user (tenant_id, user_id, created_at),
    INDEX idx_task_notifications_tenant_task (tenant_id, task_id, created_at),
    INDEX idx_task_notifications_delivery (delivery_status, email_sent_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email notification log for task events';

-- ============================================================================
-- TABLE: user_notification_preferences
-- PURPOSE: Store user preferences for email notifications
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_notification_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Preference record ID',

    -- Multi-tenant isolation
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant ID for isolation',

    -- User reference
    user_id INT UNSIGNED NOT NULL COMMENT 'User these preferences belong to',

    -- Notification preferences (individual toggles)
    notify_task_created BOOLEAN DEFAULT TRUE COMMENT 'Email when assigned to new task',
    notify_task_assigned BOOLEAN DEFAULT TRUE COMMENT 'Email when explicitly assigned',
    notify_task_removed BOOLEAN DEFAULT TRUE COMMENT 'Email when removed from task',
    notify_task_updated BOOLEAN DEFAULT TRUE COMMENT 'Email when assigned task is updated',
    notify_task_status_changed BOOLEAN DEFAULT TRUE COMMENT 'Email when task status changes',
    notify_task_comment_added BOOLEAN DEFAULT FALSE COMMENT 'Email when comment added (can be noisy)',
    notify_task_due_soon BOOLEAN DEFAULT TRUE COMMENT 'Email 24h before due date',
    notify_task_overdue BOOLEAN DEFAULT TRUE COMMENT 'Email when task becomes overdue',
    notify_task_priority_changed BOOLEAN DEFAULT TRUE COMMENT 'Email when priority changes to high/critical',
    notify_task_completed BOOLEAN DEFAULT FALSE COMMENT 'Email when task completed',

    -- Digest options (future feature)
    email_digest_enabled BOOLEAN DEFAULT FALSE COMMENT 'Send daily digest instead of real-time',
    email_digest_time TIME DEFAULT '09:00:00' COMMENT 'Time to send daily digest',

    -- Quiet hours (future feature)
    quiet_hours_enabled BOOLEAN DEFAULT FALSE COMMENT 'Enable quiet hours',
    quiet_hours_start TIME DEFAULT '22:00:00' COMMENT 'Start of quiet hours',
    quiet_hours_end TIME DEFAULT '08:00:00' COMMENT 'End of quiet hours',

    -- Soft delete
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete timestamp',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When preferences created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',

    -- Foreign Keys
    CONSTRAINT fk_user_notification_preferences_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_notification_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Unique constraint: one preference record per user
    UNIQUE KEY uk_user_notification_preferences_user (user_id, deleted_at),

    -- Indexes
    INDEX idx_user_notification_preferences_tenant (tenant_id),
    INDEX idx_user_notification_preferences_deleted (deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User preferences for task email notifications';

-- ============================================================================
-- INITIAL DATA: Default notification preferences for existing users
-- ============================================================================

-- Create default preferences for all existing users who don't have them yet
INSERT IGNORE INTO user_notification_preferences (
    tenant_id,
    user_id,
    notify_task_created,
    notify_task_assigned,
    notify_task_removed,
    notify_task_updated,
    notify_task_status_changed,
    notify_task_comment_added,
    notify_task_due_soon,
    notify_task_overdue,
    notify_task_priority_changed,
    notify_task_completed,
    created_at,
    updated_at
)
SELECT
    u.tenant_id,
    u.id AS user_id,
    TRUE,  -- notify_task_created
    TRUE,  -- notify_task_assigned
    TRUE,  -- notify_task_removed
    TRUE,  -- notify_task_updated
    TRUE,  -- notify_task_status_changed
    FALSE, -- notify_task_comment_added (can be noisy)
    TRUE,  -- notify_task_due_soon
    TRUE,  -- notify_task_overdue
    TRUE,  -- notify_task_priority_changed
    FALSE, -- notify_task_completed
    NOW(),
    NOW()
FROM users u
WHERE u.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM user_notification_preferences unp
      WHERE unp.user_id = u.id
        AND unp.deleted_at IS NULL
  );

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify tables created successfully
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME,
    TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('task_notifications', 'user_notification_preferences')
ORDER BY TABLE_NAME;

-- Verify indexes
SELECT
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('task_notifications', 'user_notification_preferences')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Verify foreign keys
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    DELETE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
  AND kcu.TABLE_NAME IN ('task_notifications', 'user_notification_preferences')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- Count default preferences created
SELECT COUNT(*) AS default_preferences_created
FROM user_notification_preferences
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================

SELECT 'Task Notification System Schema Migration Completed Successfully!' AS status;
