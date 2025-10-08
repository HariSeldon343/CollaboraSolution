-- Module: Sistema Gestione Aziende e Ruoli Multi-Tenant
-- Version: 2025-10-07
-- Author: Database Architect
-- Description: Migrazione completa per nuovo sistema di gestione aziende con ruoli gerarchici
--              Supporta: Super Admin (tenant_id NULL), Admin (multi-tenant), Manager/User (single-tenant)

USE collaboranexio;

-- ============================================
-- SECTION 1: BACKUP PREPARATION
-- ============================================
-- IMPORTANT: Always backup before running migrations!
-- mysqldump -u root -p collaboranexio > backup_before_aziende_migration_$(date +%Y%m%d_%H%M%S).sql

-- ============================================
-- SECTION 2: PRE-MIGRATION VALIDATION
-- ============================================

-- Check current state of critical tables
SELECT
    'PRE-MIGRATION STATUS' as Phase,
    (SELECT COUNT(*) FROM tenants) as CurrentTenants,
    (SELECT COUNT(*) FROM users) as CurrentUsers,
    (SELECT COUNT(*) FROM users WHERE role = 'admin') as AdminUsers,
    (SELECT COUNT(*) FROM user_tenant_access) as MultiTenantAccess;

-- Verify no orphaned records
SELECT 'Checking orphaned users...' as Check;
SELECT u.id, u.email, u.tenant_id, t.name
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE t.id IS NULL;

-- ============================================
-- SECTION 3: TRANSACTION START
-- ============================================
START TRANSACTION;

-- ============================================
-- SECTION 4: MODIFY USERS TABLE
-- Add super_admin role and allow NULL tenant_id
-- ============================================

-- Step 4.1: Add 'super_admin' to role ENUM
ALTER TABLE users
MODIFY COLUMN role ENUM('super_admin', 'admin', 'manager', 'user', 'guest') DEFAULT 'user';

-- Step 4.2: Modify tenant_id to allow NULL for super_admin
ALTER TABLE users
MODIFY COLUMN tenant_id INT UNSIGNED NULL;

-- Step 4.3: Update foreign key constraint to allow NULL
ALTER TABLE users
DROP FOREIGN KEY users_ibfk_1;

ALTER TABLE users
ADD CONSTRAINT fk_users_tenant_id
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- Step 4.4: Add index for improved query performance
CREATE INDEX idx_users_role_tenant ON users(role, tenant_id);

-- ============================================
-- SECTION 5: MODIFY TENANTS TABLE
-- Add complete company information
-- ============================================

-- Step 5.1: Add new identification fields
ALTER TABLE tenants
ADD COLUMN denominazione VARCHAR(255) NULL AFTER name,
ADD COLUMN codice_fiscale VARCHAR(16) NULL AFTER denominazione,
ADD COLUMN partita_iva VARCHAR(11) NULL AFTER codice_fiscale;

-- Step 5.2: Add sede legale fields (detailed address)
ALTER TABLE tenants
ADD COLUMN sede_legale_indirizzo VARCHAR(255) NULL AFTER partita_iva,
ADD COLUMN sede_legale_civico VARCHAR(10) NULL AFTER sede_legale_indirizzo,
ADD COLUMN sede_legale_comune VARCHAR(100) NULL AFTER sede_legale_civico,
ADD COLUMN sede_legale_provincia VARCHAR(2) NULL AFTER sede_legale_comune,
ADD COLUMN sede_legale_cap VARCHAR(5) NULL AFTER sede_legale_provincia;

-- Step 5.3: Add sedi operative field (JSON array for multiple locations)
ALTER TABLE tenants
ADD COLUMN sedi_operative JSON NULL COMMENT 'Array di oggetti: [{indirizzo, civico, comune, provincia, cap}, ...]' AFTER sede_legale_cap;

-- Step 5.4: Add business information fields
ALTER TABLE tenants
ADD COLUMN settore_merceologico VARCHAR(100) NULL AFTER sedi_operative,
ADD COLUMN numero_dipendenti INT NULL AFTER settore_merceologico,
ADD COLUMN capitale_sociale DECIMAL(15,2) NULL AFTER numero_dipendenti;

-- Step 5.5: Add contact fields
ALTER TABLE tenants
ADD COLUMN telefono VARCHAR(20) NULL AFTER capitale_sociale,
ADD COLUMN email VARCHAR(255) NULL AFTER telefono,
ADD COLUMN pec VARCHAR(255) NULL AFTER email;

-- Step 5.6: Add manager and legal representative
ALTER TABLE tenants
ADD COLUMN manager_id INT UNSIGNED NULL COMMENT 'Responsabile aziendale - FK to users.id' AFTER pec,
ADD COLUMN rappresentante_legale VARCHAR(255) NULL AFTER manager_id;

-- Step 5.7: Drop deprecated 'piano' column if it exists
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'piano'
);

SET @sql_drop_piano = IF(@col_exists > 0,
    'ALTER TABLE tenants DROP COLUMN piano;',
    'SELECT "Column piano does not exist, skipping..." as Info;'
);
PREPARE stmt FROM @sql_drop_piano;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 5.8: Add CHECK constraint (CF OR P.IVA required)
-- Note: MySQL 8.0.16+ required for CHECK constraints
ALTER TABLE tenants
ADD CONSTRAINT chk_tenant_fiscal_code
CHECK (
    codice_fiscale IS NOT NULL
    OR partita_iva IS NOT NULL
);

-- Step 5.9: Add foreign key for manager_id
ALTER TABLE tenants
ADD CONSTRAINT fk_tenants_manager_id
FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE RESTRICT;

-- Step 5.10: Add indexes for performance
CREATE INDEX idx_tenants_manager ON tenants(manager_id);
CREATE INDEX idx_tenants_denominazione ON tenants(denominazione);
CREATE INDEX idx_tenants_partita_iva ON tenants(partita_iva);
CREATE INDEX idx_tenants_codice_fiscale ON tenants(codice_fiscale);

-- ============================================
-- SECTION 6: MODIFY USER_TENANT_ACCESS TABLE
-- Add role_in_tenant field
-- ============================================

-- Step 6.1: Add role_in_tenant to track specific role per tenant
ALTER TABLE user_tenant_access
ADD COLUMN role_in_tenant ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'admin'
AFTER tenant_id;

-- Step 6.2: Add index for role-based queries
CREATE INDEX idx_user_tenant_access_role ON user_tenant_access(role_in_tenant, tenant_id);

-- ============================================
-- SECTION 7: MIGRATE EXISTING DATA
-- Preserve all existing information
-- ============================================

-- Step 7.1: Copy name to denominazione for existing tenants
UPDATE tenants
SET denominazione = name
WHERE denominazione IS NULL;

-- Step 7.2: Make denominazione NOT NULL after migration
ALTER TABLE tenants
MODIFY COLUMN denominazione VARCHAR(255) NOT NULL;

-- Step 7.3: Set default role_in_tenant for existing user_tenant_access records
UPDATE user_tenant_access uta
INNER JOIN users u ON uta.user_id = u.id
SET uta.role_in_tenant = u.role
WHERE uta.role_in_tenant IS NULL AND u.role IN ('admin', 'manager', 'user', 'guest');

-- ============================================
-- SECTION 8: DATA VALIDATION AFTER MIGRATION
-- ============================================

-- Verify all users have proper configuration
SELECT
    'POST-MIGRATION VALIDATION' as Phase,
    role,
    COUNT(*) as UserCount,
    SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) as UsersWithoutTenant,
    SUM(CASE WHEN tenant_id IS NOT NULL THEN 1 ELSE 0 END) as UsersWithTenant
FROM users
GROUP BY role;

-- Verify tenants data integrity
SELECT
    'TENANTS VALIDATION' as Phase,
    COUNT(*) as TotalTenants,
    SUM(CASE WHEN denominazione IS NOT NULL THEN 1 ELSE 0 END) as WithDenominazione,
    SUM(CASE WHEN codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL THEN 1 ELSE 0 END) as WithFiscalCode,
    SUM(CASE WHEN manager_id IS NOT NULL THEN 1 ELSE 0 END) as WithManager
FROM tenants;

-- Verify multi-tenant access
SELECT
    'MULTI-TENANT ACCESS VALIDATION' as Phase,
    COUNT(*) as TotalRecords,
    COUNT(DISTINCT user_id) as UniqueUsers,
    COUNT(DISTINCT tenant_id) as UniqueTenants
FROM user_tenant_access;

-- ============================================
-- SECTION 9: COMMIT TRANSACTION
-- ============================================
COMMIT;

-- ============================================
-- SECTION 10: POST-MIGRATION SUMMARY
-- ============================================
SELECT
    'MIGRATION COMPLETED SUCCESSFULLY' as Status,
    NOW() as CompletedAt,
    @@version as MySQLVersion;

SELECT '========================================' as Separator;
SELECT 'TABLES MODIFIED:' as Summary;
SELECT '1. users - Added super_admin role, tenant_id now nullable' as Change
UNION ALL
SELECT '2. tenants - Added complete company information (CF, P.IVA, sede legale, manager)' as Change
UNION ALL
SELECT '3. user_tenant_access - Added role_in_tenant field' as Change;

-- ============================================
-- SECTION 11: USAGE EXAMPLES
-- ============================================
/*
-- Example 1: Create Super Admin (no tenant association)
INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, role, status)
VALUES (NULL, 'superadmin@collaboranexio.com', '$2y$10$...', 'Super', 'Admin', 'super_admin', 'active');

-- Example 2: Create Admin with multi-tenant access
INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, role, status)
VALUES (1, 'admin@company1.com', '$2y$10$...', 'Mario', 'Rossi', 'admin', 'active');

-- Grant access to additional tenants
INSERT INTO user_tenant_access (user_id, tenant_id, role_in_tenant, granted_by)
VALUES (LAST_INSERT_ID(), 2, 'admin', 1);

-- Example 3: Create Manager (single tenant)
INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, role, status)
VALUES (1, 'manager@company1.com', '$2y$10$...', 'Luigi', 'Bianchi', 'manager', 'active');

-- Example 4: Create complete tenant with manager
INSERT INTO tenants (
    name, denominazione, codice_fiscale, partita_iva,
    sede_legale_indirizzo, sede_legale_civico, sede_legale_comune, sede_legale_provincia, sede_legale_cap,
    sedi_operative, settore_merceologico, numero_dipendenti, capitale_sociale,
    telefono, email, pec, manager_id, rappresentante_legale, status
) VALUES (
    'Tech Solutions SRL',
    'Tech Solutions SRL',
    'TCHS01234567890',
    '01234567890',
    'Via Roma',
    '123',
    'Milano',
    'MI',
    '20100',
    JSON_ARRAY(
        JSON_OBJECT('indirizzo', 'Via Torino', 'civico', '45', 'comune', 'Roma', 'provincia', 'RM', 'cap', '00100'),
        JSON_OBJECT('indirizzo', 'Corso Vittorio', 'civico', '78', 'comune', 'Napoli', 'provincia', 'NA', 'cap', '80100')
    ),
    'Tecnologia e Software',
    50,
    100000.00,
    '+39 02 1234567',
    'info@techsolutions.it',
    'pec@techsolutions.pec.it',
    5, -- manager_id (must exist in users table)
    'Mario Rossi',
    'active'
);

-- Example 5: Query users by role hierarchy
-- Super Admins (all tenants)
SELECT * FROM users WHERE role = 'super_admin' AND tenant_id IS NULL;

-- Admins with multi-tenant access
SELECT u.*, GROUP_CONCAT(t.denominazione) as accessible_companies
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE u.role = 'admin'
GROUP BY u.id;

-- Managers (single tenant)
SELECT u.*, t.denominazione as company
FROM users u
INNER JOIN tenants t ON u.tenant_id = t.id
WHERE u.role = 'manager';

-- Example 6: Validate user can access tenant
DELIMITER //
CREATE FUNCTION can_user_access_tenant(p_user_id INT, p_tenant_id INT)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_role VARCHAR(20);
    DECLARE v_user_tenant INT;
    DECLARE v_has_access BOOLEAN DEFAULT FALSE;

    -- Get user role and primary tenant
    SELECT role, tenant_id INTO v_role, v_user_tenant
    FROM users WHERE id = p_user_id;

    -- Super admin has access to all tenants
    IF v_role = 'super_admin' THEN
        SET v_has_access = TRUE;
    -- Admin/Manager can access via user_tenant_access or primary tenant
    ELSEIF v_role IN ('admin', 'manager') THEN
        IF v_user_tenant = p_tenant_id THEN
            SET v_has_access = TRUE;
        ELSEIF EXISTS (SELECT 1 FROM user_tenant_access WHERE user_id = p_user_id AND tenant_id = p_tenant_id) THEN
            SET v_has_access = TRUE;
        END IF;
    -- Regular users only access their primary tenant
    ELSEIF v_role = 'user' AND v_user_tenant = p_tenant_id THEN
        SET v_has_access = TRUE;
    END IF;

    RETURN v_has_access;
END//
DELIMITER ;

-- Usage:
-- SELECT can_user_access_tenant(1, 2) as has_access;
*/

-- ============================================
-- SECTION 12: ROLLBACK SCRIPT (Use only if needed)
-- ============================================
/*
-- WARNING: This will revert all changes. Use with caution!

START TRANSACTION;

-- Revert users table
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'user';
ALTER TABLE users MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;
DROP INDEX IF EXISTS idx_users_role_tenant ON users;

-- Revert tenants table
DROP INDEX IF EXISTS idx_tenants_manager ON tenants;
DROP INDEX IF EXISTS idx_tenants_denominazione ON tenants;
DROP INDEX IF EXISTS idx_tenants_partita_iva ON tenants;
DROP INDEX IF EXISTS idx_tenants_codice_fiscale ON tenants;

ALTER TABLE tenants DROP FOREIGN KEY IF EXISTS fk_tenants_manager_id;
ALTER TABLE tenants DROP CONSTRAINT IF EXISTS chk_tenant_fiscal_code;

ALTER TABLE tenants
DROP COLUMN IF EXISTS rappresentante_legale,
DROP COLUMN IF EXISTS manager_id,
DROP COLUMN IF EXISTS pec,
DROP COLUMN IF EXISTS email,
DROP COLUMN IF EXISTS telefono,
DROP COLUMN IF EXISTS capitale_sociale,
DROP COLUMN IF EXISTS numero_dipendenti,
DROP COLUMN IF EXISTS settore_merceologico,
DROP COLUMN IF EXISTS sedi_operative,
DROP COLUMN IF EXISTS sede_legale_cap,
DROP COLUMN IF EXISTS sede_legale_provincia,
DROP COLUMN IF EXISTS sede_legale_comune,
DROP COLUMN IF EXISTS sede_legale_civico,
DROP COLUMN IF EXISTS sede_legale_indirizzo,
DROP COLUMN IF EXISTS partita_iva,
DROP COLUMN IF EXISTS codice_fiscale,
DROP COLUMN IF EXISTS denominazione;

-- Revert user_tenant_access
DROP INDEX IF EXISTS idx_user_tenant_access_role ON user_tenant_access;
ALTER TABLE user_tenant_access DROP COLUMN IF EXISTS role_in_tenant;

COMMIT;

SELECT 'ROLLBACK COMPLETED' as Status, NOW() as RollbackAt;
*/

-- ============================================
-- END OF MIGRATION SCRIPT
-- ============================================
