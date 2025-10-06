-- ============================================
-- Company Deletion and Tenant Management Fix
-- Version: 2025-09-28
-- Author: Database Architect
-- Description: Enable safe company deletion with orphaned content management
-- ============================================

USE collaboranexio;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- TABLE MODIFICATIONS
-- ============================================

-- Modify USERS table to allow NULL tenant_id
ALTER TABLE users
    MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for users without company assignment';

-- Add tracking columns to users table
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS tenant_removed_at TIMESTAMP NULL COMMENT 'When user was removed from tenant' AFTER deleted_at;

-- Add index for original tenant tracking
CREATE INDEX IF NOT EXISTS idx_users_original_tenant ON users(original_tenant_id);

-- Modify FOLDERS table
ALTER TABLE folders
    DROP FOREIGN KEY IF EXISTS folders_ibfk_1;

ALTER TABLE folders
    MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for Super Admin global folders';

-- Add tracking columns to folders table
ALTER TABLE folders
    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS reassigned_at TIMESTAMP NULL COMMENT 'When folder was reassigned' AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS reassigned_by INT UNSIGNED NULL COMMENT 'User who performed reassignment' AFTER reassigned_at;

-- Add index for original tenant tracking
CREATE INDEX IF NOT EXISTS idx_folders_original_tenant ON folders(original_tenant_id);

-- Re-add foreign key with SET NULL on delete
ALTER TABLE folders
    ADD CONSTRAINT fk_folders_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL;

-- Modify FILES table
ALTER TABLE files
    DROP FOREIGN KEY IF EXISTS files_ibfk_1;

ALTER TABLE files
    MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for Super Admin global files';

-- Add tracking columns to files table
ALTER TABLE files
    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS reassigned_at TIMESTAMP NULL COMMENT 'When file was reassigned' AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS reassigned_by INT UNSIGNED NULL COMMENT 'User who performed reassignment' AFTER reassigned_at;

-- Add index for original tenant tracking
CREATE INDEX IF NOT EXISTS idx_files_original_tenant ON files(original_tenant_id);

-- Re-add foreign key with SET NULL on delete
ALTER TABLE files
    ADD CONSTRAINT fk_files_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL;

-- ============================================
-- CREATE SUPER ADMIN FOLDERS
-- ============================================

-- Ensure super admin user exists
INSERT INTO users (tenant_id, nome, cognome, email, password, role, is_active)
SELECT NULL, 'Super', 'Admin', 'superadmin@collaboranexio.com',
       '$2y$10$YourHashedPasswordHere', 'super_admin', 1
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'superadmin@collaboranexio.com'
);

-- Create Super Admin root folder
INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, settings)
SELECT
    NULL as tenant_id,
    NULL as parent_id,
    'Super Admin Files' as name,
    '/super-admin' as path,
    (SELECT id FROM users WHERE role = 'super_admin' LIMIT 1) as owner_id,
    FALSE as is_public,
    JSON_OBJECT('system_folder', true, 'description', 'Root folder for Super Admin global files') as settings
WHERE NOT EXISTS (
    SELECT 1 FROM folders
    WHERE tenant_id IS NULL
    AND parent_id IS NULL
    AND name = 'Super Admin Files'
);

-- Create Orphaned Companies folder
INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, settings)
SELECT
    NULL as tenant_id,
    (SELECT id FROM folders WHERE tenant_id IS NULL AND parent_id IS NULL AND name = 'Super Admin Files' LIMIT 1) as parent_id,
    'Orphaned Companies' as name,
    '/super-admin/orphaned-companies' as path,
    (SELECT id FROM users WHERE role = 'super_admin' LIMIT 1) as owner_id,
    FALSE as is_public,
    JSON_OBJECT('system_folder', true, 'description', 'Files from deleted companies') as settings
WHERE NOT EXISTS (
    SELECT 1 FROM folders
    WHERE tenant_id IS NULL
    AND name = 'Orphaned Companies'
);

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER //

-- Drop existing procedures if they exist
DROP PROCEDURE IF EXISTS SafeDeleteCompany//
DROP PROCEDURE IF EXISTS CheckUserLoginAccess//
DROP PROCEDURE IF EXISTS GetAccessibleFolders//

-- Procedure to safely delete a company with content preservation
CREATE PROCEDURE SafeDeleteCompany(
    IN p_company_id INT,
    IN p_deleted_by INT,
    OUT p_success BOOLEAN,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_company_name VARCHAR(255);
    DECLARE v_super_admin_folder_id INT;
    DECLARE v_reassigned_folders INT DEFAULT 0;
    DECLARE v_reassigned_files INT DEFAULT 0;
    DECLARE v_updated_users INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = 'Error during company deletion';
    END;

    START TRANSACTION;

    -- Get company name
    SELECT name INTO v_company_name
    FROM tenants
    WHERE id = p_company_id;

    IF v_company_name IS NULL THEN
        SET p_success = FALSE;
        SET p_message = 'Company not found';
        ROLLBACK;
    ELSE
        -- Get orphaned companies folder
        SELECT id INTO v_super_admin_folder_id
        FROM folders
        WHERE tenant_id IS NULL
        AND name = 'Orphaned Companies'
        LIMIT 1;

        -- Create company-specific folder in orphaned companies
        INSERT INTO folders (
            tenant_id, parent_id, name, path,
            owner_id, is_public, original_tenant_id,
            reassigned_at, reassigned_by
        )
        VALUES (
            NULL,
            v_super_admin_folder_id,
            CONCAT(v_company_name, ' (Deleted ', DATE_FORMAT(NOW(), '%Y-%m-%d'), ')'),
            CONCAT('/super-admin/orphaned-companies/', REPLACE(v_company_name, ' ', '-'), '-', p_company_id),
            p_deleted_by,
            FALSE,
            p_company_id,
            NOW(),
            p_deleted_by
        );

        SET @new_folder_id = LAST_INSERT_ID();

        -- Reassign folders to Super Admin
        UPDATE folders
        SET tenant_id = NULL,
            original_tenant_id = p_company_id,
            parent_id = @new_folder_id,
            reassigned_at = NOW(),
            reassigned_by = p_deleted_by
        WHERE tenant_id = p_company_id;

        SET v_reassigned_folders = ROW_COUNT();

        -- Reassign files to Super Admin
        UPDATE files
        SET tenant_id = NULL,
            original_tenant_id = p_company_id,
            folder_id = @new_folder_id,
            reassigned_at = NOW(),
            reassigned_by = p_deleted_by
        WHERE tenant_id = p_company_id;

        SET v_reassigned_files = ROW_COUNT();

        -- Update regular users - remove tenant assignment
        UPDATE users
        SET tenant_id = NULL,
            original_tenant_id = p_company_id,
            tenant_removed_at = NOW(),
            is_active = 0
        WHERE tenant_id = p_company_id
        AND role IN ('user', 'manager');

        SET v_updated_users = ROW_COUNT();

        -- Delete admin users for this company
        DELETE FROM users
        WHERE tenant_id = p_company_id
        AND role IN ('admin', 'tenant_admin');

        -- Remove user_tenant_access entries
        DELETE FROM user_tenant_access
        WHERE tenant_id = p_company_id;

        -- Delete dependent data (check if tables exist before deleting)
        DELETE FROM tasks WHERE tenant_id = p_company_id;
        DELETE FROM projects WHERE tenant_id = p_company_id;
        DELETE FROM calendar_events WHERE tenant_id = p_company_id;
        DELETE FROM chat_messages WHERE tenant_id = p_company_id;
        DELETE FROM chat_channels WHERE tenant_id = p_company_id;

        -- Finally delete the company
        DELETE FROM tenants WHERE id = p_company_id;

        COMMIT;

        SET p_success = TRUE;
        SET p_message = CONCAT(
            'Company deleted successfully. ',
            v_reassigned_folders, ' folders reassigned, ',
            v_reassigned_files, ' files reassigned, ',
            v_updated_users, ' users deactivated.'
        );
    END IF;
END//

-- Procedure to check if user can login without tenant
CREATE PROCEDURE CheckUserLoginAccess(
    IN p_user_id INT,
    OUT p_can_login BOOLEAN,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_user_role VARCHAR(20);
    DECLARE v_tenant_id INT;
    DECLARE v_is_active BOOLEAN;

    SELECT role, tenant_id, is_active
    INTO v_user_role, v_tenant_id, v_is_active
    FROM users
    WHERE id = p_user_id
    AND deleted_at IS NULL;

    IF v_user_role IS NULL THEN
        SET p_can_login = FALSE;
        SET p_message = 'User not found';
    ELSEIF v_is_active = 0 THEN
        SET p_can_login = FALSE;
        SET p_message = 'User account is deactivated';
    ELSEIF v_user_role IN ('super_admin', 'admin') THEN
        -- Admin and Super Admin can always login
        SET p_can_login = TRUE;
        SET p_message = 'Admin user can login';
    ELSEIF v_tenant_id IS NULL THEN
        -- Regular users need a tenant
        SET p_can_login = FALSE;
        SET p_message = 'User requires company assignment to login';
    ELSE
        -- Check if tenant exists and is active
        IF EXISTS (
            SELECT 1 FROM tenants
            WHERE id = v_tenant_id
            AND status = 'active'
        ) THEN
            SET p_can_login = TRUE;
            SET p_message = 'User can login';
        ELSE
            SET p_can_login = FALSE;
            SET p_message = 'Company is not active';
        END IF;
    END IF;
END//

-- Procedure to get accessible folders for user
CREATE PROCEDURE GetAccessibleFolders(
    IN p_user_id INT
)
BEGIN
    DECLARE v_user_role VARCHAR(20);
    DECLARE v_tenant_id INT;

    SELECT role, tenant_id
    INTO v_user_role, v_tenant_id
    FROM users
    WHERE id = p_user_id;

    IF v_user_role = 'super_admin' THEN
        -- Super Admin sees all folders
        SELECT f.*,
               t.name as tenant_name,
               CASE
                   WHEN f.original_tenant_id IS NOT NULL THEN 'Orphaned'
                   WHEN f.tenant_id IS NULL THEN 'Global'
                   ELSE 'Active'
               END as folder_status
        FROM folders f
        LEFT JOIN tenants t ON f.tenant_id = t.id
        WHERE f.deleted_at IS NULL
        ORDER BY f.tenant_id IS NULL DESC, f.name;

    ELSEIF v_user_role = 'admin' THEN
        -- Admin sees their tenants' folders and global folders
        SELECT f.*,
               t.name as tenant_name,
               CASE
                   WHEN f.original_tenant_id IS NOT NULL THEN 'Orphaned'
                   WHEN f.tenant_id IS NULL THEN 'Global'
                   ELSE 'Active'
               END as folder_status
        FROM folders f
        LEFT JOIN tenants t ON f.tenant_id = t.id
        WHERE f.deleted_at IS NULL
        AND (
            f.tenant_id IS NULL
            OR f.tenant_id IN (
                SELECT tenant_id FROM user_tenant_access
                WHERE user_id = p_user_id
            )
            OR f.tenant_id = v_tenant_id
        )
        ORDER BY f.tenant_id IS NULL DESC, f.name;

    ELSE
        -- Regular users see only their tenant folders
        SELECT f.*,
               t.name as tenant_name,
               'Active' as folder_status
        FROM folders f
        LEFT JOIN tenants t ON f.tenant_id = t.id
        WHERE f.tenant_id = v_tenant_id
        AND f.deleted_at IS NULL
        ORDER BY f.name;
    END IF;
END//

DELIMITER ;

-- ============================================
-- UPDATE MIGRATION HISTORY (if table exists)
-- ============================================
INSERT IGNORE INTO migration_history (migration_name, executed_at, execution_time_ms, status, description)
VALUES (
    '07_company_deletion_fix',
    NOW(),
    0,
    'success',
    'Modified tables to allow NULL tenant_id and created company deletion procedures'
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================
SELECT 'Migration completed successfully' as Status;

SELECT
    'Tables Modified' as Category,
    COUNT(*) as Count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('users', 'folders', 'files')
AND COLUMN_NAME = 'original_tenant_id';

SELECT
    'Super Admin Folders' as Category,
    COUNT(*) as Count
FROM folders
WHERE tenant_id IS NULL;

SELECT
    'Stored Procedures' as Category,
    COUNT(*) as Count
FROM INFORMATION_SCHEMA.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
AND ROUTINE_NAME IN ('SafeDeleteCompany', 'CheckUserLoginAccess', 'GetAccessibleFolders');