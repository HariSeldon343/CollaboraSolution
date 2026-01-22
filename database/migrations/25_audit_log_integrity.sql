-- ============================================
-- Module: Audit Logs Integrity (Tamper-Evident)
-- Version: 2025-12-28
-- Description:
--   Adds integrity columns to audit_logs to enable per-tenant hash-chain
--   using HMAC-SHA256. This makes audit data tamper-evident for forensics.
--
-- Notes:
--   - Idempotent migration (safe to run multiple times)
--   - Does NOT modify existing audit data besides adding columns/indexes
--   - Signing/backfill is done by application tools (tools/audit_integrity_*.php)
-- ============================================

USE collaboranexio;

SELECT 'Adding tamper-evident integrity columns to audit_logs...' AS status;

-- Helper: add column if missing (MariaDB/MySQL compatible)
SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE audit_logs ADD COLUMN integrity_algo VARCHAR(16) NULL COMMENT ''Integrity algorithm (e.g. hmac-sha256)'' AFTER created_at'
        ELSE
            'SELECT ''Column integrity_algo already exists'' AS info'
    END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'integrity_algo'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE audit_logs ADD COLUMN integrity_key_id VARCHAR(32) NULL COMMENT ''HMAC key identifier (supports rotation)'' AFTER integrity_algo'
        ELSE
            'SELECT ''Column integrity_key_id already exists'' AS info'
    END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'integrity_key_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE audit_logs ADD COLUMN integrity_prev_hash CHAR(64) NULL COMMENT ''Previous hash in tenant chain (hex sha256)'' AFTER integrity_key_id'
        ELSE
            'SELECT ''Column integrity_prev_hash already exists'' AS info'
    END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'integrity_prev_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE audit_logs ADD COLUMN integrity_hash CHAR(64) NULL COMMENT ''Row integrity hash (hex hmac-sha256)'' AFTER integrity_prev_hash'
        ELSE
            'SELECT ''Column integrity_hash already exists'' AS info'
    END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'integrity_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE audit_logs ADD COLUMN integrity_signed_at TIMESTAMP NULL DEFAULT NULL COMMENT ''When integrity hash was computed'' AFTER integrity_hash'
        ELSE
            'SELECT ''Column integrity_signed_at already exists'' AS info'
    END
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND column_name = 'integrity_signed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes
SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'CREATE INDEX idx_audit_integrity_hash ON audit_logs(tenant_id, integrity_hash)'
        ELSE
            'SELECT ''Index idx_audit_integrity_hash already exists'' AS info'
    END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_integrity_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'CREATE INDEX idx_audit_integrity_chain ON audit_logs(tenant_id, created_at, id)'
        ELSE
            'SELECT ''Index idx_audit_integrity_chain already exists'' AS info'
    END
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_logs'
      AND index_name = 'idx_audit_integrity_chain'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Audit integrity migration complete.' AS status;

