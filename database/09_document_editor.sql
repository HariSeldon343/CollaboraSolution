-- ============================================
-- Document Editor Database Migration
-- OnlyOffice Integration for CollaboraNexio
-- Version: 1.0.0
-- Date: 2025-10-12
-- ============================================

-- 1. Create document_editor_sessions table
CREATE TABLE IF NOT EXISTS document_editor_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
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

-- 2. Add new columns to files table
ALTER TABLE files
    ADD COLUMN IF NOT EXISTS is_editable BOOLEAN DEFAULT TRUE AFTER mime_type,
    ADD COLUMN IF NOT EXISTS editor_format VARCHAR(10) NULL AFTER is_editable,
    ADD COLUMN IF NOT EXISTS last_edited_by INT NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS last_edited_at TIMESTAMP NULL AFTER last_edited_by,
    ADD COLUMN IF NOT EXISTS version INT DEFAULT 1 AFTER last_edited_at;

-- 3. Add foreign key for last_edited_by
ALTER TABLE files
    ADD CONSTRAINT fk_files_last_edited_by
    FOREIGN KEY (last_edited_by) REFERENCES users(id) ON DELETE SET NULL;

-- 4. Create index for performance
CREATE INDEX IF NOT EXISTS idx_files_editable ON files(is_editable, mime_type);

-- 5. Create stored procedure for cleanup
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

-- 6. Create event for automatic cleanup (optional)
SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS auto_cleanup_editor_sessions;

CREATE EVENT IF NOT EXISTS auto_cleanup_editor_sessions
    ON SCHEDULE EVERY 1 HOUR
    DO CALL cleanup_expired_editor_sessions(2);

-- 7. Update editor_format for existing files based on extension
-- Word documents
UPDATE files
SET editor_format = 'word'
WHERE LOWER(SUBSTRING_INDEX(file_name, '.', -1)) IN ('doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'odt', 'fodt', 'ott', 'rtf', 'txt')
AND editor_format IS NULL;

-- Spreadsheets
UPDATE files
SET editor_format = 'cell'
WHERE LOWER(SUBSTRING_INDEX(file_name, '.', -1)) IN ('xls', 'xlsx', 'xlsm', 'xlt', 'xltx', 'xltm', 'ods', 'fods', 'ots', 'csv')
AND editor_format IS NULL;

-- Presentations
UPDATE files
SET editor_format = 'slide'
WHERE LOWER(SUBSTRING_INDEX(file_name, '.', -1)) IN ('ppt', 'pptx', 'pptm', 'pot', 'potx', 'potm', 'odp', 'fodp', 'otp')
AND editor_format IS NULL;

-- 8. Document editor configuration table (optional for future use)
CREATE TABLE IF NOT EXISTS document_editor_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_config (tenant_id, config_key),
    INDEX idx_tenant_config (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configuration
INSERT INTO document_editor_config (tenant_id, config_key, config_value)
SELECT id, 'editor_enabled', 'true' FROM tenants
WHERE id NOT IN (
    SELECT tenant_id FROM document_editor_config WHERE config_key = 'editor_enabled'
);

INSERT INTO document_editor_config (tenant_id, config_key, config_value)
SELECT id, 'max_file_size', '104857600' FROM tenants
WHERE id NOT IN (
    SELECT tenant_id FROM document_editor_config WHERE config_key = 'max_file_size'
);

-- ============================================
-- Migration completed
-- ============================================