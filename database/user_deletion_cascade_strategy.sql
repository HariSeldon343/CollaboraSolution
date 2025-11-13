-- ============================================
-- USER DELETION CASCADE STRATEGY
-- ============================================
-- Module: User Cleanup
-- Version: 2025-10-04
-- Author: Database Architect
-- Description: Documentation of foreign key constraints and deletion strategy for users table

USE collaboranexio;

-- ============================================
-- FOREIGN KEY CONSTRAINTS ANALYSIS
-- ============================================

/*
This document outlines all 28 foreign key constraints that reference users(id)
and the proper order for cascading deletes.

CONSTRAINT TYPES:
- RESTRICT: Prevents deletion if child records exist (must be handled manually)
- CASCADE: Automatically deletes child records when parent is deleted
- SET NULL: Sets foreign key to NULL when parent is deleted

DELETION STRATEGY (3 PHASES):
1. Delete records from tables with RESTRICT constraints
2. Delete records from tables with CASCADE constraints (explicit)
3. Update records in tables with SET NULL constraints
4. Finally delete the user record
*/

-- ============================================
-- PHASE 1: RESTRICT CONSTRAINTS (Must Delete First)
-- ============================================

/*
These tables have ON DELETE RESTRICT, meaning the user cannot be deleted
until all child records are removed.
*/

-- 1. chat_channels.owner_id → users.id (RESTRICT)
--    When deleting a user, their owned chat channels must be deleted first
--    This also cascades to: chat_messages, chat_channel_members, chat_message_reads

-- 2. file_versions.uploaded_by → users.id (RESTRICT)
--    File version history must be deleted before user deletion

-- 3. folders.owner_id → users.id (RESTRICT)
--    User-owned folders must be deleted before user deletion

-- 4. projects.owner_id → users.id (RESTRICT)
--    User-owned projects must be deleted before user deletion

-- 5. project_members.added_by → users.id (RESTRICT)
--    Set to NULL for project members added by this user

-- 6. tasks.created_by → users.id (RESTRICT)
--    Set to NULL for tasks created by this user

-- 7. task_assignments.assigned_by → users.id (RESTRICT)
--    Set to NULL for task assignments assigned by this user

-- ============================================
-- PHASE 2: CASCADE CONSTRAINTS (Auto-Delete but Explicit)
-- ============================================

/*
These will auto-delete when user is removed, but we delete explicitly
for clarity and to ensure clean removal.
*/

-- 8. approval_notifications.user_id → users.id (CASCADE)
-- 9. calendar_events.organizer_id → users.id (CASCADE)
-- 10. calendar_shares.user_id → users.id (CASCADE)
-- 11. chat_channel_members.user_id → users.id (CASCADE)
-- 12. chat_messages.user_id → users.id (CASCADE)
-- 13. chat_message_reads.user_id → users.id (CASCADE)
-- 14. document_approvals.requested_by → users.id (CASCADE)
-- 15. file_shares.shared_by → users.id (CASCADE)
-- 16. file_shares.shared_with → users.id (CASCADE)
-- 17. password_expiry_notifications.user_id → users.id (CASCADE)
-- 18. project_members.user_id → users.id (CASCADE)
-- 19. task_assignments.user_id → users.id (CASCADE)
-- 20. task_comments.user_id → users.id (CASCADE)
-- 21. user_permissions.user_id → users.id (CASCADE)
-- 22. user_tenant_access.user_id → users.id (CASCADE)

-- ============================================
-- PHASE 3: SET NULL CONSTRAINTS (Update to NULL)
-- ============================================

/*
These fields will be set to NULL automatically when user is deleted,
but we do it explicitly for clarity.
*/

-- 23. audit_logs.user_id → users.id (SET NULL)
--     Keep audit trail but anonymize the user

-- 24. document_approvals.reviewed_by → users.id (SET NULL)
--     Keep approval history but anonymize the reviewer

-- 25. files.uploaded_by → users.id (SET NULL)
--     Keep files but anonymize the uploader

-- 26. tasks.assigned_to → users.id (SET NULL)
--     Unassign tasks from deleted user

-- 27. user_permissions.granted_by → users.id (SET NULL)
--     Keep permissions but anonymize who granted them

-- 28. user_tenant_access.granted_by → users.id (SET NULL)
--     Keep tenant access but anonymize who granted it

-- ============================================
-- DELETION ORDER (Critical for Success)
-- ============================================

/*
STEP 1: Delete owned resources with RESTRICT constraints
*/
DELETE FROM chat_channels WHERE owner_id = ?;
DELETE FROM file_versions WHERE uploaded_by = ?;
DELETE FROM folders WHERE owner_id = ?;
DELETE FROM projects WHERE owner_id = ?;

UPDATE project_members SET added_by = NULL WHERE added_by = ?;
UPDATE tasks SET created_by = NULL WHERE created_by = ?;
UPDATE task_assignments SET assigned_by = NULL WHERE assigned_by = ?;

/*
STEP 2: Explicitly delete CASCADE records
*/
DELETE FROM project_members WHERE user_id = ?;
DELETE FROM task_assignments WHERE user_id = ?;
DELETE FROM task_comments WHERE user_id = ?;
DELETE FROM chat_channel_members WHERE user_id = ?;
DELETE FROM chat_messages WHERE user_id = ?;
DELETE FROM chat_message_reads WHERE user_id = ?;
DELETE FROM file_shares WHERE shared_by = ? OR shared_with = ?;
DELETE FROM calendar_events WHERE organizer_id = ?;
DELETE FROM calendar_shares WHERE user_id = ?;
DELETE FROM document_approvals WHERE requested_by = ?;
DELETE FROM approval_notifications WHERE user_id = ?;
DELETE FROM password_expiry_notifications WHERE user_id = ?;
DELETE FROM user_permissions WHERE user_id = ?;
DELETE FROM user_tenant_access WHERE user_id = ?;

/*
STEP 3: Update SET NULL references
*/
UPDATE document_approvals SET reviewed_by = NULL WHERE reviewed_by = ?;
UPDATE user_permissions SET granted_by = NULL WHERE granted_by = ?;
UPDATE user_tenant_access SET granted_by = NULL WHERE granted_by = ?;
UPDATE files SET uploaded_by = NULL WHERE uploaded_by = ?;
UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?;
UPDATE audit_logs SET user_id = NULL WHERE user_id = ?;

/*
STEP 4: Finally delete the user
*/
DELETE FROM users WHERE id = ?;

-- ============================================
-- TRANSACTION EXAMPLE
-- ============================================

/*
Always use a transaction to ensure atomicity:

START TRANSACTION;

-- Perform all deletion steps in order
-- ... (all steps above)

-- If all successful
COMMIT;

-- If any error occurs
ROLLBACK;
*/

-- ============================================
-- VERIFICATION QUERY
-- ============================================

/*
After deletion, verify no orphaned records remain:
*/

SELECT
    'Orphaned Records Check' as check_type,
    COUNT(*) as orphaned_count
FROM (
    SELECT 'chat_channels' as table_name, COUNT(*) as cnt
    FROM chat_channels WHERE owner_id NOT IN (SELECT id FROM users) AND owner_id IS NOT NULL

    UNION ALL

    SELECT 'file_versions', COUNT(*)
    FROM file_versions WHERE uploaded_by NOT IN (SELECT id FROM users) AND uploaded_by IS NOT NULL

    UNION ALL

    SELECT 'folders', COUNT(*)
    FROM folders WHERE owner_id NOT IN (SELECT id FROM users) AND owner_id IS NOT NULL

    UNION ALL

    SELECT 'projects', COUNT(*)
    FROM projects WHERE owner_id NOT IN (SELECT id FROM users) AND owner_id IS NOT NULL
) orphan_check
WHERE cnt > 0;

-- ============================================
-- NOTES
-- ============================================

/*
1. Always wrap deletion in a transaction for atomicity
2. The order of deletion is critical - RESTRICT constraints first
3. Some CASCADE deletes happen automatically, but explicit is better for clarity
4. SET NULL allows data preservation while removing user references
5. Test with a backup database before running on production
6. Consider soft-delete (deleted_at timestamp) before hard delete
7. Maintain audit logs even after user deletion (user_id SET NULL)
*/
