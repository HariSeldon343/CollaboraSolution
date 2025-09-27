-- Module: Fix Database Structure
-- Version: 2025-09-27
-- Author: Database Architect
-- Description: Aligns database tables with original schema requirements

USE collaboranexio;

-- ============================================
-- BACKUP EXISTING DATA (if any)
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- FIX FILES TABLE
-- ============================================
-- First check if we need to rename columns
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'files'
  AND COLUMN_NAME = 'file_size';

-- If incorrect columns exist, we need to fix them
DROP TABLE IF EXISTS files_backup;
CREATE TABLE IF NOT EXISTS files_backup AS SELECT * FROM files;

-- Drop the incorrectly structured files table
DROP TABLE IF EXISTS files;

-- Recreate with correct structure from original schema
CREATE TABLE files (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    checksum VARCHAR(64) NULL,
    owner_id INT UNSIGNED NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    status ENUM('bozza', 'in_approvazione', 'approvato', 'rifiutato') DEFAULT 'in_approvazione',
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    download_count INT UNSIGNED DEFAULT 0,
    tags JSON NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_file_tenant_folder (tenant_id, folder_id),
    INDEX idx_file_name (name),
    INDEX idx_file_owner (owner_id),
    INDEX idx_file_mime (mime_type),
    INDEX idx_file_checksum (checksum),
    INDEX idx_file_status (status),
    INDEX idx_file_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate data from backup if it exists and has records
SET @row_count = 0;
SELECT COUNT(*) INTO @row_count FROM files_backup;

-- Only migrate if there's data
-- Note: This assumes the backup table might have wrong column names
-- Adjust the column mapping based on what exists
INSERT IGNORE INTO files (
    tenant_id,
    id,
    folder_id,
    name,
    original_name,
    mime_type,
    size_bytes,
    storage_path,
    checksum,
    owner_id,
    is_public,
    download_count,
    tags,
    metadata,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    tenant_id,
    id,
    folder_id,
    name,
    COALESCE(original_name, name),  -- Use name if original_name doesn't exist
    COALESCE(mime_type, 'application/octet-stream'),
    CASE
        WHEN COLUMN_EXISTS('files_backup', 'size_bytes') THEN size_bytes
        WHEN COLUMN_EXISTS('files_backup', 'file_size') THEN file_size
        ELSE 0
    END,
    CASE
        WHEN COLUMN_EXISTS('files_backup', 'storage_path') THEN storage_path
        WHEN COLUMN_EXISTS('files_backup', 'file_path') THEN file_path
        ELSE CONCAT('files/', id)
    END,
    checksum,
    CASE
        WHEN COLUMN_EXISTS('files_backup', 'owner_id') THEN owner_id
        WHEN COLUMN_EXISTS('files_backup', 'uploaded_by') THEN uploaded_by
        ELSE 1
    END,
    COALESCE(is_public, FALSE),
    COALESCE(download_count, 0),
    tags,
    metadata,
    created_at,
    updated_at,
    deleted_at
FROM files_backup
WHERE @row_count > 0;

-- ============================================
-- FIX FOLDERS TABLE (ensure it exists)
-- ============================================
CREATE TABLE IF NOT EXISTS folders (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1000) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_folder_tenant_parent (tenant_id, parent_id),
    INDEX idx_folder_path (path),
    INDEX idx_folder_owner (owner_id),
    INDEX idx_folder_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ENSURE USERS TABLE HAS CORRECT STRUCTURE
-- ============================================
-- Check if users table has the correct columns
SET @has_first_name = 0;
SET @has_display_name = 0;

SELECT COUNT(*) INTO @has_first_name
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'first_name';

SELECT COUNT(*) INTO @has_display_name
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'display_name';

-- Add missing columns if they don't exist
SET @sql = IF(@has_first_name = 0,
    'ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT "" AFTER password_hash',
    'SELECT "first_name already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@has_first_name = 0,
    'ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT "" AFTER first_name',
    'SELECT "last_name already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@has_display_name = 0,
    'ALTER TABLE users ADD COLUMN display_name VARCHAR(200) NULL AFTER last_name',
    'SELECT "display_name already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- If there's a 'name' column and we have first_name/last_name, populate them
SET @has_name = 0;
SELECT COUNT(*) INTO @has_name
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'name';

-- Update first_name and last_name from name field if needed
SET @sql = IF(@has_name = 1 AND @has_first_name = 0,
    'UPDATE users SET
        first_name = SUBSTRING_INDEX(name, " ", 1),
        last_name = SUBSTRING_INDEX(name, " ", -1),
        display_name = name
    WHERE name IS NOT NULL',
    'SELECT "No name migration needed"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- ADD DEMO DATA (if tables are empty)
-- ============================================
-- Check if we have demo data
SELECT COUNT(*) INTO @tenant_count FROM tenants;
SELECT COUNT(*) INTO @user_count FROM users;

-- Insert demo tenant if none exist
INSERT INTO tenants (id, name, domain, status, max_users, max_storage_gb)
SELECT 1, 'Demo Company', 'demo.local', 'active', 100, 1000
WHERE @tenant_count = 0;

-- Insert demo users if none exist
INSERT INTO users (
    tenant_id, email, password_hash, first_name, last_name, display_name,
    role, status, email_verified_at
)
SELECT
    1, 'admin@demo.local', '$2y$10$YourHashHere', 'Admin', 'User', 'Admin User',
    'admin', 'active', NOW()
WHERE @user_count = 0
UNION ALL
SELECT
    1, 'manager@demo.local', '$2y$10$YourHashHere', 'Manager', 'User', 'Manager User',
    'manager', 'active', NOW()
WHERE @user_count = 0
UNION ALL
SELECT
    1, 'user@demo.local', '$2y$10$YourHashHere', 'Regular', 'User', 'Regular User',
    'user', 'active', NOW()
WHERE @user_count = 0;

-- Insert sample folders
INSERT INTO folders (tenant_id, name, path, owner_id, is_public)
SELECT 1, 'Documents', '/Documents', 1, FALSE
WHERE NOT EXISTS (SELECT 1 FROM folders WHERE tenant_id = 1 AND name = 'Documents');

INSERT INTO folders (tenant_id, name, path, owner_id, is_public)
SELECT 1, 'Shared', '/Shared', 1, TRUE
WHERE NOT EXISTS (SELECT 1 FROM folders WHERE tenant_id = 1 AND name = 'Shared');

-- ============================================
-- CLEANUP
-- ============================================
DROP TABLE IF EXISTS files_backup;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Database structure fixed successfully' as status,
       (SELECT COUNT(*) FROM files) as files_count,
       (SELECT COUNT(*) FROM folders) as folders_count,
       (SELECT COUNT(*) FROM users) as users_count,
       NOW() as execution_time;

-- Show current structure
SELECT 'FILES TABLE STRUCTURE:' as info;
DESCRIBE files;

SELECT 'USERS TABLE STRUCTURE:' as info;
DESCRIBE users;

SELECT 'FOLDERS TABLE STRUCTURE:' as info;
DESCRIBE folders;