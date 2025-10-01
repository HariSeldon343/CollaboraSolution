-- Module: Audit Logs System
-- Version: 2025-09-29
-- Author: Database Architect
-- Description: Complete audit logging system for tracking all user actions in the multi-tenant platform

USE collaboranexio;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================
CREATE TABLE audit_logs (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT(10) UNSIGNED NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT(10) UNSIGNED NULL, -- NULL for system actions
    action VARCHAR(50) NOT NULL, -- create, update, delete, login, logout, download, upload, approve, reject, view, export, import
    entity_type VARCHAR(50) NOT NULL, -- user, file, folder, project, task, calendar_event, chat_message, document_approval, tenant, system_setting
    entity_id INT UNSIGNED NULL, -- ID of the affected entity (NULL for some actions like login/logout)

    -- Change tracking
    old_values JSON NULL, -- Previous values in JSON format
    new_values JSON NULL, -- New values in JSON format

    -- Additional context
    description TEXT NULL, -- Human-readable description of the action
    ip_address VARCHAR(45) NULL, -- Support for IPv6
    user_agent TEXT NULL, -- Browser/client information
    session_id VARCHAR(128) NULL, -- Link to session for tracking

    -- Request details
    request_method VARCHAR(10) NULL, -- GET, POST, PUT, DELETE, PATCH
    request_url TEXT NULL, -- Full URL of the request
    request_data JSON NULL, -- Request parameters (sanitized)
    response_code INT NULL, -- HTTP response code

    -- Performance tracking
    execution_time_ms INT UNSIGNED NULL, -- Time taken in milliseconds
    memory_usage_kb INT UNSIGNED NULL, -- Memory used in kilobytes

    -- Audit metadata
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    status ENUM('success', 'failed', 'pending') DEFAULT 'success',

    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    -- Foreign keys will be added after verifying tables exist
    INDEX idx_fk_tenant (tenant_id),
    INDEX idx_fk_user (user_id),

    -- Check constraints
    CONSTRAINT chk_audit_action CHECK (action IN (
        'create', 'update', 'delete', 'restore',
        'login', 'logout', 'login_failed', 'session_expired',
        'download', 'upload', 'view', 'export', 'import',
        'approve', 'reject', 'submit', 'cancel',
        'share', 'unshare', 'permission_grant', 'permission_revoke',
        'password_change', 'password_reset', 'email_change',
        'tenant_switch', 'system_update', 'backup', 'restore_backup'
    )),
    CONSTRAINT chk_audit_entity CHECK (entity_type IN (
        'user', 'tenant', 'file', 'folder', 'project', 'task',
        'calendar_event', 'chat_message', 'chat_channel',
        'document_approval', 'system_setting', 'notification',
        'permission', 'role', 'session', 'api_key', 'backup'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INDEXES
-- ============================================
-- Composite index for multi-tenant queries
CREATE INDEX idx_audit_tenant_user ON audit_logs(tenant_id, user_id, created_at DESC);

-- Index for filtering by action type
CREATE INDEX idx_audit_action ON audit_logs(action, created_at DESC);

-- Index for entity lookups
CREATE INDEX idx_audit_entity ON audit_logs(entity_type, entity_id);

-- Index for time-based queries
CREATE INDEX idx_audit_created ON audit_logs(created_at DESC);

-- Index for severity monitoring
CREATE INDEX idx_audit_severity ON audit_logs(severity, status, created_at DESC);

-- Index for IP tracking (security)
CREATE INDEX idx_audit_ip ON audit_logs(ip_address, created_at DESC);

-- Index for session tracking
CREATE INDEX idx_audit_session ON audit_logs(session_id);

-- Full-text index for description searches
CREATE FULLTEXT INDEX idx_audit_description ON audit_logs(description);

-- ============================================
-- ADD FOREIGN KEYS (if tables exist)
-- ============================================
-- Check and add foreign key for tenants table if it exists
DROP PROCEDURE IF EXISTS add_audit_foreign_keys;

DELIMITER $$
CREATE PROCEDURE add_audit_foreign_keys()
BEGIN
    -- Check if tenants table exists
    IF EXISTS (SELECT * FROM information_schema.tables
               WHERE table_schema = DATABASE()
               AND table_name = 'tenants') THEN

        -- Check if foreign key already exists
        IF NOT EXISTS (SELECT * FROM information_schema.KEY_COLUMN_USAGE
                       WHERE table_schema = DATABASE()
                       AND table_name = 'audit_logs'
                       AND constraint_name = 'fk_audit_tenant') THEN

            ALTER TABLE audit_logs
            ADD CONSTRAINT fk_audit_tenant
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
        END IF;
    END IF;

    -- Check if users table exists
    IF EXISTS (SELECT * FROM information_schema.tables
               WHERE table_schema = DATABASE()
               AND table_name = 'users') THEN

        -- Check if foreign key already exists
        IF NOT EXISTS (SELECT * FROM information_schema.KEY_COLUMN_USAGE
                       WHERE table_schema = DATABASE()
                       AND table_name = 'audit_logs'
                       AND constraint_name = 'fk_audit_user') THEN

            ALTER TABLE audit_logs
            ADD CONSTRAINT fk_audit_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
        END IF;
    END IF;
END$$
DELIMITER ;

-- Execute the procedure to add foreign keys
CALL add_audit_foreign_keys();

-- Clean up
DROP PROCEDURE IF EXISTS add_audit_foreign_keys;

-- ============================================
-- DEMO DATA
-- ============================================
-- Only insert demo data if tenants and users tables exist
-- Otherwise insert minimal demo data without foreign key dependencies
DROP PROCEDURE IF EXISTS insert_audit_demo_data;

DELIMITER $$
CREATE PROCEDURE insert_audit_demo_data()
BEGIN
    DECLARE has_tenants INT DEFAULT 0;
    DECLARE has_users INT DEFAULT 0;

    -- Check if tables exist
    SELECT COUNT(*) INTO has_tenants
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tenants';

    SELECT COUNT(*) INTO has_users
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users';

    IF has_tenants > 0 AND has_users > 0 THEN
        -- Insert full demo data with foreign key references
        INSERT INTO audit_logs (
            tenant_id, user_id, action, entity_type, entity_id,
            old_values, new_values, description,
            ip_address, user_agent, severity, status
        ) VALUES
    -- Admin login
    (1, 1, 'login', 'user', 1,
     NULL,
     '{"login_time": "2025-09-29 10:00:00", "login_method": "password"}',
     'Admin user successfully logged in',
     '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
     'info', 'success'),

    -- User creation
    (1, 1, 'create', 'user', 3,
     NULL,
     '{"name": "Mario Rossi", "email": "mario.rossi@demo.local", "role": "user"}',
     'Created new user account for Mario Rossi',
     '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
     'info', 'success'),

    -- File upload
    (1, 2, 'upload', 'file', 1,
     NULL,
     '{"filename": "report_q1_2025.pdf", "size": 2048576, "folder_id": 1}',
     'Uploaded quarterly report document',
     '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/120.0',
     'info', 'success'),

    -- Document approval
    (1, 2, 'approve', 'document_approval', 1,
     '{"status": "in_approvazione"}',
     '{"status": "approvato", "approved_by": 2, "approved_at": "2025-09-29 11:00:00"}',
     'Manager approved document: Budget Proposal 2025',
     '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/120.0',
     'info', 'success'),

    -- Failed login attempt
    (1, NULL, 'login_failed', 'user', 3,
     NULL,
     '{"email": "mario.rossi@demo.local", "attempts": 3}',
     'Failed login attempt for mario.rossi@demo.local',
     '192.168.1.102', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
     'warning', 'failed'),

    -- Permission change
    (1, 1, 'update', 'user', 3,
     '{"role": "user"}',
     '{"role": "manager"}',
     'User Mario Rossi promoted to Manager role',
     '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
     'info', 'success'),

    -- File deletion
    (1, 1, 'delete', 'file', 5,
     '{"filename": "old_draft.docx", "size": 512000}',
     NULL,
     'Deleted obsolete draft document',
     '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
     'warning', 'success'),

    -- System backup
    (1, 1, 'backup', 'system_setting', NULL,
     NULL,
     '{"backup_file": "backup_2025_09_29.sql", "size": 10485760}',
     'System backup completed successfully',
     '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
     'info', 'success'),

    -- Tenant 2 activities
    (2, 4, 'login', 'user', 4,
     NULL,
     '{"login_time": "2025-09-29 09:00:00"}',
     'User from Company B logged in',
     '192.168.2.100', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
     'info', 'success'),

    (2, 4, 'create', 'project', 1,
     NULL,
     '{"name": "Website Redesign", "budget": 50000}',
     'Created new project: Website Redesign',
     '192.168.2.100', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
     'info', 'success');

    ELSE
        -- Insert minimal demo data without foreign key dependencies
        INSERT INTO audit_logs (
            tenant_id, user_id, action, entity_type, entity_id,
            description, ip_address, severity, status
        ) VALUES
        (1, NULL, 'system_update', 'system_setting', NULL,
         'Audit logs table created - tenants/users tables not yet available',
         '127.0.0.1', 'info', 'success');
    END IF;
END$$
DELIMITER ;

-- Execute the procedure to insert demo data
CALL insert_audit_demo_data();

-- Clean up
DROP PROCEDURE IF EXISTS insert_audit_demo_data;

-- ============================================
-- STORED PROCEDURES
-- ============================================
DELIMITER $$

-- Procedure to log user actions
CREATE PROCEDURE IF NOT EXISTS log_user_action(
    IN p_tenant_id INT,
    IN p_user_id INT,
    IN p_action VARCHAR(50),
    IN p_entity_type VARCHAR(50),
    IN p_entity_id INT,
    IN p_old_values JSON,
    IN p_new_values JSON,
    IN p_description TEXT,
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT
)
BEGIN
    INSERT INTO audit_logs (
        tenant_id, user_id, action, entity_type, entity_id,
        old_values, new_values, description,
        ip_address, user_agent, created_at
    ) VALUES (
        p_tenant_id, p_user_id, p_action, p_entity_type, p_entity_id,
        p_old_values, p_new_values, p_description,
        p_ip_address, p_user_agent, NOW()
    );
END$$

-- Function to get last user action
CREATE FUNCTION IF NOT EXISTS get_last_user_action(
    p_tenant_id INT,
    p_user_id INT
) RETURNS JSON
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE result JSON;

    SELECT JSON_OBJECT(
        'action', action,
        'entity_type', entity_type,
        'created_at', created_at,
        'description', description
    ) INTO result
    FROM audit_logs
    WHERE tenant_id = p_tenant_id
      AND user_id = p_user_id
    ORDER BY created_at DESC
    LIMIT 1;

    RETURN result;
END$$

DELIMITER ;

-- ============================================
-- VIEWS
-- ============================================
-- View for recent critical actions
CREATE OR REPLACE VIEW v_critical_audit_logs AS
SELECT
    al.id,
    t.name AS tenant_name,
    u.name AS user_name,
    al.action,
    al.entity_type,
    al.entity_id,
    al.description,
    al.ip_address,
    al.severity,
    al.status,
    al.created_at
FROM audit_logs al
LEFT JOIN tenants t ON al.tenant_id = t.id
LEFT JOIN users u ON al.user_id = u.id
WHERE al.severity IN ('error', 'critical')
   OR al.action IN ('delete', 'permission_grant', 'permission_revoke', 'password_change')
ORDER BY al.created_at DESC;

-- View for login activity
CREATE OR REPLACE VIEW v_login_activity AS
SELECT
    al.tenant_id,
    al.user_id,
    u.email,
    u.name,
    al.action,
    al.ip_address,
    al.user_agent,
    al.status,
    al.created_at
FROM audit_logs al
LEFT JOIN users u ON al.user_id = u.id
WHERE al.action IN ('login', 'logout', 'login_failed', 'session_expired')
ORDER BY al.created_at DESC;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Audit logs table created successfully' as status,
       COUNT(*) as demo_records,
       NOW() as execution_time
FROM audit_logs;