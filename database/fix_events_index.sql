-- ============================================
-- Fix: Add Missing Index to events Table
-- Date: 2025-11-17
-- Context: Post Calendar Implementation Verification
-- ============================================

USE collaboranexio;

SELECT 'Adding idx_events_tenant_created index to events table...' as status;

-- Check if index already exists
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'events'
      AND INDEX_NAME = 'idx_events_tenant_created'
);

-- Add index if not exists
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_events_tenant_created ON events(tenant_id, created_at)',
    'SELECT "Index already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify index
SELECT 'Verifying index...' as status;
SHOW INDEX FROM events WHERE Key_name = 'idx_events_tenant_created';

SELECT '✓ Index added successfully' as status;
