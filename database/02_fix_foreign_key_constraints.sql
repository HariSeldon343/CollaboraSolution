-- Module: Fix Foreign Key Constraints for Soft-Delete Compatibility
-- Version: 2025-10-08
-- Author: Database Architect
-- Description: Modifies RESTRICT foreign keys to SET NULL to allow soft-delete cascade without blocking

USE collaboranexio;

-- ============================================
-- PROBLEM STATEMENT
-- ============================================
/*
Current Issue:
Foreign keys with ON DELETE RESTRICT prevent tenant deletion when users own resources.

Example scenario:
1. User with ID=5 uploads files
2. files.uploaded_by = 5 (FK with ON DELETE RESTRICT)
3. Attempting to soft-delete user 5 fails with constraint error
4. Attempting to soft-delete entire tenant fails

Solution:
Change ON DELETE RESTRICT to ON DELETE SET NULL for audit trail preservation.
This allows soft-delete while maintaining referential integrity.

Tables Affected:
- files.uploaded_by
- folders.owner_id
- projects.owner_id
- tasks.created_by
- tasks.assigned_to
- And other creator/owner fields
*/

-- ============================================
-- BACKUP VERIFICATION
-- ============================================
SELECT 'IMPORTANT: Ensure database backup exists before proceeding!' as WARNING;
SELECT NOW() as migration_started_at;

-- ============================================
-- STEP 1: IDENTIFY ALL RESTRICT CONSTRAINTS
-- ============================================

-- Display all RESTRICT foreign keys that reference users table
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME,
    DELETE_RULE,
    UPDATE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
  AND kcu.REFERENCED_TABLE_NAME = 'users'
  AND rc.DELETE_RULE = 'RESTRICT'
ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME;

-- ============================================
-- STEP 2: MODIFY FILES TABLE
-- ============================================

-- TABLE: files
-- Column: uploaded_by (references users.id)

-- First, make column nullable (if not already)
ALTER TABLE files
    MODIFY COLUMN uploaded_by INT UNSIGNED NULL COMMENT 'User who uploaded file (NULL if user deleted)';

-- Drop existing foreign key constraint
ALTER TABLE files
    DROP FOREIGN KEY IF EXISTS fk_file_uploaded_by;

-- Some schemas may use different constraint names, try alternatives
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'files'
      AND COLUMN_NAME = 'uploaded_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE files DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE files
    ADD CONSTRAINT fk_file_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 3: MODIFY FOLDERS TABLE
-- ============================================

-- TABLE: folders
-- Column: owner_id (references users.id)

ALTER TABLE folders
    MODIFY COLUMN owner_id INT UNSIGNED NULL COMMENT 'Folder owner (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'folders'
      AND COLUMN_NAME = 'owner_id'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE folders DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE folders
    ADD CONSTRAINT fk_folder_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 4: MODIFY PROJECTS TABLE
-- ============================================

-- TABLE: projects
-- Column: owner_id (references users.id)

ALTER TABLE projects
    MODIFY COLUMN owner_id INT UNSIGNED NULL COMMENT 'Project owner (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'owner_id'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE projects DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE projects
    ADD CONSTRAINT fk_project_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 5: MODIFY TASKS TABLE
-- ============================================

-- TABLE: tasks
-- Column: created_by (references users.id)

ALTER TABLE tasks
    MODIFY COLUMN created_by INT UNSIGNED NULL COMMENT 'Task creator (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'created_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE tasks DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE tasks
    ADD CONSTRAINT fk_task_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- TABLE: tasks
-- Column: assigned_to (already nullable, already SET NULL in schema)
-- Verify it's SET NULL, not RESTRICT
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'assigned_to'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

-- Only modify if it's RESTRICT
SET @delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS rc
    INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
        ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
      AND kcu.TABLE_NAME = 'tasks'
      AND kcu.COLUMN_NAME = 'assigned_to'
    LIMIT 1
);

-- If RESTRICT, fix it
SET @sql = IF(@delete_rule = 'RESTRICT',
    CONCAT('ALTER TABLE tasks DROP FOREIGN KEY ', @constraint_name, '; ',
           'ALTER TABLE tasks ADD CONSTRAINT fk_task_assigned_to ',
           'FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;'),
    'SELECT "tasks.assigned_to already uses SET NULL" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 6: MODIFY CHAT_CHANNELS TABLE
-- ============================================

-- TABLE: chat_channels
-- Column: owner_id (references users.id)

ALTER TABLE chat_channels
    MODIFY COLUMN owner_id INT UNSIGNED NULL COMMENT 'Channel owner (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'chat_channels'
      AND COLUMN_NAME = 'owner_id'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE chat_channels DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE chat_channels
    ADD CONSTRAINT fk_chat_channel_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 7: MODIFY FILE_VERSIONS TABLE
-- ============================================

-- TABLE: file_versions
-- Column: uploaded_by (references users.id)

ALTER TABLE file_versions
    MODIFY COLUMN uploaded_by INT UNSIGNED NULL COMMENT 'User who created version (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'file_versions'
      AND COLUMN_NAME = 'uploaded_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE file_versions DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE file_versions
    ADD CONSTRAINT fk_file_version_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 8: MODIFY PROJECT_MEMBERS TABLE
-- ============================================

-- TABLE: project_members
-- Column: added_by (references users.id)

ALTER TABLE project_members
    MODIFY COLUMN added_by INT UNSIGNED NULL COMMENT 'User who added member (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'project_members'
      AND COLUMN_NAME = 'added_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE project_members DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE project_members
    ADD CONSTRAINT fk_project_member_added_by
    FOREIGN KEY (added_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 9: MODIFY TASK_ASSIGNMENTS TABLE
-- ============================================

-- TABLE: task_assignments
-- Column: assigned_by (references users.id)

ALTER TABLE task_assignments
    MODIFY COLUMN assigned_by INT UNSIGNED NULL COMMENT 'User who assigned task (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'task_assignments'
      AND COLUMN_NAME = 'assigned_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE task_assignments DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE task_assignments
    ADD CONSTRAINT fk_task_assignment_assigned_by
    FOREIGN KEY (assigned_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 10: MODIFY PROJECT_MILESTONES TABLE
-- ============================================

-- TABLE: project_milestones (if exists)
-- Column: created_by (references users.id)

ALTER TABLE project_milestones
    MODIFY COLUMN created_by INT UNSIGNED NULL COMMENT 'Milestone creator (NULL if user deleted)';

-- Drop existing constraint
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'project_milestones'
      AND COLUMN_NAME = 'created_by'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE project_milestones DROP FOREIGN KEY IF EXISTS ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with ON DELETE SET NULL
ALTER TABLE project_milestones
    ADD CONSTRAINT fk_milestone_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- List all remaining RESTRICT constraints on users table
SELECT
    'Remaining RESTRICT constraints on users' as check_type,
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
  AND kcu.REFERENCED_TABLE_NAME = 'users'
  AND rc.DELETE_RULE = 'RESTRICT';

-- List all SET NULL constraints (should see our changes)
SELECT
    'Foreign keys using SET NULL' as check_type,
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
  AND kcu.REFERENCED_TABLE_NAME = 'users'
  AND rc.DELETE_RULE = 'SET NULL'
ORDER BY kcu.TABLE_NAME;

-- Verify columns are now nullable
SELECT
    'Nullable audit columns' as check_type,
    TABLE_NAME,
    COLUMN_NAME,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND COLUMN_NAME IN ('uploaded_by', 'owner_id', 'created_by', 'assigned_by', 'added_by')
  AND IS_NULLABLE = 'YES'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- ============================================
-- MIGRATION SUMMARY
-- ============================================

SELECT '========================================' as '';
SELECT 'FOREIGN KEY CONSTRAINTS UPDATED' as status;
SELECT '========================================' as '';
SELECT '' as '';

SELECT 'Migration Summary:' as info, '' as value
UNION ALL
SELECT 'Constraints Modified:', CAST(COUNT(*) AS CHAR)
    FROM information_schema.KEY_COLUMN_USAGE kcu
    INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
      AND kcu.REFERENCED_TABLE_NAME = 'users'
      AND rc.DELETE_RULE = 'SET NULL'
UNION ALL
SELECT 'Remaining RESTRICT:', CAST(COUNT(*) AS CHAR)
    FROM information_schema.KEY_COLUMN_USAGE kcu
    INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'collaboranexio'
      AND kcu.REFERENCED_TABLE_NAME = 'users'
      AND rc.DELETE_RULE = 'RESTRICT'
UNION ALL
SELECT 'Completed At:', CAST(NOW() AS CHAR);

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Tenant soft-delete will now work without FK blocking!' as achievement;
SELECT '========================================' as '';
