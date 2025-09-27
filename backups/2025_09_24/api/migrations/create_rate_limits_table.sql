-- Migration: Create API Rate Limits Table
-- Description: Creates table for tracking API rate limits
-- Date: 2025-01-21
-- Version: 1.0.0

-- Create rate limits table if it doesn't exist
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(255) NOT NULL COMMENT 'IP address or user identifier',
    `action` VARCHAR(100) NOT NULL COMMENT 'API action being rate limited',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_identifier_action` (`identifier`, `action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks API rate limit attempts for preventing abuse';

-- Create cleanup event to remove old rate limit records
DELIMITER $$

DROP EVENT IF EXISTS `cleanup_rate_limits`$$

CREATE EVENT IF NOT EXISTS `cleanup_rate_limits`
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Cleanup old rate limit records hourly'
DO
BEGIN
    -- Delete records older than 1 hour
    DELETE FROM `api_rate_limits`
    WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR);
END$$

DELIMITER ;

-- Grant necessary permissions (adjust user as needed)
-- GRANT SELECT, INSERT, DELETE ON `collaboranexio`.`api_rate_limits` TO 'api_user'@'localhost';