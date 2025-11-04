-- ============================================
-- WORKFLOW ACTIVATION SYSTEM - ROLLBACK
-- Module: Document Workflow Extension
-- Version: 1.0.0
-- Date: 2025-11-02
-- Author: Database Architect
-- Description: Rollback workflow activation configuration system
-- ============================================

USE collaboranexio;

-- ============================================
-- BACKUP DATA (Optional - for safety)
-- ============================================

-- Create backup table with timestamp
SET @backup_table = CONCAT('workflow_settings_backup_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s'));
SET @sql = CONCAT('CREATE TABLE IF NOT EXISTS ', @backup_table, ' AS SELECT * FROM workflow_settings WHERE deleted_at IS NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT
    CONCAT('Backup created: ', @backup_table) as backup_status,
    COUNT(*) as records_backed_up
FROM workflow_settings
WHERE deleted_at IS NULL;

-- ============================================
-- DROP HELPER FUNCTION
-- ============================================

DROP FUNCTION IF EXISTS get_workflow_enabled_for_folder;

SELECT 'Helper function dropped' as status;

-- ============================================
-- DROP TABLE
-- ============================================

-- Drop foreign key constraints first (if they exist)
ALTER TABLE workflow_settings DROP FOREIGN KEY IF EXISTS fk_workflow_settings_tenant;
ALTER TABLE workflow_settings DROP FOREIGN KEY IF EXISTS fk_workflow_settings_folder;
ALTER TABLE workflow_settings DROP FOREIGN KEY IF EXISTS fk_workflow_settings_configured_by;

-- Drop table
DROP TABLE IF EXISTS workflow_settings;

SELECT 'workflow_settings table dropped' as status;

-- ============================================
-- VERIFICATION
-- ============================================

-- Verify table dropped
SELECT
    'Rollback completed successfully' as status,
    CASE
        WHEN COUNT(*) = 0 THEN 'Table successfully dropped'
        ELSE 'ERROR: Table still exists'
    END as table_status
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name = 'workflow_settings';

-- List backup tables
SELECT
    table_name,
    table_rows,
    create_time
FROM information_schema.tables
WHERE table_schema = 'collaboranexio'
  AND table_name LIKE 'workflow_settings_backup_%'
ORDER BY create_time DESC;

-- ============================================
-- NOTES
-- ============================================

/*
ROLLBACK NOTES:

1. BACKUP TABLES:
   - All active workflow_settings records backed up to timestamped table
   - Format: workflow_settings_backup_YYYYMMDD_HHiiss
   - Only non-deleted records backed up (deleted_at IS NULL)
   - Backup tables can be dropped manually if not needed

2. FUNCTION REMOVAL:
   - Helper function `get_workflow_enabled_for_folder()` dropped
   - Any stored procedures using this function will fail
   - Any API calls using this function will need to be updated

3. DATA LOSS:
   - All workflow configuration settings will be PERMANENTLY deleted
   - Backup tables preserve data but need manual restore if needed
   - Document workflow records (document_workflow table) are NOT affected
   - Workflow roles (workflow_roles table) are NOT affected

4. RESTORE PROCEDURE (if needed):
   a. Find backup table: SELECT * FROM workflow_settings_backup_YYYYMMDD_HHiiss;
   b. Re-run migration: SOURCE workflow_activation_system.sql;
   c. Restore data: INSERT INTO workflow_settings SELECT * FROM workflow_settings_backup_YYYYMMDD_HHiiss;

5. CLEANUP AFTER ROLLBACK:
   - Drop backup tables: DROP TABLE workflow_settings_backup_YYYYMMDD_HHiiss;
   - Remove any API endpoints using workflow settings
   - Update file upload logic to NOT check workflow enabled status

6. IMPACT ON EXISTING WORKFLOWS:
   - Existing document workflows continue to function
   - Files already in workflow state (bozza, in_validazione, etc.) are unaffected
   - New file uploads will NOT automatically create workflows
   - Workflow roles (validators/approvers) remain configured

7. MIGRATION FORWARD AGAIN:
   - Can safely re-run workflow_activation_system.sql
   - Previous configurations lost unless manually restored from backup
   - Default tenant configuration will be recreated (workflow_enabled=0)

8. VERIFICATION:
   - Table check: SHOW TABLES LIKE 'workflow_settings';
   - Function check: SHOW FUNCTION STATUS WHERE Name = 'get_workflow_enabled_for_folder';
   - Backup check: SHOW TABLES LIKE 'workflow_settings_backup_%';
*/

-- ============================================
-- CLEANUP BACKUP TABLES (OPTIONAL)
-- ============================================

/*
-- Uncomment to drop ALL backup tables (DESTRUCTIVE - cannot undo)

-- WARNING: This will permanently delete all backup data!

SET @sql_cleanup = (
    SELECT GROUP_CONCAT(
        CONCAT('DROP TABLE IF EXISTS ', table_name)
        SEPARATOR '; '
    )
    FROM information_schema.tables
    WHERE table_schema = 'collaboranexio'
      AND table_name LIKE 'workflow_settings_backup_%'
);

PREPARE stmt FROM @sql_cleanup;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'All backup tables dropped' as cleanup_status;
*/

-- ============================================
-- END OF ROLLBACK
-- ============================================
