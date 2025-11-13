-- ============================================
-- ACTUAL FILES TABLE SCHEMA
-- Documented as verified on 2025-10-04
-- Database Architect
-- ============================================

-- This is the ACTUAL schema currently in production
-- Use this as the source of truth for development

USE collaboranexio;

-- ============================================
-- FILES TABLE (Unified files & folders)
-- ============================================

-- Note: This table serves BOTH files and folders
-- Use is_folder flag to distinguish between them

CREATE TABLE IF NOT EXISTS files (
    -- Primary Key
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy Support
    tenant_id INT(10) UNSIGNED NULL,
    original_tenant_id INT(10) UNSIGNED NULL COMMENT 'For cross-tenant shared files',

    -- Core Fields
    name VARCHAR(255) NOT NULL COMMENT 'File or folder name',
    file_path VARCHAR(500) NULL COMMENT 'Storage path',
    file_type VARCHAR(50) NULL COMMENT 'File extension or "folder"',
    file_size BIGINT(20) NULL DEFAULT 0 COMMENT 'Size in bytes',
    mime_type VARCHAR(100) NULL,

    -- NEW: Approval Workflow
    status VARCHAR(50) NULL COMMENT 'in_approvazione, approvato, rifiutato',

    -- Folder Support
    is_folder TINYINT(1) NULL DEFAULT 0,
    folder_id INT(10) UNSIGNED NULL COMMENT 'Parent folder (self-referencing)',

    -- User Tracking
    uploaded_by INT(10) UNSIGNED NULL,

    -- File Management
    original_name VARCHAR(255) NULL,

    -- Sharing Features
    is_public TINYINT(1) NULL DEFAULT 0,
    public_token VARCHAR(64) NULL,
    shared_with LONGTEXT NULL COMMENT 'JSON array of user IDs',

    -- Analytics
    download_count INT(11) NULL DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Soft Delete
    deleted_at TIMESTAMP NULL,

    -- Cross-Tenant Reassignment
    reassigned_at TIMESTAMP NULL,
    reassigned_by INT(10) UNSIGNED NULL,

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_files_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_files_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_files_folder
        FOREIGN KEY (folder_id) REFERENCES files(id)
        ON DELETE SET NULL,

    -- Check Constraints
    CONSTRAINT chk_files_status
        CHECK (status IN ('in_approvazione', 'approvato', 'rifiutato', NULL)),

    CONSTRAINT shared_with
        CHECK (json_valid(`shared_with`))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INDEXES
-- ============================================

-- Primary Key (auto-created)
-- CREATE INDEX PRIMARY ON files(id);

-- Multi-Tenant Queries
CREATE INDEX IF NOT EXISTS idx_tenant ON files(tenant_id);
CREATE INDEX IF NOT EXISTS idx_original_tenant ON files(original_tenant_id);

-- Folder Hierarchy
CREATE INDEX IF NOT EXISTS idx_folder ON files(folder_id);

-- File Type Filtering
CREATE INDEX IF NOT EXISTS idx_type ON files(file_type);

-- User File Queries
CREATE INDEX IF NOT EXISTS idx_uploaded_by ON files(uploaded_by);

-- File Name Searches
CREATE INDEX IF NOT EXISTS idx_name ON files(name);

-- Soft Delete Queries
CREATE INDEX IF NOT EXISTS idx_deleted ON files(deleted_at);

-- Approval Workflow (Composite Index)
CREATE INDEX IF NOT EXISTS idx_tenant_status ON files(tenant_id, status);

-- ============================================
-- COMMON QUERY PATTERNS
-- ============================================

-- Pattern 1: List files for a tenant (excluding folders and deleted)
-- SELECT * FROM files
-- WHERE tenant_id = ?
-- AND is_folder = 0
-- AND deleted_at IS NULL
-- ORDER BY created_at DESC;

-- Pattern 2: Get folder hierarchy
-- SELECT * FROM files
-- WHERE tenant_id = ?
-- AND is_folder = 1
-- AND folder_id IS NULL  -- root folders
-- AND deleted_at IS NULL;

-- Pattern 3: Approval workflow - pending documents
-- SELECT f.*, u.name as uploader_name
-- FROM files f
-- LEFT JOIN users u ON f.uploaded_by = u.id
-- WHERE f.tenant_id = ?
-- AND f.status = 'in_approvazione'
-- AND f.deleted_at IS NULL;

-- Pattern 4: User's uploaded files
-- SELECT * FROM files
-- WHERE tenant_id = ?
-- AND uploaded_by = ?
-- AND deleted_at IS NULL;

-- Pattern 5: Search by name
-- SELECT * FROM files
-- WHERE tenant_id = ?
-- AND name LIKE ?
-- AND deleted_at IS NULL;

-- ============================================
-- IMPORTANT NOTES FOR DEVELOPERS
-- ============================================

/*
1. COLUMN NAMING:
   - Use `name` NOT `file_name`
   - Use `file_size` NOT `size`
   - Use `file_path` NOT `path`
   - Use `uploaded_by` NOT `user_id`

2. USERS TABLE:
   - User name column is `name` NOT `username`
   - Always join: LEFT JOIN users u ON f.uploaded_by = u.id

3. MULTI-TENANT QUERIES:
   - ALWAYS filter by tenant_id
   - ALWAYS check deleted_at IS NULL
   - Use idx_tenant_status for approval queries

4. FOLDER LOGIC:
   - is_folder = 1 means it's a folder
   - is_folder = 0 means it's a file
   - Folders can contain files (folder_id references parent)
   - Self-referencing FK allows nested folders

5. STATUS VALUES:
   - 'in_approvazione' - Pending approval
   - 'approvato' - Approved
   - 'rifiutato' - Rejected
   - NULL - No approval required (default)

6. SOFT DELETE:
   - NEVER use DELETE FROM files
   - Always use: UPDATE files SET deleted_at = NOW() WHERE id = ?
   - Always filter: WHERE deleted_at IS NULL

7. FOREIGN KEYS:
   - All FKs use ON DELETE SET NULL
   - tenant_id CANNOT be NOT NULL due to FK policy
   - Application must ensure tenant_id is set on INSERT
*/

-- ============================================
-- VERIFICATION QUERY
-- ============================================

-- Run this to verify your schema matches:
-- DESCRIBE files;

-- Expected output should match the structure above
