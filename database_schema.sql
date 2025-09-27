-- Module: CollaboraNexio Complete Database Schema
-- Version: 2025-09-25
-- Author: Database Architect
-- Description: Complete multi-tenant collaborative platform database with all required tables

-- ============================================
-- CREATE DATABASE (if not exists)
-- ============================================
CREATE DATABASE IF NOT EXISTS collaboranexio
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE collaboranexio;

-- ============================================
-- CLEANUP (Development only - comment out in production)
-- ============================================
-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS file_shares;
DROP TABLE IF EXISTS file_versions;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS folders;
DROP TABLE IF EXISTS task_assignments;
DROP TABLE IF EXISTS task_comments;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS chat_message_reads;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS chat_channel_members;
DROP TABLE IF EXISTS chat_channels;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;

-- ============================================
-- TABLE: TENANTS (Master table for multi-tenancy)
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
    INDEX idx_tenant_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: USERS
-- ============================================
CREATE TABLE users (
    -- Multi-tenancy support
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
    INDEX idx_user_role (role),
    INDEX idx_user_email (email),
    INDEX idx_user_last_activity (last_activity_at)
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
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (user_id),
    INDEX idx_session_last_activity (last_activity),
    INDEX idx_session_expires (expires_at)
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
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project_tenant_status (tenant_id, status),
    INDEX idx_project_owner (owner_id),
    INDEX idx_project_dates (start_date, end_date)
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
    INDEX idx_task_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: FOLDERS
-- ============================================
CREATE TABLE folders (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1000) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    permissions JSON NULL,
    is_shared BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_folder_path (tenant_id, path),
    INDEX idx_folder_parent (parent_id),
    INDEX idx_folder_owner (owner_id)
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
    hash VARCHAR(64) NULL,
    owner_id INT UNSIGNED NOT NULL,
    is_shared BOOLEAN DEFAULT FALSE,
    share_link VARCHAR(255) NULL,
    download_count INT DEFAULT 0,
    version INT DEFAULT 1,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_file_tenant_folder (tenant_id, folder_id),
    INDEX idx_file_owner (owner_id),
    INDEX idx_file_hash (hash),
    INDEX idx_file_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_CHANNELS
-- ============================================
CREATE TABLE chat_channels (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
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
    INDEX idx_channel_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: CHAT_MESSAGES
-- ============================================
CREATE TABLE chat_messages (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    type ENUM('text', 'file', 'image', 'system') DEFAULT 'text',
    attachments JSON NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    is_deleted BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_message_channel (channel_id, created_at),
    INDEX idx_message_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: NOTIFICATIONS
-- ============================================
CREATE TABLE notifications (
    tenant_id INT UNSIGNED NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    data JSON NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_user_unread (user_id, is_read),
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
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
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
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================
-- Additional composite indexes for common queries
CREATE INDEX idx_user_tenant_role_status ON users(tenant_id, role, status);
CREATE INDEX idx_task_tenant_project_status ON tasks(tenant_id, project_id, status);
CREATE INDEX idx_file_tenant_owner_created ON files(tenant_id, owner_id, created_at);
CREATE INDEX idx_notification_tenant_user_read ON notifications(tenant_id, user_id, is_read);

-- ============================================
-- DEMO DATA
-- ============================================
-- Insert sample tenants
INSERT INTO tenants (id, name, domain, status, max_users, max_storage_gb) VALUES
    (1, 'Demo Company', 'demo.local', 'active', 50, 500),
    (2, 'Test Organization', 'test.local', 'active', 20, 200)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Insert sample users (password is 'Admin123!' for all)
-- Using proper bcrypt hash for 'Admin123!'
INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, display_name, role, status, email_verified_at) VALUES
    -- Tenant 1 users
    (1, 'admin@demo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 'Admin User', 'admin', 'active', NOW()),
    (1, 'manager@demo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Manager', 'User', 'Manager User', 'manager', 'active', NOW()),
    (1, 'user1@demo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Doe', 'John Doe', 'user', 'active', NOW()),
    (1, 'user2@demo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jane', 'Smith', 'Jane Smith', 'user', 'active', NOW()),
    -- Tenant 2 users
    (2, 'admin@test.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test', 'Admin', 'Test Admin', 'admin', 'active', NOW()),
    (2, 'user@test.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test', 'User', 'Test User', 'user', 'active', NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Insert sample projects
INSERT INTO projects (tenant_id, name, description, owner_id, status, priority) VALUES
    (1, 'Website Redesign', 'Complete overhaul of company website', 1, 'active', 'high'),
    (1, 'Mobile App Development', 'Native mobile app for iOS and Android', 2, 'planning', 'medium'),
    (2, 'Data Migration', 'Migrate legacy data to new system', 5, 'active', 'critical')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Insert sample tasks
INSERT INTO tasks (tenant_id, project_id, title, description, assigned_to, created_by, status, priority) VALUES
    (1, 1, 'Design Homepage Mockup', 'Create initial design concepts', 3, 1, 'in_progress', 'high'),
    (1, 1, 'Implement Responsive Layout', 'Make design mobile-friendly', 4, 2, 'todo', 'medium'),
    (1, 2, 'Setup Development Environment', 'Configure React Native environment', 3, 2, 'done', 'high'),
    (2, 3, 'Analyze Current Database', 'Document existing schema', 6, 5, 'in_progress', 'critical')
ON DUPLICATE KEY UPDATE title=VALUES(title);

-- Insert sample folders
INSERT INTO folders (tenant_id, name, path, owner_id) VALUES
    (1, 'Documents', '/Documents', 1),
    (1, 'Projects', '/Projects', 1),
    (1, 'Shared', '/Shared', 1),
    (2, 'Resources', '/Resources', 5)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Insert sample chat channels
INSERT INTO chat_channels (tenant_id, name, description, type, owner_id) VALUES
    (1, 'General', 'General discussion channel', 'public', 1),
    (1, 'Development Team', 'Dev team discussions', 'private', 2),
    (2, 'Announcements', 'Company announcements', 'public', 5)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Insert sample audit log entries
INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, ip_address) VALUES
    (1, 1, 'user.login', 'user', 1, '127.0.0.1'),
    (1, 1, 'project.create', 'project', 1, '127.0.0.1'),
    (2, 5, 'user.login', 'user', 5, '127.0.0.1');

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Database setup completed successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'collaboranexio') as tables_created,
       NOW() as execution_time;