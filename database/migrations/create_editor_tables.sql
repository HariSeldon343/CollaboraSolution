-- Migration: Create Document Editor Support Tables
-- Date: 2025-01-12
-- Purpose: Create editor-specific tables for document editing sessions and versions

-- Create document_editor table if not exists
CREATE TABLE IF NOT EXISTS document_editor (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    file_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(20) NOT NULL,
    version_count INT DEFAULT 0,
    last_edited_by INT UNSIGNED NULL,
    last_edited_at TIMESTAMP NULL,
    is_locked BOOLEAN DEFAULT 0,
    locked_by INT UNSIGNED NULL,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_document_editor_file (file_id),
    INDEX idx_document_editor_tenant (tenant_id),

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (last_edited_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create document_versions table if not exists
CREATE TABLE IF NOT EXISTS document_versions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    file_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    version_number INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comment TEXT NULL,

    INDEX idx_versions_file (file_id),
    INDEX idx_versions_tenant (tenant_id),
    UNIQUE KEY unique_file_version (file_id, version_number),

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create editor_sessions table if not exists
CREATE TABLE IF NOT EXISTS editor_sessions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    file_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extract extensions from existing file names if not set
UPDATE files
SET extension = LOWER(SUBSTRING_INDEX(name, '.', -1))
WHERE (extension IS NULL OR extension = '')
  AND name LIKE '%.%'
  AND (is_folder = 0 OR is_folder IS NULL);

-- Set is_editable for known document types based on extension
UPDATE files
SET is_editable = 1,
    editor_format = CASE
        WHEN extension IN ('doc', 'docx', 'odt', 'txt', 'rtf') THEN 'word'
        WHEN extension IN ('xls', 'xlsx', 'ods', 'csv') THEN 'cell'
        WHEN extension IN ('ppt', 'pptx', 'odp') THEN 'slide'
        ELSE NULL
    END
WHERE extension IN ('doc', 'docx', 'odt', 'txt', 'rtf', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp')
  AND extension IS NOT NULL
  AND extension != '';
