-- ============================================
-- Module: Document Editor Integration
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Implements OnlyOffice Document Editor integration as per OpenSpec 003
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFICATION: Check prerequisite tables
-- ============================================

SELECT 'Verifying prerequisite tables...' as status;

-- Check tenants table exists
SELECT COUNT(*) as tenant_count FROM tenants;

-- Check users table exists
SELECT COUNT(*) as user_count FROM users;

-- Check files table exists
SELECT COUNT(*) as files_count FROM files;

-- ============================================
-- TABLE: DOCUMENT_EDITOR_SESSIONS
-- Purpose: Track active document editing sessions for OnlyOffice integration
-- Multi-tenant: YES (tenant_id required)
-- Soft Delete: YES (deleted_at for session archiving)
-- ============================================

CREATE TABLE IF NOT EXISTS document_editor_sessions (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Core business fields
    file_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    editor_key VARCHAR(255) NOT NULL COMMENT 'Unique key for OnlyOffice document instance',

    -- Session tracking
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,

    -- Editor state
    changes_saved BOOLEAN DEFAULT FALSE,
    is_collaborative BOOLEAN DEFAULT FALSE COMMENT 'Multiple users editing simultaneously',
    editor_version VARCHAR(20) NULL COMMENT 'OnlyOffice version used',

    -- Connection info
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    -- Document state at session start
    document_version INT UNSIGNED DEFAULT 1,
    initial_checksum VARCHAR(64) NULL COMMENT 'File checksum when session started',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys with CASCADE strategy
    CONSTRAINT fk_editor_session_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_session_file FOREIGN KEY (file_id)
        REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_session_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant performance (MANDATORY patterns)
    INDEX idx_session_tenant_created (tenant_id, created_at),
    INDEX idx_session_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_session_tenant_activity (tenant_id, last_activity),

    -- Functional indexes
    UNIQUE INDEX uk_session_token (session_token),
    INDEX idx_editor_key (editor_key),
    INDEX idx_session_file (file_id, opened_at),
    INDEX idx_session_user (user_id, opened_at),
    INDEX idx_session_active (tenant_id, closed_at, deleted_at) COMMENT 'Find active sessions',

    -- Performance index for finding concurrent sessions
    INDEX idx_session_concurrent (file_id, closed_at, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks document editing sessions for OnlyOffice integration';

-- ============================================
-- TABLE: DOCUMENT_EDITOR_LOCKS
-- Purpose: Manage document locking to prevent conflicts
-- Multi-tenant: YES
-- Soft Delete: NO (transient data, hard delete on release)
-- ============================================

CREATE TABLE IF NOT EXISTS document_editor_locks (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Lock information
    file_id INT UNSIGNED NOT NULL,
    locked_by INT UNSIGNED NOT NULL,
    lock_token VARCHAR(255) NOT NULL,
    lock_type ENUM('exclusive', 'shared') DEFAULT 'exclusive',

    -- Lock timing
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'Auto-release after timeout',

    -- Lock metadata
    session_id INT UNSIGNED NULL COMMENT 'Link to editor session',
    lock_reason VARCHAR(255) NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_lock_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_lock_file FOREIGN KEY (file_id)
        REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_lock_user FOREIGN KEY (locked_by)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lock_session FOREIGN KEY (session_id)
        REFERENCES document_editor_sessions(id) ON DELETE CASCADE,

    -- Ensure only one exclusive lock per file per tenant
    UNIQUE INDEX uk_file_exclusive_lock (tenant_id, file_id, lock_type),

    -- Performance indexes
    INDEX idx_lock_expires (expires_at),
    INDEX idx_lock_token (lock_token),
    INDEX idx_lock_user (locked_by)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Manages file locking for document editor';

-- ============================================
-- TABLE: DOCUMENT_EDITOR_CHANGES
-- Purpose: Track changes history from OnlyOffice callbacks
-- Multi-tenant: YES
-- Soft Delete: YES (for audit trail)
-- ============================================

CREATE TABLE IF NOT EXISTS document_editor_changes (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Change tracking
    session_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- OnlyOffice callback data
    callback_status INT NOT NULL COMMENT '1=editing, 2=ready to save, 3=error, 4=closed without changes, 6=force save, 7=force save error',
    document_url VARCHAR(500) NULL COMMENT 'URL to download modified document from OnlyOffice',
    changes_url VARCHAR(500) NULL COMMENT 'URL to download change history',

    -- Change metadata
    version_number INT UNSIGNED NOT NULL,
    previous_version INT UNSIGNED NULL,
    change_summary TEXT NULL,

    -- Save status
    save_status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    save_error TEXT NULL,
    saved_at TIMESTAMP NULL,

    -- File info after change
    new_file_size BIGINT NULL,
    new_checksum VARCHAR(64) NULL,

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_change_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_session FOREIGN KEY (session_id)
        REFERENCES document_editor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_file FOREIGN KEY (file_id)
        REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries
    INDEX idx_change_tenant_created (tenant_id, created_at),
    INDEX idx_change_tenant_deleted (tenant_id, deleted_at),

    -- Functional indexes
    INDEX idx_change_session (session_id),
    INDEX idx_change_file_version (file_id, version_number),
    INDEX idx_change_status (save_status),
    INDEX idx_change_callback (callback_status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks document changes from OnlyOffice callbacks';

-- ============================================
-- ALTER TABLE: FILES
-- Add editor-related columns as specified in OpenSpec
-- ============================================

-- Add new columns to files table for editor support
ALTER TABLE files
    ADD COLUMN IF NOT EXISTS is_editable BOOLEAN DEFAULT TRUE
        COMMENT 'Whether file can be edited in OnlyOffice' AFTER mime_type,

    ADD COLUMN IF NOT EXISTS editor_format VARCHAR(10) NULL
        COMMENT 'OnlyOffice document type: word, cell, slide' AFTER is_editable,

    ADD COLUMN IF NOT EXISTS last_edited_by INT UNSIGNED NULL
        COMMENT 'Last user who edited via OnlyOffice' AFTER updated_at,

    ADD COLUMN IF NOT EXISTS last_edited_at TIMESTAMP NULL
        COMMENT 'Last edit timestamp via OnlyOffice' AFTER last_edited_by,

    ADD COLUMN IF NOT EXISTS editor_version INT UNSIGNED DEFAULT 0
        COMMENT 'Document version for OnlyOffice key generation' AFTER last_edited_at,

    ADD COLUMN IF NOT EXISTS is_locked BOOLEAN DEFAULT FALSE
        COMMENT 'Quick check if file is currently locked' AFTER editor_version,

    ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) NULL
        COMMENT 'File checksum for integrity verification' AFTER is_locked;

-- Add foreign key for last_edited_by if not exists
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'files'
    AND CONSTRAINT_NAME = 'fk_files_last_edited_by'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE files ADD CONSTRAINT fk_files_last_edited_by
     FOREIGN KEY (last_edited_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Foreign key fk_files_last_edited_by already exists" as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for editable files lookup
CREATE INDEX IF NOT EXISTS idx_files_editable
    ON files(tenant_id, is_editable, mime_type, deleted_at);

-- Add index for locked files
CREATE INDEX IF NOT EXISTS idx_files_locked
    ON files(tenant_id, is_locked, deleted_at);

-- ============================================
-- STORED FUNCTION: Generate Document Key
-- Purpose: Create unique document key for OnlyOffice
-- ============================================

DELIMITER $$

DROP FUNCTION IF EXISTS generate_document_key$$

CREATE FUNCTION generate_document_key(
    p_file_id INT UNSIGNED,
    p_version INT UNSIGNED,
    p_timestamp TIMESTAMP
) RETURNS VARCHAR(255)
DETERMINISTIC
READS SQL DATA
COMMENT 'Generates unique document key for OnlyOffice'
BEGIN
    DECLARE v_key VARCHAR(255);

    -- Format: file_{id}_v{version}_{timestamp}
    -- Example: file_123_v5_20251012143022
    SET v_key = CONCAT(
        'file_',
        p_file_id,
        '_v',
        p_version,
        '_',
        DATE_FORMAT(p_timestamp, '%Y%m%d%H%i%s')
    );

    RETURN v_key;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURE: Clean Expired Sessions
-- Purpose: Clean up expired editor sessions
-- ============================================

DELIMITER $$

DROP PROCEDURE IF EXISTS cleanup_expired_editor_sessions$$

CREATE PROCEDURE cleanup_expired_editor_sessions(
    IN p_hours_old INT
)
COMMENT 'Soft delete editor sessions older than specified hours with no activity'
BEGIN
    DECLARE v_cutoff_time TIMESTAMP;
    DECLARE v_deleted_count INT;

    -- Calculate cutoff time
    SET v_cutoff_time = DATE_SUB(NOW(), INTERVAL p_hours_old HOUR);

    -- Soft delete old sessions that were never closed properly
    UPDATE document_editor_sessions
    SET deleted_at = NOW(),
        closed_at = CASE
            WHEN closed_at IS NULL THEN last_activity
            ELSE closed_at
        END
    WHERE deleted_at IS NULL
      AND closed_at IS NULL
      AND last_activity < v_cutoff_time;

    -- Get count of deleted sessions
    SET v_deleted_count = ROW_COUNT();

    -- Clean up expired locks
    DELETE FROM document_editor_locks
    WHERE expires_at < NOW();

    -- Return summary
    SELECT v_deleted_count as sessions_cleaned,
           ROW_COUNT() as locks_released,
           NOW() as cleanup_time;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURE: Get Active Editor Sessions
-- Purpose: Get all active editing sessions for a tenant
-- ============================================

DELIMITER $$

DROP PROCEDURE IF EXISTS get_active_editor_sessions$$

CREATE PROCEDURE get_active_editor_sessions(
    IN p_tenant_id INT UNSIGNED
)
COMMENT 'Get all active editor sessions for a tenant'
BEGIN
    SELECT
        s.id,
        s.file_id,
        f.name as file_name,
        s.user_id,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        s.session_token,
        s.editor_key,
        s.opened_at,
        s.last_activity,
        s.is_collaborative,
        s.changes_saved,
        CASE
            WHEN l.id IS NOT NULL THEN TRUE
            ELSE FALSE
        END as is_locked
    FROM document_editor_sessions s
    INNER JOIN files f ON s.file_id = f.id AND f.deleted_at IS NULL
    INNER JOIN users u ON s.user_id = u.id AND u.deleted_at IS NULL
    LEFT JOIN document_editor_locks l ON s.file_id = l.file_id
        AND l.tenant_id = s.tenant_id
        AND l.expires_at > NOW()
    WHERE s.tenant_id = p_tenant_id
      AND s.deleted_at IS NULL
      AND s.closed_at IS NULL
    ORDER BY s.last_activity DESC;
END$$

DELIMITER ;

-- ============================================
-- TRIGGER: Update file editor version
-- Purpose: Increment version when file is edited
-- ============================================

DELIMITER $$

DROP TRIGGER IF EXISTS update_file_editor_version$$

CREATE TRIGGER update_file_editor_version
AFTER INSERT ON document_editor_changes
FOR EACH ROW
BEGIN
    IF NEW.save_status = 'completed' THEN
        UPDATE files
        SET editor_version = editor_version + 1,
            last_edited_by = NEW.user_id,
            last_edited_at = NOW()
        WHERE id = NEW.file_id;
    END IF;
END$$

DELIMITER ;

-- ============================================
-- VIEW: Editor Statistics
-- Purpose: Provide quick stats on editor usage
-- ============================================

CREATE OR REPLACE VIEW v_editor_statistics AS
SELECT
    s.tenant_id,
    COUNT(DISTINCT s.id) as total_sessions,
    COUNT(DISTINCT CASE WHEN s.closed_at IS NULL THEN s.id END) as active_sessions,
    COUNT(DISTINCT s.file_id) as unique_files_edited,
    COUNT(DISTINCT s.user_id) as unique_editors,
    AVG(TIMESTAMPDIFF(MINUTE, s.opened_at, COALESCE(s.closed_at, NOW()))) as avg_session_minutes,
    MAX(s.last_activity) as last_activity
FROM document_editor_sessions s
WHERE s.deleted_at IS NULL
GROUP BY s.tenant_id;

-- ============================================
-- DEMO DATA (Optional - for testing)
-- ============================================

-- Insert sample editor format mappings for existing files
UPDATE files
SET editor_format = CASE
    WHEN mime_type IN ('application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') THEN 'word'
    WHEN mime_type IN ('application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') THEN 'cell'
    WHEN mime_type IN ('application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation') THEN 'slide'
    ELSE NULL
END,
is_editable = CASE
    WHEN mime_type IN (
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv'
    ) THEN TRUE
    ELSE FALSE
END
WHERE editor_format IS NULL;

-- ============================================
-- GRANTS (if needed for application user)
-- ============================================

-- Uncomment and adjust if using a non-root database user
-- GRANT SELECT, INSERT, UPDATE, DELETE ON document_editor_sessions TO 'app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON document_editor_locks TO 'app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON document_editor_changes TO 'app_user'@'localhost';
-- GRANT EXECUTE ON FUNCTION generate_document_key TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE cleanup_expired_editor_sessions TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE get_active_editor_sessions TO 'app_user'@'localhost';

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME IN ('document_editor_sessions', 'document_editor_locks', 'document_editor_changes')) as new_tables_created,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'collaboranexio'
        AND TABLE_NAME = 'files'
        AND COLUMN_NAME IN ('is_editable', 'editor_format', 'last_edited_by', 'last_edited_at', 'editor_version', 'is_locked', 'checksum')) as new_columns_added,
       NOW() as executed_at;

-- Show created tables
SELECT TABLE_NAME, TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME LIKE 'document_editor%';

-- Show indexes created
SELECT DISTINCT TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME LIKE 'document_editor%'
ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================
-- ROLLBACK SCRIPT (Save separately)
-- ============================================
/*
-- To rollback this migration, run:

-- Soft delete all editor data
UPDATE document_editor_sessions SET deleted_at = NOW() WHERE deleted_at IS NULL;
UPDATE document_editor_changes SET deleted_at = NOW() WHERE deleted_at IS NULL;

-- Remove locks
DELETE FROM document_editor_locks;

-- Drop tables (use with caution!)
DROP TABLE IF EXISTS document_editor_changes;
DROP TABLE IF EXISTS document_editor_locks;
DROP TABLE IF EXISTS document_editor_sessions;

-- Remove columns from files table
ALTER TABLE files
    DROP COLUMN IF EXISTS is_editable,
    DROP COLUMN IF EXISTS editor_format,
    DROP COLUMN IF EXISTS last_edited_by,
    DROP COLUMN IF EXISTS last_edited_at,
    DROP COLUMN IF EXISTS editor_version,
    DROP COLUMN IF EXISTS is_locked,
    DROP COLUMN IF EXISTS checksum;

-- Drop stored procedures and functions
DROP FUNCTION IF EXISTS generate_document_key;
DROP PROCEDURE IF EXISTS cleanup_expired_editor_sessions;
DROP PROCEDURE IF EXISTS get_active_editor_sessions;
DROP TRIGGER IF EXISTS update_file_editor_version;
DROP VIEW IF EXISTS v_editor_statistics;

*/

-- ============================================
-- END OF MIGRATION
-- ============================================