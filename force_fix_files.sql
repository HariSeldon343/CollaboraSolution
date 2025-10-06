-- Force Fix Files Table
-- This script forcefully recreates the files table

SET FOREIGN_KEY_CHECKS = 0;

-- Drop all dependent objects
DROP TABLE IF EXISTS file_activity_logs;
DROP TABLE IF EXISTS file_permissions;
DROP VIEW IF EXISTS active_files;

-- Backup existing files table if it has data
CREATE TABLE IF NOT EXISTS files_backup_force LIKE files;
INSERT IGNORE INTO files_backup_force SELECT * FROM files;

-- Drop the existing files table
DROP TABLE IF EXISTS files;

-- Create new files table with correct structure
CREATE TABLE files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500),
    file_type VARCHAR(50),
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100),
    is_folder BOOLEAN DEFAULT 0,
    folder_id INT UNSIGNED NULL,
    uploaded_by INT,
    original_name VARCHAR(255),
    is_public BOOLEAN DEFAULT 0,
    public_token VARCHAR(64),
    shared_with JSON,
    download_count INT DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_folder (folder_id),
    INDEX idx_deleted (deleted_at),
    INDEX idx_name (name),
    INDEX idx_type (file_type),
    INDEX idx_uploaded_by (uploaded_by),
    FOREIGN KEY (folder_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restore data from backup with field mapping
INSERT INTO files (
    tenant_id,
    name,
    file_path,
    mime_type,
    file_size,
    is_folder,
    folder_id,
    uploaded_by,
    original_name,
    is_public,
    download_count,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    COALESCE(tenant_id, 1),
    COALESCE(name, original_name, 'Unnamed'),
    COALESCE(storage_path, ''),
    COALESCE(mime_type, 'application/octet-stream'),
    COALESCE(size_bytes, 0),
    0 as is_folder,
    folder_id,
    COALESCE(owner_id, 1),
    original_name,
    COALESCE(is_public, 0),
    COALESCE(download_count, 0),
    created_at,
    updated_at,
    deleted_at
FROM files_backup_force
WHERE name IS NOT NULL OR original_name IS NOT NULL;

-- Add default folders for each tenant
INSERT INTO files (tenant_id, name, is_folder, uploaded_by, file_path) VALUES
(1, 'Documents', 1, 1, '/documents'),
(1, 'Images', 1, 1, '/images'),
(1, 'Reports', 1, 1, '/reports'),
(2, 'Documents', 1, 1, '/documents'),
(2, 'Images', 1, 1, '/images'),
(2, 'Reports', 1, 1, '/reports');

-- Create view for active files
CREATE VIEW active_files AS
SELECT * FROM files
WHERE deleted_at IS NULL
ORDER BY is_folder DESC, name ASC;

SET FOREIGN_KEY_CHECKS = 1;

-- Show results
SELECT 'Migration completed successfully!' as Status;
SELECT COUNT(*) as 'Total Files' FROM files;
SELECT COUNT(*) as 'Folders' FROM files WHERE is_folder = 1;
SELECT COUNT(*) as 'Regular Files' FROM files WHERE is_folder = 0;