-- GDPR / Authority (Garante) - DPO/Privacy contact recordkeeping
-- Stores the current protocol reference and a history of changes.

CREATE TABLE IF NOT EXISTS dpo_protocol_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_number VARCHAR(50) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    public_contact_url VARCHAR(255) NOT NULL,
    event_type ENUM('create','update','revoke') NOT NULL DEFAULT 'update',
    old_values_json MEDIUMTEXT NULL,
    new_values_json MEDIUMTEXT NULL,
    changed_by INT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note TEXT NULL,
    INDEX idx_changed_at (changed_at),
    INDEX idx_protocol (protocol_number)
);

-- Seed defaults in system_settings (best-effort; requires system_settings table)
SET @cnx_ss_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings');
SET @sql := IF(@cnx_ss_exists > 0,
  'INSERT INTO system_settings (setting_key, setting_value, value_type, updated_at)
   VALUES
     (\"dpo_protocol_number\", \"20250009908\", \"string\", NOW()),
     (\"privacy_contact_recipient_email\", \"asamodeo@fortibyte.it\", \"string\", NOW()),
     (\"privacy_contact_public_url\", \"https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php\", \"string\", NOW())
   ON DUPLICATE KEY UPDATE
     setting_value = setting_value,
     updated_at = updated_at',
  'SET @cnx_ss_exists = @cnx_ss_exists'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


