-- ============================================
-- Module: Event Participants Management
-- Version: 2025-11-18
-- Author: Database Architect
-- Description: Create event_participants table for managing event participants and invitations
--
-- This migration adds the event_participants table to manage:
-- - Event invitations (pending, accepted, declined, tentative)
-- - RSVP status tracking
-- - Participant-event relationships
--
-- Referenced by:
-- - includes/calendar.php (getEventsBetween, inviteUsers, deleteEvent, checkConflicts)
-- - Email notification system (event invitations, reminders)
-- ============================================

USE collaboranexio;

-- Verify prerequisites
SELECT 'Checking prerequisites...' as status;
SELECT COUNT(*) as tenant_count FROM tenants;
SELECT COUNT(*) as events_count FROM events;

-- ============================================
-- TABLE CREATION: event_participants
-- ============================================

SELECT 'Creating event_participants table...' as status;

CREATE TABLE IF NOT EXISTS event_participants (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY - except tenants table)
    tenant_id INT UNSIGNED NOT NULL,

    -- Relationship fields
    event_id INT UNSIGNED NOT NULL COMMENT 'Event being attended',
    user_id INT UNSIGNED NOT NULL COMMENT 'Participant user',

    -- RSVP status
    status ENUM('pending', 'accepted', 'declined', 'tentative', 'cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'pending=awaiting response, accepted=confirmed attendance, declined=rejected, tentative=maybe, cancelled=event cancelled',

    -- Invitation tracking
    invited_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When participant was invited',
    responded_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When participant responded (accepted/declined)',
    response_note TEXT NULL COMMENT 'Optional note from participant (reason for decline, etc.)',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_event_participants_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_participants_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_participants_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_participants_tenant_created (tenant_id, created_at),
    INDEX idx_participants_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_participants_event (event_id),
    INDEX idx_participants_user (user_id),
    INDEX idx_participants_status (status),

    -- Unique constraint: one participation per user per event (allows soft delete)
    UNIQUE INDEX uk_participants_event_user (event_id, user_id, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Event participants for managing invitations, RSVP status, and attendance tracking';

SELECT 'event_participants table created successfully' as status;

-- ============================================
-- DEMO DATA (only if table is empty)
-- ============================================

SELECT 'Checking if demo data needed...' as status;

-- Link existing event organizers as automatic participants (auto-accepted)
INSERT INTO event_participants (tenant_id, event_id, user_id, status, invited_at, responded_at, created_at)
SELECT DISTINCT
    e.tenant_id,
    e.id as event_id,
    e.created_by as user_id,
    'accepted' as status,
    e.created_at as invited_at,
    e.created_at as responded_at,
    NOW() as created_at
FROM events e
LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.user_id = e.created_by AND ep.deleted_at IS NULL
WHERE e.deleted_at IS NULL
  AND e.created_by IS NOT NULL
  AND ep.id IS NULL
LIMIT 100;

SELECT 'Event organizers added as auto-accepted participants' as status;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '=== MIGRATION VERIFICATION ===' as status;

SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM event_participants WHERE deleted_at IS NULL) as active_participants,
       (SELECT COUNT(DISTINCT event_id) FROM event_participants WHERE deleted_at IS NULL) as events_with_participants,
       (SELECT COUNT(DISTINCT user_id) FROM event_participants WHERE deleted_at IS NULL) as unique_participants,
       NOW() as executed_at;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SHOW INDEX FROM event_participants WHERE Key_name LIKE 'idx_%' OR Key_name LIKE 'uk_%';

-- Verify foreign keys
SELECT 'Verifying foreign keys...' as status;
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'event_participants'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- Check multi-tenant compliance
SELECT 'Checking multi-tenant compliance...' as status;
SELECT COUNT(*) as null_tenant_violations
FROM event_participants
WHERE tenant_id IS NULL;

-- Verify CASCADE constraints working
SELECT 'Verifying CASCADE constraints...' as status;
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'event_participants';

-- Status distribution
SELECT 'RSVP status distribution:' as status;
SELECT
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM event_participants WHERE deleted_at IS NULL), 2) as percentage
FROM event_participants
WHERE deleted_at IS NULL
GROUP BY status
ORDER BY count DESC;

SELECT '=== MIGRATION COMPLETE ===' as status;
SELECT 'event_participants table is production ready' as result;
