-- Module: Missing Tables Migration
-- Version: 2025-01-25
-- Author: Database Architect
-- Description: Add missing tables to complete system setup (project_milestones, event_attendees, sessions, rate_limits, system_settings)

USE collaboranexio;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
DROP TABLE IF EXISTS event_attendees;
DROP TABLE IF EXISTS project_milestones;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS system_settings;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- Project Milestones Table
CREATE TABLE project_milestones (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    due_date DATE NOT NULL,
    completion_date DATE DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'delayed', 'cancelled') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    progress_percentage INT DEFAULT 0 CHECK (progress_percentage >= 0 AND progress_percentage <= 100),
    deliverables JSON DEFAULT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_completion_status CHECK (
        (status = 'completed' AND completion_date IS NOT NULL) OR
        (status != 'completed' AND completion_date IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event Attendees Table
CREATE TABLE event_attendees (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    response_status ENUM('pending', 'accepted', 'declined', 'tentative') DEFAULT 'pending',
    attendance_type ENUM('required', 'optional', 'informational') DEFAULT 'required',
    is_organizer BOOLEAN DEFAULT FALSE,
    notes TEXT,
    reminder_sent BOOLEAN DEFAULT FALSE,
    attended BOOLEAN DEFAULT NULL,

    -- Audit fields
    responded_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY unique_event_attendee (event_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions Table (for user session management)
CREATE TABLE sessions (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id VARCHAR(128) NOT NULL,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    device_info JSON DEFAULT NULL,

    -- Security fields
    csrf_token VARCHAR(64) DEFAULT NULL,
    remember_token VARCHAR(100) DEFAULT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate Limits Table (for API rate limiting)
CREATE TABLE rate_limits (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    identifier VARCHAR(255) NOT NULL,
    identifier_type ENUM('ip', 'user', 'api_key', 'tenant') NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'GET',
    attempts INT UNSIGNED DEFAULT 0,
    max_attempts INT UNSIGNED DEFAULT 100,
    window_minutes INT UNSIGNED DEFAULT 60,
    reset_at TIMESTAMP NOT NULL,
    is_blocked BOOLEAN DEFAULT FALSE,
    block_duration_minutes INT UNSIGNED DEFAULT 15,

    -- Metadata
    metadata JSON DEFAULT NULL,

    -- Audit fields
    first_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY unique_rate_limit (identifier, endpoint, method),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Settings Table (for system configuration)
CREATE TABLE system_settings (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    category VARCHAR(100) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    value_type ENUM('string', 'integer', 'boolean', 'json', 'float', 'datetime') DEFAULT 'string',
    default_value TEXT,
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    is_encrypted BOOLEAN DEFAULT FALSE,
    validation_rules JSON DEFAULT NULL,

    -- Metadata
    display_order INT DEFAULT 0,
    group_name VARCHAR(100) DEFAULT NULL,

    -- Audit fields
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY unique_setting (tenant_id, category, setting_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INDEXES
-- ============================================

-- Project Milestones Indexes
CREATE INDEX idx_milestones_tenant_lookup ON project_milestones(tenant_id, project_id, status);
CREATE INDEX idx_milestones_due_date ON project_milestones(due_date, status);
CREATE INDEX idx_milestones_assigned ON project_milestones(assigned_to, status);

-- Event Attendees Indexes
CREATE INDEX idx_attendees_tenant_lookup ON event_attendees(tenant_id, event_id, response_status);
CREATE INDEX idx_attendees_user_events ON event_attendees(user_id, response_status);
CREATE INDEX idx_attendees_response ON event_attendees(response_status, attendance_type);

-- Sessions Indexes
CREATE INDEX idx_sessions_tenant_user ON sessions(tenant_id, user_id, is_active);
CREATE INDEX idx_sessions_expires ON sessions(expires_at, is_active);
CREATE INDEX idx_sessions_activity ON sessions(last_activity);

-- Rate Limits Indexes
CREATE INDEX idx_rate_limits_tenant ON rate_limits(tenant_id, identifier, endpoint);
CREATE INDEX idx_rate_limits_reset ON rate_limits(reset_at, is_blocked);
CREATE INDEX idx_rate_limits_identifier ON rate_limits(identifier_type, identifier);

-- System Settings Indexes
CREATE INDEX idx_settings_tenant_category ON system_settings(tenant_id, category);
CREATE INDEX idx_settings_public ON system_settings(is_public, category);
CREATE INDEX idx_settings_group ON system_settings(group_name, display_order);

-- ============================================
-- DEMO DATA
-- ============================================

-- Ensure tenants exist
INSERT INTO tenants (id, name, code, is_active) VALUES
    (1, 'Demo Company A', 'DEMO_A', 1),
    (2, 'Demo Company B', 'DEMO_B', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Project Milestones Demo Data
INSERT INTO project_milestones (tenant_id, project_id, name, description, due_date, status, priority, progress_percentage, created_by, assigned_to, deliverables) VALUES
(1, 1, 'Project Kickoff', 'Initial project setup and team onboarding', '2025-02-01', 'completed', 'high', 100, 1, 1, '["Project charter", "Team roster", "Communication plan"]'),
(1, 1, 'Requirements Analysis', 'Gather and document all project requirements', '2025-02-15', 'in_progress', 'high', 65, 1, 2, '["Requirements document", "Use cases", "User stories"]'),
(1, 2, 'Design Phase', 'Create system architecture and UI/UX designs', '2025-03-01', 'pending', 'medium', 0, 1, 3, '["Architecture diagram", "Wireframes", "Database schema"]'),
(1, 2, 'Development Sprint 1', 'Implement core features', '2025-03-15', 'pending', 'high', 0, 1, 2, '["Login system", "Dashboard", "Basic CRUD operations"]'),
(2, 3, 'Testing Phase', 'Complete system testing and bug fixes', '2025-03-30', 'pending', 'critical', 0, 4, 5, '["Test cases", "Bug reports", "Performance metrics"]');

-- Event Attendees Demo Data
INSERT INTO event_attendees (tenant_id, event_id, user_id, response_status, attendance_type, is_organizer, notes) VALUES
(1, 1, 1, 'accepted', 'required', TRUE, 'Meeting organizer'),
(1, 1, 2, 'accepted', 'required', FALSE, 'Will present Q1 results'),
(1, 1, 3, 'declined', 'optional', FALSE, 'On vacation'),
(1, 2, 1, 'accepted', 'required', FALSE, NULL),
(1, 2, 2, 'tentative', 'required', TRUE, 'May need to reschedule'),
(2, 3, 4, 'accepted', 'required', TRUE, 'Project lead'),
(2, 3, 5, 'pending', 'optional', FALSE, NULL);

-- Sessions Demo Data
INSERT INTO sessions (tenant_id, id, user_id, ip_address, user_agent, payload, last_activity, expires_at, device_info) VALUES
(1, 'sess_demo_001', 1, '192.168.1.100', 'Mozilla/5.0 Chrome/120.0', '{"auth":true}', UNIX_TIMESTAMP(), DATE_ADD(NOW(), INTERVAL 2 HOUR), '{"browser":"Chrome","os":"Windows","device":"Desktop"}'),
(1, 'sess_demo_002', 2, '192.168.1.101', 'Mozilla/5.0 Firefox/121.0', '{"auth":true}', UNIX_TIMESTAMP(), DATE_ADD(NOW(), INTERVAL 2 HOUR), '{"browser":"Firefox","os":"MacOS","device":"Desktop"}'),
(2, 'sess_demo_003', 4, '192.168.1.102', 'Mozilla/5.0 Safari/17.0', '{"auth":true}', UNIX_TIMESTAMP(), DATE_ADD(NOW(), INTERVAL 2 HOUR), '{"browser":"Safari","os":"iOS","device":"Mobile"}');

-- Rate Limits Demo Data
INSERT INTO rate_limits (tenant_id, identifier, identifier_type, endpoint, method, attempts, max_attempts, window_minutes, reset_at) VALUES
(1, '192.168.1.100', 'ip', '/api/auth.php', 'POST', 3, 5, 15, DATE_ADD(NOW(), INTERVAL 15 MINUTE)),
(1, 'user_1', 'user', '/api/files.php', 'GET', 25, 100, 60, DATE_ADD(NOW(), INTERVAL 1 HOUR)),
(2, 'tenant_2', 'tenant', '/api/dashboard.php', 'GET', 50, 1000, 60, DATE_ADD(NOW(), INTERVAL 1 HOUR));

-- System Settings Demo Data
INSERT INTO system_settings (tenant_id, category, setting_key, setting_value, value_type, default_value, description, is_public, display_order, group_name) VALUES
(1, 'general', 'site_name', 'CollaboraNexio Demo A', 'string', 'CollaboraNexio', 'The name of the site', TRUE, 1, 'General'),
(1, 'general', 'maintenance_mode', 'false', 'boolean', 'false', 'Enable maintenance mode', FALSE, 2, 'General'),
(1, 'email', 'smtp_host', 'smtp.gmail.com', 'string', 'localhost', 'SMTP server hostname', FALSE, 1, 'Email'),
(1, 'email', 'smtp_port', '587', 'integer', '25', 'SMTP server port', FALSE, 2, 'Email'),
(1, 'security', 'session_timeout', '7200', 'integer', '3600', 'Session timeout in seconds', FALSE, 1, 'Security'),
(1, 'security', 'password_min_length', '8', 'integer', '8', 'Minimum password length', TRUE, 2, 'Security'),
(1, 'files', 'max_upload_size', '104857600', 'integer', '10485760', 'Maximum file upload size in bytes', TRUE, 1, 'Files'),
(1, 'files', 'allowed_extensions', '["jpg","jpeg","png","pdf","doc","docx","xls","xlsx"]', 'json', '["jpg","png","pdf"]', 'Allowed file extensions', TRUE, 2, 'Files'),
(2, 'general', 'site_name', 'CollaboraNexio Demo B', 'string', 'CollaboraNexio', 'The name of the site', TRUE, 1, 'General'),
(2, 'general', 'timezone', 'Europe/Rome', 'string', 'UTC', 'System timezone', TRUE, 3, 'General');

-- Add demo data for other empty tables

-- Task Comments Demo Data
INSERT INTO task_comments (tenant_id, task_id, user_id, comment, parent_id)
SELECT 1, 1, 1, 'Starting work on this task today', NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM task_comments WHERE tenant_id = 1 AND task_id = 1);

INSERT INTO task_comments (tenant_id, task_id, user_id, comment, parent_id)
SELECT 1, 1, 2, 'Great! Let me know if you need help', NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM task_comments WHERE tenant_id = 1 AND task_id = 1 AND user_id = 2);

INSERT INTO task_comments (tenant_id, task_id, user_id, comment, parent_id)
SELECT 1, 2, 3, 'Database schema is ready for review', NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM task_comments WHERE tenant_id = 1 AND task_id = 2);

INSERT INTO task_comments (tenant_id, task_id, user_id, comment, parent_id)
SELECT 2, 3, 4, 'API endpoints tested and working', NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM task_comments WHERE tenant_id = 2 AND task_id = 3);

-- File Shares Demo Data
INSERT INTO file_shares (tenant_id, file_id, shared_by, shared_with, permission, expires_at)
SELECT 1, 1, 1, 2, 'view', DATE_ADD(NOW(), INTERVAL 30 DAY) FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_shares WHERE tenant_id = 1 AND file_id = 1);

INSERT INTO file_shares (tenant_id, file_id, shared_by, shared_with, permission, expires_at)
SELECT 1, 2, 2, 3, 'edit', DATE_ADD(NOW(), INTERVAL 7 DAY) FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_shares WHERE tenant_id = 1 AND file_id = 2);

INSERT INTO file_shares (tenant_id, file_id, shared_by, shared_with, permission)
SELECT 2, 3, 4, 5, 'view', NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_shares WHERE tenant_id = 2 AND file_id = 3);

-- File Versions Demo Data
INSERT INTO file_versions (tenant_id, file_id, version_number, file_path, file_size, mime_type, checksum, uploaded_by, comment)
SELECT 1, 1, 1, '/uploads/files/doc_v1.pdf', 1024000, 'application/pdf', MD5('version1'), 1, 'Initial version' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_versions WHERE tenant_id = 1 AND file_id = 1 AND version_number = 1);

INSERT INTO file_versions (tenant_id, file_id, version_number, file_path, file_size, mime_type, checksum, uploaded_by, comment)
SELECT 1, 1, 2, '/uploads/files/doc_v2.pdf', 1124000, 'application/pdf', MD5('version2'), 2, 'Added new section' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_versions WHERE tenant_id = 1 AND file_id = 1 AND version_number = 2);

INSERT INTO file_versions (tenant_id, file_id, version_number, file_path, file_size, mime_type, checksum, uploaded_by, comment)
SELECT 1, 2, 1, '/uploads/files/report.xlsx', 524000, 'application/vnd.ms-excel', MD5('excel1'), 2, 'Q1 Report' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM file_versions WHERE tenant_id = 1 AND file_id = 2 AND version_number = 1);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Database migration completed successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collaboranexio'
        AND table_name IN ('project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings')) as new_tables_created,
       NOW() as execution_time;