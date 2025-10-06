-- Module: Document Management System (Fixed for XAMPP)
-- Version: 2025-01-22
-- Fixed for MariaDB/MySQL compatibility

USE collabora;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
DROP TABLE IF EXISTS file_activity_logs;
DROP TABLE IF EXISTS file_shares;
DROP TABLE IF EXISTS file_permissions;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS folders;

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
    full_path VARCHAR(4096) NOT NULL COMMENT 'Full path from root for fast queries',
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
    INDEX idx_folders_tenant (tenant_id),
    INDEX idx_folders_parent (parent_id),
    INDEX idx_folders_owner (owner_id),
    INDEX idx_folders_created_by (created_by),
    INDEX idx_folders_updated_by (updated_by),
    INDEX idx_folders_deleted_by (deleted_by),
    UNIQUE KEY uk_folder_path (tenant_id, full_path, deleted_at),
    UNIQUE KEY uk_folder_name (tenant_id, parent_id, name, deleted_at)
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
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_version_id) REFERENCES files(id),
    INDEX idx_files_tenant (tenant_id),
    INDEX idx_files_owner (owner_id),
    INDEX idx_files_created_by (created_by),
    INDEX idx_files_updated_by (updated_by),
    INDEX idx_files_deleted_by (deleted_by),
    UNIQUE KEY uk_file_current_version (tenant_id, folder_id, name, is_current, deleted_at)
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

    -- Granular permissions
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
    INDEX idx_permissions_tenant (tenant_id),
    INDEX idx_permissions_created_by (created_by),
    UNIQUE KEY uk_permission (tenant_id, resource_type, resource_id, target_type, target_id),
    INDEX idx_resource_lookup (resource_type, resource_id),
    INDEX idx_target_lookup (target_type, target_id)
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
    INDEX idx_shares_tenant (tenant_id),
    INDEX idx_shares_owner (owner_id),
    INDEX idx_shares_created_by (created_by),
    INDEX idx_shares_revoked_by (revoked_by),
    UNIQUE KEY uk_share_token (share_token),
    INDEX idx_resource_shares (resource_type, resource_id),
    INDEX idx_active_shares (tenant_id, is_active, expires_at)
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
    INDEX idx_activity_tenant (tenant_id),
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_lookup (tenant_id, resource_type, resource_id, created_at),
    INDEX idx_user_activity (tenant_id, user_id, created_at),
    INDEX idx_activity_date (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Comprehensive activity logging for audit trail';

-- ============================================
-- INDEXES FOR OPTIMIZATION
-- ============================================

-- Folders indexes
CREATE INDEX idx_folders_tenant_lookup ON folders(tenant_id, deleted_at, parent_id);
CREATE INDEX idx_folders_path_search ON folders(tenant_id, full_path);
CREATE INDEX idx_folders_owner_lookup ON folders(tenant_id, owner_id, deleted_at);
CREATE INDEX idx_folders_hierarchy ON folders(tenant_id, parent_id, path_depth);
CREATE FULLTEXT INDEX ft_folders_name ON folders(name, description);

-- Files indexes
CREATE INDEX idx_files_tenant_lookup ON files(tenant_id, deleted_at, folder_id);
CREATE INDEX idx_files_version_group ON files(tenant_id, version_group_id, is_current);
CREATE INDEX idx_files_hash_dedup ON files(tenant_id, sha256_hash);
CREATE INDEX idx_files_owner_lookup ON files(tenant_id, owner_id, deleted_at);
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
CREATE INDEX idx_shares_owner_lookup ON file_shares(tenant_id, owner_id, is_active);

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample folders for testing
INSERT INTO folders (tenant_id, parent_id, name, full_path, path_depth, owner_id, created_by, description, color, icon) VALUES
    (1, NULL, 'Documents', '/Documents', 0, 1, 1, 'Company documents', '#4285f4', 'folder'),
    (1, NULL, 'Projects', '/Projects', 0, 1, 1, 'Active projects', '#34a853', 'folder-open'),
    (1, NULL, 'Archive', '/Archive', 0, 1, 1, 'Archived files', '#808080', 'archive')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample files for testing
INSERT INTO files (
    tenant_id, folder_id, name, display_name, version, version_group_id, is_current,
    size, mime_type, extension, storage_path, sha256_hash, owner_id, created_by
) VALUES
    (1, 1, 'sample.pdf', 'Sample Document', 1, 'uuid-sample-001', TRUE,
     1024000, 'application/pdf', 'pdf', '/storage/tenant1/sample.pdf',
     'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 1, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

SELECT 'Phase 2: Document Management System installed successfully' as status;