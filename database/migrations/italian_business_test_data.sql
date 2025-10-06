-- Module: Italian Business Test Data
-- Version: 2025-09-26
-- Author: Database Architect
-- Description: Sample data for testing Italian business schema

USE collaboranexio;

-- ============================================
-- SAMPLE ITALIAN COMPANIES
-- ============================================

-- Clear existing test data (optional - comment out in production)
-- DELETE FROM tenants WHERE codice_fiscale LIKE 'TEST_%';

-- Insert sample Italian companies
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
    max_storage_gb,
    name,  -- Keep for backward compatibility
    domain
) VALUES
-- Technology company in Milan
(
    'Innovazione Digitale S.r.l.',
    'Via Alessandro Manzoni 42, 20121 Milano (MI), Italia',
    'Corso Buenos Aires 15, 20124 Milano (MI), Italia',
    'INVDIG85M15F205X',
    'IT02345678901',
    'Sviluppo Software e Intelligenza Artificiale',
    45,
    'Francesco Colombo',
    '+39 02 8765 4321',
    'info@innovazione-digitale.it',
    'innovazione.digitale@pec.it',
    '2018-06-15',
    100000.00,
    'active',
    100,
    1000,
    'Innovazione Digitale S.r.l.',  -- For backward compatibility
    'innovazione-digitale.it'
),

-- Manufacturing company in Turin
(
    'Automazione Industriale S.p.A.',
    'Corso Francia 123, 10143 Torino (TO), Italia',
    NULL,  -- Same as registered office
    'AUTIND71L22L219K',
    'IT11223344556',
    'Automazione e Robotica Industriale',
    230,
    'Giovanni Agnelli',
    '+39 011 9876 5432',
    'contatti@automazione-industriale.it',
    'automazione.spa@legalmail.it',
    '1971-09-22',
    2500000.00,
    'active',
    300,
    5000,
    'Automazione Industriale S.p.A.',
    'automazione-industriale.it'
),

-- Fashion company in Florence
(
    'Moda Toscana S.r.l.',
    'Via de\' Tornabuoni 18, 50123 Firenze (FI), Italia',
    'Via del Proconsolo 12, 50122 Firenze (FI), Italia',
    'MODTOS92D45D612Z',
    'IT33445566778',
    'Moda e Abbigliamento di Lusso',
    75,
    'Elisabetta Gucci',
    '+39 055 2345 678',
    'info@moda-toscana.it',
    'modatoscana@pec.it',
    '1992-04-05',
    500000.00,
    'active',
    100,
    750,
    'Moda Toscana S.r.l.',
    'moda-toscana.it'
),

-- Food & Restaurant in Rome
(
    'Sapori Romani S.r.l.s.',
    'Via dei Coronari 45, 00186 Roma (RM), Italia',
    'Piazza Navona 22, 00186 Roma (RM), Italia',
    'SAPROM03P55H501W',
    'IT44556677889',
    'Ristorazione e Catering',
    35,
    'Carlo Cracco',
    '+39 06 6789 0123',
    'info@sapori-romani.it',
    'saporiromani@pec.it',
    '2003-09-15',
    50000.00,
    'active',
    50,
    200,
    'Sapori Romani S.r.l.s.',
    'sapori-romani.it'
),

-- Consulting firm in Bologna
(
    'Consulenza Strategica S.r.l.',
    'Via Zamboni 33, 40126 Bologna (BO), Italia',
    NULL,
    'CONSTR95E12A944L',
    'IT55667788990',
    'Consulenza Aziendale e Strategica',
    28,
    'Marco Bianchi',
    '+39 051 234 5678',
    'info@consulenza-strategica.it',
    'consulenza.strategica@legalmail.it',
    '1995-05-12',
    75000.00,
    'active',
    40,
    300,
    'Consulenza Strategica S.r.l.',
    'consulenza-strategica.it'
),

-- E-commerce startup in Naples
(
    'E-Shop Italia S.r.l.',
    'Via Toledo 156, 80134 Napoli (NA), Italia',
    'Centro Direzionale Isola E1, 80143 Napoli (NA), Italia',
    'ESHPIT19H25F839M',
    'IT66778899001',
    'E-commerce e Marketplace Online',
    18,
    'Lucia Esposito',
    '+39 081 7654 321',
    'support@eshop-italia.it',
    'eshopitalia@pec.it',
    '2019-08-25',
    25000.00,
    'active',
    30,
    500,
    'E-Shop Italia S.r.l.',
    'eshop-italia.it'
),

-- Green energy company in Venice
(
    'Energia Verde Veneto S.p.A.',
    'Dorsoduro 3488/U, 30123 Venezia (VE), Italia',
    'Via dell\'Elettricità 10, 30175 Marghera (VE), Italia',
    'ENVVEN08C18L736T',
    'IT77889900112',
    'Energie Rinnovabili e Sostenibilità',
    120,
    'Paolo Verdi',
    '+39 041 5678 901',
    'info@energia-verde-veneto.it',
    'energiaverde@legalmail.it',
    '2008-03-18',
    1500000.00,
    'active',
    150,
    2000,
    'Energia Verde Veneto S.p.A.',
    'energia-verde-veneto.it'
),

-- Pharmaceutical in Verona
(
    'Farmaceutica Scaligera S.r.l.',
    'Via della Valverde 15, 37122 Verona (VR), Italia',
    NULL,
    'FARSCL99T30L781A',
    'IT88990011223',
    'Farmaceutica e Ricerca Medica',
    95,
    'Dott. Roberto Medicina',
    '+39 045 8901 234',
    'ricerca@farmaceutica-scaligera.it',
    'farmascaligera@pec.it',
    '1999-12-30',
    800000.00,
    'active',
    120,
    1500,
    'Farmaceutica Scaligera S.r.l.',
    'farmaceutica-scaligera.it'
);

-- ============================================
-- SAMPLE USERS FOR COMPANIES
-- ============================================

-- Create managers and users for the companies
INSERT INTO users (
    tenant_id,
    email,
    password_hash,
    first_name,
    last_name,
    display_name,
    role,
    status,
    department,
    position
)
SELECT
    t.id as tenant_id,
    CONCAT('admin@', REPLACE(t.domain, '.it', '.com')) as email,
    '$2y$10$dummyHashForTesting123456789012345678901234567890' as password_hash,
    'Admin' as first_name,
    SUBSTRING_INDEX(t.denominazione, ' ', 1) as last_name,
    CONCAT('Admin ', SUBSTRING_INDEX(t.denominazione, ' ', 1)) as display_name,
    'admin' as role,
    'active' as status,
    'Direzione' as department,
    'Amministratore Sistema' as position
FROM tenants t
WHERE t.codice_fiscale IN (
    'INVDIG85M15F205X', 'AUTIND71L22L219K', 'MODTOS92D45D612Z',
    'SAPROM03P55H501W', 'CONSTR95E12A944L', 'ESHPIT19H25F839M',
    'ENVVEN08C18L736T', 'FARSCL99T30L781A'
)
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name);

-- ============================================
-- ASSIGN MANAGERS TO COMPANIES
-- ============================================

-- Update each company with its manager
UPDATE tenants t
INNER JOIN users u ON u.tenant_id = t.id AND u.role = 'admin'
SET t.manager_user_id = u.id
WHERE t.manager_user_id IS NULL;

-- ============================================
-- MULTI-COMPANY ACCESS EXAMPLES
-- ============================================

-- Create a super-admin who can access multiple companies
-- (First ensure we have a super-admin user)
INSERT INTO users (
    tenant_id,
    email,
    password_hash,
    first_name,
    last_name,
    display_name,
    role,
    status,
    department,
    position
) VALUES (
    1,  -- Primary tenant
    'superadmin@collaboranexio.it',
    '$2y$10$dummyHashForTesting123456789012345678901234567890',
    'Super',
    'Admin',
    'Super Administrator',
    'admin',
    'active',
    'IT Management',
    'System Administrator'
)
ON DUPLICATE KEY UPDATE
    role = 'admin',
    status = 'active';

-- Grant this super-admin access to multiple companies
INSERT INTO user_companies (user_id, company_id, access_level, assigned_by)
SELECT
    (SELECT id FROM users WHERE email = 'superadmin@collaboranexio.it' LIMIT 1) as user_id,
    t.id as company_id,
    'full' as access_level,
    (SELECT id FROM users WHERE email = 'superadmin@collaboranexio.it' LIMIT 1) as assigned_by
FROM tenants t
WHERE t.status = 'active'
LIMIT 5
ON DUPLICATE KEY UPDATE
    access_level = VALUES(access_level);

-- Create cross-company consultant access (read-only to multiple companies)
INSERT INTO user_companies (user_id, company_id, access_level)
SELECT
    u.id as user_id,
    t.id as company_id,
    'read_only' as access_level
FROM users u
CROSS JOIN tenants t
WHERE u.email LIKE '%consulenza%'
AND t.id != u.tenant_id
AND t.settore_merceologico IN ('Sviluppo Software e Intelligenza Artificiale', 'E-commerce e Marketplace Online')
ON DUPLICATE KEY UPDATE
    access_level = VALUES(access_level);

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Show companies with their Italian business details
SELECT
    denominazione as 'Company Name',
    codice_fiscale as 'Tax Code',
    partita_iva as 'VAT Number',
    settore_merceologico as 'Business Sector',
    numero_dipendenti as 'Employees',
    rappresentante_legale as 'Legal Rep',
    pec as 'Certified Email',
    FORMAT(capitale_sociale, 2, 'it_IT') as 'Share Capital (EUR)'
FROM tenants
ORDER BY denominazione;

-- Show multi-company access relationships
SELECT
    u.email as 'User Email',
    u.role as 'User Role',
    t.denominazione as 'Has Access To Company',
    uc.access_level as 'Access Level',
    uc.assigned_at as 'Assigned Date'
FROM user_companies uc
JOIN users u ON u.id = uc.user_id
JOIN tenants t ON t.id = uc.company_id
ORDER BY u.email, t.denominazione;

-- Show companies with their assigned managers
SELECT
    t.denominazione as 'Company',
    t.codice_fiscale as 'Tax Code',
    CONCAT(u.first_name, ' ', u.last_name) as 'Manager',
    u.email as 'Manager Email',
    t.numero_dipendenti as 'Employees'
FROM tenants t
LEFT JOIN users u ON u.id = t.manager_user_id
ORDER BY t.denominazione;