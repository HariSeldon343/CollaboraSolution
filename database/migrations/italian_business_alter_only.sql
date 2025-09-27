-- Module: Italian Business Schema - ALTER Statements Only
-- Version: 2025-09-26
-- Author: Database Architect
-- Description: Clean ALTER and CREATE statements for Italian business requirements

USE collaboranexio;

-- ============================================
-- ALTER TABLE STATEMENTS FOR TENANTS
-- ============================================

ALTER TABLE tenants
    -- Core Italian business fields
    ADD COLUMN IF NOT EXISTS denominazione VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Official company name' AFTER id,
    ADD COLUMN IF NOT EXISTS sede_legale TEXT NOT NULL COMMENT 'Registered office address' AFTER denominazione,
    ADD COLUMN IF NOT EXISTS sede_operativa TEXT NULL COMMENT 'Operational office address (if different)' AFTER sede_legale,
    ADD COLUMN IF NOT EXISTS codice_fiscale VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'Italian tax code' AFTER sede_operativa,
    ADD COLUMN IF NOT EXISTS partita_iva VARCHAR(13) NULL COMMENT 'VAT number (P.IVA)' AFTER codice_fiscale,

    -- Business information
    ADD COLUMN IF NOT EXISTS settore_merceologico VARCHAR(255) NULL COMMENT 'Business sector/industry' AFTER partita_iva,
    ADD COLUMN IF NOT EXISTS numero_dipendenti INT UNSIGNED NULL COMMENT 'Number of employees' AFTER settore_merceologico,
    ADD COLUMN IF NOT EXISTS manager_user_id INT UNSIGNED NULL COMMENT 'Assigned company manager' AFTER numero_dipendenti,
    ADD COLUMN IF NOT EXISTS rappresentante_legale VARCHAR(255) NULL COMMENT 'Legal representative name' AFTER manager_user_id,

    -- Contact information
    ADD COLUMN IF NOT EXISTS telefono VARCHAR(50) NULL COMMENT 'Company phone number' AFTER rappresentante_legale,
    ADD COLUMN IF NOT EXISTS email_aziendale VARCHAR(255) NULL COMMENT 'Company email' AFTER telefono,
    ADD COLUMN IF NOT EXISTS pec VARCHAR(255) NULL COMMENT 'Certified email (PEC) for Italy' AFTER email_aziendale,

    -- Legal/financial information
    ADD COLUMN IF NOT EXISTS data_costituzione DATE NULL COMMENT 'Company foundation date' AFTER pec,
    ADD COLUMN IF NOT EXISTS capitale_sociale DECIMAL(15,2) NULL COMMENT 'Share capital in EUR' AFTER data_costituzione;

-- Add indexes for better query performance
ALTER TABLE tenants
    ADD INDEX IF NOT EXISTS idx_tenant_codice_fiscale (codice_fiscale),
    ADD INDEX IF NOT EXISTS idx_tenant_partita_iva (partita_iva),
    ADD INDEX IF NOT EXISTS idx_tenant_manager (manager_user_id),
    ADD INDEX IF NOT EXISTS idx_tenant_pec (pec);

-- Add foreign key constraint for manager
ALTER TABLE tenants
    ADD CONSTRAINT fk_tenant_manager
    FOREIGN KEY (manager_user_id)
    REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- Remove deprecated columns
ALTER TABLE tenants
    DROP COLUMN IF EXISTS plan_type,
    DROP COLUMN IF EXISTS code;

-- ============================================
-- CREATE USER_COMPANIES JUNCTION TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS user_companies (
    -- Foreign keys (composite primary key)
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL COMMENT 'References tenant_id',

    -- Assignment tracking
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NULL COMMENT 'User who assigned this access',
    access_level ENUM('full', 'read_only', 'limited') DEFAULT 'full',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (company_id) REFERENCES tenants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    -- Indexes for performance
    INDEX idx_user_companies_user (user_id),
    INDEX idx_user_companies_company (company_id),
    INDEX idx_user_companies_assigned (assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DATA MIGRATION STATEMENTS
-- ============================================

-- Migrate existing name to denominazione
UPDATE tenants
SET denominazione = name
WHERE denominazione = '' OR denominazione IS NULL;

-- Set required fields with temporary values if empty
UPDATE tenants
SET sede_legale = 'Da completare'
WHERE sede_legale = '' OR sede_legale IS NULL;

UPDATE tenants
SET codice_fiscale = CONCAT('TEMP_', id)
WHERE codice_fiscale = '' OR codice_fiscale IS NULL;

-- Populate user_companies with existing relationships
INSERT IGNORE INTO user_companies (user_id, company_id, access_level)
SELECT DISTINCT
    u.id as user_id,
    u.tenant_id as company_id,
    'full' as access_level
FROM users u
WHERE u.tenant_id IS NOT NULL;