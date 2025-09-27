-- Fix Base Tables Structure for CollaboraNexio (SAFE VERSION)
-- Version: 2025-01-22
-- This script safely handles foreign key constraints

USE collabora;

-- ============================================
-- DISABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- DROP ALL TABLES IN CORRECT ORDER
-- ============================================
-- Drop chat tables first (if they exist)
DROP TABLE IF EXISTS message_read_receipts;
DROP TABLE IF EXISTS message_mentions;
DROP TABLE IF EXISTS message_attachments;
DROP TABLE IF EXISTS message_reactions;
DROP TABLE IF EXISTS message_edits;
DROP TABLE IF EXISTS chat_typing;
DROP TABLE IF EXISTS chat_presence;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS channel_members;
DROP TABLE IF EXISTS chat_channels;

-- Now drop base tables
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;

-- ============================================
-- RECREATE BASE TABLES WITH CORRECT STRUCTURE
-- ============================================

-- Tenants table (Multi-tenancy root)
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

-- Users table
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

-- Teams table
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

-- Files table
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

-- ============================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- INSERT SAMPLE DATA
-- ============================================

-- Sample tenants
INSERT INTO tenants (id, name, domain) VALUES
    (1, 'Demo Company A', 'demo-a.local'),
    (2, 'Demo Company B', 'demo-b.local')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample users (password is 'password' hashed with bcrypt)
INSERT INTO users (id, tenant_id, name, email, password) VALUES
    (1, 1, 'Alice Johnson', 'alice@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (2, 1, 'Bob Smith', 'bob@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (3, 1, 'Charlie Brown', 'charlie@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (4, 2, 'Diana Prince', 'diana@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (5, 1, 'Eve Anderson', 'eve@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample teams
INSERT INTO teams (id, tenant_id, name, description, manager_id) VALUES
    (1, 1, 'Engineering', 'Engineering and Development team', 1),
    (2, 1, 'Marketing', 'Marketing and Communications team', 2),
    (3, 1, 'Support', 'Customer Support team', 3),
    (4, 2, 'Operations', 'Operations team', 4)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample files
INSERT INTO files (tenant_id, user_id, file_name, file_path, file_size, mime_type) VALUES
    (1, 1, 'document.pdf', '/uploads/1/document.pdf', 102400, 'application/pdf'),
    (1, 2, 'image.jpg', '/uploads/1/image.jpg', 51200, 'image/jpeg')
ON DUPLICATE KEY UPDATE file_name=VALUES(file_name);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Base tables created successfully!' as Status;

-- Show tables
SHOW TABLES;

-- Verify structure
SELECT
    table_name,
    COUNT(*) as column_count
FROM information_schema.columns
WHERE table_schema = DATABASE()
AND table_name IN ('tenants', 'users', 'teams', 'files')
GROUP BY table_name;

-- Count records
SELECT 'tenants' as table_name, COUNT(*) as records FROM tenants
UNION ALL
SELECT 'users', COUNT(*) FROM users
UNION ALL
SELECT 'teams', COUNT(*) FROM teams
UNION ALL
SELECT 'files', COUNT(*) FROM files;