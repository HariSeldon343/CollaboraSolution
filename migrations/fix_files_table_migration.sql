-- ============================================
-- ⚠️ OBSOLETE - DO NOT RUN THIS MIGRATION ⚠️
-- ============================================
-- This migration is OBSOLETE and should NOT be executed.
--
-- Reason: The production database already has the correct schema:
-- - file_size (correct)
-- - file_path (correct)
-- - uploaded_by (correct)
--
-- This migration attempted to migrate TO those columns, but the database
-- already has them. Running this would destroy working data.
--
-- Date Obsoleted: 2025-10-03
-- Schema Drift Fix: Code was updated to match existing database schema
-- See: /database/SCHEMA_DRIFT_ANALYSIS_REPORT.md
-- ============================================

-- ORIGINAL HEADER (for reference):
-- Module: Files Table Migration
-- Version: 2025-09-27
-- Author: Database Architect
-- Description: Safe migration script to fix the files table structure

-- WRONG DATABASE NAME (should be collaboranexio, not collabora)
USE collabora;

-- ============================================
-- BACKUP EXISTING DATA
-- ============================================
-- Create backup of existing files table if it exists
DROP TABLE IF EXISTS files_backup_20250927;

-- Check if files table exists and create backup
CREATE TABLE IF NOT EXISTS files_backup_20250927 AS
SELECT * FROM files WHERE 1=1;

-- ============================================
-- DROP DEPENDENT OBJECTS
-- ============================================
-- Drop views that depend on files table
DROP VIEW IF EXISTS active_files;

-- Drop tables that have foreign keys to files
DROP TABLE IF EXISTS file_activity_logs;
DROP TABLE IF EXISTS file_permissions;

-- ============================================
-- RECREATE FILES TABLE WITH CORRECT STRUCTURE
-- ============================================
DROP TABLE IF EXISTS files;

CREATE TABLE files (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core file/folder fields
    name VARCHAR(255) NOT NULL COMMENT 'Name of the file or folder',
    file_path VARCHAR(500) NOT NULL COMMENT 'Full path to the file',
    file_type VARCHAR(50) DEFAULT NULL COMMENT 'File extension (pdf, doc, etc)',
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
    mime_type VARCHAR(100) DEFAULT NULL COMMENT 'MIME type of the file',

    -- Folder structure fields
    is_folder BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE if this is a folder',
    folder_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent folder ID for hierarchical structure',

    -- User tracking
    uploaded_by INT NOT NULL COMMENT 'User who uploaded the file',

    -- Additional metadata
    original_name VARCHAR(255) DEFAULT NULL COMMENT 'Original filename when uploaded',
    description TEXT DEFAULT NULL COMMENT 'File or folder description',

    -- Sharing and permissions
    is_public BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether file is publicly accessible',
    public_token VARCHAR(64) DEFAULT NULL COMMENT 'Token for public access',
    shared_with JSON DEFAULT NULL COMMENT 'JSON array of user IDs with access',

    -- Statistics
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_accessed_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Constraints
    PRIMARY KEY (id),
    CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_files_parent_folder FOREIGN KEY (folder_id) REFERENCES files(id) ON DELETE CASCADE,

    -- Check constraints
    CONSTRAINT chk_folder_consistency CHECK (
        (is_folder = TRUE AND file_size = 0 AND file_type IS NULL AND mime_type IS NULL) OR
        (is_folder = FALSE)
    ),
    CONSTRAINT chk_public_token CHECK (
        (is_public = TRUE AND public_token IS NOT NULL) OR
        (is_public = FALSE)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================
-- Composite index for multi-tenant queries
CREATE INDEX idx_files_tenant_lookup ON files(tenant_id, deleted_at, is_folder);
CREATE INDEX idx_files_folder_structure ON files(tenant_id, folder_id, name);
CREATE INDEX idx_files_uploaded_by ON files(uploaded_by, created_at);
CREATE INDEX idx_files_public_access ON files(is_public, public_token);
CREATE INDEX idx_files_file_type ON files(file_type, tenant_id);
CREATE INDEX idx_files_deleted ON files(deleted_at);
CREATE INDEX idx_files_folder_contents ON files(folder_id, deleted_at, name);

-- ============================================
-- RECREATE DEPENDENT TABLES
-- ============================================
-- File permissions table
CREATE TABLE file_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT UNSIGNED NOT NULL,
    user_id INT DEFAULT NULL COMMENT 'NULL means all tenant users',
    permission ENUM('view', 'download', 'edit', 'delete', 'share') NOT NULL DEFAULT 'view',
    granted_by INT NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Permission expiration',

    PRIMARY KEY (id),
    UNIQUE KEY unique_file_user_permission (file_id, user_id, permission),
    INDEX idx_permission_file (file_id),
    INDEX idx_permission_user (user_id),
    INDEX idx_permission_expires (expires_at),

    CONSTRAINT fk_permissions_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File activity logs table
CREATE TABLE file_activity_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    action ENUM('upload', 'download', 'view', 'edit', 'delete', 'restore', 'share', 'move', 'rename', 'create_folder') NOT NULL,
    details JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_log_file (file_id, created_at),
    INDEX idx_log_user (user_id, created_at),
    INDEX idx_log_tenant (tenant_id, created_at),
    INDEX idx_log_action (action, created_at),

    CONSTRAINT fk_logs_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- RESTORE DATA FROM BACKUP
-- ============================================
-- Migrate data from backup table if it exists
-- This will attempt to map old columns to new structure
INSERT INTO files (
    tenant_id,
    id,
    name,
    file_path,
    file_type,
    file_size,
    mime_type,
    is_folder,
    folder_id,
    uploaded_by,
    original_name,
    is_public,
    public_token,
    shared_with,
    download_count,
    last_accessed_at,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    tenant_id,
    id,
    COALESCE(file_name, original_name, 'unnamed_file') as name,
    file_path,
    file_type,
    COALESCE(file_size, 0) as file_size,
    mime_type,
    COALESCE(is_folder, 0) as is_folder,
    folder_id,
    uploaded_by,
    original_name,
    COALESCE(is_public, 0) as is_public,
    public_token,
    shared_with,
    COALESCE(download_count, 0) as download_count,
    last_accessed_at,
    created_at,
    updated_at,
    deleted_at
FROM files_backup_20250927
WHERE EXISTS (SELECT 1 FROM files_backup_20250927 LIMIT 1);

-- ============================================
-- CREATE DEFAULT FOLDERS
-- ============================================
-- Create root folders for each active tenant
INSERT INTO files (tenant_id, name, file_path, is_folder, uploaded_by, created_at)
SELECT
    t.id as tenant_id,
    'Documents' as name,
    CONCAT('/tenant_', t.id, '/documents') as file_path,
    TRUE as is_folder,
    COALESCE(
        (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1),
        (SELECT id FROM users WHERE tenant_id = t.id ORDER BY id LIMIT 1)
    ) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Documents'
    AND f.is_folder = TRUE
    AND f.deleted_at IS NULL
);

INSERT INTO files (tenant_id, name, file_path, is_folder, uploaded_by, created_at)
SELECT
    t.id as tenant_id,
    'Projects' as name,
    CONCAT('/tenant_', t.id, '/projects') as file_path,
    TRUE as is_folder,
    COALESCE(
        (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1),
        (SELECT id FROM users WHERE tenant_id = t.id ORDER BY id LIMIT 1)
    ) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Projects'
    AND f.is_folder = TRUE
    AND f.deleted_at IS NULL
);

INSERT INTO files (tenant_id, name, file_path, is_folder, uploaded_by, created_at)
SELECT
    t.id as tenant_id,
    'Shared' as name,
    CONCAT('/tenant_', t.id, '/shared') as file_path,
    TRUE as is_folder,
    COALESCE(
        (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1),
        (SELECT id FROM users WHERE tenant_id = t.id ORDER BY id LIMIT 1)
    ) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Shared'
    AND f.is_folder = TRUE
    AND f.deleted_at IS NULL
);

-- ============================================
-- RECREATE VIEWS
-- ============================================
CREATE OR REPLACE VIEW active_files AS
SELECT * FROM files
WHERE deleted_at IS NULL;

-- ============================================
-- DEMO DATA (Only if table was empty)
-- ============================================
-- Add demo data only if no data was migrated
INSERT INTO files (tenant_id, name, file_path, file_type, file_size, mime_type, is_folder, folder_id, uploaded_by)
SELECT
    1 as tenant_id,
    'Sample Document.pdf' as name,
    '/tenant_1/documents/sample_document.pdf' as file_path,
    'pdf' as file_type,
    1048576 as file_size,
    'application/pdf' as mime_type,
    FALSE as is_folder,
    (SELECT id FROM files WHERE tenant_id = 1 AND name = 'Documents' AND is_folder = TRUE LIMIT 1) as folder_id,
    (SELECT id FROM users WHERE tenant_id = 1 LIMIT 1) as uploaded_by
WHERE NOT EXISTS (SELECT 1 FROM files WHERE tenant_id = 1 AND is_folder = FALSE LIMIT 1)
AND EXISTS (SELECT 1 FROM tenants WHERE id = 1)
AND EXISTS (SELECT 1 FROM users WHERE tenant_id = 1);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT
    'Migration completed successfully' as status,
    (SELECT COUNT(*) FROM files) as total_files,
    (SELECT COUNT(*) FROM files WHERE is_folder = TRUE) as total_folders,
    (SELECT COUNT(*) FROM files WHERE is_folder = FALSE) as total_documents,
    (SELECT COUNT(*) FROM files_backup_20250927) as backed_up_records,
    NOW() as execution_time;