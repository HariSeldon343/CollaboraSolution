-- ============================================
-- Multi-Tenant Folder Permissions Enhancement
-- Version: 2025-09-27
-- Description: Enhanced folder permissions for multi-tenant file management
-- ============================================

USE collaboranexio;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- ENSURE FOLDERS TABLE EXISTS
-- ============================================
CREATE TABLE IF NOT EXISTS folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1000),
    owner_id INT UNSIGNED NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_tenant (tenant_id),
    INDEX idx_parent (parent_id),
    INDEX idx_owner (owner_id),
    INDEX idx_deleted (deleted_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MODIFY FILES TABLE STRUCTURE
-- ============================================
-- Ensure files table uses folder_id correctly
ALTER TABLE files
    MODIFY COLUMN folder_id INT UNSIGNED NULL COMMENT 'Reference to folders table';

-- Add foreign key to folders table if not exists
ALTER TABLE files
    DROP FOREIGN KEY IF EXISTS fk_files_folder;

ALTER TABLE files
    ADD CONSTRAINT fk_files_folder
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE;

-- ============================================
-- CREATE ROOT FOLDERS FOR EACH TENANT
-- ============================================
INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public)
SELECT
    t.id as tenant_id,
    NULL as parent_id,
    t.name as name,
    CONCAT('/', t.name) as path,
    (SELECT id FROM users WHERE role = 'super_admin' LIMIT 1) as owner_id,
    FALSE as is_public
FROM tenants t
WHERE NOT EXISTS (
    SELECT 1 FROM folders f
    WHERE f.tenant_id = t.id
    AND f.parent_id IS NULL
    LIMIT 1
)
AND t.deleted_at IS NULL;

-- Create default sub-folders for each tenant root folder
INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public)
SELECT
    f.tenant_id,
    f.id as parent_id,
    'Documenti' as name,
    CONCAT(f.path, '/Documenti') as path,
    f.owner_id,
    FALSE
FROM folders f
WHERE f.parent_id IS NULL
AND NOT EXISTS (
    SELECT 1 FROM folders sf
    WHERE sf.parent_id = f.id
    AND sf.name = 'Documenti'
);

INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public)
SELECT
    f.tenant_id,
    f.id as parent_id,
    'Progetti' as name,
    CONCAT(f.path, '/Progetti') as path,
    f.owner_id,
    FALSE
FROM folders f
WHERE f.parent_id IS NULL
AND NOT EXISTS (
    SELECT 1 FROM folders sf
    WHERE sf.parent_id = f.id
    AND sf.name = 'Progetti'
);

INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public)
SELECT
    f.tenant_id,
    f.id as parent_id,
    'Condivisi' as name,
    CONCAT(f.path, '/Condivisi') as path,
    f.owner_id,
    TRUE
FROM folders f
WHERE f.parent_id IS NULL
AND NOT EXISTS (
    SELECT 1 FROM folders sf
    WHERE sf.parent_id = f.id
    AND sf.name = 'Condivisi'
);

-- ============================================
-- CREATE STORED PROCEDURES
-- ============================================
DELIMITER //

-- Check if user can create root folders
DROP PROCEDURE IF EXISTS CheckRootFolderPermission//
CREATE PROCEDURE CheckRootFolderPermission(
    IN p_user_id INT,
    IN p_tenant_id INT,
    OUT p_can_create BOOLEAN,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE user_role VARCHAR(20);
    DECLARE user_tenant INT;

    -- Get user role and tenant
    SELECT role, tenant_id INTO user_role, user_tenant
    FROM users
    WHERE id = p_user_id
    AND deleted_at IS NULL
    LIMIT 1;

    -- Check permissions based on role
    IF user_role = 'super_admin' THEN
        SET p_can_create = TRUE;
        SET p_message = 'Super Admin can create folders for any tenant';
    ELSEIF user_role = 'admin' THEN
        -- Check if admin has access to this tenant
        IF EXISTS (
            SELECT 1 FROM user_tenant_access
            WHERE user_id = p_user_id
            AND tenant_id = p_tenant_id
        ) OR user_tenant = p_tenant_id THEN
            SET p_can_create = TRUE;
            SET p_message = 'Admin has access to this tenant';
        ELSE
            SET p_can_create = FALSE;
            SET p_message = 'Admin does not have access to this tenant';
        END IF;
    ELSE
        SET p_can_create = FALSE;
        SET p_message = 'Only Admin and Super Admin can create root folders';
    END IF;
END//

-- Validate folder access for user
DROP PROCEDURE IF EXISTS ValidateFolderAccess//
CREATE PROCEDURE ValidateFolderAccess(
    IN p_user_id INT,
    IN p_folder_id INT,
    OUT p_has_access BOOLEAN,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE user_role VARCHAR(20);
    DECLARE user_tenant INT;
    DECLARE folder_tenant INT;

    -- Get user info
    SELECT role, tenant_id INTO user_role, user_tenant
    FROM users
    WHERE id = p_user_id
    AND deleted_at IS NULL
    LIMIT 1;

    -- Get folder tenant
    SELECT tenant_id INTO folder_tenant
    FROM folders
    WHERE id = p_folder_id
    AND deleted_at IS NULL
    LIMIT 1;

    IF folder_tenant IS NULL THEN
        SET p_has_access = FALSE;
        SET p_message = 'Folder not found';
    ELSEIF user_role = 'super_admin' THEN
        SET p_has_access = TRUE;
        SET p_message = 'Super Admin has access to all folders';
    ELSEIF user_tenant = folder_tenant THEN
        SET p_has_access = TRUE;
        SET p_message = 'User has access to tenant folder';
    ELSEIF user_role = 'admin' AND EXISTS (
        SELECT 1 FROM user_tenant_access
        WHERE user_id = p_user_id
        AND tenant_id = folder_tenant
    ) THEN
        SET p_has_access = TRUE;
        SET p_message = 'Admin has multi-tenant access';
    ELSE
        SET p_has_access = FALSE;
        SET p_message = 'Access denied: folder belongs to different tenant';
    END IF;
END//

DELIMITER ;

-- ============================================
-- CREATE INDEXES FOR PERFORMANCE
-- ============================================
CREATE INDEX IF NOT EXISTS idx_folders_tenant_parent
    ON folders(tenant_id, parent_id, deleted_at);

CREATE INDEX IF NOT EXISTS idx_files_tenant_folder
    ON files(tenant_id, folder_id, deleted_at);

-- ============================================
-- UPDATE MIGRATION HISTORY
-- ============================================
INSERT INTO migration_history (filename, executed_at)
VALUES ('06_multi_tenant_folders.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Multi-tenant folders setup completed' as Status,
       (SELECT COUNT(*) FROM folders WHERE parent_id IS NULL) as RootFolders,
       (SELECT COUNT(DISTINCT tenant_id) FROM folders) as TenantsWithFolders;