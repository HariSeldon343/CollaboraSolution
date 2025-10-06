-- Module: CollaboraNexio Missing Tables Fix
-- Version: 2025-10-02
-- Author: Database Architect
-- Description: Creazione immediata delle 3 tabelle mancanti critiche

USE collaboranexio;

-- ============================================
-- CREAZIONE TABELLE MANCANTI
-- ============================================

-- 1. Tabella password_resets - Gestione reset password
CREATE TABLE IF NOT EXISTS password_resets (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT UNSIGNED NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    used_at TIMESTAMP NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_reset_token (token),
    INDEX idx_reset_email (email),
    INDEX idx_reset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabella user_sessions - Gestione sessioni utente
CREATE TABLE IF NOT EXISTS user_sessions (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT UNSIGNED NOT NULL,

    -- Primary key
    id VARCHAR(128) NOT NULL,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (user_id),
    INDEX idx_session_last_activity (last_activity),
    INDEX idx_session_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabella notifications - Sistema notifiche
CREATE TABLE IF NOT EXISTS notifications (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT UNSIGNED NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_user_unread (user_id, is_read),
    INDEX idx_notification_type (type),
    INDEX idx_notification_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEMO DATA PER TEST
-- ============================================

-- Inserisci una notifica di test per admin (se esiste)
INSERT INTO notifications (tenant_id, user_id, type, title, message, data)
SELECT
    1 as tenant_id,
    id as user_id,
    'system' as type,
    'Database Aggiornato' as title,
    'Le tabelle mancanti sono state create con successo.' as message,
    JSON_OBJECT('created_by', 'fix_missing_tables.sql') as data
FROM users
WHERE email = 'admin@demo.local'
LIMIT 1;

-- ============================================
-- VERIFICA
-- ============================================

SELECT
    'Tabelle create con successo' as Status,
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio'
     AND TABLE_NAME IN ('password_resets', 'user_sessions', 'notifications')) as TabelleCreate,
    NOW() as CompletedAt;

-- Mostra le nuove tabelle create
SELECT
    TABLE_NAME as Tabella,
    TABLE_ROWS as Righe,
    CREATE_TIME as DataCreazione
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME IN ('password_resets', 'user_sessions', 'notifications')
ORDER BY TABLE_NAME;