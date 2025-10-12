-- Migration: File Upload System Enhancement
-- Date: 2025-01-12
-- Purpose: Add missing columns to files table for complete upload system support

-- Add missing columns to files table if they don't exist
ALTER TABLE files
ADD COLUMN IF NOT EXISTS is_folder BOOLEAN DEFAULT 0 AFTER uploaded_by,
ADD COLUMN IF NOT EXISTS is_editable BOOLEAN DEFAULT 0 AFTER is_folder,
ADD COLUMN IF NOT EXISTS editor_format VARCHAR(10) NULL AFTER is_editable,
ADD COLUMN IF NOT EXISTS extension VARCHAR(10) NULL AFTER mime_type,
ADD COLUMN IF NOT EXISTS file_hash VARCHAR(64) NULL AFTER editor_format,
ADD COLUMN IF NOT EXISTS thumbnail_path VARCHAR(500) NULL AFTER file_hash,
ADD COLUMN IF NOT EXISTS folder_id INT NULL AFTER tenant_id,
ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;

-- Add indexes for better performance
ALTER TABLE files
ADD INDEX IF NOT EXISTS idx_files_tenant_folder (tenant_id, folder_id),
ADD INDEX IF NOT EXISTS idx_files_deleted_at (deleted_at),
ADD INDEX IF NOT EXISTS idx_files_is_folder (is_folder),
ADD INDEX IF NOT EXISTS idx_files_extension (extension);

-- Add foreign key for folder_id (self-referencing)
ALTER TABLE files
ADD CONSTRAINT IF NOT EXISTS fk_files_folder
FOREIGN KEY (folder_id) REFERENCES files(id)
ON DELETE CASCADE;

-- Create document_editor table if not exists
CREATE TABLE IF NOT EXISTS document_editor (
    id INT PRIMARY KEY AUTO_INCREMENT,
    file_id INT NOT NULL,
    tenant_id INT NOT NULL,
    document_type VARCHAR(20) NOT NULL,
    version_count INT DEFAULT 0,
    last_edited_by INT NULL,
    last_edited_at TIMESTAMP NULL,
    is_locked BOOLEAN DEFAULT 0,
    locked_by INT NULL,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_document_editor_file (file_id),
    INDEX idx_document_editor_tenant (tenant_id),

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (last_edited_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create document_versions table if not exists
CREATE TABLE IF NOT EXISTS document_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    file_id INT NOT NULL,
    tenant_id INT NOT NULL,
    version_number INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comment TEXT NULL,

    INDEX idx_versions_file (file_id),
    INDEX idx_versions_tenant (tenant_id),
    UNIQUE KEY unique_file_version (file_id, version_number),

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create editor_sessions table if not exists
CREATE TABLE IF NOT EXISTS editor_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    file_id INT NOT NULL,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    editor_key VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,

    INDEX idx_sessions_file (file_id),
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_tenant (tenant_id),
    INDEX idx_sessions_token (session_token),
    INDEX idx_sessions_active (is_active),

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update existing files to set is_folder for directories
UPDATE files
SET is_folder = 1,
    mime_type = 'inode/directory'
WHERE mime_type = 'directory' OR path LIKE '%/$';

-- Set is_editable for known document types
UPDATE files
SET is_editable = 1,
    editor_format = CASE
        WHEN extension IN ('doc', 'docx', 'odt', 'txt', 'rtf') THEN 'word'
        WHEN extension IN ('xls', 'xlsx', 'ods', 'csv') THEN 'cell'
        WHEN extension IN ('ppt', 'pptx', 'odp') THEN 'slide'
        ELSE NULL
    END
WHERE extension IN ('doc', 'docx', 'odt', 'txt', 'rtf', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp');

-- Extract extensions from existing file names if not set
UPDATE files
SET extension = LOWER(SUBSTRING_INDEX(name, '.', -1))
WHERE extension IS NULL
  AND name LIKE '%.%'
  AND is_folder = 0;

-- Create sample root folders for existing tenants
INSERT INTO files (tenant_id, name, path, mime_type, is_folder, uploaded_by, created_at, updated_at)
SELECT
    t.id,
    'Documenti',
    CONCAT('uploads/', t.id, '/documenti'),
    'inode/directory',
    1,
    (SELECT id FROM users WHERE tenant_id = t.id ORDER BY id LIMIT 1),
    NOW(),
    NOW()
FROM tenants t
LEFT JOIN files f ON f.tenant_id = t.id AND f.name = 'Documenti' AND f.is_folder = 1
WHERE t.deleted_at IS NULL
  AND f.id IS NULL;

-- Add audit log entry for migration
INSERT INTO audit_logs (
    tenant_id,
    user_id,
    action,
    entity_type,
    details,
    created_at
) VALUES (
    1,
    1,
    'database_migration',
    'files_table',
    '{"migration": "files_upload_system", "changes": "Added upload system support columns"}',
    NOW()
);