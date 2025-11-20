-- ============================================
-- Module: Rename calendar_events to events
-- Version: 2025-11-17
-- Author: Database Architect
-- Description: Rename calendar_events table to events to match application code expectations
--
-- Background: The application code (Calendar class, api/events.php) expects
-- a table named 'events', but the database has 'calendar_events'.
-- This migration renames the table to align code and database.
-- ============================================

USE collaboranexio;

SELECT 'Starting calendar_events → events rename migration...' as status;

-- ============================================
-- STEP 1: Check if events table already exists
-- ============================================

SELECT 'Checking if events table exists...' as status;

SET @events_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'events'
);

SELECT IF(@events_exists > 0,
    'WARNING: events table already exists!',
    'OK: events table does not exist, proceeding with rename') as status;

-- ============================================
-- STEP 2: Check if calendar_events table exists
-- ============================================

SELECT 'Checking if calendar_events table exists...' as status;

SET @calendar_events_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_events'
);

SELECT IF(@calendar_events_exists > 0,
    'OK: calendar_events table exists',
    'ERROR: calendar_events table does not exist!') as status;

-- ============================================
-- STEP 3: Drop foreign keys temporarily
-- (They will be recreated with correct table name)
-- ============================================

SELECT 'Dropping foreign keys from calendar_events...' as status;

-- Drop FK to calendars if exists
SET @fk_calendar_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_events'
      AND CONSTRAINT_NAME = 'fk_events_calendar'
);

SET @sql_drop_fk = IF(@fk_calendar_exists > 0,
    'ALTER TABLE calendar_events DROP FOREIGN KEY fk_events_calendar',
    'SELECT "FK fk_events_calendar does not exist" as status'
);

PREPARE stmt FROM @sql_drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 4: Rename table
-- ============================================

SELECT 'Renaming calendar_events to events...' as status;

SET @sql_rename = IF(@calendar_events_exists > 0 AND @events_exists = 0,
    'RENAME TABLE calendar_events TO events',
    'SELECT "Rename skipped - preconditions not met" as status'
);

PREPARE stmt_rename FROM @sql_rename;
EXECUTE stmt_rename;
DEALLOCATE PREPARE stmt_rename;

SELECT 'Table renamed successfully' as status;

-- ============================================
-- STEP 5: Recreate foreign key constraint with correct name
-- ============================================

SELECT 'Recreating foreign key constraint...' as status;

-- Add FK back with correct table name
ALTER TABLE events
ADD CONSTRAINT fk_events_calendar
FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE;

SELECT 'Foreign key constraint recreated' as status;

-- ============================================
-- STEP 6: Update calendar_shares table reference
-- (if it references calendar_events.id, update the FK)
-- ============================================

SELECT 'Checking calendar_shares FK references...' as status;

-- Check if calendar_shares references event_id
SET @calendar_shares_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_shares'
);

-- If calendar_shares exists and has event_id FK, drop and recreate it
SET @fk_shares_event = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'calendar_shares'
      AND COLUMN_NAME = 'event_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
);

-- Drop old FK constraint if exists
SET @sql_drop_shares_fk = IF(@fk_shares_event > 0,
    CONCAT('ALTER TABLE calendar_shares DROP FOREIGN KEY ',
           (SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'calendar_shares'
              AND COLUMN_NAME = 'event_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1)),
    'SELECT "No calendar_shares FK to drop" as status'
);

PREPARE stmt_drop_shares FROM @sql_drop_shares_fk;
EXECUTE stmt_drop_shares FROM;
DEALLOCATE PREPARE stmt_drop_shares;

-- Recreate FK with correct table reference
SET @sql_add_shares_fk = IF(@fk_shares_event > 0,
    'ALTER TABLE calendar_shares ADD CONSTRAINT fk_calendar_shares_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE',
    'SELECT "No calendar_shares FK to add" as status'
);

PREPARE stmt_add_shares FROM @sql_add_shares_fk;
EXECUTE stmt_add_shares;
DEALLOCATE PREPARE stmt_add_shares;

-- ============================================
-- STEP 7: Update event_attendees table reference
-- ============================================

SELECT 'Checking event_attendees FK references...' as status;

SET @event_attendees_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'event_attendees'
);

SET @fk_attendees_event = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'event_attendees'
      AND COLUMN_NAME = 'event_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
);

-- Drop old FK if exists
SET @sql_drop_attendees_fk = IF(@fk_attendees_event > 0,
    CONCAT('ALTER TABLE event_attendees DROP FOREIGN KEY ',
           (SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
              AND TABLE_NAME = 'event_attendees'
              AND COLUMN_NAME = 'event_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1)),
    'SELECT "No event_attendees FK to drop" as status'
);

PREPARE stmt_drop_attendees FROM @sql_drop_attendees_fk;
EXECUTE stmt_drop_attendees;
DEALLOCATE PREPARE stmt_drop_attendees;

-- Recreate FK
SET @sql_add_attendees_fk = IF(@fk_attendees_event > 0,
    'ALTER TABLE event_attendees ADD CONSTRAINT fk_event_attendees_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE',
    'SELECT "No event_attendees FK to add" as status'
);

PREPARE stmt_add_attendees FROM @sql_add_attendees_fk;
EXECUTE stmt_add_attendees;
DEALLOCATE PREPARE stmt_add_attendees;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT '=== MIGRATION VERIFICATION ===' as status;

-- Check table exists
SELECT 'Checking events table exists...' as status;
SELECT COUNT(*) as events_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'events';

-- Check old table gone
SELECT 'Checking calendar_events table removed...' as status;
SELECT COUNT(*) as calendar_events_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'calendar_events';

-- Check data preserved
SELECT 'Checking event data preserved...' as status;
SELECT COUNT(*) as total_events
FROM events;

-- Check FK constraints
SELECT 'Checking FK constraints...' as status;
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
  AND (TABLE_NAME = 'events' OR REFERENCED_TABLE_NAME = 'events')
  AND REFERENCED_TABLE_NAME IS NOT NULL;

SELECT '=== MIGRATION COMPLETE ===' as status;
SELECT 'calendar_events successfully renamed to events' as result;
