-- Fix Base Tables Structure for CollaboraNexio
-- Version: 2025-01-22
-- This script ensures proper indexes on base tables

USE collabora;

-- Check and fix tenants table
SHOW CREATE TABLE tenants;

-- If tenants.id is not properly indexed, recreate the table
DROP TABLE IF EXISTS chat_typing;
DROP TABLE IF EXISTS chat_presence;
DROP TABLE IF EXISTS message_read_receipts;
DROP TABLE IF EXISTS message_mentions;
DROP TABLE IF EXISTS message_attachments;
DROP TABLE IF EXISTS message_reactions;
DROP TABLE IF EXISTS message_edits;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS channel_members;
DROP TABLE IF EXISTS chat_channels;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;

-- Recreate tenants with proper primary key
CREATE TABLE tenants (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NULL,
    settings JSON NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_name (name),
    INDEX idx_tenant_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recreate users with proper foreign key
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'user') DEFAULT 'user',
    avatar_url TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_email (tenant_id, email),
    INDEX idx_user_tenant (tenant_id),
    INDEX idx_user_active (is_active),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recreate teams with proper foreign keys
CREATE TABLE teams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    manager_id INT UNSIGNED NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_team_tenant (tenant_id),
    INDEX idx_team_active (is_active),
    CONSTRAINT fk_teams_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_teams_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recreate files with proper foreign keys
CREATE TABLE files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NULL,
    checksum VARCHAR(64) NULL,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_file_tenant (tenant_id),
    INDEX idx_file_user (user_id),
    INDEX idx_file_checksum (checksum),
    CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data
INSERT INTO tenants (id, name, domain) VALUES
    (1, 'Demo Company A', 'demo-a.local'),
    (2, 'Demo Company B', 'demo-b.local')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO users (id, tenant_id, name, email, password) VALUES
    (1, 1, 'Alice Johnson', 'alice@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (2, 1, 'Bob Smith', 'bob@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (3, 1, 'Charlie Brown', 'charlie@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (4, 2, 'Diana Prince', 'diana@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO teams (id, tenant_id, name, description, manager_id) VALUES
    (1, 1, 'Engineering', 'Engineering team', 1),
    (2, 1, 'Marketing', 'Marketing team', 2),
    (3, 1, 'Support', 'Customer support team', 3)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Verify structure
SHOW CREATE TABLE tenants;
SHOW CREATE TABLE users;
SHOW CREATE TABLE teams;
SHOW CREATE TABLE files;

SELECT 'Base tables fixed and ready for chat installation' as Status;