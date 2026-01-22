-- 32_privacy_cookie_ack.sql
-- GDPR: Track privacy/cookie policy acknowledgement (tenant-aware)
-- Model: Each tenant/company is Controller; Nexio is Processor.

CREATE TABLE IF NOT EXISTS `privacy_acknowledgements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `ack_type` ENUM('privacy','cookie') NOT NULL,
  `policy_version` VARCHAR(64) NOT NULL,
  `ack_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_privacy_ack` (`tenant_id`, `user_id`, `ack_type`, `policy_version`),
  KEY `idx_ack_tenant_user_type` (`tenant_id`, `user_id`, `ack_type`),
  KEY `idx_ack_at` (`ack_at`),
  CONSTRAINT `fk_privacy_ack_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_privacy_ack_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


