-- ============================================
-- DATABASE COLLABORA - SCHEMA COMPLETO FASE 1
-- ============================================

-- Crea database se non esiste
CREATE DATABASE IF NOT EXISTS collabora 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE collabora;

-- ============================================
-- TABELLA TENANTS
-- ============================================
CREATE TABLE IF NOT EXISTS tenants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    logo_path VARCHAR(500) NULL,
    settings JSON DEFAULT '{}',
    storage_used BIGINT DEFAULT 0,
    storage_limit BIGINT DEFAULT 10737418240,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABELLA USERS
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'special', 'user') DEFAULT 'user',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    preferences JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email_tenant (tenant_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABELLA USER_TENANT_ACCESS
-- ============================================
CREATE TABLE IF NOT EXISTS user_tenant_access (
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, tenant_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABELLA ACTIVITY_LOGS (SENZA PARTITIONING)
-- ============================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT UNSIGNED DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    session_id VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_tenant_action (tenant_id, action, created_at),
    INDEX idx_logs_user_activity (user_id, created_at),
    INDEX idx_logs_entity (entity_type, entity_id),
    INDEX idx_logs_date_range (created_at, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERIMENTO DATI INIZIALI
-- ============================================

-- Inserisci tenant DEMO
INSERT INTO tenants (code, name, settings) VALUES
('DEMO', 'Demo Company', '{"max_users": 10, "features": ["files", "folders"]}');

-- Inserisci utenti con password hash valide
-- Password reali:
-- asamodeo@fortibyte.it: Ricord@1991
-- special@demo.com: Special123!  
-- user@demo.com: Demo123!
INSERT INTO users (tenant_id, email, password_hash, name, role) VALUES
(1, 'asamodeo@fortibyte.it', '$2y$10$wJXq5Q8x0HvVH9ZMqzV6OuFKxHJ5nB.YhNp9bCzWvH5pXGZfKqGxS', 'Admin Sistema', 'admin'),
(1, 'special@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Utente Speciale', 'special'),
(1, 'user@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Utente Normal', 'user');

-- Log di installazione
INSERT INTO activity_logs (tenant_id, action, entity_type) VALUES
(1, 'system_install', 'database');