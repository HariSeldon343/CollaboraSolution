-- ============================================
-- Module: Calendar Management System
-- Version: 2025-11-17
-- Author: Database Architect
-- Description: Create calendars table for multi-calendar support
--
-- This migration adds a calendars table to organize events into
-- separate calendars (personal, team, shared, etc.).
--
-- The existing calendar_events table will be refactored to add
-- calendar_id FK to link events to specific calendars.
-- ============================================

USE collaboranexio;

-- Verify tenant table exists
SELECT 'Checking tenants table...' as status;
SELECT COUNT(*) as tenant_count FROM tenants;

-- ============================================
-- TABLE CREATION: calendars
-- ============================================

SELECT 'Creating calendars table...' as status;

CREATE TABLE IF NOT EXISTS calendars (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY - except tenants table)
    tenant_id INT UNSIGNED NOT NULL,

    -- Core calendar fields
    name VARCHAR(255) NOT NULL COMMENT 'Calendar name (e.g., "Personal", "Team Sales", "Holidays")',
    description TEXT NULL COMMENT 'Optional calendar description',
    color VARCHAR(7) NOT NULL DEFAULT '#3B82F6' COMMENT 'Hex color code for calendar (e.g., #FF5733)',

    -- Ownership
    owner_id INT UNSIGNED NOT NULL COMMENT 'User who created/owns this calendar',

    -- Visibility control
    visibility ENUM('private', 'shared', 'public') NOT NULL DEFAULT 'private' COMMENT 'private=owner only, shared=specific users, public=all tenant users',
    is_default BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Is this the default calendar for the owner?',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_calendars_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendars_owner FOREIGN KEY (owner_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_calendars_tenant_created (tenant_id, created_at),
    INDEX idx_calendars_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_calendars_owner (owner_id),
    INDEX idx_calendars_visibility (visibility),

    -- Unique constraint: one default calendar per owner
    UNIQUE INDEX uk_calendars_default_owner (owner_id, is_default, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Calendar entities for organizing events (personal, team, shared calendars)';

SELECT 'calendars table created successfully' as status;

-- ============================================
-- MODIFY EXISTING TABLE: calendar_events
-- Add calendar_id FK to link events to calendars
-- ============================================

SELECT 'Checking if calendar_id column exists in calendar_events...' as status;

-- Check if column already exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_events'
      AND COLUMN_NAME = 'calendar_id'
);

-- Add column if it doesn't exist
SELECT IF(@column_exists > 0,
    'calendar_id column already exists',
    'Adding calendar_id column...') as status;

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE calendar_events ADD COLUMN calendar_id INT UNSIGNED NULL AFTER id',
    'SELECT "Column already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add FK constraint if not exists
SELECT 'Adding foreign key constraint for calendar_id...' as status;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_events'
      AND CONSTRAINT_NAME = 'fk_events_calendar'
);

SET @sql_fk = IF(@fk_exists = 0,
    'ALTER TABLE calendar_events ADD CONSTRAINT fk_events_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE',
    'SELECT "FK constraint already exists" as status'
);

PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- Add index for performance
SELECT 'Adding index for calendar_id...' as status;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_events'
      AND INDEX_NAME = 'idx_events_calendar'
);

SET @sql_idx = IF(@idx_exists = 0,
    'CREATE INDEX idx_events_calendar ON calendar_events(calendar_id, start_datetime)',
    'SELECT "Index already exists" as status'
);

PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- ============================================
-- TABLE CREATION: calendar_permissions
-- For granular sharing control (shared visibility)
-- ============================================

SELECT 'Creating calendar_permissions table...' as status;

CREATE TABLE IF NOT EXISTS calendar_permissions (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Permission fields
    calendar_id INT UNSIGNED NOT NULL COMMENT 'Calendar being shared',
    user_id INT UNSIGNED NOT NULL COMMENT 'User granted access',
    permission_level ENUM('read', 'write', 'admin') NOT NULL DEFAULT 'read' COMMENT 'read=view only, write=add events, admin=manage calendar',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_calperm_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calperm_calendar FOREIGN KEY (calendar_id)
        REFERENCES calendars(id) ON DELETE CASCADE,
    CONSTRAINT fk_calperm_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_calperm_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_calperm_calendar (calendar_id),
    INDEX idx_calperm_user (user_id),

    -- Unique constraint: one permission per user per calendar
    UNIQUE INDEX uk_calperm_calendar_user (calendar_id, user_id, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Granular calendar sharing permissions (for shared visibility calendars)';

SELECT 'calendar_permissions table created successfully' as status;

-- ============================================
-- DEMO DATA (only if calendars table is empty)
-- ============================================

SELECT 'Checking if demo data needed...' as status;

-- Create default calendars for existing users
INSERT INTO calendars (tenant_id, name, description, color, owner_id, visibility, is_default, created_at)
SELECT
    u.tenant_id,
    CONCAT(u.name, ' - Calendario Personale') as name,
    'Calendario personale generato automaticamente' as description,
    '#3B82F6' as color,
    u.id as owner_id,
    'private' as visibility,
    TRUE as is_default,
    NOW() as created_at
FROM users u
LEFT JOIN calendars c ON c.owner_id = u.id AND c.is_default = TRUE AND c.deleted_at IS NULL
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND c.id IS NULL
LIMIT 100;

SELECT 'Demo calendars created for existing users' as status;

-- Update existing calendar_events to link to owner's default calendar
UPDATE calendar_events ce
INNER JOIN calendars c ON c.owner_id = ce.organizer_id
    AND c.tenant_id = ce.tenant_id
    AND c.is_default = TRUE
    AND c.deleted_at IS NULL
SET ce.calendar_id = c.id
WHERE ce.calendar_id IS NULL
  AND ce.deleted_at IS NULL;

SELECT 'Existing events linked to default calendars' as status;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM calendars WHERE deleted_at IS NULL) as active_calendars,
       (SELECT COUNT(*) FROM calendar_events WHERE calendar_id IS NOT NULL AND deleted_at IS NULL) as linked_events,
       (SELECT COUNT(*) FROM calendar_permissions WHERE deleted_at IS NULL) as active_permissions,
       NOW() as executed_at;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SHOW INDEX FROM calendars WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM calendar_events WHERE Key_name = 'idx_events_calendar';
SHOW INDEX FROM calendar_permissions WHERE Key_name LIKE 'idx_%';

-- Verify foreign keys
SELECT 'Verifying foreign keys...' as status;
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('calendars', 'calendar_events', 'calendar_permissions')
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- Check orphaned events (events without calendar)
SELECT 'Checking for orphaned events...' as status;
SELECT COUNT(*) as orphaned_events
FROM calendar_events
WHERE calendar_id IS NULL
  AND deleted_at IS NULL;

SELECT '=== MIGRATION COMPLETE ===' as status;
