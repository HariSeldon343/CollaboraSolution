-- Module: Test Integrità Migrazione Aziende
-- Version: 2025-10-07
-- Author: Database Architect
-- Description: Script completo per verificare integrità dopo migrazione sistema aziende

USE collaboranexio;

-- ============================================
-- TEST 1: Verifica Struttura Tabelle
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 1: Verifica Struttura Tabelle' as TestName;
SELECT '============================================' as Separator;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME IN (
        'denominazione', 'codice_fiscale', 'partita_iva',
        'sede_legale_indirizzo', 'sede_legale_civico', 'sede_legale_comune',
        'sede_legale_provincia', 'sede_legale_cap', 'sedi_operative',
        'settore_merceologico', 'numero_dipendenti', 'capitale_sociale',
        'telefono', 'email', 'pec', 'manager_id', 'rappresentante_legale'
    )
ORDER BY ORDINAL_POSITION;

SELECT '--------------------------------------------' as Separator;
SELECT 'Struttura users (tenant_id e role)' as Info;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME IN ('tenant_id', 'role')
ORDER BY ORDINAL_POSITION;

SELECT '--------------------------------------------' as Separator;
SELECT 'Struttura user_tenant_access' as Info;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
ORDER BY ORDINAL_POSITION;

-- ============================================
-- TEST 2: Verifica Constraint e Foreign Keys
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 2: Foreign Keys e Constraints' as TestName;
SELECT '============================================' as Separator;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME,
    DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND CONSTRAINT_NAME != 'PRIMARY'
    AND (
        (TABLE_NAME = 'tenants' AND COLUMN_NAME = 'manager_id')
        OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id')
        OR TABLE_NAME = 'user_tenant_access'
    )
ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT '--------------------------------------------' as Separator;
SELECT 'CHECK Constraints' as Info;

SELECT
    CONSTRAINT_NAME,
    CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
    AND (
        CONSTRAINT_NAME = 'chk_tenant_fiscal_code'
        OR TABLE_NAME IN ('tenants', 'users', 'user_tenant_access')
    );

-- ============================================
-- TEST 3: Verifica Dati Migrati
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 3: Verifica Dati Migrati' as TestName;
SELECT '============================================' as Separator;

-- Verifica copia name → denominazione
SELECT
    COUNT(*) as TotalTenants,
    SUM(CASE WHEN denominazione IS NOT NULL THEN 1 ELSE 0 END) as WithDenominazione,
    SUM(CASE WHEN denominazione = name THEN 1 ELSE 0 END) as DenominazioneMatchesName,
    SUM(CASE WHEN codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL THEN 1 ELSE 0 END) as WithFiscalCode,
    SUM(CASE WHEN manager_id IS NOT NULL THEN 1 ELSE 0 END) as WithManager,
    SUM(CASE WHEN sede_legale_indirizzo IS NOT NULL THEN 1 ELSE 0 END) as WithSedeLegale
FROM tenants;

-- ============================================
-- TEST 4: Verifica Ruoli Utenti
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 4: Distribuzione Ruoli Utenti' as TestName;
SELECT '============================================' as Separator;

SELECT
    role,
    COUNT(*) as UserCount,
    SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) as WithoutTenant,
    SUM(CASE WHEN tenant_id IS NOT NULL THEN 1 ELSE 0 END) as WithTenant,
    GROUP_CONCAT(DISTINCT status) as Statuses
FROM users
GROUP BY role
ORDER BY FIELD(role, 'super_admin', 'admin', 'manager', 'user', 'guest');

-- ============================================
-- TEST 5: Verifica Multi-Tenant Access
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 5: Multi-Tenant Access Distribution' as TestName;
SELECT '============================================' as Separator;

SELECT
    role_in_tenant,
    COUNT(*) as AccessCount,
    COUNT(DISTINCT user_id) as UniqueUsers,
    COUNT(DISTINCT tenant_id) as UniqueTenants
FROM user_tenant_access
GROUP BY role_in_tenant;

SELECT '--------------------------------------------' as Separator;
SELECT 'Dettaglio accessi per utente' as Info;

SELECT
    u.email,
    u.role as user_role,
    COUNT(uta.id) as additional_tenant_count,
    GROUP_CONCAT(DISTINCT uta.role_in_tenant) as roles_in_tenants
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE u.role IN ('admin', 'super_admin')
GROUP BY u.id, u.email, u.role;

-- ============================================
-- TEST 6: Verifica Indici
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 6: Indici Creati' as TestName;
SELECT '============================================' as Separator;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as Columns,
    INDEX_TYPE,
    NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND (
        (TABLE_NAME = 'tenants' AND INDEX_NAME LIKE 'idx_tenants_%')
        OR (TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_tenant')
        OR (TABLE_NAME = 'user_tenant_access' AND INDEX_NAME = 'idx_user_tenant_access_role')
    )
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================
-- TEST 7: Verifica Integrità Referenziale
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 7: Integrità Referenziale' as TestName;
SELECT '============================================' as Separator;

-- Test 7.1: Verifica manager_id esistenti
SELECT 'Tenants con manager_id inesistente' as Issue, COUNT(*) as ProblematicRecords
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id
WHERE t.manager_id IS NOT NULL AND u.id IS NULL;

-- Test 7.2: Verifica user_tenant_access con user_id inesistente
SELECT 'User_tenant_access con user_id inesistente' as Issue, COUNT(*) as ProblematicRecords
FROM user_tenant_access uta
LEFT JOIN users u ON uta.user_id = u.id
WHERE u.id IS NULL;

-- Test 7.3: Verifica user_tenant_access con tenant_id inesistente
SELECT 'User_tenant_access con tenant_id inesistente' as Issue, COUNT(*) as ProblematicRecords
FROM user_tenant_access uta
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE t.id IS NULL;

-- Test 7.4: Verifica utenti senza tenant (solo super_admin dovrebbe)
SELECT 'Utenti senza tenant (non super_admin)' as Issue, COUNT(*) as ProblematicRecords
FROM users
WHERE tenant_id IS NULL AND role != 'super_admin' AND deleted_at IS NULL;

-- Test 7.5: Verifica super_admin con tenant assegnato
SELECT 'Super_admin con tenant assegnato (anomalo)' as Issue, COUNT(*) as ProblematicRecords
FROM users
WHERE tenant_id IS NOT NULL AND role = 'super_admin' AND deleted_at IS NULL;

-- ============================================
-- TEST 8: Test Funzionale
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 8: Test Funzionali' as TestName;
SELECT '============================================' as Separator;

-- Test 8.1: Super admin accessibility
SELECT '--------------------------------------------' as Separator;
SELECT 'Super Admins e loro accessi' as Info;

SELECT
    u.id,
    u.email,
    u.role,
    u.tenant_id as primary_tenant,
    (SELECT COUNT(*) FROM tenants WHERE status = 'active') as accessible_tenants,
    'All tenants via super_admin privilege' as access_method
FROM users u
WHERE u.role = 'super_admin' AND u.deleted_at IS NULL;

-- Test 8.2: Admin con multi-tenant access
SELECT '--------------------------------------------' as Separator;
SELECT 'Admin con accessi multi-tenant' as Info;

SELECT
    u.id,
    u.email,
    u.role,
    t_primary.denominazione as primary_company,
    COUNT(DISTINCT uta.tenant_id) as additional_tenants,
    GROUP_CONCAT(DISTINCT t_access.denominazione ORDER BY t_access.denominazione) as accessible_companies
FROM users u
LEFT JOIN tenants t_primary ON u.tenant_id = t_primary.id
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t_access ON uta.tenant_id = t_access.id
WHERE u.role = 'admin' AND u.deleted_at IS NULL
GROUP BY u.id, u.email, u.role, t_primary.denominazione;

-- Test 8.3: Manager/User single-tenant
SELECT '--------------------------------------------' as Separator;
SELECT 'Manager e User (single-tenant)' as Info;

SELECT
    u.id,
    u.email,
    u.role,
    t.denominazione as company,
    CASE
        WHEN EXISTS(SELECT 1 FROM user_tenant_access WHERE user_id = u.id) THEN 'WARNING: HAS MULTI-TENANT ACCESS'
        ELSE 'OK: SINGLE TENANT ONLY'
    END as access_level_check
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.role IN ('manager', 'user') AND u.deleted_at IS NULL
ORDER BY u.role, u.email
LIMIT 10;

-- Test 8.4: Verifica tenants completi
SELECT '--------------------------------------------' as Separator;
SELECT 'Completezza informazioni tenants (primi 5)' as Info;

SELECT
    id,
    denominazione,
    CASE WHEN codice_fiscale IS NOT NULL THEN 'SI' ELSE 'NO' END as has_cf,
    CASE WHEN partita_iva IS NOT NULL THEN 'SI' ELSE 'NO' END as has_piva,
    CASE WHEN sede_legale_indirizzo IS NOT NULL THEN 'SI' ELSE 'NO' END as has_address,
    CASE WHEN manager_id IS NOT NULL THEN 'SI' ELSE 'NO' END as has_manager,
    CASE WHEN email IS NOT NULL THEN 'SI' ELSE 'NO' END as has_email,
    status
FROM tenants
ORDER BY created_at DESC
LIMIT 5;

-- ============================================
-- TEST 9: Verifica JSON Fields
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 9: Verifica Campi JSON' as TestName;
SELECT '============================================' as Separator;

SELECT
    COUNT(*) as total_tenants,
    SUM(CASE WHEN sedi_operative IS NOT NULL THEN 1 ELSE 0 END) as with_sedi_operative,
    SUM(CASE WHEN JSON_LENGTH(sedi_operative) > 0 THEN 1 ELSE 0 END) as with_valid_json
FROM tenants;

-- Esempio sedi_operative (se esistenti)
SELECT '--------------------------------------------' as Separator;
SELECT 'Esempio sedi_operative JSON (primo record con dati)' as Info;

SELECT
    id,
    denominazione,
    JSON_PRETTY(sedi_operative) as sedi_operative_formatted
FROM tenants
WHERE sedi_operative IS NOT NULL AND JSON_LENGTH(sedi_operative) > 0
LIMIT 1;

-- ============================================
-- TEST 10: Performance Check
-- ============================================
SELECT '============================================' as Separator;
SELECT 'TEST 10: Performance Check (Index Usage)' as TestName;
SELECT '============================================' as Separator;

-- Verifica indici utilizzabili
EXPLAIN SELECT * FROM users WHERE role = 'admin' AND tenant_id = 1;
EXPLAIN SELECT * FROM tenants WHERE manager_id = 1;
EXPLAIN SELECT * FROM user_tenant_access WHERE user_id = 1 AND tenant_id = 1;

-- ============================================
-- SUMMARY FINALE
-- ============================================
SELECT '============================================' as Separator;
SELECT 'MIGRATION INTEGRITY TESTS COMPLETED' as Status;
SELECT NOW() as TestedAt;
SELECT @@version as MySQLVersion;
SELECT '============================================' as Separator;

-- Riepilogo numerico
SELECT
    'SUMMARY' as Section,
    (SELECT COUNT(*) FROM tenants) as TotalTenants,
    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as TotalUsers,
    (SELECT COUNT(*) FROM users WHERE role = 'super_admin') as SuperAdmins,
    (SELECT COUNT(*) FROM users WHERE role = 'admin') as Admins,
    (SELECT COUNT(*) FROM users WHERE role = 'manager') as Managers,
    (SELECT COUNT(*) FROM user_tenant_access) as MultiTenantAccessRecords;

-- Verifica problemi
SELECT
    'ISSUES FOUND' as Section,
    (SELECT COUNT(*) FROM tenants t LEFT JOIN users u ON t.manager_id = u.id WHERE t.manager_id IS NOT NULL AND u.id IS NULL) as InvalidManagerReferences,
    (SELECT COUNT(*) FROM user_tenant_access uta LEFT JOIN users u ON uta.user_id = u.id WHERE u.id IS NULL) as InvalidUserTenantAccess,
    (SELECT COUNT(*) FROM users WHERE tenant_id IS NULL AND role != 'super_admin' AND deleted_at IS NULL) as UsersWithoutTenantNonSuperAdmin,
    (SELECT COUNT(*) FROM tenants WHERE denominazione IS NULL) as TenantsWithoutDenominazione,
    (SELECT COUNT(*) FROM tenants WHERE codice_fiscale IS NULL AND partita_iva IS NULL) as TenantsWithoutFiscalCode;

-- Raccomandazioni
SELECT '============================================' as Separator;
SELECT 'RACCOMANDAZIONI' as Section;
SELECT '============================================' as Separator;

SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM users WHERE role = 'super_admin') = 0
            THEN 'WARNING: Nessun super_admin presente. Creare almeno un super_admin.'
        ELSE 'OK: Super admin presente'
    END as SuperAdminCheck;

SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM tenants WHERE manager_id IS NULL) > 0
            THEN CONCAT('WARNING: ', (SELECT COUNT(*) FROM tenants WHERE manager_id IS NULL), ' tenant senza manager. Assegnare un manager.')
        ELSE 'OK: Tutti i tenant hanno un manager'
    END as ManagerCheck;

SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM tenants WHERE sede_legale_indirizzo IS NULL) > (SELECT COUNT(*) FROM tenants) * 0.5
            THEN 'INFO: Più del 50% dei tenant non ha sede legale completa. Considerare aggiornamento dati.'
        ELSE 'OK: La maggior parte dei tenant ha sede legale'
    END as AddressCheck;

-- ============================================
-- END OF INTEGRITY TESTS
-- ============================================
