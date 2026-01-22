-- ============================================
-- Migration 20: Password Policy Per-Utente + Codici Scadenza
-- Version: 2025-12-16
-- Author: CollaboraNexio Team
-- Description:
--  - Add users.password_max_age_days (default 90)
--  - Ensure users.password_set_at exists (if missing)
--  - Create password_expiry_codes table (6-digit codes stored hashed)
-- ============================================

USE collaboranexio;

SELECT 'Starting Migration 20: Password policy + expiry codes' as status;

-- ============================================
-- STEP 1: Ensure users.password_set_at exists
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_set_at'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
     ADD COLUMN password_set_at DATETIME NULL
     AFTER password_hash
     COMMENT ''When the current password was set''',
    'SELECT ''Column users.password_set_at already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 2: Add users.password_max_age_days
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_max_age_days'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
     ADD COLUMN password_max_age_days INT NOT NULL DEFAULT 90
     COMMENT ''Max password age in days (per-user). Default 90.''',
    'SELECT ''Column users.password_max_age_days already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 3: Create password_expiry_codes table
-- ============================================

CREATE TABLE IF NOT EXISTS password_expiry_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,

    -- Hash of the 6-digit numeric code (store hashed, never plaintext)
    code_hash VARCHAR(255) NOT NULL,

    -- Code validity
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    used_at DATETIME NULL,

    -- Who initiated sending (null for system cron)
    sent_by_user_id INT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT fk_password_expiry_codes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_password_expiry_codes_sent_by
        FOREIGN KEY (sent_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_password_expiry_codes_user_used (user_id, used_at),
    INDEX idx_password_expiry_codes_user_expires (user_id, expires_at),
    INDEX idx_password_expiry_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores password expiry verification codes (6-digit) hashed';

SELECT 'Migration 20 completed' as final_status;


