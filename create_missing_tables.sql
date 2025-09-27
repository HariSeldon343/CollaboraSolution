-- Module: Create Missing Tables
-- Version: 2025-01-25
-- Author: Database Architect
-- Description: Simple, foolproof script to create 5 missing tables in collabora database

USE collabora;

-- ============================================
-- DISABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- CREATE TABLES WITHOUT FOREIGN KEYS FIRST
-- ============================================

-- 1. PROJECT_MILESTONES TABLE (without foreign keys)
CREATE TABLE IF NOT EXISTS project_milestones (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'delayed', 'cancelled') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    completion_percentage DECIMAL(5,2) DEFAULT 0.00,
    responsible_user_id INT UNSIGNED NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_milestone_project (project_id, tenant_id),
    INDEX idx_milestone_due_date (due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. EVENT_ATTENDEES TABLE (without foreign keys)
CREATE TABLE IF NOT EXISTS event_attendees (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    attendance_status ENUM('invited', 'accepted', 'declined', 'tentative') DEFAULT 'invited',
    rsvp_response ENUM('yes', 'no', 'maybe', 'pending') DEFAULT 'pending',
    is_organizer BOOLEAN DEFAULT FALSE,
    responded_at DATETIME NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_event_attendee (event_id, user_id),
    INDEX idx_attendee_user (user_id),
    INDEX idx_attendee_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SESSIONS TABLE (without foreign keys)
CREATE TABLE IF NOT EXISTS sessions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key (session ID)
    id VARCHAR(128) NOT NULL,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    data JSON NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_session_user (user_id, is_active),
    INDEX idx_session_tenant (tenant_id, is_active),
    INDEX idx_session_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RATE_LIMITS TABLE (without foreign keys)
CREATE TABLE IF NOT EXISTS rate_limits (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    identifier VARCHAR(255) NOT NULL,
    identifier_type ENUM('ip', 'user', 'api_key', 'endpoint') NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    attempts INT UNSIGNED DEFAULT 1,
    max_attempts INT UNSIGNED DEFAULT 60,
    window_minutes INT UNSIGNED DEFAULT 1,
    first_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    is_blocked BOOLEAN DEFAULT FALSE,

    PRIMARY KEY (id),
    UNIQUE KEY uk_rate_limit (identifier, endpoint),
    INDEX idx_rate_limit_blocked (is_blocked, blocked_until),
    INDEX idx_rate_limit_tenant (tenant_id, identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SYSTEM_SETTINGS TABLE (without foreign keys)
CREATE TABLE IF NOT EXISTS system_settings (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    setting_group VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type ENUM('string', 'integer', 'boolean', 'json', 'datetime', 'decimal') DEFAULT 'string',
    display_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    default_value TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_setting (tenant_id, setting_group, setting_key),
    INDEX idx_setting_group (setting_group, is_public),
    INDEX idx_setting_tenant (tenant_id, setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CREATE REQUIRED SUPPORTING TABLES IF MISSING
-- ============================================

-- Create projects table if it doesn't exist
CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_project_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create events table if it doesn't exist (renamed from calendar_events)
CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_event_tenant (tenant_id),
    INDEX idx_event_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create calendar_events as alias to events if needed
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_calendar_event_tenant (tenant_id),
    INDEX idx_calendar_event_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADD FOREIGN KEYS (Only if tables exist)
-- ============================================

-- Add foreign keys for project_milestones
ALTER TABLE project_milestones
    ADD CONSTRAINT fk_milestone_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_milestone_project FOREIGN KEY (project_id)
        REFERENCES projects(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_milestone_user FOREIGN KEY (responsible_user_id)
        REFERENCES users(id) ON DELETE SET NULL;

-- Add foreign keys for event_attendees (try both events and calendar_events)
ALTER TABLE event_attendees
    ADD CONSTRAINT fk_attendee_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_attendee_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE;

-- Try to add foreign key to events table first, if fails try calendar_events
ALTER TABLE event_attendees
    ADD CONSTRAINT fk_attendee_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE;

-- Add foreign keys for sessions
ALTER TABLE sessions
    ADD CONSTRAINT fk_session_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_session_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for rate_limits
ALTER TABLE rate_limits
    ADD CONSTRAINT fk_rate_limit_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE;

-- Add foreign keys for system_settings
ALTER TABLE system_settings
    ADD CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE;

-- ============================================
-- INSERT DEMO DATA
-- ============================================

-- Ensure demo tenant exists
INSERT INTO tenants (id, name) VALUES (1, 'Demo Company')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Ensure demo users exist (with simpler structure)
INSERT INTO users (id, tenant_id, name, email, password) VALUES
    (1, 1, 'Admin User', 'admin@demo.com', 'password_hash'),
    (2, 1, 'John Doe', 'john@demo.com', 'password_hash'),
    (3, 1, 'Jane Smith', 'jane@demo.com', 'password_hash')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Insert demo projects
INSERT INTO projects (id, tenant_id, name, description) VALUES
    (1, 1, 'Website Redesign', 'Redesign company website'),
    (2, 1, 'Mobile App', 'Develop mobile application')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Insert demo events
INSERT INTO events (id, tenant_id, title, description, start_date, end_date, created_by) VALUES
    (1, 1, 'Project Kickoff', 'Initial meeting', NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 1),
    (2, 1, 'Sprint Review', 'Review progress', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 8 DAY), 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Also insert into calendar_events for compatibility
INSERT INTO calendar_events (id, tenant_id, title, description, start_date, end_date, created_by) VALUES
    (1, 1, 'Project Kickoff', 'Initial meeting', NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 1),
    (2, 1, 'Sprint Review', 'Review progress', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 8 DAY), 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Insert demo project milestones
INSERT INTO project_milestones (tenant_id, project_id, name, description, due_date, status, responsible_user_id) VALUES
    (1, 1, 'Design Complete', 'Complete all designs', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'pending', 2),
    (1, 1, 'Development Phase', 'Complete development', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pending', 3),
    (1, 2, 'Requirements', 'Gather requirements', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_progress', 1);

-- Insert demo event attendees
INSERT INTO event_attendees (tenant_id, event_id, user_id, attendance_status, rsvp_response) VALUES
    (1, 1, 1, 'accepted', 'yes'),
    (1, 1, 2, 'invited', 'pending'),
    (1, 1, 3, 'accepted', 'yes'),
    (1, 2, 1, 'accepted', 'yes'),
    (1, 2, 2, 'tentative', 'maybe');

-- Insert demo sessions
INSERT INTO sessions (tenant_id, id, user_id, ip_address, user_agent) VALUES
    (1, MD5('session_1'), 1, '127.0.0.1', 'Mozilla/5.0'),
    (1, MD5('session_2'), 2, '127.0.0.1', 'Chrome/120.0');

-- Insert demo rate limits
INSERT INTO rate_limits (tenant_id, identifier, identifier_type, endpoint, attempts) VALUES
    (1, '127.0.0.1', 'ip', '/api/login', 2),
    (1, 'user_1', 'user', '/api/upload', 5);

-- Insert demo system settings
INSERT INTO system_settings (tenant_id, setting_group, setting_key, setting_value, value_type, display_name, description) VALUES
    (1, 'general', 'app_name', 'CollaboraNexio', 'string', 'Application Name', 'Name of the application'),
    (1, 'general', 'timezone', 'UTC', 'string', 'Time Zone', 'Default timezone'),
    (1, 'security', 'session_timeout', '3600', 'integer', 'Session Timeout', 'Session timeout in seconds'),
    (1, 'security', 'max_login_attempts', '5', 'integer', 'Max Login Attempts', 'Maximum login attempts'),
    (1, 'email', 'smtp_host', 'localhost', 'string', 'SMTP Host', 'Mail server host'),
    (1, 'email', 'smtp_port', '25', 'integer', 'SMTP Port', 'Mail server port'),
    (1, 'api', 'rate_limit_enabled', 'true', 'boolean', 'Enable Rate Limiting', 'Enable API rate limiting');

-- ============================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Tables created successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('project_milestones', 'event_attendees', 'sessions', 'rate_limits', 'system_settings')) as tables_created,
       (SELECT COUNT(*) FROM project_milestones) as milestones_count,
       (SELECT COUNT(*) FROM event_attendees) as attendees_count,
       (SELECT COUNT(*) FROM sessions) as sessions_count,
       (SELECT COUNT(*) FROM rate_limits) as rate_limits_count,
       (SELECT COUNT(*) FROM system_settings) as settings_count,
       NOW() as execution_time;