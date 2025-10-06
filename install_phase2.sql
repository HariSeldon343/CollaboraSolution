-- Module: Document Management System
-- Version: 2025-01-22
-- Author: Database Architect
-- Description: Complete document management system with hierarchical folders, versioning, permissions, and sharing

USE collabora;

-- ============================================
-- CLEANUP (Development only - remove in production)
-- ============================================
DROP TABLE IF EXISTS file_activity_logs;
DROP TABLE IF EXISTS file_shares;
DROP TABLE IF EXISTS file_permissions;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS folders;

DROP PROCEDURE IF EXISTS calculate_folder_size;
DROP PROCEDURE IF EXISTS get_folder_tree;
DROP PROCEDURE IF EXISTS move_folder;
DROP PROCEDURE IF EXISTS restore_from_trash;

DROP TRIGGER IF EXISTS after_file_insert;
DROP TRIGGER IF EXISTS after_file_delete;
DROP TRIGGER IF EXISTS after_file_update;
DROP TRIGGER IF EXISTS before_folder_update;
DROP TRIGGER IF EXISTS after_file_version_insert;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- Folders table with hierarchical structure
CREATE TABLE folders (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Hierarchical structure
    parent_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL for root folders',
    name VARCHAR(255) NOT NULL COMMENT 'Folder name, must be unique within parent',
    full_path VARCHAR(4096) NOT NULL COMMENT 'Full path from root for fast queries (e.g., /documents/projects/2024)',
    path_depth TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Depth level for hierarchical queries',

    -- Metadata
    description TEXT COMMENT 'Optional folder description',
    color VARCHAR(7) DEFAULT '#808080' COMMENT 'Folder color for UI (hex format)',
    icon VARCHAR(50) DEFAULT 'folder' COMMENT 'Icon identifier for UI',

    -- Ownership and permissions
    owner_id INT NOT NULL COMMENT 'User who owns this folder',
    is_system BOOLEAN DEFAULT FALSE COMMENT 'System folders cannot be deleted',
    is_shared BOOLEAN DEFAULT FALSE COMMENT 'Quick flag to identify shared folders',

    -- Soft delete support
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    deleted_by INT DEFAULT NULL COMMENT 'User who deleted the folder',

    -- Statistics (denormalized for performance)
    file_count INT UNSIGNED DEFAULT 0 COMMENT 'Direct file count (excluding subfolders)',
    total_size BIGINT UNSIGNED DEFAULT 0 COMMENT 'Total size in bytes of direct files',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_by INT DEFAULT NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id),
    UNIQUE KEY uk_folder_path (tenant_id, full_path, deleted_at),
    UNIQUE KEY uk_folder_name (tenant_id, parent_id, name, deleted_at),
    CONSTRAINT chk_path_depth CHECK (path_depth >= 0 AND path_depth <= 20),
    CONSTRAINT chk_color_format CHECK (color REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Hierarchical folder structure for document management';

-- Files table with versioning support
CREATE TABLE files (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- File location and identification
    folder_id INT UNSIGNED NOT NULL COMMENT 'Parent folder',
    name VARCHAR(255) NOT NULL COMMENT 'File name including extension',
    display_name VARCHAR(255) NOT NULL COMMENT 'User-friendly display name',

    -- Version control
    version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Version number',
    version_group_id VARCHAR(36) NOT NULL COMMENT 'UUID to group all versions of the same file',
    is_current BOOLEAN DEFAULT TRUE COMMENT 'Marks the current/active version',
    version_comment TEXT COMMENT 'Optional comment for this version',
    previous_version_id INT UNSIGNED DEFAULT NULL COMMENT 'Link to previous version',

    -- File properties
    size BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(255) NOT NULL COMMENT 'MIME type (e.g., application/pdf)',
    extension VARCHAR(50) NOT NULL COMMENT 'File extension without dot',

    -- Storage and deduplication
    storage_path VARCHAR(512) NOT NULL COMMENT 'Physical storage path on disk/cloud',
    sha256_hash CHAR(64) NOT NULL COMMENT 'SHA256 hash for deduplication and integrity',
    storage_backend VARCHAR(50) DEFAULT 'local' COMMENT 'Storage backend: local, s3, azure, etc.',

    -- Metadata (flexible JSON storage)
    metadata JSON COMMENT 'Flexible metadata storage (dimensions, duration, author, etc.)',
    tags JSON COMMENT 'Array of tags for categorization',

    -- Security and access
    is_encrypted BOOLEAN DEFAULT FALSE COMMENT 'Whether file is encrypted at rest',
    encryption_key_id VARCHAR(100) COMMENT 'Reference to encryption key',
    last_accessed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last access timestamp for analytics',
    access_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of times accessed',

    -- Content indexing
    content_indexed BOOLEAN DEFAULT FALSE COMMENT 'Whether content has been indexed for search',
    content_indexed_at TIMESTAMP NULL DEFAULT NULL,
    thumbnail_path VARCHAR(512) COMMENT 'Path to thumbnail if applicable',

    -- Soft delete and trash
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    deleted_by INT DEFAULT NULL COMMENT 'User who deleted the file',
    trash_expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When to permanently delete from trash',

    -- Ownership
    owner_id INT NOT NULL COMMENT 'User who owns this file',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_by INT DEFAULT NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    FOREIGN KEY (previous_version_id) REFERENCES files(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id),
    UNIQUE KEY uk_file_current_version (tenant_id, folder_id, name, is_current, deleted_at),
    CONSTRAINT chk_version CHECK (version > 0),
    CONSTRAINT chk_size CHECK (size >= 0),
    CONSTRAINT chk_sha256 CHECK (LENGTH(sha256_hash) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='File storage with versioning, metadata, and deduplication support';

-- File permissions table (polymorphic for files and folders)
CREATE TABLE file_permissions (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Polymorphic reference
    resource_type ENUM('file', 'folder') NOT NULL COMMENT 'Type of resource',
    resource_id INT UNSIGNED NOT NULL COMMENT 'ID of file or folder',

    -- Permission target (user or group)
    target_type ENUM('user', 'group', 'role') NOT NULL COMMENT 'Type of permission target',
    target_id INT NOT NULL COMMENT 'ID of user, group, or role',

    -- Granular permissions (bit flags could be used for optimization)
    can_read BOOLEAN DEFAULT TRUE COMMENT 'View and download permission',
    can_write BOOLEAN DEFAULT FALSE COMMENT 'Edit and upload permission',
    can_delete BOOLEAN DEFAULT FALSE COMMENT 'Delete permission',
    can_share BOOLEAN DEFAULT FALSE COMMENT 'Share with others permission',
    can_manage BOOLEAN DEFAULT FALSE COMMENT 'Manage permissions and settings',

    -- Additional permission settings
    inherit_to_children BOOLEAN DEFAULT TRUE COMMENT 'Apply to subfolders and files',
    grant_type ENUM('allow', 'deny') DEFAULT 'allow' COMMENT 'Allow or explicitly deny',
    priority INT DEFAULT 0 COMMENT 'Permission priority (higher wins in conflicts)',

    -- Time-based permissions
    valid_from TIMESTAMP NULL DEFAULT NULL COMMENT 'Permission start time',
    valid_until TIMESTAMP NULL DEFAULT NULL COMMENT 'Permission expiration',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uk_permission (tenant_id, resource_type, resource_id, target_type, target_id),
    INDEX idx_resource_lookup (resource_type, resource_id),
    INDEX idx_target_lookup (target_type, target_id),
    CONSTRAINT chk_valid_dates CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from < valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Granular permission system for files and folders';

-- File shares table for public/private sharing
CREATE TABLE file_shares (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Share identification
    share_token VARCHAR(64) NOT NULL COMMENT 'Unique token for share URL',
    share_type ENUM('public', 'private', 'password') NOT NULL DEFAULT 'private' COMMENT 'Type of share',

    -- Shared resource (polymorphic)
    resource_type ENUM('file', 'folder') NOT NULL COMMENT 'Type of shared resource',
    resource_id INT UNSIGNED NOT NULL COMMENT 'ID of shared file or folder',

    -- Share settings
    title VARCHAR(255) COMMENT 'Optional title for the share',
    description TEXT COMMENT 'Optional description',
    password_hash VARCHAR(255) DEFAULT NULL COMMENT 'BCrypt hash for password protection',

    -- Access control
    allow_download BOOLEAN DEFAULT TRUE COMMENT 'Allow downloading files',
    allow_upload BOOLEAN DEFAULT FALSE COMMENT 'Allow uploading to shared folder',
    allow_preview BOOLEAN DEFAULT TRUE COMMENT 'Allow preview without download',
    require_email BOOLEAN DEFAULT FALSE COMMENT 'Require email before access',

    -- Limitations
    max_downloads INT UNSIGNED DEFAULT NULL COMMENT 'Maximum number of downloads allowed',
    max_access_count INT UNSIGNED DEFAULT NULL COMMENT 'Maximum number of times share can be accessed',
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Share expiration date',

    -- Tracking
    access_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of times accessed',
    download_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of downloads',
    last_accessed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last access timestamp',
    last_accessed_ip VARCHAR(45) COMMENT 'Last access IP address',

    -- Notification settings
    notify_on_access BOOLEAN DEFAULT FALSE COMMENT 'Email owner on access',
    notify_on_download BOOLEAN DEFAULT FALSE COMMENT 'Email owner on download',

    -- Share metadata
    custom_message TEXT COMMENT 'Custom message shown to recipients',
    branding JSON COMMENT 'Custom branding settings (logo, colors, etc.)',

    -- Status
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether share is currently active',
    revoked_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When share was revoked',
    revoked_by INT DEFAULT NULL COMMENT 'User who revoked the share',
    revoke_reason VARCHAR(500) COMMENT 'Reason for revocation',

    -- Ownership
    owner_id INT NOT NULL COMMENT 'User who created the share',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (revoked_by) REFERENCES users(id),
    UNIQUE KEY uk_share_token (share_token),
    INDEX idx_resource_shares (resource_type, resource_id),
    INDEX idx_active_shares (tenant_id, is_active, expires_at),
    CONSTRAINT chk_max_downloads CHECK (max_downloads IS NULL OR max_downloads > 0),
    CONSTRAINT chk_max_access CHECK (max_access_count IS NULL OR max_access_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Public and private file/folder sharing with access control';

-- Activity log table for audit trail
CREATE TABLE file_activity_logs (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Activity details
    activity_type ENUM('create', 'read', 'update', 'delete', 'move', 'copy', 'share', 'download', 'upload', 'restore', 'permission_change') NOT NULL,
    resource_type ENUM('file', 'folder') NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    resource_name VARCHAR(255) NOT NULL COMMENT 'Store name for historical reference',

    -- Additional context
    details JSON COMMENT 'Additional activity details',
    ip_address VARCHAR(45) COMMENT 'IP address of the action',
    user_agent VARCHAR(500) COMMENT 'User agent string',

    -- User who performed the action
    user_id INT NOT NULL,

    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_activity_lookup (tenant_id, resource_type, resource_id, created_at),
    INDEX idx_user_activity (tenant_id, user_id, created_at),
    INDEX idx_activity_date (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Comprehensive activity logging for audit trail'
PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027),
    PARTITION pfuture VALUES LESS THAN MAXVALUE
);

-- ============================================
-- INDEXES FOR OPTIMIZATION
-- ============================================

-- Folders indexes
CREATE INDEX idx_folders_tenant_lookup ON folders(tenant_id, deleted_at, parent_id);
CREATE INDEX idx_folders_path_search ON folders(tenant_id, full_path);
CREATE INDEX idx_folders_owner ON folders(tenant_id, owner_id, deleted_at);
CREATE INDEX idx_folders_hierarchy ON folders(tenant_id, parent_id, path_depth);
CREATE FULLTEXT INDEX ft_folders_name ON folders(name, description);

-- Files indexes
CREATE INDEX idx_files_tenant_lookup ON files(tenant_id, deleted_at, folder_id);
CREATE INDEX idx_files_version_group ON files(tenant_id, version_group_id, is_current);
CREATE INDEX idx_files_hash_dedup ON files(tenant_id, sha256_hash);
CREATE INDEX idx_files_owner ON files(tenant_id, owner_id, deleted_at);
CREATE INDEX idx_files_trash ON files(tenant_id, deleted_at, trash_expires_at);
CREATE INDEX idx_files_mime_type ON files(tenant_id, mime_type, deleted_at);
CREATE INDEX idx_files_search ON files(tenant_id, deleted_at, content_indexed);
CREATE FULLTEXT INDEX ft_files_name ON files(name, display_name);

-- Permissions indexes
CREATE INDEX idx_permissions_resource ON file_permissions(tenant_id, resource_type, resource_id, grant_type);
CREATE INDEX idx_permissions_target ON file_permissions(tenant_id, target_type, target_id);
CREATE INDEX idx_permissions_valid ON file_permissions(tenant_id, valid_until);

-- Shares indexes
CREATE INDEX idx_shares_expires ON file_shares(tenant_id, is_active, expires_at);
CREATE INDEX idx_shares_owner ON file_shares(tenant_id, owner_id, is_active);

-- ============================================
-- TRIGGERS
-- ============================================

DELIMITER $$

-- Trigger to update tenant storage usage after file insert
CREATE TRIGGER after_file_insert
AFTER INSERT ON files
FOR EACH ROW
BEGIN
    IF NEW.deleted_at IS NULL AND NEW.is_current = TRUE THEN
        UPDATE tenants
        SET storage_used = storage_used + NEW.size
        WHERE id = NEW.tenant_id;

        UPDATE folders
        SET file_count = file_count + 1,
            total_size = total_size + NEW.size
        WHERE id = NEW.folder_id;
    END IF;
END$$

-- Trigger to update tenant storage usage after file delete
CREATE TRIGGER after_file_delete
AFTER UPDATE ON files
FOR EACH ROW
BEGIN
    -- Soft delete
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL AND NEW.is_current = TRUE THEN
        UPDATE tenants
        SET storage_used = GREATEST(0, storage_used - NEW.size)
        WHERE id = NEW.tenant_id;

        UPDATE folders
        SET file_count = GREATEST(0, file_count - 1),
            total_size = GREATEST(0, total_size - NEW.size)
        WHERE id = NEW.folder_id;
    END IF;

    -- Restore from trash
    IF OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL AND NEW.is_current = TRUE THEN
        UPDATE tenants
        SET storage_used = storage_used + NEW.size
        WHERE id = NEW.tenant_id;

        UPDATE folders
        SET file_count = file_count + 1,
            total_size = total_size + NEW.size
        WHERE id = NEW.folder_id;
    END IF;
END$$

-- Trigger to maintain folder paths when parent changes
CREATE TRIGGER before_folder_update
BEFORE UPDATE ON folders
FOR EACH ROW
BEGIN
    DECLARE new_path VARCHAR(4096);
    DECLARE parent_path VARCHAR(4096);
    DECLARE parent_depth TINYINT UNSIGNED;

    -- Check if parent_id is changing
    IF OLD.parent_id != NEW.parent_id OR (OLD.parent_id IS NULL AND NEW.parent_id IS NOT NULL) OR (OLD.parent_id IS NOT NULL AND NEW.parent_id IS NULL) THEN

        IF NEW.parent_id IS NULL THEN
            -- Moving to root
            SET NEW.full_path = CONCAT('/', NEW.name);
            SET NEW.path_depth = 0;
        ELSE
            -- Get parent path and depth
            SELECT full_path, path_depth INTO parent_path, parent_depth
            FROM folders
            WHERE id = NEW.parent_id AND tenant_id = NEW.tenant_id;

            -- Calculate new path and depth
            SET NEW.full_path = CONCAT(parent_path, '/', NEW.name);
            SET NEW.path_depth = parent_depth + 1;
        END IF;
    END IF;

    -- Update path if name changes
    IF OLD.name != NEW.name THEN
        IF NEW.parent_id IS NULL THEN
            SET NEW.full_path = CONCAT('/', NEW.name);
        ELSE
            SELECT full_path INTO parent_path
            FROM folders
            WHERE id = NEW.parent_id AND tenant_id = NEW.tenant_id;

            SET NEW.full_path = CONCAT(parent_path, '/', NEW.name);
        END IF;
    END IF;
END$$

-- Trigger for version management
CREATE TRIGGER after_file_version_insert
BEFORE INSERT ON files
FOR EACH ROW
BEGIN
    DECLARE max_version INT;

    -- If this is a new version of an existing file
    IF NEW.version > 1 THEN
        -- Set all other versions to non-current
        UPDATE files
        SET is_current = FALSE
        WHERE tenant_id = NEW.tenant_id
          AND version_group_id = NEW.version_group_id
          AND id != NEW.id;

        -- Get the max version for this group
        SELECT COALESCE(MAX(version), 0) INTO max_version
        FROM files
        WHERE tenant_id = NEW.tenant_id
          AND version_group_id = NEW.version_group_id;

        -- Set the version number
        SET NEW.version = max_version + 1;
    END IF;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER $$

-- Calculate total size of a folder including subfolders
CREATE PROCEDURE calculate_folder_size(
    IN p_folder_id INT UNSIGNED,
    IN p_tenant_id INT
)
BEGIN
    WITH RECURSIVE folder_tree AS (
        -- Anchor: start with the specified folder
        SELECT id, parent_id, total_size
        FROM folders
        WHERE id = p_folder_id
          AND tenant_id = p_tenant_id
          AND deleted_at IS NULL

        UNION ALL

        -- Recursive: get all subfolders
        SELECT f.id, f.parent_id, f.total_size
        FROM folders f
        INNER JOIN folder_tree ft ON f.parent_id = ft.id
        WHERE f.tenant_id = p_tenant_id
          AND f.deleted_at IS NULL
    )
    SELECT
        p_folder_id as folder_id,
        COUNT(DISTINCT ft.id) as total_folders,
        COUNT(DISTINCT f.id) as total_files,
        COALESCE(SUM(f.size), 0) as total_size
    FROM folder_tree ft
    LEFT JOIN files f ON f.folder_id = ft.id
        AND f.tenant_id = p_tenant_id
        AND f.deleted_at IS NULL
        AND f.is_current = TRUE;
END$$

-- Get folder tree structure
CREATE PROCEDURE get_folder_tree(
    IN p_tenant_id INT,
    IN p_parent_id INT UNSIGNED
)
BEGIN
    WITH RECURSIVE folder_tree AS (
        -- Anchor: start with root or specified parent
        SELECT
            id,
            parent_id,
            name,
            full_path,
            path_depth,
            file_count,
            total_size,
            created_at,
            0 as level
        FROM folders
        WHERE tenant_id = p_tenant_id
          AND (
              (p_parent_id IS NULL AND parent_id IS NULL) OR
              (p_parent_id IS NOT NULL AND parent_id = p_parent_id)
          )
          AND deleted_at IS NULL

        UNION ALL

        -- Recursive: get all subfolders
        SELECT
            f.id,
            f.parent_id,
            f.name,
            f.full_path,
            f.path_depth,
            f.file_count,
            f.total_size,
            f.created_at,
            ft.level + 1
        FROM folders f
        INNER JOIN folder_tree ft ON f.parent_id = ft.id
        WHERE f.tenant_id = p_tenant_id
          AND f.deleted_at IS NULL
    )
    SELECT
        id,
        parent_id,
        name,
        full_path,
        CONCAT(REPEAT('  ', level), name) as display_name,
        level,
        file_count,
        total_size,
        created_at
    FROM folder_tree
    ORDER BY full_path;
END$$

-- Move folder to new parent
CREATE PROCEDURE move_folder(
    IN p_folder_id INT UNSIGNED,
    IN p_new_parent_id INT UNSIGNED,
    IN p_tenant_id INT,
    IN p_user_id INT
)
BEGIN
    DECLARE old_path VARCHAR(4096);
    DECLARE new_path VARCHAR(4096);
    DECLARE parent_path VARCHAR(4096);
    DECLARE folder_name VARCHAR(255);
    DECLARE new_depth TINYINT UNSIGNED;

    -- Start transaction
    START TRANSACTION;

    -- Get current folder info
    SELECT full_path, name INTO old_path, folder_name
    FROM folders
    WHERE id = p_folder_id AND tenant_id = p_tenant_id AND deleted_at IS NULL;

    IF old_path IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Folder not found';
    END IF;

    -- Check for circular reference
    IF p_new_parent_id IS NOT NULL THEN
        WITH RECURSIVE parent_tree AS (
            SELECT id, parent_id
            FROM folders
            WHERE id = p_new_parent_id AND tenant_id = p_tenant_id

            UNION ALL

            SELECT f.id, f.parent_id
            FROM folders f
            INNER JOIN parent_tree pt ON f.id = pt.parent_id
            WHERE f.tenant_id = p_tenant_id
        )
        SELECT COUNT(*) INTO @circular_check
        FROM parent_tree
        WHERE id = p_folder_id;

        IF @circular_check > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Circular reference detected';
        END IF;
    END IF;

    -- Calculate new path
    IF p_new_parent_id IS NULL THEN
        SET new_path = CONCAT('/', folder_name);
        SET new_depth = 0;
    ELSE
        SELECT full_path, path_depth INTO parent_path, new_depth
        FROM folders
        WHERE id = p_new_parent_id AND tenant_id = p_tenant_id AND deleted_at IS NULL;

        IF parent_path IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Parent folder not found';
        END IF;

        SET new_path = CONCAT(parent_path, '/', folder_name);
        SET new_depth = new_depth + 1;
    END IF;

    -- Update the folder
    UPDATE folders
    SET parent_id = p_new_parent_id,
        full_path = new_path,
        path_depth = new_depth,
        updated_by = p_user_id,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_folder_id AND tenant_id = p_tenant_id;

    -- Update all subfolders recursively
    WITH RECURSIVE subfolder_tree AS (
        SELECT id, full_path, path_depth
        FROM folders
        WHERE parent_id = p_folder_id AND tenant_id = p_tenant_id AND deleted_at IS NULL

        UNION ALL

        SELECT f.id, f.full_path, f.path_depth
        FROM folders f
        INNER JOIN subfolder_tree st ON f.parent_id = st.id
        WHERE f.tenant_id = p_tenant_id AND f.deleted_at IS NULL
    )
    UPDATE folders f
    INNER JOIN subfolder_tree st ON f.id = st.id
    SET f.full_path = REPLACE(f.full_path, old_path, new_path),
        f.path_depth = f.path_depth - (LENGTH(old_path) - LENGTH(REPLACE(old_path, '/', ''))) + new_depth + 1,
        f.updated_by = p_user_id,
        f.updated_at = CURRENT_TIMESTAMP;

    -- Log the activity
    INSERT INTO file_activity_logs (tenant_id, activity_type, resource_type, resource_id, resource_name, user_id, details)
    VALUES (p_tenant_id, 'move', 'folder', p_folder_id, folder_name, p_user_id,
            JSON_OBJECT('old_path', old_path, 'new_path', new_path, 'new_parent_id', p_new_parent_id));

    COMMIT;

    SELECT 'Folder moved successfully' as status, new_path as new_location;
END$$

-- Restore item from trash
CREATE PROCEDURE restore_from_trash(
    IN p_item_id INT UNSIGNED,
    IN p_item_type ENUM('file', 'folder'),
    IN p_tenant_id INT,
    IN p_user_id INT
)
BEGIN
    DECLARE item_name VARCHAR(255);

    -- Start transaction
    START TRANSACTION;

    IF p_item_type = 'file' THEN
        -- Check if file exists and is deleted
        SELECT name INTO item_name
        FROM files
        WHERE id = p_item_id
          AND tenant_id = p_tenant_id
          AND deleted_at IS NOT NULL;

        IF item_name IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'File not found in trash';
        END IF;

        -- Restore the file
        UPDATE files
        SET deleted_at = NULL,
            deleted_by = NULL,
            trash_expires_at = NULL,
            updated_by = p_user_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_item_id AND tenant_id = p_tenant_id;

        -- Log the activity
        INSERT INTO file_activity_logs (tenant_id, activity_type, resource_type, resource_id, resource_name, user_id)
        VALUES (p_tenant_id, 'restore', 'file', p_item_id, item_name, p_user_id);

    ELSEIF p_item_type = 'folder' THEN
        -- Check if folder exists and is deleted
        SELECT name INTO item_name
        FROM folders
        WHERE id = p_item_id
          AND tenant_id = p_tenant_id
          AND deleted_at IS NOT NULL;

        IF item_name IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Folder not found in trash';
        END IF;

        -- Restore the folder and all its contents
        UPDATE folders
        SET deleted_at = NULL,
            deleted_by = NULL,
            updated_by = p_user_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_item_id AND tenant_id = p_tenant_id;

        -- Restore all files in this folder
        UPDATE files
        SET deleted_at = NULL,
            deleted_by = NULL,
            trash_expires_at = NULL,
            updated_by = p_user_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE folder_id = p_item_id
          AND tenant_id = p_tenant_id
          AND deleted_at IS NOT NULL;

        -- Restore all subfolders recursively
        WITH RECURSIVE subfolder_tree AS (
            SELECT id
            FROM folders
            WHERE parent_id = p_item_id
              AND tenant_id = p_tenant_id
              AND deleted_at IS NOT NULL

            UNION ALL

            SELECT f.id
            FROM folders f
            INNER JOIN subfolder_tree st ON f.parent_id = st.id
            WHERE f.tenant_id = p_tenant_id AND f.deleted_at IS NOT NULL
        )
        UPDATE folders f
        INNER JOIN subfolder_tree st ON f.id = st.id
        SET f.deleted_at = NULL,
            f.deleted_by = NULL,
            f.updated_by = p_user_id,
            f.updated_at = CURRENT_TIMESTAMP;

        -- Log the activity
        INSERT INTO file_activity_logs (tenant_id, activity_type, resource_type, resource_id, resource_name, user_id)
        VALUES (p_tenant_id, 'restore', 'folder', p_item_id, item_name, p_user_id);
    END IF;

    COMMIT;

    SELECT 'Item restored successfully' as status, item_name as restored_item;
END$$

DELIMITER ;

-- ============================================
-- DEMO DATA
-- ============================================

-- Ensure we have sample tenants
INSERT INTO tenants (id, name, domain, storage_quota, storage_used, status) VALUES
    (1, 'Acme Corporation', 'acme.collabora.com', 107374182400, 0, 'active'),
    (2, 'TechStart Inc', 'techstart.collabora.com', 53687091200, 0, 'active')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    domain = VALUES(domain);

-- Ensure we have sample users
INSERT INTO users (id, tenant_id, email, username, first_name, last_name, password_hash, status, role) VALUES
    (1, 1, 'admin@acme.com', 'admin_acme', 'Admin', 'User', '$2y$10$YourHashHere', 'active', 'admin'),
    (2, 1, 'john.doe@acme.com', 'jdoe', 'John', 'Doe', '$2y$10$YourHashHere', 'active', 'user'),
    (3, 1, 'jane.smith@acme.com', 'jsmith', 'Jane', 'Smith', '$2y$10$YourHashHere', 'active', 'user'),
    (4, 2, 'admin@techstart.com', 'admin_tech', 'Tech', 'Admin', '$2y$10$YourHashHere', 'active', 'admin'),
    (5, 2, 'developer@techstart.com', 'dev_user', 'Dev', 'User', '$2y$10$YourHashHere', 'active', 'user')
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    username = VALUES(username);

-- Sample folders for Acme Corporation
INSERT INTO folders (tenant_id, parent_id, name, full_path, path_depth, owner_id, created_by, description, color, icon) VALUES
    (1, NULL, 'Documents', '/Documents', 0, 1, 1, 'Company documents', '#4285f4', 'folder'),
    (1, NULL, 'Projects', '/Projects', 0, 1, 1, 'Active projects', '#34a853', 'folder-open'),
    (1, NULL, 'Archive', '/Archive', 0, 1, 1, 'Archived files', '#808080', 'archive'),
    (1, 1, 'Policies', '/Documents/Policies', 1, 1, 1, 'Company policies', '#ea4335', 'shield'),
    (1, 1, 'Templates', '/Documents/Templates', 1, 1, 1, 'Document templates', '#fbbc04', 'template'),
    (1, 2, 'Project Alpha', '/Projects/Project Alpha', 1, 2, 2, 'Q1 2025 Project', '#4285f4', 'briefcase'),
    (1, 2, 'Project Beta', '/Projects/Project Beta', 1, 3, 3, 'Q2 2025 Project', '#34a853', 'briefcase'),
    (1, 6, 'Design', '/Projects/Project Alpha/Design', 2, 2, 2, 'Design files', '#e91e63', 'palette'),
    (1, 6, 'Development', '/Projects/Project Alpha/Development', 2, 2, 2, 'Source code', '#9c27b0', 'code');

-- Sample folders for TechStart Inc
INSERT INTO folders (tenant_id, parent_id, name, full_path, path_depth, owner_id, created_by, description) VALUES
    (2, NULL, 'Engineering', '/Engineering', 0, 4, 4, 'Engineering resources'),
    (2, NULL, 'Marketing', '/Marketing', 0, 4, 4, 'Marketing materials'),
    (2, 10, 'Backend', '/Engineering/Backend', 1, 5, 5, 'Backend services'),
    (2, 10, 'Frontend', '/Engineering/Frontend', 1, 5, 5, 'Frontend applications');

-- Sample files with versions for Acme Corporation
INSERT INTO files (
    tenant_id, folder_id, name, display_name, version, version_group_id, is_current,
    size, mime_type, extension, storage_path, sha256_hash, owner_id, created_by, metadata
) VALUES
    -- Documents in Policies folder
    (1, 4, 'employee_handbook_v2.pdf', 'Employee Handbook', 2, 'uuid-handbook-001', TRUE,
     2457600, 'application/pdf', 'pdf', '/storage/tenant1/2025/01/handbook_v2.pdf',
     'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 1, 1,
     '{"pages": 48, "author": "HR Department", "version": "2.0", "last_review": "2025-01-15"}'),

    (1, 4, 'employee_handbook_v1.pdf', 'Employee Handbook', 1, 'uuid-handbook-001', FALSE,
     2097152, 'application/pdf', 'pdf', '/storage/tenant1/2024/12/handbook_v1.pdf',
     'b665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae4', 1, 1,
     '{"pages": 42, "author": "HR Department", "version": "1.0"}'),

    -- Template files
    (1, 5, 'proposal_template.docx', 'Proposal Template', 1, 'uuid-template-001', TRUE,
     524288, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx',
     '/storage/tenant1/2025/01/proposal_template.docx',
     'c665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae5', 1, 1,
     '{"template_type": "proposal", "department": "Sales"}'),

    -- Project Alpha files
    (1, 8, 'mockup_homepage.fig', 'Homepage Mockup', 1, 'uuid-design-001', TRUE,
     8388608, 'application/octet-stream', 'fig', '/storage/tenant1/2025/01/mockup_homepage.fig',
     'd665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae6', 2, 2,
     '{"tool": "Figma", "dimensions": "1920x1080", "components": 24}'),

    (1, 9, 'api_service.zip', 'API Service Code', 1, 'uuid-code-001', TRUE,
     15728640, 'application/zip', 'zip', '/storage/tenant1/2025/01/api_service.zip',
     'e665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae7', 2, 2,
     '{"language": "Python", "framework": "FastAPI", "lines_of_code": 5420}');

-- Sample files for TechStart Inc
INSERT INTO files (
    tenant_id, folder_id, name, display_name, version, version_group_id, is_current,
    size, mime_type, extension, storage_path, sha256_hash, owner_id, created_by
) VALUES
    (2, 12, 'database_schema.sql', 'Database Schema', 1, 'uuid-schema-001', TRUE,
     65536, 'text/plain', 'sql', '/storage/tenant2/2025/01/schema.sql',
     'f665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae8', 5, 5),

    (2, 13, 'app.js', 'Main Application', 1, 'uuid-app-001', TRUE,
     131072, 'text/javascript', 'js', '/storage/tenant2/2025/01/app.js',
     '0665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae9', 5, 5);

-- Sample permissions
INSERT INTO file_permissions (
    tenant_id, resource_type, resource_id, target_type, target_id,
    can_read, can_write, can_delete, can_share, can_manage, created_by
) VALUES
    -- John Doe can read and write to Project Alpha folder
    (1, 'folder', 6, 'user', 2, TRUE, TRUE, FALSE, FALSE, FALSE, 1),
    -- Jane Smith has full access to Project Beta folder
    (1, 'folder', 7, 'user', 3, TRUE, TRUE, TRUE, TRUE, TRUE, 1),
    -- Everyone in tenant 1 can read Templates folder
    (1, 'folder', 5, 'role', 1, TRUE, FALSE, FALSE, FALSE, FALSE, 1);

-- Sample file shares
INSERT INTO file_shares (
    tenant_id, share_token, share_type, resource_type, resource_id,
    title, description, allow_download, expires_at, owner_id, created_by
) VALUES
    -- Public share for employee handbook
    (1, SHA2(CONCAT('share', NOW(), RAND()), 256), 'public', 'file', 1,
     'Employee Handbook 2025', 'Latest version of our employee handbook', TRUE,
     DATE_ADD(NOW(), INTERVAL 30 DAY), 1, 1),

    -- Password-protected share for Project Alpha folder
    (1, SHA2(CONCAT('share', NOW(), RAND()), 256), 'password', 'folder', 6,
     'Project Alpha Files', 'Confidential project files', TRUE,
     DATE_ADD(NOW(), INTERVAL 7 DAY), 2, 2);

-- Sample activity logs
INSERT INTO file_activity_logs (
    tenant_id, activity_type, resource_type, resource_id, resource_name, user_id, details
) VALUES
    (1, 'upload', 'file', 1, 'employee_handbook_v2.pdf', 1, '{"size": 2457600, "version": 2}'),
    (1, 'create', 'folder', 6, 'Project Alpha', 2, '{"parent": "Projects"}'),
    (1, 'share', 'file', 1, 'employee_handbook_v2.pdf', 1, '{"share_type": "public", "expires_in_days": 30}'),
    (2, 'upload', 'file', 6, 'database_schema.sql', 5, '{"size": 65536}');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Show summary of created objects
SELECT 'Database setup completed successfully' as status, NOW() as execution_time;

SELECT 'Tables Created' as object_type, COUNT(*) as count
FROM information_schema.tables
WHERE table_schema = 'collabora'
  AND table_name IN ('folders', 'files', 'file_permissions', 'file_shares', 'file_activity_logs');

SELECT 'Indexes Created' as object_type, COUNT(DISTINCT index_name) as count
FROM information_schema.statistics
WHERE table_schema = 'collabora'
  AND table_name IN ('folders', 'files', 'file_permissions', 'file_shares', 'file_activity_logs')
  AND index_name != 'PRIMARY';

SELECT 'Triggers Created' as object_type, COUNT(*) as count
FROM information_schema.triggers
WHERE trigger_schema = 'collabora';

SELECT 'Procedures Created' as object_type, COUNT(*) as count
FROM information_schema.routines
WHERE routine_schema = 'collabora'
  AND routine_type = 'PROCEDURE';

-- Show sample data statistics
SELECT
    'Data Statistics' as category,
    (SELECT COUNT(*) FROM folders) as total_folders,
    (SELECT COUNT(*) FROM files) as total_files,
    (SELECT COUNT(*) FROM file_permissions) as total_permissions,
    (SELECT COUNT(*) FROM file_shares) as total_shares,
    (SELECT COUNT(*) FROM file_activity_logs) as activity_logs;

-- Show storage usage by tenant
SELECT
    t.id as tenant_id,
    t.name as tenant_name,
    COUNT(DISTINCT f.id) as file_count,
    COALESCE(SUM(f.size), 0) as total_storage_bytes,
    ROUND(COALESCE(SUM(f.size), 0) / 1048576, 2) as total_storage_mb
FROM tenants t
LEFT JOIN files f ON f.tenant_id = t.id AND f.deleted_at IS NULL AND f.is_current = TRUE
GROUP BY t.id, t.name;

-- ============================================
-- MAINTENANCE QUERIES (for reference)
-- ============================================

/*
-- Clean up old trash items (run periodically)
DELETE FROM files
WHERE deleted_at IS NOT NULL
  AND trash_expires_at < NOW();

-- Find duplicate files by hash
SELECT
    sha256_hash,
    COUNT(*) as duplicate_count,
    SUM(size) as total_size,
    GROUP_CONCAT(name) as file_names
FROM files
WHERE deleted_at IS NULL AND is_current = TRUE
GROUP BY sha256_hash
HAVING COUNT(*) > 1;

-- Find large files
SELECT
    name,
    ROUND(size / 1048576, 2) as size_mb,
    mime_type,
    created_at
FROM files
WHERE deleted_at IS NULL AND is_current = TRUE
ORDER BY size DESC
LIMIT 10;

-- Check folder integrity
SELECT
    f1.id,
    f1.name,
    f1.full_path,
    f1.parent_id,
    f2.name as parent_name
FROM folders f1
LEFT JOIN folders f2 ON f1.parent_id = f2.id
WHERE f1.deleted_at IS NULL
  AND f1.parent_id IS NOT NULL
  AND f2.id IS NULL;
*/