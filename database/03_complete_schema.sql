-- Module: CollaboraNexio Complete Database Schema
-- Version: 2025-09-25
-- Author: Database Architect
-- Description: Complete multi-tenant collaborative platform database following COLLABORA specifications

-- ============================================
-- DATABASE SETUP
-- ============================================
CREATE DATABASE IF NOT EXISTS collaboranexio
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE collaboranexio;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS chat_message_reads;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS chat_channel_members;
DROP TABLE IF EXISTS chat_channels;
DROP TABLE IF EXISTS task_comments;
DROP TABLE IF EXISTS task_assignments;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS file_shares;
DROP TABLE IF EXISTS file_versions;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS folders;
DROP TABLE IF EXISTS calendar_shares;
DROP TABLE IF EXISTS calendar_events;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- ============================================
-- TABLE: TENANTS (Core multi-tenancy)
-- ============================================
CREATE TABLE tenants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    max_users INT DEFAULT 10,
    max_storage_gb INT DEFAULT 100,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_domain (domain),
    INDEX idx_tenant_status (status),
    INDEX idx_tenant_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: USERS
-- ============================================
CREATE TABLE users (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT UNSIGNED NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(200) NULL,

    -- Role and permissions
    role ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'user',
    permissions JSON NULL,

    -- Status and security
    status ENUM('active', 'inactive', 'suspended', 'pending') DEFAULT 'pending',
    email_verified_at TIMESTAMP NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(255) NULL,

    -- Profile
    avatar_url VARCHAR(500) NULL,
    phone VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    position VARCHAR(100) NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    language VARCHAR(10) DEFAULT 'en',

    -- Activity tracking
    last_login_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_user_email_tenant (tenant_id, email),
    INDEX idx_user_tenant_status (tenant_id, status),
    INDEX idx_user_role (tenant_id, role),
    INDEX idx_user_email (email),
    INDEX idx_user_last_activity (last_activity_at),
    INDEX idx_user_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: PASSWORD_RESETS
-- ============================================
CREATE TABLE password_resets (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    used_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_reset_token (token),
    INDEX idx_reset_email (email),
    INDEX idx_reset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: USER_SESSIONS
-- ============================================
CREATE TABLE user_sessions (
    tenant_id INT UNSIGNED NOT NULL,
    id VARCHAR(128) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (user_id),
    INDEX idx_session_last_activity (last_activity),
    INDEX idx_session_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: USER_PERMISSIONS (Role-based access control)
-- ============================================
CREATE TABLE user_permissions (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id INT UNSIGNED NULL,
    permission VARCHAR(100) NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_user_permission (user_id, resource_type, resource_id, permission),
    INDEX idx_permission_user (user_id),
    INDEX idx_permission_resource (resource_type, resource_id),
    INDEX idx_permission_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: FOLDERS (File system structure)
-- ============================================
CREATE TABLE folders (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1000) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_folder_tenant_parent (tenant_id, parent_id),
    INDEX idx_folder_path (path),
    INDEX idx_folder_owner (owner_id),
    INDEX idx_folder_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: FILES
-- ============================================
CREATE TABLE files (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    checksum VARCHAR(64) NULL,
    owner_id INT UNSIGNED NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    download_count INT UNSIGNED DEFAULT 0,
    tags JSON NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_file_tenant_folder (tenant_id, folder_id),
    INDEX idx_file_name (name),
    INDEX idx_file_owner (owner_id),
    INDEX idx_file_mime (mime_type),
    INDEX idx_file_checksum (checksum),
    INDEX idx_file_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: FILE_VERSIONS
-- ============================================
CREATE TABLE file_versions (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    checksum VARCHAR(64) NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_file_version (file_id, version_number),
    INDEX idx_version_file (file_id),
    INDEX idx_version_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: FILE_SHARES
-- ============================================
CREATE TABLE file_shares (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT UNSIGNED NULL,
    folder_id INT UNSIGNED NULL,
    shared_by INT UNSIGNED NOT NULL,
    shared_with INT UNSIGNED NULL,
    share_type ENUM('user', 'group', 'link') NOT NULL,
    permissions ENUM('view', 'edit', 'delete') DEFAULT 'view',
    share_token VARCHAR(64) NULL,
    password_hash VARCHAR(255) NULL,
    expires_at TIMESTAMP NULL,
    accessed_at TIMESTAMP NULL,
    access_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_share_token (share_token),
    INDEX idx_share_file (file_id),
    INDEX idx_share_folder (folder_id),
    INDEX idx_share_user (shared_with),
    INDEX idx_share_expires (expires_at),
    CHECK ((file_id IS NOT NULL AND folder_id IS NULL) OR (file_id IS NULL AND folder_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: PROJECTS
-- ============================================
CREATE TABLE projects (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    owner_id INT UNSIGNED NOT NULL,
    status ENUM('planning', 'active', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    start_date DATE NULL,
    end_date DATE NULL,
    budget DECIMAL(15,2) NULL,
    progress_percentage TINYINT UNSIGNED DEFAULT 0,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project_tenant_status (tenant_id, status),
    INDEX idx_project_owner (owner_id),
    INDEX idx_project_dates (start_date, end_date),
    INDEX idx_project_priority (priority),
    CHECK (progress_percentage >= 0 AND progress_percentage <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: PROJECT_MEMBERS
-- ============================================
CREATE TABLE project_members (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'manager', 'member', 'viewer') DEFAULT 'member',
    added_by INT UNSIGNED NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_project_member (project_id, user_id),
    INDEX idx_member_project (project_id),
    INDEX idx_member_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: TASKS
-- ============================================
CREATE TABLE tasks (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NULL,
    parent_task_id INT UNSIGNED NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    assigned_to INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    status ENUM('todo', 'in_progress', 'review', 'done', 'cancelled') DEFAULT 'todo',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    due_date DATETIME NULL,
    estimated_hours DECIMAL(6,2) NULL,
    actual_hours DECIMAL(6,2) NULL,
    progress_percentage TINYINT UNSIGNED DEFAULT 0,
    tags JSON NULL,
    attachments JSON NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_task_tenant_status (tenant_id, status),
    INDEX idx_task_project (project_id),
    INDEX idx_task_assigned (assigned_to),
    INDEX idx_task_due_date (due_date),
    INDEX idx_task_priority (priority),
    INDEX idx_task_parent (parent_task_id),
    CHECK (progress_percentage >= 0 AND progress_percentage <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: TASK_ASSIGNMENTS (Multiple assignees)
-- ============================================
CREATE TABLE task_assignments (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_task_assignment (task_id, user_id),
    INDEX idx_assignment_task (task_id),
    INDEX idx_assignment_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: TASK_COMMENTS
-- ============================================
CREATE TABLE task_comments (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_comment_id INT UNSIGNED NULL,
    content TEXT NOT NULL,
    attachments JSON NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES task_comments(id) ON DELETE CASCADE,
    INDEX idx_comment_task (task_id),
    INDEX idx_comment_user (user_id),
    INDEX idx_comment_parent (parent_comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CALENDAR_EVENTS
-- ============================================
CREATE TABLE calendar_events (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    organizer_id INT UNSIGNED NOT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    all_day BOOLEAN DEFAULT FALSE,
    recurrence_rule VARCHAR(500) NULL,
    recurrence_end DATE NULL,
    event_type ENUM('meeting', 'task', 'reminder', 'holiday', 'other') DEFAULT 'meeting',
    status ENUM('tentative', 'confirmed', 'cancelled') DEFAULT 'confirmed',
    visibility ENUM('public', 'private', 'confidential') DEFAULT 'public',
    color VARCHAR(7) NULL,
    reminder_minutes INT NULL,
    meeting_url VARCHAR(500) NULL,
    attachments JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_event_tenant_date (tenant_id, start_datetime, end_datetime),
    INDEX idx_event_organizer (organizer_id),
    INDEX idx_event_type (event_type),
    INDEX idx_event_status (status),
    CHECK (end_datetime >= start_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CALENDAR_SHARES (Event attendees/invites)
-- ============================================
CREATE TABLE calendar_shares (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    response_status ENUM('pending', 'accepted', 'declined', 'tentative') DEFAULT 'pending',
    is_optional BOOLEAN DEFAULT FALSE,
    responded_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_event_attendee (event_id, user_id),
    INDEX idx_attendee_event (event_id),
    INDEX idx_attendee_user (user_id),
    INDEX idx_attendee_response (response_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_CHANNELS
-- ============================================
CREATE TABLE chat_channels (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    type ENUM('public', 'private', 'direct') DEFAULT 'public',
    owner_id INT UNSIGNED NOT NULL,
    is_archived BOOLEAN DEFAULT FALSE,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_channel_tenant_type (tenant_id, type),
    INDEX idx_channel_owner (owner_id),
    INDEX idx_channel_archived (is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_CHANNEL_MEMBERS
-- ============================================
CREATE TABLE chat_channel_members (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_read_at TIMESTAMP NULL,
    notification_level ENUM('all', 'mentions', 'none') DEFAULT 'all',

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_channel_member (channel_id, user_id),
    INDEX idx_member_channel (channel_id),
    INDEX idx_member_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_MESSAGES
-- ============================================
CREATE TABLE chat_messages (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_message_id INT UNSIGNED NULL,
    content TEXT NOT NULL,
    message_type ENUM('text', 'file', 'image', 'system') DEFAULT 'text',
    attachments JSON NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL,
    INDEX idx_message_channel (channel_id),
    INDEX idx_message_user (user_id),
    INDEX idx_message_parent (parent_message_id),
    INDEX idx_message_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_MESSAGE_READS
-- ============================================
CREATE TABLE chat_message_reads (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_message_read (message_id, user_id),
    INDEX idx_read_message (message_id),
    INDEX idx_read_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: NOTIFICATIONS
-- ============================================
CREATE TABLE notifications (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_user_unread (user_id, is_read),
    INDEX idx_notification_type (type),
    INDEX idx_notification_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: AUDIT_LOGS
-- ============================================
CREATE TABLE audit_logs (
    tenant_id INT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_tenant_action (tenant_id, action),
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_resource (resource_type, resource_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample tenants
INSERT INTO tenants (id, name, domain, status, max_users, max_storage_gb) VALUES
    (1, 'Acme Corporation', 'acme.collaboranexio.com', 'active', 50, 500),
    (2, 'Tech Innovations Inc', 'tech.collaboranexio.com', 'active', 25, 250)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample users for Tenant 1
INSERT INTO users (tenant_id, id, email, password_hash, first_name, last_name, role, status, email_verified_at) VALUES
    (1, 1, 'admin@acme.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Admin', 'admin', 'active', NOW()),
    (1, 2, 'jane.doe@acme.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane', 'Doe', 'manager', 'active', NOW()),
    (1, 3, 'bob.smith@acme.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bob', 'Smith', 'user', 'active', NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Sample users for Tenant 2
INSERT INTO users (tenant_id, id, email, password_hash, first_name, last_name, role, status, email_verified_at) VALUES
    (2, 4, 'admin@tech.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah', 'Tech', 'admin', 'active', NOW()),
    (2, 5, 'dev@tech.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike', 'Developer', 'user', 'active', NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Sample projects for Tenant 1
INSERT INTO projects (tenant_id, id, name, description, owner_id, status, priority, start_date, end_date, budget) VALUES
    (1, 1, 'Website Redesign', 'Complete redesign of corporate website', 1, 'active', 'high', '2025-01-01', '2025-06-30', 50000.00),
    (1, 2, 'Mobile App Development', 'Develop iOS and Android mobile applications', 2, 'planning', 'medium', '2025-03-01', '2025-12-31', 100000.00)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample project members
INSERT INTO project_members (tenant_id, project_id, user_id, role, added_by) VALUES
    (1, 1, 1, 'owner', 1),
    (1, 1, 2, 'manager', 1),
    (1, 1, 3, 'member', 2)
ON DUPLICATE KEY UPDATE role=VALUES(role);

-- Sample tasks for Project 1
INSERT INTO tasks (tenant_id, id, project_id, title, description, assigned_to, created_by, status, priority, due_date) VALUES
    (1, 1, 1, 'Create wireframes', 'Design wireframes for all pages', 2, 1, 'in_progress', 'high', '2025-02-15'),
    (1, 2, 1, 'Develop homepage', 'Implement responsive homepage design', 3, 2, 'todo', 'medium', '2025-03-01'),
    (1, 3, 1, 'Setup hosting', 'Configure cloud hosting environment', 3, 1, 'done', 'high', '2025-01-20')
ON DUPLICATE KEY UPDATE title=VALUES(title);

-- Sample folders
INSERT INTO folders (tenant_id, id, parent_id, name, path, owner_id, is_public) VALUES
    (1, 1, NULL, 'Documents', '/Documents', 1, FALSE),
    (1, 2, 1, 'Projects', '/Documents/Projects', 1, FALSE),
    (1, 3, NULL, 'Public', '/Public', 1, TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample files
INSERT INTO files (tenant_id, id, folder_id, name, original_name, mime_type, size_bytes, storage_path, owner_id) VALUES
    (1, 1, 2, 'project_plan.pdf', 'Project Plan 2025.pdf', 'application/pdf', 2048000, '/storage/tenant_1/files/abc123.pdf', 1),
    (1, 2, 2, 'budget.xlsx', 'Budget Spreadsheet.xlsx', 'application/vnd.ms-excel', 512000, '/storage/tenant_1/files/def456.xlsx', 2)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample chat channels
INSERT INTO chat_channels (tenant_id, id, name, description, type, owner_id) VALUES
    (1, 1, 'General', 'General discussion channel', 'public', 1),
    (1, 2, 'Project Team', 'Website redesign team discussion', 'private', 2),
    (2, 1, 'Tech General', 'Tech team general chat', 'public', 4)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample chat channel members
INSERT INTO chat_channel_members (tenant_id, channel_id, user_id, role) VALUES
    (1, 1, 1, 'owner'),
    (1, 1, 2, 'member'),
    (1, 1, 3, 'member'),
    (1, 2, 2, 'owner'),
    (1, 2, 3, 'member')
ON DUPLICATE KEY UPDATE role=VALUES(role);

-- Sample chat messages
INSERT INTO chat_messages (tenant_id, channel_id, user_id, content, message_type) VALUES
    (1, 1, 1, 'Welcome to the general channel!', 'text'),
    (1, 1, 2, 'Thanks for setting this up!', 'text'),
    (1, 2, 2, 'Let\'s discuss the project timeline', 'text')
ON DUPLICATE KEY UPDATE content=VALUES(content);

-- Sample calendar events
INSERT INTO calendar_events (tenant_id, title, description, organizer_id, start_datetime, end_datetime, event_type) VALUES
    (1, 'Project Kickoff Meeting', 'Initial project planning meeting', 1, '2025-02-01 10:00:00', '2025-02-01 11:30:00', 'meeting'),
    (1, 'Sprint Review', 'Review sprint progress', 2, '2025-02-15 14:00:00', '2025-02-15 15:00:00', 'meeting')
ON DUPLICATE KEY UPDATE title=VALUES(title);

-- Sample notifications
INSERT INTO notifications (tenant_id, user_id, type, title, message) VALUES
    (1, 2, 'task_assigned', 'New Task Assigned', 'You have been assigned to "Create wireframes"'),
    (1, 3, 'project_added', 'Added to Project', 'You have been added to "Website Redesign" project')
ON DUPLICATE KEY UPDATE title=VALUES(title);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Database schema created successfully' as Status,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = 'collaboranexio') as TablesCreated,
       NOW() as CompletedAt;