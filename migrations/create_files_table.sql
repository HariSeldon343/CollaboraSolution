-- Tabella per la gestione dei file con multi-tenancy e soft delete
-- Data: 2025-09-27
-- Versione: 1.0.0

-- Elimina la tabella se esiste già (solo per sviluppo, rimuovere in produzione)
DROP TABLE IF EXISTS `files`;

-- Creazione tabella files
CREATE TABLE `files` (
  -- Multi-tenancy support
  `tenant_id` INT(11) NOT NULL,

  -- Primary key
  `id` INT(11) NOT NULL AUTO_INCREMENT,

  -- Core fields (aligned with requirements)
  `name` VARCHAR(255) NOT NULL COMMENT 'Nome del file o cartella',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Percorso completo del file',
  `file_type` VARCHAR(50) DEFAULT NULL COMMENT 'Tipo di file (pdf, doc, etc)',
  `file_size` BIGINT(20) NOT NULL DEFAULT 0 COMMENT 'Dimensione in bytes',
  `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME type del file',

  -- Folder structure
  `is_folder` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se è una cartella, 0 se è un file',
  `folder_id` INT(11) DEFAULT NULL COMMENT 'ID della cartella parent (per struttura gerarchica)',

  -- User tracking
  `uploaded_by` INT(11) NOT NULL COMMENT 'ID dell\'utente che ha caricato il file',

  -- Additional metadata
  `original_name` VARCHAR(255) DEFAULT NULL COMMENT 'Nome originale del file caricato',
  `shared_with` JSON DEFAULT NULL COMMENT 'JSON array con ID utenti con cui è condiviso',
  `is_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se il file è pubblicamente accessibile',
  `public_token` VARCHAR(64) DEFAULT NULL COMMENT 'Token per accesso pubblico',
  `download_count` INT(11) NOT NULL DEFAULT 0,
  `last_accessed_at` TIMESTAMP NULL DEFAULT NULL,

  -- Audit fields
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

  -- Keys and constraints
  PRIMARY KEY (`id`),
  INDEX `idx_tenant_id` (`tenant_id`),
  INDEX `idx_uploaded_by` (`uploaded_by`),
  INDEX `idx_folder_id` (`folder_id`),
  INDEX `idx_deleted_at` (`deleted_at`),
  INDEX `idx_file_type` (`file_type`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_tenant_deleted` (`tenant_id`, `deleted_at`),
  INDEX `idx_public_token` (`public_token`),
  INDEX `idx_folder_structure` (`tenant_id`, `folder_id`, `name`),
  CONSTRAINT `fk_files_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_files_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabella per la gestione dei file con multi-tenancy';

-- Creazione tabella per i permessi sui file
CREATE TABLE IF NOT EXISTS `file_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `file_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL COMMENT 'NULL significa tutti gli utenti del tenant',
  `permission` ENUM('view', 'download', 'edit', 'delete', 'share') NOT NULL DEFAULT 'view',
  `granted_by` INT(11) NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_file_user_permission` (`file_id`, `user_id`, `permission`),
  INDEX `idx_file_id` (`file_id`),
  INDEX `idx_user_id` (`user_id`),
  CONSTRAINT `fk_file_permissions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_file_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_file_permissions_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabella per i permessi sui file';

-- Creazione tabella per il log delle attività sui file
CREATE TABLE IF NOT EXISTS `file_activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `file_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `action` ENUM('upload', 'download', 'view', 'edit', 'delete', 'restore', 'share', 'move', 'rename') NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_file_id` (`file_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_tenant_id` (`tenant_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_file_logs_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_file_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_file_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log delle attività sui file';

-- Inserimento cartelle di base per ogni tenant esistente
INSERT INTO `files` (`tenant_id`, `name`, `file_path`, `is_folder`, `uploaded_by`, `created_at`)
SELECT
    t.id as tenant_id,
    'Documents' as name,
    CONCAT('/tenant_', t.id, '/documents') as file_path,
    1 as is_folder,
    (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Documents'
    AND f.is_folder = 1
);

INSERT INTO `files` (`tenant_id`, `name`, `file_path`, `is_folder`, `uploaded_by`, `created_at`)
SELECT
    t.id as tenant_id,
    'Projects' as name,
    CONCAT('/tenant_', t.id, '/projects') as file_path,
    1 as is_folder,
    (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Projects'
    AND f.is_folder = 1
);

INSERT INTO `files` (`tenant_id`, `name`, `file_path`, `is_folder`, `uploaded_by`, `created_at`)
SELECT
    t.id as tenant_id,
    'Shared' as name,
    CONCAT('/tenant_', t.id, '/shared') as file_path,
    1 as is_folder,
    (SELECT id FROM users WHERE tenant_id = t.id AND role = 'super_admin' LIMIT 1) as uploaded_by,
    NOW() as created_at
FROM tenants t
WHERE t.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM files f
    WHERE f.tenant_id = t.id
    AND f.name = 'Shared'
    AND f.is_folder = 1
);

-- View per i file non eliminati (per semplificare le query)
CREATE OR REPLACE VIEW `active_files` AS
SELECT * FROM `files`
WHERE `deleted_at` IS NULL;