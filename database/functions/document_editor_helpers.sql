-- ============================================
-- Document Editor Helper Functions
-- Version: 2025-10-12
-- Author: Database Architect
-- Description: Helper functions and procedures for document editor
-- ============================================

USE collaboranexio;

DELIMITER $$

-- ============================================
-- FUNCTION: Check if file is editable
-- ============================================

DROP FUNCTION IF EXISTS is_file_editable$$

CREATE FUNCTION is_file_editable(
    p_file_id INT UNSIGNED,
    p_tenant_id INT UNSIGNED
) RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
COMMENT 'Check if a file can be edited in OnlyOffice'
BEGIN
    DECLARE v_is_editable BOOLEAN DEFAULT FALSE;
    DECLARE v_mime_type VARCHAR(100);
    DECLARE v_is_locked BOOLEAN;
    DECLARE v_deleted_at TIMESTAMP;

    -- Get file information
    SELECT
        is_editable,
        mime_type,
        is_locked,
        deleted_at
    INTO
        v_is_editable,
        v_mime_type,
        v_is_locked,
        v_deleted_at
    FROM files
    WHERE id = p_file_id
    AND tenant_id = p_tenant_id;

    -- File must exist, not be deleted, not locked, and be editable
    IF v_deleted_at IS NOT NULL OR v_is_locked = TRUE THEN
        RETURN FALSE;
    END IF;

    -- Check if mime type is supported
    IF v_mime_type IN (
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation'
    ) THEN
        RETURN TRUE;
    END IF;

    RETURN v_is_editable;
END$$

-- ============================================
-- FUNCTION: Get OnlyOffice document type
-- ============================================

DROP FUNCTION IF EXISTS get_document_type$$

CREATE FUNCTION get_document_type(
    p_mime_type VARCHAR(100)
) RETURNS VARCHAR(10)
DETERMINISTIC
COMMENT 'Get OnlyOffice document type from MIME type'
BEGIN
    RETURN CASE
        WHEN p_mime_type IN (
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'application/rtf',
            'text/plain'
        ) THEN 'word'

        WHEN p_mime_type IN (
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv'
        ) THEN 'cell'

        WHEN p_mime_type IN (
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation'
        ) THEN 'slide'

        ELSE NULL
    END;
END$$

-- ============================================
-- FUNCTION: Generate secure session token
-- ============================================

DROP FUNCTION IF EXISTS generate_session_token$$

CREATE FUNCTION generate_session_token() RETURNS VARCHAR(255)
NOT DETERMINISTIC
READS SQL DATA
COMMENT 'Generate unique session token for editor'
BEGIN
    DECLARE v_token VARCHAR(255);
    DECLARE v_exists INT DEFAULT 1;

    WHILE v_exists > 0 DO
        -- Generate random token
        SET v_token = CONCAT(
            'sess_',
            MD5(CONCAT(UUID(), RAND(), NOW())),
            '_',
            UNIX_TIMESTAMP()
        );

        -- Check if token already exists
        SELECT COUNT(*) INTO v_exists
        FROM document_editor_sessions
        WHERE session_token = v_token;
    END WHILE;

    RETURN v_token;
END$$

-- ============================================
-- PROCEDURE: Open editor session
-- ============================================

DROP PROCEDURE IF EXISTS open_editor_session$$

CREATE PROCEDURE open_editor_session(
    IN p_tenant_id INT UNSIGNED,
    IN p_file_id INT UNSIGNED,
    IN p_user_id INT UNSIGNED,
    IN p_ip_address VARCHAR(45),
    IN p_user_agent VARCHAR(500),
    OUT p_session_id INT UNSIGNED,
    OUT p_session_token VARCHAR(255),
    OUT p_editor_key VARCHAR(255)
)
COMMENT 'Open a new document editor session'
BEGIN
    DECLARE v_editor_version INT UNSIGNED;
    DECLARE v_checksum VARCHAR(64);
    DECLARE v_is_collaborative BOOLEAN DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Check if file is editable
    IF NOT is_file_editable(p_file_id, p_tenant_id) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'File is not editable or is locked';
    END IF;

    -- Get current file version and checksum
    SELECT
        editor_version,
        checksum
    INTO
        v_editor_version,
        v_checksum
    FROM files
    WHERE id = p_file_id
    AND tenant_id = p_tenant_id
    AND deleted_at IS NULL;

    -- Check for existing active sessions (collaborative editing)
    SELECT COUNT(*) > 0 INTO v_is_collaborative
    FROM document_editor_sessions
    WHERE file_id = p_file_id
    AND tenant_id = p_tenant_id
    AND closed_at IS NULL
    AND deleted_at IS NULL;

    -- Generate tokens
    SET p_session_token = generate_session_token();
    SET p_editor_key = generate_document_key(p_file_id, v_editor_version, NOW());

    -- Create new session
    INSERT INTO document_editor_sessions (
        tenant_id,
        file_id,
        user_id,
        session_token,
        editor_key,
        is_collaborative,
        ip_address,
        user_agent,
        document_version,
        initial_checksum
    ) VALUES (
        p_tenant_id,
        p_file_id,
        p_user_id,
        p_session_token,
        p_editor_key,
        v_is_collaborative,
        p_ip_address,
        p_user_agent,
        v_editor_version,
        v_checksum
    );

    SET p_session_id = LAST_INSERT_ID();

    -- Create lock if not collaborative
    IF NOT v_is_collaborative THEN
        INSERT INTO document_editor_locks (
            tenant_id,
            file_id,
            locked_by,
            lock_token,
            lock_type,
            expires_at,
            session_id,
            lock_reason
        ) VALUES (
            p_tenant_id,
            p_file_id,
            p_user_id,
            p_session_token,
            'exclusive',
            DATE_ADD(NOW(), INTERVAL 2 HOUR),
            p_session_id,
            'Document editing session'
        );

        -- Update file lock status
        UPDATE files
        SET is_locked = TRUE
        WHERE id = p_file_id;
    END IF;

    COMMIT;
END$$

-- ============================================
-- PROCEDURE: Close editor session
-- ============================================

DROP PROCEDURE IF EXISTS close_editor_session$$

CREATE PROCEDURE close_editor_session(
    IN p_session_token VARCHAR(255),
    IN p_changes_saved BOOLEAN
)
COMMENT 'Close a document editor session'
BEGIN
    DECLARE v_session_id INT UNSIGNED;
    DECLARE v_file_id INT UNSIGNED;
    DECLARE v_tenant_id INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Get session information
    SELECT
        id,
        file_id,
        tenant_id
    INTO
        v_session_id,
        v_file_id,
        v_tenant_id
    FROM document_editor_sessions
    WHERE session_token = p_session_token
    AND deleted_at IS NULL
    AND closed_at IS NULL
    FOR UPDATE;

    IF v_session_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Session not found or already closed';
    END IF;

    -- Update session
    UPDATE document_editor_sessions
    SET closed_at = NOW(),
        changes_saved = p_changes_saved
    WHERE id = v_session_id;

    -- Release lock if exists
    DELETE FROM document_editor_locks
    WHERE session_id = v_session_id;

    -- Check if there are other active sessions
    IF NOT EXISTS (
        SELECT 1
        FROM document_editor_sessions
        WHERE file_id = v_file_id
        AND tenant_id = v_tenant_id
        AND id != v_session_id
        AND closed_at IS NULL
        AND deleted_at IS NULL
    ) THEN
        -- No other sessions, unlock file
        UPDATE files
        SET is_locked = FALSE
        WHERE id = v_file_id;
    END IF;

    COMMIT;

    -- Return session close summary
    SELECT
        v_session_id as session_id,
        v_file_id as file_id,
        p_changes_saved as changes_saved,
        NOW() as closed_at;
END$$

-- ============================================
-- PROCEDURE: Record document change
-- ============================================

DROP PROCEDURE IF EXISTS record_document_change$$

CREATE PROCEDURE record_document_change(
    IN p_session_token VARCHAR(255),
    IN p_callback_status INT,
    IN p_document_url VARCHAR(500),
    IN p_changes_url VARCHAR(500),
    IN p_new_file_size BIGINT,
    IN p_new_checksum VARCHAR(64)
)
COMMENT 'Record document change from OnlyOffice callback'
BEGIN
    DECLARE v_session_id INT UNSIGNED;
    DECLARE v_tenant_id INT UNSIGNED;
    DECLARE v_file_id INT UNSIGNED;
    DECLARE v_user_id INT UNSIGNED;
    DECLARE v_version_number INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Get session information
    SELECT
        s.id,
        s.tenant_id,
        s.file_id,
        s.user_id,
        f.editor_version
    INTO
        v_session_id,
        v_tenant_id,
        v_file_id,
        v_user_id,
        v_version_number
    FROM document_editor_sessions s
    INNER JOIN files f ON s.file_id = f.id
    WHERE s.session_token = p_session_token
    AND s.deleted_at IS NULL;

    IF v_session_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid session token';
    END IF;

    -- Insert change record
    INSERT INTO document_editor_changes (
        tenant_id,
        session_id,
        file_id,
        user_id,
        callback_status,
        document_url,
        changes_url,
        version_number,
        new_file_size,
        new_checksum,
        save_status
    ) VALUES (
        v_tenant_id,
        v_session_id,
        v_file_id,
        v_user_id,
        p_callback_status,
        p_document_url,
        p_changes_url,
        v_version_number + 1,
        p_new_file_size,
        p_new_checksum,
        CASE
            WHEN p_callback_status = 2 THEN 'processing'
            WHEN p_callback_status IN (3, 7) THEN 'failed'
            ELSE 'pending'
        END
    );

    -- Update session activity
    UPDATE document_editor_sessions
    SET last_activity = NOW(),
        changes_saved = (p_callback_status = 2)
    WHERE id = v_session_id;

    COMMIT;

    -- Return change ID
    SELECT LAST_INSERT_ID() as change_id;
END$$

-- ============================================
-- PROCEDURE: Get concurrent editors
-- ============================================

DROP PROCEDURE IF EXISTS get_concurrent_editors$$

CREATE PROCEDURE get_concurrent_editors(
    IN p_file_id INT UNSIGNED,
    IN p_tenant_id INT UNSIGNED
)
COMMENT 'Get list of users currently editing a document'
BEGIN
    SELECT
        s.id as session_id,
        s.user_id,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.email,
        s.opened_at,
        s.last_activity,
        s.ip_address
    FROM document_editor_sessions s
    INNER JOIN users u ON s.user_id = u.id
    WHERE s.file_id = p_file_id
    AND s.tenant_id = p_tenant_id
    AND s.closed_at IS NULL
    AND s.deleted_at IS NULL
    AND u.deleted_at IS NULL
    ORDER BY s.opened_at;
END$$

-- ============================================
-- PROCEDURE: Extend lock expiration
-- ============================================

DROP PROCEDURE IF EXISTS extend_editor_lock$$

CREATE PROCEDURE extend_editor_lock(
    IN p_session_token VARCHAR(255),
    IN p_minutes INT
)
COMMENT 'Extend lock expiration for active editing session'
BEGIN
    DECLARE v_session_id INT UNSIGNED;

    -- Get session ID
    SELECT id INTO v_session_id
    FROM document_editor_sessions
    WHERE session_token = p_session_token
    AND closed_at IS NULL
    AND deleted_at IS NULL;

    IF v_session_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Session not found or closed';
    END IF;

    -- Extend lock
    UPDATE document_editor_locks
    SET expires_at = DATE_ADD(NOW(), INTERVAL p_minutes MINUTE)
    WHERE session_id = v_session_id;

    -- Update session activity
    UPDATE document_editor_sessions
    SET last_activity = NOW()
    WHERE id = v_session_id;

    SELECT 'Lock extended successfully' as status,
           (SELECT expires_at FROM document_editor_locks WHERE session_id = v_session_id) as new_expiry;
END$$

DELIMITER ;

-- ============================================
-- Grant permissions (if needed)
-- ============================================

-- Uncomment if using non-root user
-- GRANT EXECUTE ON FUNCTION is_file_editable TO 'app_user'@'localhost';
-- GRANT EXECUTE ON FUNCTION get_document_type TO 'app_user'@'localhost';
-- GRANT EXECUTE ON FUNCTION generate_session_token TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE open_editor_session TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE close_editor_session TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE record_document_change TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE get_concurrent_editors TO 'app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE extend_editor_lock TO 'app_user'@'localhost';

-- ============================================
-- END OF HELPER FUNCTIONS
-- ============================================