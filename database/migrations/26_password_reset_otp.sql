-- ============================================
-- Migration 26: One-Time Password (OTP) for password reset via set_password.php
-- Version: 2025-12-28
-- Author: CollaboraNexio Team
-- Description:
--  - Add users.password_reset_otp_hash (hashed OTP)
--  - Add users.password_reset_otp_expires (expiry datetime)
-- Notes:
--  - Idempotent: safe to run multiple times
-- ============================================

USE collaboranexio;

SELECT 'Starting Migration 26: password reset OTP columns' as status;

-- ============================================
-- STEP 1: Add users.password_reset_otp_hash
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_otp_hash'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
     ADD COLUMN password_reset_otp_hash VARCHAR(255) NULL
     COMMENT ''Hashed one-time password used to authorize password reset via set_password.php''',
    'SELECT ''Column users.password_reset_otp_hash already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 2: Add users.password_reset_otp_expires
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_otp_expires'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
     ADD COLUMN password_reset_otp_expires DATETIME NULL
     COMMENT ''Expiration datetime for password reset OTP''',
    'SELECT ''Column users.password_reset_otp_expires already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration 26 completed' as final_status;


