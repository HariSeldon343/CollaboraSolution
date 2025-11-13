-- ============================================
-- ROLLBACK: Support Ticket System
-- Version: 2025-10-26
-- Author: Database Architect - CollaboraNexio
-- Description: Rollback script for ticket system schema
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP RECOMMENDATION
-- ============================================

-- IMPORTANT: Create backup before rollback!
-- mysqldump -u root -p collaboranexio > backup_before_rollback_$(date +%Y%m%d_%H%M%S).sql

SELECT 'ROLLBACK WARNING: This will permanently delete all ticket data!' as warning;
SELECT 'Make sure you have a database backup before proceeding.' as recommendation;

-- ============================================
-- OPTIONAL: BACKUP TICKET DATA
-- ============================================

-- Create backup tables (uncomment if you want to preserve data)
-- CREATE TABLE IF NOT EXISTS tickets_backup AS SELECT * FROM tickets;
-- CREATE TABLE IF NOT EXISTS ticket_responses_backup AS SELECT * FROM ticket_responses;
-- CREATE TABLE IF NOT EXISTS ticket_assignments_backup AS SELECT * FROM ticket_assignments;
-- CREATE TABLE IF NOT EXISTS ticket_notifications_backup AS SELECT * FROM ticket_notifications;
-- CREATE TABLE IF NOT EXISTS ticket_history_backup AS SELECT * FROM ticket_history;

-- SELECT 'Backup tables created' as status,
--        (SELECT COUNT(*) FROM tickets_backup) as tickets_backed_up,
--        (SELECT COUNT(*) FROM ticket_responses_backup) as responses_backed_up,
--        (SELECT COUNT(*) FROM ticket_assignments_backup) as assignments_backed_up,
--        (SELECT COUNT(*) FROM ticket_notifications_backup) as notifications_backed_up,
--        (SELECT COUNT(*) FROM ticket_history_backup) as history_backed_up;

-- ============================================
-- DROP TABLES (REVERSE FK DEPENDENCY ORDER)
-- ============================================

-- Drop child tables first (have foreign keys TO parent tables)

SELECT 'Dropping ticket_history table...' as status;
DROP TABLE IF EXISTS ticket_history;

SELECT 'Dropping ticket_notifications table...' as status;
DROP TABLE IF EXISTS ticket_notifications;

SELECT 'Dropping ticket_assignments table...' as status;
DROP TABLE IF EXISTS ticket_assignments;

SELECT 'Dropping ticket_responses table...' as status;
DROP TABLE IF EXISTS ticket_responses;

-- Drop parent table last
SELECT 'Dropping tickets table...' as status;
DROP TABLE IF EXISTS tickets;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Rollback completed successfully' as status,
       NOW() as executed_at;

-- Verify tables no longer exist
SELECT 'Verifying tables removed...' as status;

SELECT COUNT(*) as remaining_ticket_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME LIKE 'ticket%';
-- Should return 0 if rollback successful

-- ============================================
-- RESTORE INSTRUCTIONS (IF NEEDED)
-- ============================================

/*
If you need to restore from backup after rollback:

1. Restore full database backup:
   mysql -u root -p collaboranexio < backup_before_rollback_YYYYMMDD_HHMMSS.sql

2. Or restore only ticket tables from backup tables:
   CREATE TABLE tickets AS SELECT * FROM tickets_backup;
   CREATE TABLE ticket_responses AS SELECT * FROM ticket_responses_backup;
   CREATE TABLE ticket_assignments AS SELECT * FROM ticket_assignments_backup;
   CREATE TABLE ticket_notifications AS SELECT * FROM ticket_notifications_backup;
   CREATE TABLE ticket_history AS SELECT * FROM ticket_history_backup;

   -- Then re-run schema migration to recreate indexes and foreign keys:
   source database/migrations/ticket_system_schema.sql;

3. Verify restoration:
   SELECT COUNT(*) FROM tickets;
   SHOW INDEX FROM tickets;
*/

-- ============================================
-- CLEANUP BACKUP TABLES (OPTIONAL)
-- ============================================

-- Uncomment to remove backup tables after successful verification
-- DROP TABLE IF EXISTS tickets_backup;
-- DROP TABLE IF EXISTS ticket_responses_backup;
-- DROP TABLE IF EXISTS ticket_assignments_backup;
-- DROP TABLE IF EXISTS ticket_notifications_backup;
-- DROP TABLE IF EXISTS ticket_history_backup;

-- SELECT 'Backup tables removed' as status;

-- ============================================
-- END OF ROLLBACK
-- ============================================
