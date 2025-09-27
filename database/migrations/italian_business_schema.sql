-- Module: Italian Business Schema Update
-- Version: 2025-09-26
-- Author: Database Architect
-- Description: Updates tenants table for Italian business requirements and adds multi-company access support

USE collaboranexio;

-- ============================================
-- BACKUP EXISTING DATA (Safety measure)
-- ============================================
CREATE TABLE IF NOT EXISTS tenants_backup_20250926 AS
SELECT * FROM tenants;

-- ============================================
-- STEP 1: ALTER TENANTS TABLE
-- ============================================

-- First, add new columns without dropping existing ones
ALTER TABLE tenants
    -- Core Italian business fields
    ADD COLUMN denominazione VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Official company name' AFTER id,
    ADD COLUMN sede_legale TEXT NOT NULL COMMENT 'Registered office address' AFTER denominazione,
    ADD COLUMN sede_operativa TEXT NULL COMMENT 'Operational office address (if different)' AFTER sede_legale,
    ADD COLUMN codice_fiscale VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'Italian tax code' AFTER sede_operativa,
    ADD COLUMN partita_iva VARCHAR(13) NULL COMMENT 'VAT number (P.IVA)' AFTER codice_fiscale,

    -- Business information
    ADD COLUMN settore_merceologico VARCHAR(255) NULL COMMENT 'Business sector/industry' AFTER partita_iva,
    ADD COLUMN numero_dipendenti INT UNSIGNED NULL COMMENT 'Number of employees' AFTER settore_merceologico,
    ADD COLUMN manager_user_id INT UNSIGNED NULL COMMENT 'Assigned company manager' AFTER numero_dipendenti,
    ADD COLUMN rappresentante_legale VARCHAR(255) NULL COMMENT 'Legal representative name' AFTER manager_user_id,

    -- Contact information
    ADD COLUMN telefono VARCHAR(50) NULL COMMENT 'Company phone number' AFTER rappresentante_legale,
    ADD COLUMN email_aziendale VARCHAR(255) NULL COMMENT 'Company email' AFTER telefono,
    ADD COLUMN pec VARCHAR(255) NULL COMMENT 'Certified email (PEC) for Italy' AFTER email_aziendale,

    -- Legal/financial information
    ADD COLUMN data_costituzione DATE NULL COMMENT 'Company foundation date' AFTER pec,
    ADD COLUMN capitale_sociale DECIMAL(15,2) NULL COMMENT 'Share capital in EUR' AFTER data_costituzione,

    -- Add indexes for new fields
    ADD INDEX idx_tenant_codice_fiscale (codice_fiscale),
    ADD INDEX idx_tenant_partita_iva (partita_iva),
    ADD INDEX idx_tenant_manager (manager_user_id),
    ADD INDEX idx_tenant_pec (pec);

-- Add foreign key constraint for manager (after ensuring users table exists)
ALTER TABLE tenants
    ADD CONSTRAINT fk_tenant_manager
    FOREIGN KEY (manager_user_id)
    REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- ============================================
-- STEP 2: MIGRATE DATA FROM OLD COLUMNS
-- ============================================

-- Copy existing 'name' field to 'denominazione'
UPDATE tenants
SET denominazione = name
WHERE denominazione = '';

-- Set default values for required fields where missing
UPDATE tenants
SET sede_legale = CONCAT('Via da definire, ', 'Italia')
WHERE sede_legale = '' OR sede_legale IS NULL;

UPDATE tenants
SET codice_fiscale = CONCAT('CF_TEMP_', id)
WHERE codice_fiscale = '';

-- ============================================
-- STEP 3: DROP/DEPRECATE OLD COLUMNS
-- ============================================

-- Mark columns as deprecated (instead of dropping immediately for safety)
ALTER TABLE tenants
    MODIFY COLUMN name VARCHAR(255) NULL COMMENT 'DEPRECATED - Use denominazione instead',
    DROP COLUMN IF EXISTS plan_type,
    DROP COLUMN IF EXISTS code;

-- ============================================
-- STEP 4: CREATE USER_COMPANIES JUNCTION TABLE
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
-- STEP 5: MIGRATE EXISTING USER-TENANT RELATIONSHIPS
-- ============================================

-- Populate user_companies table with existing user-tenant relationships
INSERT INTO user_companies (user_id, company_id, access_level)
SELECT DISTINCT
    u.id as user_id,
    u.tenant_id as company_id,
    'full' as access_level
FROM users u
WHERE u.tenant_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    access_level = VALUES(access_level);

-- ============================================
-- DEMO DATA FOR TESTING
-- ============================================

-- Sample Italian companies
INSERT INTO tenants (
    denominazione,
    sede_legale,
    sede_operativa,
    codice_fiscale,
    partita_iva,
    settore_merceologico,
    numero_dipendenti,
    rappresentante_legale,
    telefono,
    email_aziendale,
    pec,
    data_costituzione,
    capitale_sociale,
    status,
    max_users,
    max_storage_gb
) VALUES
(
    'Tecnologie Innovative S.r.l.',
    'Via Roma 123, 20121 Milano (MI), Italia',
    'Via Torino 45, 20123 Milano (MI), Italia',
    'TEC1234567890123',
    'IT12345678901',
    'Sviluppo Software e Consulenza IT',
    25,
    'Mario Rossi',
    '+39 02 12345678',
    'info@tecnologie-innovative.it',
    'tecnologie.innovative@pec.it',
    '2020-03-15',
    50000.00,
    'active',
    50,
    500
),
(
    'Manifattura Italiana S.p.A.',
    'Corso Italia 456, 10121 Torino (TO), Italia',
    NULL, -- Same as registered office
    'MAN9876543210987',
    'IT98765432109',
    'Produzione e Manifattura',
    150,
    'Giuseppe Verdi',
    '+39 011 87654321',
    'contatti@manifattura-italiana.it',
    'manifattura.italiana@legalmail.it',
    '1985-07-22',
    500000.00,
    'active',
    200,
    2000
),
(
    'Servizi Digitali Innovativi S.r.l.s.',
    'Piazza Garibaldi 78, 80142 Napoli (NA), Italia',
    'Via dei Mille 90, 80139 Napoli (NA), Italia',
    'SDI5555666677778',
    'IT55556666777',
    'Servizi Digitali e Marketing',
    8,
    'Anna Bianchi',
    '+39 081 5551234',
    'info@servizi-digitali.it',
    'servizi.digitali@pec.it',
    '2022-11-03',
    10000.00,
    'active',
    20,
    100
);

-- Create sample users if they don't exist
INSERT IGNORE INTO users (
    tenant_id,
    email,
    password_hash,
    first_name,
    last_name,
    role,
    status
) VALUES
(1, 'mario.rossi@tecnologie.it', '$2y$10$YourHashHere', 'Mario', 'Rossi', 'admin', 'active'),
(2, 'giuseppe.verdi@manifattura.it', '$2y$10$YourHashHere', 'Giuseppe', 'Verdi', 'admin', 'active'),
(3, 'anna.bianchi@servizi.it', '$2y$10$YourHashHere', 'Anna', 'Bianchi', 'admin', 'active'),
(1, 'luca.neri@tecnologie.it', '$2y$10$YourHashHere', 'Luca', 'Neri', 'manager', 'active');

-- Update manager assignments for demo companies
UPDATE tenants t
JOIN users u ON u.email = 'mario.rossi@tecnologie.it' AND u.tenant_id = t.id
SET t.manager_user_id = u.id
WHERE t.codice_fiscale = 'TEC1234567890123';

UPDATE tenants t
JOIN users u ON u.email = 'giuseppe.verdi@manifattura.it' AND u.tenant_id = t.id
SET t.manager_user_id = u.id
WHERE t.codice_fiscale = 'MAN9876543210987';

UPDATE tenants t
JOIN users u ON u.email = 'anna.bianchi@servizi.it' AND u.tenant_id = t.id
SET t.manager_user_id = u.id
WHERE t.codice_fiscale = 'SDI5555666677778';

-- Grant multi-company access for a super admin (example)
-- This user can access multiple companies
INSERT INTO user_companies (user_id, company_id, access_level, assigned_by)
SELECT
    (SELECT id FROM users WHERE email = 'mario.rossi@tecnologie.it' LIMIT 1) as user_id,
    t.id as company_id,
    'read_only' as access_level,
    (SELECT id FROM users WHERE email = 'mario.rossi@tecnologie.it' LIMIT 1) as assigned_by
FROM tenants t
WHERE t.id IN (2, 3)
ON DUPLICATE KEY UPDATE
    access_level = VALUES(access_level);

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check updated tenants structure
SELECT
    'Tenants table updated' as status,
    COUNT(*) as total_companies,
    COUNT(DISTINCT codice_fiscale) as unique_tax_codes,
    COUNT(manager_user_id) as companies_with_managers
FROM tenants;

-- Check user-company relationships
SELECT
    'User-Company relationships' as status,
    COUNT(DISTINCT user_id) as users_with_multi_access,
    COUNT(DISTINCT company_id) as companies_with_shared_access,
    COUNT(*) as total_relationships
FROM user_companies;

-- Display sample company data
SELECT
    denominazione,
    codice_fiscale,
    partita_iva,
    settore_merceologico,
    numero_dipendenti,
    pec,
    capitale_sociale
FROM tenants
LIMIT 5;

-- ============================================
-- ROLLBACK SCRIPT (if needed)
-- ============================================
/*
-- To rollback these changes, execute:

-- Restore original tenants structure
DROP TABLE IF EXISTS tenants;
RENAME TABLE tenants_backup_20250926 TO tenants;

-- Remove junction table
DROP TABLE IF EXISTS user_companies;

*/