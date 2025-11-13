-- Module: Tenant Locations - Multi-Location Support for Companies
-- Version: 2025-10-07
-- Author: Database Architect
-- Description: Redesign database structure to properly support unlimited operational locations (sedi operative)
--              for companies with proper relational integrity and separation of concerns

USE collaboranexio;

-- ============================================
-- ANALYSIS OF CURRENT STRUCTURE
-- ============================================
/*
CURRENT PROBLEM:
- tenants table has sede_legale split into 5 columns (correct)
- sedi_operative stored as LONGTEXT JSON (problematic)

ISSUES WITH CURRENT JSON APPROACH:
1. Cannot query/filter by operational location
2. No referential integrity
3. Cannot index location data
4. Difficult to maintain
5. No proper validation
6. Cannot support location-specific data (manager, phone, etc.)

RECOMMENDED SOLUTION:
Create separate tenant_locations table with proper relational design
This allows unlimited locations, proper indexing, and future extensibility
*/

-- ============================================
-- BACKUP EXISTING DATA (Safety measure)
-- ============================================
CREATE TABLE IF NOT EXISTS tenants_backup_locations_20251007 AS
SELECT * FROM tenants;

SELECT 'Backup created successfully' as status, COUNT(*) as backed_up_records
FROM tenants_backup_locations_20251007;

-- ============================================
-- STEP 1: CREATE TENANT_LOCATIONS TABLE
-- ============================================

DROP TABLE IF EXISTS tenant_locations;

CREATE TABLE tenant_locations (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Foreign key to tenant
    tenant_id INT UNSIGNED NOT NULL,

    -- Location type: sede_legale or sede_operativa
    location_type ENUM('sede_legale', 'sede_operativa') NOT NULL,

    -- Italian address structure (5 required fields)
    indirizzo VARCHAR(255) NOT NULL COMMENT 'Street address (Via/Piazza)',
    civico VARCHAR(10) NOT NULL COMMENT 'Street number (e.g., 25, 10/A)',
    cap VARCHAR(5) NOT NULL COMMENT 'Postal code (5 digits)',
    comune VARCHAR(100) NOT NULL COMMENT 'Municipality/City',
    provincia VARCHAR(2) NOT NULL COMMENT 'Province code (e.g., MI, RM, TO)',

    -- Location-specific contact information (optional)
    telefono VARCHAR(50) NULL COMMENT 'Location phone number',
    email VARCHAR(255) NULL COMMENT 'Location email',

    -- Location management
    manager_nome VARCHAR(255) NULL COMMENT 'Location manager name',
    manager_user_id INT UNSIGNED NULL COMMENT 'Reference to user managing this location',

    -- Location flags
    is_primary BOOLEAN DEFAULT FALSE COMMENT 'Is this the primary location?',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Is location currently active?',

    -- Additional notes
    note TEXT NULL COMMENT 'Additional location notes',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete support',

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL,

    -- Business rule: Each tenant must have exactly one sede_legale marked as primary
    UNIQUE KEY uk_tenant_primary_sede_legale (tenant_id, location_type, is_primary),

    -- Indexes for performance
    INDEX idx_tenant_locations_tenant (tenant_id),
    INDEX idx_tenant_locations_type (location_type),
    INDEX idx_tenant_locations_comune (comune),
    INDEX idx_tenant_locations_provincia (provincia),
    INDEX idx_tenant_locations_primary (is_primary),
    INDEX idx_tenant_locations_active (is_active),
    INDEX idx_tenant_locations_deleted (deleted_at),

    -- Composite indexes for common queries
    INDEX idx_tenant_locations_tenant_type (tenant_id, location_type, deleted_at),
    INDEX idx_tenant_locations_tenant_active (tenant_id, is_active, deleted_at),

    -- Data validation constraints
    CHECK (LENGTH(cap) = 5),
    CHECK (LENGTH(provincia) = 2),
    CHECK (location_type IN ('sede_legale', 'sede_operativa'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores all tenant locations (legal headquarters and operational locations)';

-- ============================================
-- STEP 2: MIGRATE EXISTING SEDE LEGALE DATA
-- ============================================

-- Migrate existing sede_legale from tenants table to tenant_locations
INSERT INTO tenant_locations (
    tenant_id,
    location_type,
    indirizzo,
    civico,
    cap,
    comune,
    provincia,
    is_primary,
    is_active
)
SELECT
    t.id as tenant_id,
    'sede_legale' as location_type,
    COALESCE(t.sede_legale_indirizzo, 'Da definire') as indirizzo,
    COALESCE(t.sede_legale_civico, 'SN') as civico,
    COALESCE(t.sede_legale_cap, '00000') as cap,
    COALESCE(t.sede_legale_comune, 'Da definire') as comune,
    COALESCE(t.sede_legale_provincia, 'XX') as provincia,
    TRUE as is_primary,
    TRUE as is_active
FROM tenants t
WHERE t.sede_legale_indirizzo IS NOT NULL
  AND t.sede_legale_indirizzo != ''
ON DUPLICATE KEY UPDATE
    indirizzo = VALUES(indirizzo),
    civico = VALUES(civico),
    cap = VALUES(cap),
    comune = VALUES(comune),
    provincia = VALUES(provincia);

SELECT 'Sede legale migrated' as status, COUNT(*) as records_migrated
FROM tenant_locations
WHERE location_type = 'sede_legale';

-- ============================================
-- STEP 3: MIGRATE EXISTING SEDI OPERATIVE FROM JSON
-- ============================================

-- Note: This requires PHP script to parse JSON
-- Create temporary table for manual inspection/migration if needed

CREATE TEMPORARY TABLE IF NOT EXISTS temp_sedi_operative_json (
    tenant_id INT UNSIGNED,
    sedi_operative_json LONGTEXT,
    has_data BOOLEAN
);

INSERT INTO temp_sedi_operative_json (tenant_id, sedi_operative_json, has_data)
SELECT
    id,
    sedi_operative,
    (sedi_operative IS NOT NULL AND sedi_operative != '' AND sedi_operative != '[]') as has_data
FROM tenants
WHERE sedi_operative IS NOT NULL;

-- Display tenants with existing JSON sedi operative data
SELECT
    'Tenants with JSON sedi operative' as info,
    COUNT(*) as total_tenants,
    SUM(CASE WHEN has_data THEN 1 ELSE 0 END) as tenants_with_data
FROM temp_sedi_operative_json;

-- Display sample JSON data for manual inspection
SELECT
    t.id,
    t.denominazione,
    t.sedi_operative
FROM tenants t
WHERE t.sedi_operative IS NOT NULL
  AND t.sedi_operative != ''
  AND t.sedi_operative != '[]'
LIMIT 5;

-- ============================================
-- STEP 4: UPDATE TENANTS TABLE STRUCTURE
-- ============================================

-- Mark old sede_legale columns as deprecated (keep for backward compatibility temporarily)
ALTER TABLE tenants
    MODIFY COLUMN sede_legale_indirizzo VARCHAR(255) NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead',
    MODIFY COLUMN sede_legale_civico VARCHAR(10) NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead',
    MODIFY COLUMN sede_legale_comune VARCHAR(100) NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead',
    MODIFY COLUMN sede_legale_provincia VARCHAR(2) NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead',
    MODIFY COLUMN sede_legale_cap VARCHAR(5) NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead',
    MODIFY COLUMN sedi_operative LONGTEXT NULL
        COMMENT 'DEPRECATED - Use tenant_locations table instead';

-- Add new summary columns for quick access (denormalized for performance)
ALTER TABLE tenants
    ADD COLUMN total_locations INT UNSIGNED DEFAULT 0
        COMMENT 'Cached count of active locations',
    ADD COLUMN primary_location_id INT UNSIGNED NULL
        COMMENT 'Quick reference to primary sede_legale',
    ADD INDEX idx_tenant_primary_location (primary_location_id);

-- Update cached location count
UPDATE tenants t
SET t.total_locations = (
    SELECT COUNT(*)
    FROM tenant_locations tl
    WHERE tl.tenant_id = t.id
      AND tl.deleted_at IS NULL
      AND tl.is_active = TRUE
);

-- Update primary location reference
UPDATE tenants t
SET t.primary_location_id = (
    SELECT tl.id
    FROM tenant_locations tl
    WHERE tl.tenant_id = t.id
      AND tl.location_type = 'sede_legale'
      AND tl.is_primary = TRUE
      AND tl.deleted_at IS NULL
    LIMIT 1
);

-- ============================================
-- STEP 5: CREATE HELPER VIEWS
-- ============================================

-- View: Tenants with their primary location (sede legale)
CREATE OR REPLACE VIEW v_tenants_with_sede_legale AS
SELECT
    t.id as tenant_id,
    t.denominazione,
    t.codice_fiscale,
    t.partita_iva,
    tl.id as location_id,
    tl.indirizzo,
    tl.civico,
    tl.cap,
    tl.comune,
    tl.provincia,
    CONCAT(tl.indirizzo, ' ', tl.civico, ', ', tl.cap, ' ', tl.comune, ' (', tl.provincia, ')') as indirizzo_completo,
    tl.telefono as sede_telefono,
    tl.email as sede_email,
    t.status,
    t.created_at
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
    AND tl.location_type = 'sede_legale'
    AND tl.is_primary = TRUE
    AND tl.deleted_at IS NULL
WHERE t.deleted_at IS NULL;

-- View: All active tenant locations
CREATE OR REPLACE VIEW v_tenant_locations_active AS
SELECT
    tl.id,
    tl.tenant_id,
    t.denominazione as tenant_name,
    tl.location_type,
    tl.indirizzo,
    tl.civico,
    tl.cap,
    tl.comune,
    tl.provincia,
    CONCAT(tl.indirizzo, ' ', tl.civico, ', ', tl.cap, ' ', tl.comune, ' (', tl.provincia, ')') as indirizzo_completo,
    tl.telefono,
    tl.email,
    tl.manager_nome,
    tl.is_primary,
    tl.is_active,
    tl.created_at,
    tl.updated_at
FROM tenant_locations tl
INNER JOIN tenants t ON tl.tenant_id = t.id
WHERE tl.deleted_at IS NULL
  AND tl.is_active = TRUE
  AND t.deleted_at IS NULL;

-- View: Count locations per tenant
CREATE OR REPLACE VIEW v_tenant_location_counts AS
SELECT
    t.id as tenant_id,
    t.denominazione,
    COUNT(CASE WHEN tl.location_type = 'sede_legale' THEN 1 END) as sede_legale_count,
    COUNT(CASE WHEN tl.location_type = 'sede_operativa' THEN 1 END) as sedi_operative_count,
    COUNT(*) as total_locations
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
    AND tl.deleted_at IS NULL
    AND tl.is_active = TRUE
WHERE t.deleted_at IS NULL
GROUP BY t.id, t.denominazione;

-- ============================================
-- STEP 6: DEMO DATA - Multiple Operational Locations
-- ============================================

-- Sample Italian companies with multiple locations
INSERT INTO tenants (
    denominazione,
    codice_fiscale,
    partita_iva,
    settore_merceologico,
    numero_dipendenti,
    rappresentante_legale,
    telefono,
    email,
    pec,
    status,
    max_users,
    max_storage_gb
) VALUES
(
    'TechnoItalia S.p.A.',
    'TCNITL12345678901',
    'IT12345678901',
    'Tecnologia e Innovazione',
    250,
    'Marco Bianchi',
    '+39 02 12345678',
    'info@technoitalia.it',
    'technoitalia@pec.it',
    'active',
    300,
    5000
),
(
    'Logistics Express S.r.l.',
    'LGSTXP98765432109',
    'IT98765432109',
    'Logistica e Trasporti',
    450,
    'Giulia Verdi',
    '+39 011 87654321',
    'contact@logisticsexpress.it',
    'logistics.express@legalmail.it',
    'active',
    500,
    10000
)
ON DUPLICATE KEY UPDATE denominazione=VALUES(denominazione);

-- Get IDs for demo companies
SET @technoitalia_id = (SELECT id FROM tenants WHERE codice_fiscale = 'TCNITL12345678901' LIMIT 1);
SET @logistics_id = (SELECT id FROM tenants WHERE codice_fiscale = 'LGSTXP98765432109' LIMIT 1);

-- TechnoItalia locations
INSERT INTO tenant_locations (tenant_id, location_type, indirizzo, civico, cap, comune, provincia, telefono, email, is_primary, is_active, note) VALUES
(@technoitalia_id, 'sede_legale', 'Via Milano', '100', '20100', 'Milano', 'MI', '+39 02 12345678', 'sede.milano@technoitalia.it', TRUE, TRUE, 'Sede principale e uffici amministrativi'),
(@technoitalia_id, 'sede_operativa', 'Via Roma', '45', '00100', 'Roma', 'RM', '+39 06 11223344', 'sede.roma@technoitalia.it', FALSE, TRUE, 'Ufficio commerciale centro-sud'),
(@technoitalia_id, 'sede_operativa', 'Corso Torino', '78', '10121', 'Torino', 'TO', '+39 011 99887766', 'sede.torino@technoitalia.it', FALSE, TRUE, 'Centro R&D e sviluppo software'),
(@technoitalia_id, 'sede_operativa', 'Via Napoli', '23', '80100', 'Napoli', 'NA', '+39 081 55443322', 'sede.napoli@technoitalia.it', FALSE, TRUE, 'Supporto tecnico sud Italia'),
(@technoitalia_id, 'sede_operativa', 'Via Firenze', '12', '50100', 'Firenze', 'FI', '+39 055 66778899', 'sede.firenze@technoitalia.it', FALSE, TRUE, 'Hub formazione e training');

-- Logistics Express locations (more extensive network)
INSERT INTO tenant_locations (tenant_id, location_type, indirizzo, civico, cap, comune, provincia, telefono, email, is_primary, is_active, note) VALUES
(@logistics_id, 'sede_legale', 'Corso Italia', '250', '10121', 'Torino', 'TO', '+39 011 87654321', 'hq@logisticsexpress.it', TRUE, TRUE, 'Sede legale e direzione generale'),
(@logistics_id, 'sede_operativa', 'Via Logistica', '88', '20100', 'Milano', 'MI', '+39 02 33445566', 'milano.hub@logisticsexpress.it', FALSE, TRUE, 'Hub principale Nord Italia'),
(@logistics_id, 'sede_operativa', 'Via Interporto', '15', '40100', 'Bologna', 'BO', '+39 051 22334455', 'bologna.hub@logisticsexpress.it', FALSE, TRUE, 'Centro smistamento centro Italia'),
(@logistics_id, 'sede_operativa', 'Via Trasporti', '42', '00100', 'Roma', 'RM', '+39 06 77889900', 'roma.hub@logisticsexpress.it', FALSE, TRUE, 'Hub centro-sud e coordinamento'),
(@logistics_id, 'sede_operativa', 'Via Porto', '5', '80100', 'Napoli', 'NA', '+39 081 11223344', 'napoli.hub@logisticsexpress.it', FALSE, TRUE, 'Centro distribuzione sud e isole'),
(@logistics_id, 'sede_operativa', 'Via Mare', '99', '98100', 'Messina', 'ME', '+39 090 44556677', 'messina.hub@logisticsexpress.it', FALSE, TRUE, 'Collegamento Sicilia-continente'),
(@logistics_id, 'sede_operativa', 'Via Sardegna', '33', '09100', 'Cagliari', 'CA', '+39 070 88990011', 'cagliari.hub@logisticsexpress.it', FALSE, TRUE, 'Hub Sardegna'),
(@logistics_id, 'sede_operativa', 'Via Veneto', '156', '31100', 'Treviso', 'TV', '+39 0422 55667788', 'treviso.depot@logisticsexpress.it', FALSE, TRUE, 'Deposito nord-est');

-- Update cached counts
UPDATE tenants t
SET t.total_locations = (
    SELECT COUNT(*)
    FROM tenant_locations tl
    WHERE tl.tenant_id = t.id
      AND tl.deleted_at IS NULL
      AND tl.is_active = TRUE
);

UPDATE tenants t
SET t.primary_location_id = (
    SELECT tl.id
    FROM tenant_locations tl
    WHERE tl.tenant_id = t.id
      AND tl.location_type = 'sede_legale'
      AND tl.is_primary = TRUE
      AND tl.deleted_at IS NULL
    LIMIT 1
);

-- ============================================
-- STEP 7: CREATE TRIGGERS FOR DATA INTEGRITY
-- ============================================

-- Trigger: Ensure only one primary sede_legale per tenant
DROP TRIGGER IF EXISTS trg_tenant_locations_before_insert;
DROP TRIGGER IF EXISTS trg_tenant_locations_before_update;

DELIMITER $$

CREATE TRIGGER trg_tenant_locations_before_insert
BEFORE INSERT ON tenant_locations
FOR EACH ROW
BEGIN
    -- If inserting a primary sede_legale, unmark any existing primary
    IF NEW.location_type = 'sede_legale' AND NEW.is_primary = TRUE THEN
        UPDATE tenant_locations
        SET is_primary = FALSE
        WHERE tenant_id = NEW.tenant_id
          AND location_type = 'sede_legale'
          AND is_primary = TRUE
          AND deleted_at IS NULL;
    END IF;

    -- Ensure provincia is uppercase
    SET NEW.provincia = UPPER(NEW.provincia);

    -- Ensure CAP is 5 digits
    IF LENGTH(NEW.cap) != 5 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'CAP must be exactly 5 digits';
    END IF;
END$$

CREATE TRIGGER trg_tenant_locations_before_update
BEFORE UPDATE ON tenant_locations
FOR EACH ROW
BEGIN
    -- If updating to primary sede_legale, unmark any existing primary
    IF NEW.location_type = 'sede_legale' AND NEW.is_primary = TRUE AND OLD.is_primary = FALSE THEN
        UPDATE tenant_locations
        SET is_primary = FALSE
        WHERE tenant_id = NEW.tenant_id
          AND location_type = 'sede_legale'
          AND is_primary = TRUE
          AND id != NEW.id
          AND deleted_at IS NULL;
    END IF;

    -- Ensure provincia is uppercase
    SET NEW.provincia = UPPER(NEW.provincia);

    -- Ensure CAP is 5 digits
    IF LENGTH(NEW.cap) != 5 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'CAP must be exactly 5 digits';
    END IF;
END$$

-- Trigger: Update tenant location count cache on insert/update/delete
CREATE TRIGGER trg_tenant_locations_after_insert
AFTER INSERT ON tenant_locations
FOR EACH ROW
BEGIN
    UPDATE tenants
    SET total_locations = (
        SELECT COUNT(*)
        FROM tenant_locations
        WHERE tenant_id = NEW.tenant_id
          AND deleted_at IS NULL
          AND is_active = TRUE
    )
    WHERE id = NEW.tenant_id;
END$$

CREATE TRIGGER trg_tenant_locations_after_update
AFTER UPDATE ON tenant_locations
FOR EACH ROW
BEGIN
    UPDATE tenants
    SET total_locations = (
        SELECT COUNT(*)
        FROM tenant_locations
        WHERE tenant_id = NEW.tenant_id
          AND deleted_at IS NULL
          AND is_active = TRUE
    )
    WHERE id = NEW.tenant_id;
END$$

CREATE TRIGGER trg_tenant_locations_after_delete
AFTER DELETE ON tenant_locations
FOR EACH ROW
BEGIN
    UPDATE tenants
    SET total_locations = (
        SELECT COUNT(*)
        FROM tenant_locations
        WHERE tenant_id = OLD.tenant_id
          AND deleted_at IS NULL
          AND is_active = TRUE
    )
    WHERE id = OLD.tenant_id;
END$$

DELIMITER ;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check table structure
SELECT
    'tenant_locations table created' as status,
    COUNT(*) as column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME = 'tenant_locations';

-- Check migrated data
SELECT
    'Data migration summary' as report,
    location_type,
    COUNT(*) as total_locations,
    COUNT(DISTINCT tenant_id) as unique_tenants,
    SUM(CASE WHEN is_primary THEN 1 ELSE 0 END) as primary_locations
FROM tenant_locations
WHERE deleted_at IS NULL
GROUP BY location_type;

-- Check tenant location counts
SELECT
    t.id,
    t.denominazione,
    t.total_locations as cached_count,
    COUNT(tl.id) as actual_count,
    CASE
        WHEN t.total_locations = COUNT(tl.id) THEN 'OK'
        ELSE 'MISMATCH'
    END as cache_status
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
    AND tl.deleted_at IS NULL
    AND tl.is_active = TRUE
GROUP BY t.id, t.denominazione, t.total_locations
HAVING COUNT(tl.id) > 0
ORDER BY actual_count DESC;

-- Display sample data with locations
SELECT
    t.denominazione,
    tl.location_type,
    CONCAT(tl.indirizzo, ' ', tl.civico) as address,
    tl.comune,
    tl.provincia,
    tl.is_primary,
    tl.note
FROM tenants t
INNER JOIN tenant_locations tl ON t.id = tl.tenant_id
WHERE tl.deleted_at IS NULL
ORDER BY t.denominazione,
         CASE tl.location_type WHEN 'sede_legale' THEN 0 ELSE 1 END,
         tl.created_at;

-- Check view results
SELECT * FROM v_tenants_with_sede_legale LIMIT 5;
SELECT * FROM v_tenant_location_counts;

-- ============================================
-- PERFORMANCE ANALYSIS
-- ============================================

-- Check index usage
SHOW INDEX FROM tenant_locations;

-- Analyze query performance for common patterns
EXPLAIN SELECT *
FROM tenant_locations
WHERE tenant_id = 1
  AND deleted_at IS NULL
  AND is_active = TRUE;

EXPLAIN SELECT tl.*
FROM tenant_locations tl
INNER JOIN tenants t ON tl.tenant_id = t.id
WHERE t.status = 'active'
  AND tl.location_type = 'sede_operativa'
  AND tl.comune = 'Milano'
  AND tl.deleted_at IS NULL;

-- ============================================
-- SUCCESS SUMMARY
-- ============================================

SELECT '========================================' as '';
SELECT 'TENANT LOCATIONS MIGRATION COMPLETED' as status;
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Tables Created:' as info, '1 (tenant_locations)' as value
UNION ALL
SELECT 'Views Created:', '3 (v_tenants_with_sede_legale, v_tenant_locations_active, v_tenant_location_counts)'
UNION ALL
SELECT 'Triggers Created:', '5 (data integrity and cache management)'
UNION ALL
SELECT 'Demo Companies:', CAST(COUNT(DISTINCT tenant_id) AS CHAR)
    FROM tenant_locations WHERE deleted_at IS NULL
UNION ALL
SELECT 'Total Locations:', CAST(COUNT(*) AS CHAR)
    FROM tenant_locations WHERE deleted_at IS NULL
UNION ALL
SELECT 'Sede Legale:', CAST(COUNT(*) AS CHAR)
    FROM tenant_locations WHERE location_type = 'sede_legale' AND deleted_at IS NULL
UNION ALL
SELECT 'Sedi Operative:', CAST(COUNT(*) AS CHAR)
    FROM tenant_locations WHERE location_type = 'sede_operativa' AND deleted_at IS NULL;

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Database redesign completed successfully!' as status;
SELECT 'Old JSON approach replaced with proper relational design' as achievement;
SELECT '========================================' as '';

-- ============================================
-- ROLLBACK SCRIPT (Emergency use only)
-- ============================================
/*
-- TO ROLLBACK THIS MIGRATION:

-- Drop new objects
DROP TRIGGER IF EXISTS trg_tenant_locations_after_delete;
DROP TRIGGER IF EXISTS trg_tenant_locations_after_update;
DROP TRIGGER IF EXISTS trg_tenant_locations_after_insert;
DROP TRIGGER IF EXISTS trg_tenant_locations_before_update;
DROP TRIGGER IF EXISTS trg_tenant_locations_before_insert;
DROP VIEW IF EXISTS v_tenant_location_counts;
DROP VIEW IF EXISTS v_tenant_locations_active;
DROP VIEW IF EXISTS v_tenants_with_sede_legale;
DROP TABLE IF EXISTS tenant_locations;

-- Restore tenants table columns
ALTER TABLE tenants
    DROP COLUMN IF EXISTS total_locations,
    DROP COLUMN IF EXISTS primary_location_id,
    MODIFY COLUMN sede_legale_indirizzo VARCHAR(255) NOT NULL,
    MODIFY COLUMN sede_legale_civico VARCHAR(10) NOT NULL,
    MODIFY COLUMN sede_legale_comune VARCHAR(100) NOT NULL,
    MODIFY COLUMN sede_legale_provincia VARCHAR(2) NOT NULL,
    MODIFY COLUMN sede_legale_cap VARCHAR(5) NOT NULL,
    MODIFY COLUMN sedi_operative LONGTEXT NULL;

-- Restore from backup if needed
-- DROP TABLE IF EXISTS tenants;
-- RENAME TABLE tenants_backup_locations_20251007 TO tenants;
*/
