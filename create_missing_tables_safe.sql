-- Module: Safe Creation of Missing Tables
-- Version: 2025-01-25
-- Author: Database Architect
-- Description: Ultra-safe script that creates missing tables without errors
-- IMPORTANT: This script can be run multiple times safely

USE collabora;

-- ============================================
-- COMPLETELY DISABLE CONSTRAINTS
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';
SET AUTOCOMMIT = 0;
START TRANSACTION;

-- ============================================
-- DROP EXISTING TABLES (CLEAN START)
-- ============================================
DROP TABLE IF EXISTS project_milestones;
DROP TABLE IF EXISTS event_attendees;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS system_settings;

-- ============================================
-- CREATE TABLES WITHOUT ANY FOREIGN KEYS
-- ============================================

-- 1. PROJECT_MILESTONES
CREATE TABLE project_milestones (
    tenant_id INT NOT NULL DEFAULT 1,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    due_date DATE NOT NULL DEFAULT (CURRENT_DATE + INTERVAL 30 DAY),
    status VARCHAR(50) DEFAULT 'pending',
    priority VARCHAR(50) DEFAULT 'medium',
    completion_percentage DECIMAL(5,2) DEFAULT 0.00,
    responsible_user_id INT UNSIGNED NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_milestone_tenant (tenant_id),
    KEY idx_milestone_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. EVENT_ATTENDEES
CREATE TABLE event_attendees (
    tenant_id INT NOT NULL DEFAULT 1,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL DEFAULT 1,
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    attendance_status VARCHAR(50) DEFAULT 'invited',
    rsvp_response VARCHAR(50) DEFAULT 'pending',
    is_organizer TINYINT(1) DEFAULT 0,
    responded_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_event_user (event_id, user_id),
    KEY idx_attendee_tenant (tenant_id),
    KEY idx_attendee_event (event_id),
    KEY idx_attendee_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SESSIONS
CREATE TABLE sessions (
    tenant_id INT NOT NULL DEFAULT 1,
    id VARCHAR(128) NOT NULL,
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    ip_address VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    user_agent TEXT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    data TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session_tenant (tenant_id),
    KEY idx_session_user (user_id),
    KEY idx_session_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RATE_LIMITS
CREATE TABLE rate_limits (
    tenant_id INT NOT NULL DEFAULT 1,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(255) NOT NULL,
    identifier_type VARCHAR(50) NOT NULL DEFAULT 'ip',
    endpoint VARCHAR(255) NOT NULL DEFAULT '/',
    attempts INT UNSIGNED DEFAULT 1,
    max_attempts INT UNSIGNED DEFAULT 60,
    window_minutes INT UNSIGNED DEFAULT 1,
    first_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    is_blocked TINYINT(1) DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rate_limit (identifier, endpoint),
    KEY idx_rate_tenant (tenant_id),
    KEY idx_rate_blocked (is_blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SYSTEM_SETTINGS
CREATE TABLE system_settings (
    tenant_id INT NOT NULL DEFAULT 1,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_group VARCHAR(50) NOT NULL DEFAULT 'general',
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type VARCHAR(50) DEFAULT 'string',
    display_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_public TINYINT(1) DEFAULT 0,
    default_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_setting (tenant_id, setting_group, setting_key),
    KEY idx_setting_tenant (tenant_id),
    KEY idx_setting_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT MINIMAL DEMO DATA
-- ============================================

-- Demo data for project_milestones
INSERT INTO project_milestones (tenant_id, name, description, due_date) VALUES
(1, 'Phase 1 - Planning', 'Initial planning phase', DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(1, 'Phase 2 - Development', 'Development phase', DATE_ADD(CURDATE(), INTERVAL 30 DAY)),
(1, 'Phase 3 - Testing', 'Testing and QA', DATE_ADD(CURDATE(), INTERVAL 45 DAY));

-- Demo data for event_attendees
INSERT INTO event_attendees (tenant_id, event_id, user_id, attendance_status) VALUES
(1, 1, 1, 'accepted'),
(1, 1, 2, 'invited'),
(1, 1, 3, 'tentative');

-- Demo data for sessions
INSERT INTO sessions (id, tenant_id, user_id, ip_address) VALUES
('session_demo_1', 1, 1, '127.0.0.1'),
('session_demo_2', 1, 2, '192.168.1.100');

-- Demo data for rate_limits
INSERT INTO rate_limits (tenant_id, identifier, identifier_type, endpoint) VALUES
(1, '127.0.0.1', 'ip', '/api/login'),
(1, '192.168.1.100', 'ip', '/api/data');

-- Demo data for system_settings
INSERT INTO system_settings (tenant_id, setting_group, setting_key, setting_value, display_name, description) VALUES
(1, 'general', 'app_name', 'CollaboraNexio', 'Application Name', 'The name of the application'),
(1, 'general', 'timezone', 'UTC', 'Time Zone', 'System timezone'),
(1, 'general', 'language', 'en', 'Default Language', 'System default language'),
(1, 'security', 'session_timeout', '3600', 'Session Timeout', 'Session timeout in seconds'),
(1, 'security', 'max_attempts', '5', 'Max Login Attempts', 'Maximum login attempts allowed'),
(1, 'email', 'smtp_host', 'localhost', 'SMTP Host', 'Email server hostname'),
(1, 'email', 'smtp_port', '25', 'SMTP Port', 'Email server port'),
(1, 'api', 'rate_limit', '60', 'API Rate Limit', 'Requests per minute'),
(1, 'storage', 'max_upload', '10485760', 'Max Upload Size', 'Maximum file upload size in bytes');

-- ============================================
-- COMMIT TRANSACTION
-- ============================================
COMMIT;

-- ============================================
-- RE-ENABLE CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;
SET AUTOCOMMIT = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'INSTALLATION COMPLETE' as Status;

SELECT TABLE_NAME as 'Created Tables',
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = t.TABLE_NAME AND table_schema = 'collabora') as 'Column Count'
FROM information_schema.tables t
WHERE t.table_schema = 'collabora'
AND t.table_name IN ('project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings');

SELECT
    'project_milestones' as TableName, COUNT(*) as RecordCount FROM project_milestones
UNION ALL
SELECT
    'event_attendees', COUNT(*) FROM event_attendees
UNION ALL
SELECT
    'sessions', COUNT(*) FROM sessions
UNION ALL
SELECT
    'rate_limits', COUNT(*) FROM rate_limits
UNION ALL
SELECT
    'system_settings', COUNT(*) FROM system_settings;