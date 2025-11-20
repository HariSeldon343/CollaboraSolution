-- ============================================
-- Module: Event Reminders Management
-- Version: 2025-11-18
-- Author: Database Architect
-- Description: Create event_reminders table for managing event notifications and reminders
--
-- This migration adds the event_reminders table to manage:
-- - Email reminders (sent before event start)
-- - Push notifications (in-app alerts)
-- - SMS reminders (future feature)
-- - Reminder scheduling (minutes before event)
-- - Delivery tracking (sent/pending status)
--
-- Referenced by:
-- - includes/calendar.php (getEventsBetween, sendReminders, sendReminderEmail)
-- - Scheduled tasks (cron job for sending reminders)
-- ============================================

USE collaboranexio;

-- Verify prerequisites
SELECT 'Checking prerequisites...' as status;
SELECT COUNT(*) as tenant_count FROM tenants;
SELECT COUNT(*) as events_count FROM events;

-- ============================================
-- TABLE CREATION: event_reminders
-- ============================================

SELECT 'Creating event_reminders table...' as status;

CREATE TABLE IF NOT EXISTS event_reminders (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY - except tenants table)
    tenant_id INT UNSIGNED NOT NULL,

    -- Relationship field
    event_id INT UNSIGNED NOT NULL COMMENT 'Event for which reminder is set',

    -- Reminder configuration
    type ENUM('email', 'notification', 'sms') NOT NULL DEFAULT 'email'
        COMMENT 'email=email notification, notification=in-app push, sms=SMS (future)',
    minutes_before INT UNSIGNED NOT NULL DEFAULT 15
        COMMENT 'Minutes before event start to send reminder (e.g., 15, 30, 60, 1440 for 1 day)',

    -- Delivery tracking
    is_sent BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Has reminder been sent?',
    sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When reminder was actually sent',
    send_after TIMESTAMP NULL DEFAULT NULL COMMENT 'Calculated timestamp when reminder should be sent (event start - minutes_before)',

    -- Error tracking
    send_attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of delivery attempts',
    last_error TEXT NULL COMMENT 'Last error message if delivery failed',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_event_reminders_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_reminders_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_reminders_tenant_created (tenant_id, created_at),
    INDEX idx_reminders_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_reminders_event (event_id),
    INDEX idx_reminders_type (type),

    -- Critical index for scheduled reminder processing
    INDEX idx_reminders_pending (is_sent, send_after, deleted_at)
        COMMENT 'Optimizes query: SELECT * FROM event_reminders WHERE is_sent=0 AND send_after <= NOW() AND deleted_at IS NULL',

    -- Index for reminder status monitoring
    INDEX idx_reminders_sent_status (is_sent, sent_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Event reminders for email/notification/SMS delivery scheduling and tracking';

SELECT 'event_reminders table created successfully' as status;

-- ============================================
-- DEMO DATA (only if table is empty)
-- ============================================

SELECT 'Checking if demo data needed...' as status;

-- Create default reminders for upcoming events (15 minutes before)
INSERT INTO event_reminders (tenant_id, event_id, type, minutes_before, send_after, created_at)
SELECT DISTINCT
    e.tenant_id,
    e.id as event_id,
    'email' as type,
    15 as minutes_before,
    DATE_SUB(e.start_datetime, INTERVAL 15 MINUTE) as send_after,
    NOW() as created_at
FROM events e
LEFT JOIN event_reminders er ON er.event_id = e.id AND er.deleted_at IS NULL
WHERE e.deleted_at IS NULL
  AND e.start_datetime > NOW()
  AND e.status = 'confirmed'
  AND er.id IS NULL
LIMIT 50;

SELECT 'Default reminders created for upcoming events' as status;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '=== MIGRATION VERIFICATION ===' as status;

SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM event_reminders WHERE deleted_at IS NULL) as active_reminders,
       (SELECT COUNT(*) FROM event_reminders WHERE is_sent = FALSE AND deleted_at IS NULL) as pending_reminders,
       (SELECT COUNT(*) FROM event_reminders WHERE is_sent = TRUE AND deleted_at IS NULL) as sent_reminders,
       (SELECT COUNT(DISTINCT event_id) FROM event_reminders WHERE deleted_at IS NULL) as events_with_reminders,
       NOW() as executed_at;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SHOW INDEX FROM event_reminders WHERE Key_name LIKE 'idx_%';

-- Verify foreign keys
SELECT 'Verifying foreign keys...' as status;
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'event_reminders'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- Check multi-tenant compliance
SELECT 'Checking multi-tenant compliance...' as status;
SELECT COUNT(*) as null_tenant_violations
FROM event_reminders
WHERE tenant_id IS NULL;

-- Verify CASCADE constraints working
SELECT 'Verifying CASCADE constraints...' as status;
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'event_reminders';

-- Reminder type distribution
SELECT 'Reminder type distribution:' as status;
SELECT
    type,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM event_reminders WHERE deleted_at IS NULL), 2) as percentage
FROM event_reminders
WHERE deleted_at IS NULL
GROUP BY type
ORDER BY count DESC;

-- Delivery status report
SELECT 'Delivery status report:' as status;
SELECT
    CASE WHEN is_sent = TRUE THEN 'Sent' ELSE 'Pending' END as delivery_status,
    COUNT(*) as count,
    MIN(send_after) as earliest_send_time,
    MAX(send_after) as latest_send_time
FROM event_reminders
WHERE deleted_at IS NULL
GROUP BY is_sent;

-- Minutes before distribution
SELECT 'Minutes before event distribution:' as status;
SELECT
    minutes_before,
    COUNT(*) as count,
    CASE
        WHEN minutes_before = 15 THEN '15 minutes before'
        WHEN minutes_before = 30 THEN '30 minutes before'
        WHEN minutes_before = 60 THEN '1 hour before'
        WHEN minutes_before = 1440 THEN '1 day before'
        ELSE CONCAT(minutes_before, ' minutes before')
    END as description
FROM event_reminders
WHERE deleted_at IS NULL
GROUP BY minutes_before
ORDER BY minutes_before ASC;

-- Check pending reminders ready to send
SELECT 'Pending reminders ready to send (next hour):' as status;
SELECT COUNT(*) as ready_to_send_count
FROM event_reminders
WHERE is_sent = FALSE
  AND send_after <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
  AND deleted_at IS NULL;

SELECT '=== MIGRATION COMPLETE ===' as status;
SELECT 'event_reminders table is production ready' as result;
