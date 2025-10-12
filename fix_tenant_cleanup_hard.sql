-- ============================================
-- HARD DELETE TENANT CLEANUP (USE WITH CAUTION)
-- ============================================
-- Module: Tenant Hard Delete Fix
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: HARD DELETE all tenants except ID 11
--
-- WARNING: This PERMANENTLY DELETES data!
-- Use ONLY if soft delete doesn't work
--
-- BEFORE RUNNING:
-- 1. Backup your database
-- 2. Try fix_tenant_cleanup.sql (soft delete) first
-- 3. Understand this is IRREVERSIBLE
-- ============================================

USE collaboranexio;

-- Safety check
SELECT '=== WARNING: HARD DELETE SCRIPT ===' as warning_message;
SELECT 'This will PERMANENTLY delete tenant data!' as warning;
SELECT 'Press Ctrl+C to cancel, or continue to execute' as action;

-- Wait 5 seconds (manual execution only)
-- SELECT SLEEP(5);

-- Show current state
SELECT '=== CURRENT TENANTS (BEFORE HARD DELETE) ===' as status;
SELECT id, name, status, deleted_at FROM tenants ORDER BY id;

-- ============================================
-- BACKUP DATA BEFORE DELETION
-- ============================================

SELECT '=== CREATING BACKUP TABLES ===' as status;

-- Backup tenants to be deleted
CREATE TABLE IF NOT EXISTS tenants_backup_20251012 AS
SELECT * FROM tenants WHERE id != 11;

SELECT
    'Backed up to tenants_backup_20251012' as backup_status,
    (SELECT COUNT(*) FROM tenants_backup_20251012) as backed_up_count;

-- ============================================
-- DISABLE FOREIGN KEY CHECKS (TEMPORARY)
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- HARD DELETE RELATED DATA
-- ============================================

SELECT '=== DELETING RELATED DATA ===' as status;

-- Delete users from other tenants
DELETE FROM users WHERE tenant_id != 11;
SELECT ROW_COUNT() as users_deleted;

-- Delete user_tenant_access for other tenants
DELETE FROM user_tenant_access WHERE tenant_id != 11;
SELECT ROW_COUNT() as user_accesses_deleted;

-- Delete folders from other tenants
DELETE FROM folders WHERE tenant_id != 11;
SELECT ROW_COUNT() as folders_deleted;

-- Delete files from other tenants
DELETE FROM files WHERE tenant_id != 11;
SELECT ROW_COUNT() as files_deleted;

-- Delete projects from other tenants
DELETE FROM projects WHERE tenant_id != 11;
SELECT ROW_COUNT() as projects_deleted;

-- Delete tasks from other tenants
DELETE FROM tasks WHERE tenant_id != 11;
SELECT ROW_COUNT() as tasks_deleted;

-- Delete calendar events from other tenants
DELETE FROM calendar_events WHERE tenant_id != 11;
SELECT ROW_COUNT() as calendar_events_deleted;

-- Delete chat channels from other tenants
DELETE FROM chat_channels WHERE tenant_id != 11;
SELECT ROW_COUNT() as chat_channels_deleted;

-- Delete chat messages from other tenants
DELETE FROM chat_messages WHERE tenant_id != 11;
SELECT ROW_COUNT() as chat_messages_deleted;

-- Delete document approvals from other tenants
DELETE FROM document_approvals WHERE tenant_id != 11;
SELECT ROW_COUNT() as document_approvals_deleted;

-- Delete audit logs from other tenants (optional - for compliance, keep these)
-- DELETE FROM audit_logs WHERE tenant_id != 11;
-- SELECT ROW_COUNT() as audit_logs_deleted;

-- Delete notifications from other tenants
DELETE FROM notifications WHERE tenant_id != 11;
SELECT ROW_COUNT() as notifications_deleted;

-- Delete tenant locations from other tenants
DELETE FROM tenant_locations WHERE tenant_id != 11;
SELECT ROW_COUNT() as tenant_locations_deleted;

-- ============================================
-- DELETE TENANTS (EXCEPT ID 11)
-- ============================================

SELECT '=== DELETING TENANTS ===' as status;

DELETE FROM tenants WHERE id != 11;
SELECT ROW_COUNT() as tenants_deleted;

-- ============================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFY TENANT ID 11 STILL EXISTS
-- ============================================

SELECT '=== VERIFYING TENANT ID 11 ===' as status;

SELECT
    id,
    name,
    status,
    deleted_at,
    'ACTIVE ✓' as state
FROM tenants
WHERE id = 11;

-- Ensure tenant 11 is active
UPDATE tenants
SET
    deleted_at = NULL,
    status = 'active',
    updated_at = NOW()
WHERE id = 11;

-- ============================================
-- FINAL VERIFICATION
-- ============================================

SELECT '=== FINAL STATE ===' as status;

SELECT COUNT(*) as remaining_tenants FROM tenants;
SELECT * FROM tenants ORDER BY id;

-- API query simulation
SELECT '=== API QUERY RESULT ===' as status;
SELECT
    id,
    name,
    CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
    status
FROM tenants
WHERE deleted_at IS NULL
AND status != 'suspended'
ORDER BY name;

-- ============================================
-- RESET AUTO_INCREMENT (OPTIONAL)
-- ============================================

-- Uncomment to reset auto_increment after cleanup
-- ALTER TABLE tenants AUTO_INCREMENT = 12;

-- ============================================
-- COMPLETION
-- ============================================

SELECT
    '=== HARD DELETE COMPLETE ===' as status,
    NOW() as completed_at,
    'Backup saved to: tenants_backup_20251012' as backup_location,
    'Run verify_tenant_cleanup.php to confirm' as next_step;
