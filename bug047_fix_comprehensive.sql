-- =====================================================
-- BUG-047 COMPREHENSIVE FIX
-- Issues addressed:
-- 1. Extend CHECK constraints to allow 'audit_log' entity_type
-- 2. Add missing actions for comprehensive tracking
-- 3. Prepare database for full audit coverage
-- =====================================================

USE collaboranexio;

-- Backup current constraints (for reference)
SELECT
    CONSTRAINT_NAME,
    CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='collaboranexio'
AND TABLE_NAME='audit_logs'
ORDER BY CONSTRAINT_NAME;

-- =====================================================
-- FIX 1: Extend entity_type CHECK constraint
-- Add: 'audit_log', 'audit_log_deletion', 'system', 'config'
-- =====================================================

ALTER TABLE audit_logs
DROP CONSTRAINT chk_audit_entity;

ALTER TABLE audit_logs
ADD CONSTRAINT chk_audit_entity CHECK (entity_type IN (
    -- Existing values
    'user',
    'tenant',
    'file',
    'folder',
    'project',
    'task',
    'calendar_event',
    'chat_message',
    'chat_channel',
    'document_approval',
    'system_setting',
    'notification',
    'page',
    'ticket',
    'ticket_response',
    'document',
    'editor_session',

    -- BUG-047: NEW VALUES for comprehensive audit tracking
    'audit_log',           -- Track audit log deletions (BUG-047 Issue 1)
    'audit_log_deletion',  -- Track deletion records
    'system',              -- System-level events
    'config',              -- Configuration changes
    'role',                -- Role management
    'permission',          -- Permission changes
    'location',            -- Tenant location management
    'tenant_location'      -- Tenant location assignments
));

-- =====================================================
-- FIX 2: Extend action CHECK constraint
-- Add: 'assign', 'unassign', 'complete', 'reopen', 'close'
-- =====================================================

ALTER TABLE audit_logs
DROP CONSTRAINT chk_audit_action;

ALTER TABLE audit_logs
ADD CONSTRAINT chk_audit_action CHECK (action IN (
    -- Existing values (from BUG-041)
    'create',
    'update',
    'delete',
    'restore',
    'login',
    'logout',
    'login_failed',
    'session_expired',
    'download',
    'upload',
    'view',
    'export',
    'import',
    'approve',
    'reject',
    'submit',
    'cancel',
    'share',
    'unshare',
    'permission_grant',
    'permission_revoke',
    'password_change',
    'password_reset',
    'email_change',
    'tenant_switch',
    'system_update',
    'backup',
    'restore_backup',
    'access',
    'document_opened',
    'document_closed',
    'document_saved',

    -- BUG-047: NEW VALUES for comprehensive audit tracking
    'assign',              -- Task/ticket assignment
    'unassign',            -- Task/ticket unassignment
    'complete',            -- Task completion
    'reopen',              -- Ticket/task reopening
    'close',               -- Ticket/task closing
    'comment',             -- Comment creation
    'reply',               -- Reply/response creation
    'archive',             -- Archive operation
    'unarchive',           -- Unarchive operation
    'duplicate',           -- Duplication operation
    'merge',               -- Merge operation
    'move',                -- Move operation (files/tasks)
    'rename',              -- Rename operation
    'config_change',       -- Configuration change
    'setting_change'       -- Setting change
));

-- =====================================================
-- VERIFICATION: Test insert with new values
-- =====================================================

-- Test 1: Can we insert audit_log entity_type?
INSERT INTO audit_logs (
    tenant_id,
    user_id,
    action,
    entity_type,
    entity_id,
    description,
    severity,
    status,
    created_at
) VALUES (
    1,
    1,
    'delete',
    'audit_log',
    999999,
    'TEST: Audit log deletion tracking',
    'critical',
    'success',
    NOW()
);

-- Verify insertion succeeded
SELECT
    id,
    action,
    entity_type,
    entity_id,
    description,
    created_at
FROM audit_logs
WHERE entity_type='audit_log' AND entity_id=999999
ORDER BY id DESC LIMIT 1;

-- Clean up test record
DELETE FROM audit_logs WHERE entity_type='audit_log' AND entity_id=999999;

-- Test 2: Can we insert new actions?
INSERT INTO audit_logs (
    tenant_id,
    user_id,
    action,
    entity_type,
    entity_id,
    description,
    severity,
    status,
    created_at
) VALUES
(1, 1, 'assign', 'task', 1, 'TEST: Task assignment', 'info', 'success', NOW()),
(1, 1, 'complete', 'task', 1, 'TEST: Task completion', 'info', 'success', NOW()),
(1, 1, 'close', 'ticket', 1, 'TEST: Ticket closing', 'info', 'success', NOW()),
(1, 1, 'reopen', 'ticket', 1, 'TEST: Ticket reopening', 'info', 'success', NOW());

-- Verify insertions succeeded
SELECT
    id,
    action,
    entity_type,
    description
FROM audit_logs
WHERE description LIKE 'TEST:%'
ORDER BY id DESC;

-- Clean up test records
DELETE FROM audit_logs WHERE description LIKE 'TEST:%';

-- =====================================================
-- VERIFICATION SUMMARY
-- =====================================================

SELECT
    'chk_audit_entity' as constraint_name,
    CASE
        WHEN CHECK_CLAUSE LIKE "%'audit_log'%" THEN '✓ Contains audit_log'
        ELSE '✗ Missing audit_log'
    END as status
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='collaboranexio'
AND TABLE_NAME='audit_logs'
AND CONSTRAINT_NAME='chk_audit_entity'

UNION ALL

SELECT
    'chk_audit_action' as constraint_name,
    CASE
        WHEN CHECK_CLAUSE LIKE "%'assign'%" AND CHECK_CLAUSE LIKE "%'complete'%" THEN '✓ Contains new actions'
        ELSE '✗ Missing new actions'
    END as status
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='collaboranexio'
AND TABLE_NAME='audit_logs'
AND CONSTRAINT_NAME='chk_audit_action';

-- =====================================================
-- SUCCESS MESSAGE
-- =====================================================

SELECT
    'BUG-047 FIX APPLIED SUCCESSFULLY' as message,
    NOW() as applied_at,
    'audit_logs CHECK constraints extended' as details;
