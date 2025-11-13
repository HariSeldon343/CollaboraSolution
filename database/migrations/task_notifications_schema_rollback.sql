-- ============================================================================
-- TASK NOTIFICATION SYSTEM SCHEMA ROLLBACK
-- ============================================================================
-- Version: 1.0.0
-- Date: 2025-10-25
-- Author: Claude Code - Staff Engineer
-- Description: Rollback script for task notification system
--
-- WARNING: This will delete all notification data and user preferences!
-- ============================================================================

USE collaboranexio;

-- ============================================================================
-- BACKUP RECOMMENDATIONS
-- ============================================================================

-- Before running this rollback, consider backing up the data:
-- mysqldump -u root collaboranexio task_notifications user_notification_preferences > task_notifications_backup.sql

-- ============================================================================
-- DROP TABLES (in reverse order of creation to respect FK constraints)
-- ============================================================================

-- Drop task_notifications table (has FKs to user_notification_preferences)
DROP TABLE IF EXISTS task_notifications;

-- Drop user_notification_preferences table
DROP TABLE IF EXISTS user_notification_preferences;

-- ============================================================================
-- VERIFICATION
-- ============================================================================

-- Verify tables were dropped
SELECT
    TABLE_NAME,
    TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('task_notifications', 'user_notification_preferences');

-- If the above query returns 0 rows, rollback was successful

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================

SELECT 'Task Notification System Schema Rollback Completed!' AS status,
       'All notification data has been deleted.' AS warning;
