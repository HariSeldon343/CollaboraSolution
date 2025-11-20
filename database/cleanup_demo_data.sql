-- ============================================================
-- CollaboraNexio - Cleanup Demo Data Script
-- ============================================================
-- Purpose: Remove demo/test data from dashboard
-- Date: 2025-11-16
-- Author: Database Architect
-- Type: REVERSIBLE (with backup)
-- ============================================================

-- CRITICAL: This script is REVERSIBLE via backup restore
-- Before execution:
-- 1. Create database backup: mysqldump -u root collaboranexio > backup_pre_cleanup.sql
-- 2. Verify backup file is not empty
-- 3. Execute this script

USE collaboranexio;

-- ============================================================
-- STEP 1: Soft Delete Demo Files
-- ============================================================
-- Pattern: Files with 'test', 'demo', 'sample' in name
-- Action: Soft delete (preserves audit trail)
-- ============================================================

UPDATE files
SET deleted_at = NOW()
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (
    name LIKE '%test%' OR
    name LIKE '%demo%' OR
    name LIKE '%sample%' OR
    name LIKE '%esempio%' OR
    name LIKE '%prova%'
  )
  AND tenant_id IS NOT NULL; -- Safety: only update tenant-scoped records

-- Report affected files
SELECT
    CONCAT('Soft deleted ', COUNT(*), ' demo files') AS result,
    COUNT(*) as files_deleted
FROM files
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (
    name LIKE '%test%' OR
    name LIKE '%demo%' OR
    name LIKE '%sample%' OR
    name LIKE '%esempio%' OR
    name LIKE '%prova%'
  );

-- ============================================================
-- STEP 2: Soft Delete Demo Calendar Events
-- ============================================================
-- Pattern: Events with 'test', 'demo', 'sample' in title
-- Action: Soft delete (preserves audit trail)
-- ============================================================

UPDATE calendar_events
SET deleted_at = NOW()
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (
    title LIKE '%test%' OR
    title LIKE '%demo%' OR
    title LIKE '%sample%' OR
    title LIKE '%esempio%' OR
    title LIKE '%prova%'
  )
  AND tenant_id IS NOT NULL; -- Safety: only update tenant-scoped records

-- Report affected events
SELECT
    CONCAT('Soft deleted ', COUNT(*), ' demo events') AS result,
    COUNT(*) as events_deleted
FROM calendar_events
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (
    title LIKE '%test%' OR
    title LIKE '%demo%' OR
    title LIKE '%sample%' OR
    title LIKE '%esempio%' OR
    title LIKE '%prova%'
  );

-- ============================================================
-- STEP 3: Soft Delete Demo Tickets
-- ============================================================
-- Pattern: Tickets with 'test', 'demo', 'sample' in subject
-- Action: Soft delete (preserves audit trail)
-- ============================================================

UPDATE tickets
SET deleted_at = NOW()
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (
    subject LIKE '%test%' OR
    subject LIKE '%demo%' OR
    subject LIKE '%sample%' OR
    subject LIKE '%esempio%' OR
    subject LIKE '%prova%'
  )
  AND tenant_id IS NOT NULL; -- Safety: only update tenant-scoped records

-- Report affected tickets
SELECT
    CONCAT('Soft deleted ', COUNT(*), ' demo tickets') AS result,
    COUNT(*) as tickets_deleted
FROM tickets
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (
    subject LIKE '%test%' OR
    subject LIKE '%demo%' OR
    subject LIKE '%sample%' OR
    subject LIKE '%esempio%' OR
    subject LIKE '%prova%'
  );

-- ============================================================
-- STEP 4: Clean Orphaned Physical Files (MANUAL STEP)
-- ============================================================
-- CRITICAL: This step MUST be done manually via PHP script
-- Reason: SQL cannot delete physical files from filesystem
-- Script: /database/cleanup_orphaned_physical_files.php
-- ============================================================

-- List physical files that should be deleted (for manual verification)
SELECT
    CONCAT('uploads/', folder_id, '/', stored_name) AS physical_path,
    name AS original_name,
    uploaded_at,
    deleted_at
FROM files
WHERE deleted_at IS NOT NULL
  AND deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
ORDER BY uploaded_at DESC;

-- ============================================================
-- FINAL SUMMARY
-- ============================================================
-- Display summary of cleanup operations
-- ============================================================

SELECT
    '=== CLEANUP SUMMARY ===' AS summary_title;

SELECT
    'Files' AS entity_type,
    COUNT(*) AS soft_deleted_count,
    CONCAT(ROUND(SUM(file_size) / 1024 / 1024, 2), ' MB') AS total_size
FROM files
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (name LIKE '%test%' OR name LIKE '%demo%' OR name LIKE '%sample%')

UNION ALL

SELECT
    'Events' AS entity_type,
    COUNT(*) AS soft_deleted_count,
    'N/A' AS total_size
FROM calendar_events
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (title LIKE '%test%' OR title LIKE '%demo%' OR title LIKE '%sample%')

UNION ALL

SELECT
    'Tickets' AS entity_type,
    COUNT(*) AS soft_deleted_count,
    'N/A' AS total_size
FROM tickets
WHERE deleted_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
  AND (subject LIKE '%test%' OR subject LIKE '%demo%' OR subject LIKE '%sample%');

-- ============================================================
-- VERIFICATION QUERIES (Run after cleanup)
-- ============================================================
-- Verify no demo data remains active in dashboard queries
-- ============================================================

-- 1. Check active files (should return 0 demo files)
SELECT
    COUNT(*) AS active_demo_files
FROM files
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (name LIKE '%test%' OR name LIKE '%demo%' OR name LIKE '%sample%');

-- 2. Check active events (should return 0 demo events)
SELECT
    COUNT(*) AS active_demo_events
FROM calendar_events
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (title LIKE '%test%' OR title LIKE '%demo%' OR title LIKE '%sample%');

-- 3. Check active tickets (should return 0 demo tickets)
SELECT
    COUNT(*) AS active_demo_tickets
FROM tickets
WHERE (deleted_at IS NULL OR deleted_at = '')
  AND (subject LIKE '%test%' OR subject LIKE '%demo%' OR subject LIKE '%sample%');

-- ============================================================
-- ROLLBACK INSTRUCTIONS (If needed)
-- ============================================================
-- To restore deleted data:
-- 1. mysql -u root collaboranexio < backup_pre_cleanup.sql
-- 2. Verify restoration: SELECT COUNT(*) FROM files;
-- ============================================================

-- ============================================================
-- END OF CLEANUP SCRIPT
-- ============================================================
