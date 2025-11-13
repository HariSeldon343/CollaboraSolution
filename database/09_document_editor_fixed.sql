-- ============================================
-- Document Editor Database Migration (FIXED)
-- OnlyOffice Integration for CollaboraNexio
-- Version: 1.0.1
-- Date: 2025-10-12
-- ============================================

-- 1. Create document_editor_sessions table
CREATE TABLE IF NOT EXISTS document_editor_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    editor_key VARCHAR(255) NOT NULL,
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    changes_saved BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,

    INDEX idx_session_token (session_token),
    INDEX idx_editor_key (editor_key),
    INDEX idx_tenant_activity (tenant_id, last_activity),
    INDEX idx_user_sessions (user_id, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Files table columns (is_editable, editor_format, last_edited_by, last_edited_at, version)
-- already exist - migration skipped as they were added in previous migration

-- 2. Create index for performance (if not exists)
CREATE INDEX IF NOT EXISTS idx_files_editable ON files(is_editable, mime_type);

-- 3. Create stored procedure for cleanup
DELIMITER //

DROP PROCEDURE IF EXISTS cleanup_expired_editor_sessions//

CREATE PROCEDURE cleanup_expired_editor_sessions(IN hours_old INT)
BEGIN
    UPDATE document_editor_sessions
    SET closed_at = NOW(), changes_saved = FALSE
    WHERE closed_at IS NULL
    AND last_activity < DATE_SUB(NOW(), INTERVAL hours_old HOUR);
END//

DELIMITER ;

-- 4. Create event for automatic cleanup (optional)
SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS auto_cleanup_editor_sessions;

CREATE EVENT IF NOT EXISTS auto_cleanup_editor_sessions
    ON SCHEDULE EVERY 1 HOUR
    DO CALL cleanup_expired_editor_sessions(2);

-- 5. Update editor_format for existing files based on extension (using 'name' column)
-- Word documents
UPDATE files
SET editor_format = 'word'
WHERE LOWER(SUBSTRING_INDEX(name, '.', -1)) IN ('doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'odt', 'fodt', 'ott', 'rtf', 'txt')
AND (editor_format IS NULL OR editor_format = '');

-- Spreadsheets
UPDATE files
SET editor_format = 'cell'
WHERE LOWER(SUBSTRING_INDEX(name, '.', -1)) IN ('xls', 'xlsx', 'xlsm', 'xlt', 'xltx', 'xltm', 'ods', 'fods', 'ots', 'csv')
AND (editor_format IS NULL OR editor_format = '');

-- Presentations
UPDATE files
SET editor_format = 'slide'
WHERE LOWER(SUBSTRING_INDEX(name, '.', -1)) IN ('ppt', 'pptx', 'pptm', 'pot', 'potx', 'potm', 'odp', 'fodp', 'otp')
AND (editor_format IS NULL OR editor_format = '');

-- 6. Document editor configuration table
CREATE TABLE IF NOT EXISTS document_editor_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_config (tenant_id, config_key),
    INDEX idx_tenant_config (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Insert default configuration for existing tenants
INSERT IGNORE INTO document_editor_config (tenant_id, config_key, config_value)
SELECT id, 'editor_enabled', 'true' FROM tenants;

INSERT IGNORE INTO document_editor_config (tenant_id, config_key, config_value)
SELECT id, 'max_file_size', '104857600' FROM tenants;

-- ============================================
-- Migration completed
-- ============================================
