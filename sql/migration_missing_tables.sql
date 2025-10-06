-- ============================================
-- Migration Script for Missing Tables
-- Version: 2025-01-25
-- Author: Database Architect
-- Description: Creates missing tables with proper structure
-- ============================================

USE collabora;

-- Disable foreign key checks for migration
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- 1. PROJECT_MILESTONES
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
    deliverables JSON NULL,
    responsible_user_id INT UNSIGNED NULL,
    dependencies JSON NULL,
    completed_at DATETIME NULL,
    notes TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_milestone_project (project_id, tenant_id),
    INDEX idx_milestone_due_date (due_date, status),
    INDEX idx_milestone_responsible (responsible_user_id, status),
    INDEX idx_milestone_status_date (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. EVENT_ATTENDEES
CREATE TABLE IF NOT EXISTS event_attendees (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    attendance_status ENUM('invited', 'accepted', 'declined', 'tentative', 'attended', 'no_show') DEFAULT 'invited',
    rsvp_response ENUM('yes', 'no', 'maybe', 'pending') DEFAULT 'pending',
    is_organizer BOOLEAN DEFAULT FALSE,
    is_optional BOOLEAN DEFAULT FALSE,
    response_message TEXT NULL,
    responded_at DATETIME NULL,
    reminder_sent BOOLEAN DEFAULT FALSE,
    reminder_sent_at DATETIME NULL,
    check_in_time DATETIME NULL,
    check_out_time DATETIME NULL,
    notes TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_event_attendee (event_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_attendee_user (user_id, attendance_status),
    INDEX idx_attendee_event (event_id, attendance_status),
    INDEX idx_attendee_response (rsvp_response, attendance_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SESSIONS
CREATE TABLE IF NOT EXISTS sessions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key (session ID)
    id VARCHAR(128) NOT NULL,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
    browser VARCHAR(50) NULL,
    platform VARCHAR(50) NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    logout_time DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    remember_token VARCHAR(100) NULL,
    csrf_token VARCHAR(64) NULL,
    location_country VARCHAR(2) NULL,
    location_city VARCHAR(100) NULL,
    data JSON NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_session_user (user_id, is_active),
    INDEX idx_session_tenant (tenant_id, is_active),
    INDEX idx_session_activity (last_activity),
    INDEX idx_session_remember (remember_token, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RATE_LIMITS
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
    block_reason TEXT NULL,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_rate_limit (identifier, endpoint),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_rate_limit_blocked (is_blocked, blocked_until),
    INDEX idx_rate_limit_tenant (tenant_id, identifier),
    INDEX idx_rate_window (first_attempt_at, window_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SYSTEM_SETTINGS
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
    is_encrypted BOOLEAN DEFAULT FALSE,
    can_override BOOLEAN DEFAULT TRUE,
    default_value TEXT NULL,
    validation_rules JSON NULL,
    last_modified_by INT UNSIGNED NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_setting (tenant_id, setting_group, setting_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (last_modified_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_setting_group (setting_group, is_public),
    INDEX idx_setting_tenant (tenant_id, setting_group),
    INDEX idx_settings_public (is_public, setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEMO DATA
-- ============================================

-- Ensure demo tenants exist
INSERT INTO tenants (id, name, created_at) VALUES
    (1, 'Demo Company A', NOW()),
    (2, 'Demo Company B', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Ensure demo users exist
INSERT INTO users (id, tenant_id, username, email, password_hash, created_at) VALUES
    (1, 1, 'admin', 'admin@demo.com', '$2y$10$YourHashHere', NOW()),
    (2, 1, 'user1', 'user1@demo.com', '$2y$10$YourHashHere', NOW()),
    (3, 1, 'user2', 'user2@demo.com', '$2y$10$YourHashHere', NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Ensure demo projects exist
INSERT INTO projects (id, tenant_id, name, description, status, created_at) VALUES
    (1, 1, 'Website Redesign', 'Complete overhaul of company website', 'active', NOW()),
    (2, 1, 'Mobile App Development', 'Native mobile app for customers', 'planning', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Ensure demo events exist
INSERT INTO events (id, tenant_id, title, description, start_date, end_date, created_by, created_at) VALUES
    (1, 1, 'Project Kickoff', 'Initial project meeting', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW()),
    (2, 1, 'Sprint Review', 'Review sprint progress', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, NOW())
ON DUPLICATE KEY UPDATE title=VALUES(title);

-- Insert demo project milestones
INSERT INTO project_milestones (tenant_id, project_id, name, description, due_date, status, priority, responsible_user_id, completion_percentage) VALUES
    (1, 1, 'Design Mockups Complete', 'Finalize all design mockups and get approval', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_progress', 'high', 2, 65.00),
    (1, 1, 'Frontend Development', 'Complete frontend implementation', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pending', 'high', 2, 0.00),
    (1, 1, 'Backend API Integration', 'Integrate all backend APIs', DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'pending', 'medium', 3, 0.00),
    (1, 2, 'Requirements Gathering', 'Complete requirements document', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_progress', 'critical', 1, 80.00),
    (1, 2, 'Technical Architecture', 'Design system architecture', DATE_ADD(CURDATE(), INTERVAL 21 DAY), 'pending', 'high', 1, 0.00);

-- Insert demo event attendees
INSERT INTO event_attendees (tenant_id, event_id, user_id, attendance_status, rsvp_response, is_organizer) VALUES
    (1, 1, 1, 'accepted', 'yes', TRUE),
    (1, 1, 2, 'invited', 'pending', FALSE),
    (1, 1, 3, 'accepted', 'yes', FALSE),
    (1, 2, 1, 'accepted', 'yes', TRUE),
    (1, 2, 2, 'tentative', 'maybe', FALSE),
    (1, 2, 3, 'declined', 'no', FALSE);

-- Insert demo sessions (active sessions)
INSERT INTO sessions (tenant_id, id, user_id, ip_address, user_agent, device_type, browser, platform, is_active) VALUES
    (1, MD5(CONCAT('session_1_', UNIX_TIMESTAMP())), 1, '192.168.1.100', 'Mozilla/5.0 Chrome/120.0', 'desktop', 'Chrome', 'Windows', TRUE),
    (1, MD5(CONCAT('session_2_', UNIX_TIMESTAMP())), 2, '192.168.1.101', 'Mozilla/5.0 Safari/17.0', 'mobile', 'Safari', 'iOS', TRUE);

-- Insert demo rate limits
INSERT INTO rate_limits (tenant_id, identifier, identifier_type, endpoint, attempts, max_attempts, window_minutes) VALUES
    (1, '192.168.1.100', 'ip', '/api/login', 3, 5, 15),
    (1, 'user_1', 'user', '/api/upload', 10, 100, 60);

-- Insert demo system settings
INSERT INTO system_settings (tenant_id, setting_group, setting_key, setting_value, value_type, display_name, description, is_public, default_value) VALUES
    (1, 'general', 'app_name', 'CollaboraNexio', 'string', 'Application Name', 'The name of the application', TRUE, 'CollaboraNexio'),
    (1, 'general', 'timezone', 'UTC', 'string', 'Time Zone', 'Default timezone for the application', TRUE, 'UTC'),
    (1, 'general', 'date_format', 'Y-m-d', 'string', 'Date Format', 'Default date format', TRUE, 'Y-m-d'),
    (1, 'general', 'time_format', 'H:i:s', 'string', 'Time Format', 'Default time format', TRUE, 'H:i:s'),
    (1, 'security', 'session_timeout', '3600', 'integer', 'Session Timeout', 'Session timeout in seconds', FALSE, '3600'),
    (1, 'security', 'max_login_attempts', '5', 'integer', 'Max Login Attempts', 'Maximum login attempts before lockout', FALSE, '5'),
    (1, 'security', 'password_min_length', '8', 'integer', 'Minimum Password Length', 'Minimum password length required', FALSE, '8'),
    (1, 'security', 'enable_2fa', 'false', 'boolean', 'Enable 2FA', 'Enable two-factor authentication', FALSE, 'false'),
    (1, 'email', 'smtp_host', 'localhost', 'string', 'SMTP Host', 'SMTP server hostname', FALSE, 'localhost'),
    (1, 'email', 'smtp_port', '25', 'integer', 'SMTP Port', 'SMTP server port', FALSE, '25'),
    (1, 'email', 'smtp_encryption', 'none', 'string', 'SMTP Encryption', 'SMTP encryption method', FALSE, 'none'),
    (1, 'email', 'from_email', 'noreply@collabora.com', 'string', 'From Email', 'Default from email address', FALSE, 'noreply@example.com'),
    (1, 'storage', 'max_file_size', '10485760', 'integer', 'Max File Size', 'Maximum file upload size in bytes', FALSE, '10485760'),
    (1, 'storage', 'allowed_extensions', '["pdf","doc","docx","xls","xlsx","png","jpg","jpeg","gif"]', 'json', 'Allowed File Extensions', 'List of allowed file extensions', FALSE, '["pdf","doc","docx"]'),
    (1, 'api', 'rate_limit_enabled', 'true', 'boolean', 'Enable Rate Limiting', 'Enable API rate limiting', FALSE, 'true'),
    (1, 'api', 'rate_limit_requests', '60', 'integer', 'Rate Limit Requests', 'Number of requests allowed per window', FALSE, '60'),
    (1, 'api', 'rate_limit_window', '60', 'integer', 'Rate Limit Window', 'Rate limit window in seconds', FALSE, '60');

-- Insert demo data for tenant 2
INSERT INTO project_milestones (tenant_id, project_id, name, description, due_date, status, priority, responsible_user_id) VALUES
    (2, 1, 'Initial Planning', 'Complete project planning phase', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'completed', 'high', 1, 100.00);

INSERT INTO system_settings (tenant_id, setting_group, setting_key, setting_value, value_type, display_name, description, is_public) VALUES
    (2, 'general', 'app_name', 'CollaboraNexio B', 'string', 'Application Name', 'The name of the application', TRUE);

-- ============================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM project_milestones) as milestones_count,
       (SELECT COUNT(*) FROM event_attendees) as attendees_count,
       (SELECT COUNT(*) FROM sessions) as sessions_count,
       (SELECT COUNT(*) FROM rate_limits) as rate_limits_count,
       (SELECT COUNT(*) FROM system_settings) as settings_count,
       NOW() as execution_time;