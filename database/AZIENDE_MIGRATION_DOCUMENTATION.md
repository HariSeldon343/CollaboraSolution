# Documentazione Migrazione Sistema Aziende e Ruoli Multi-Tenant

## INDICE
1. [Schema ERD Testuale](#schema-erd-testuale)
2. [Mapping Campi (Vecchio → Nuovo)](#mapping-campi)
3. [Breaking Changes](#breaking-changes)
4. [Script di Test e Verifica](#script-di-test-e-verifica)
5. [Gerarchia Ruoli](#gerarchia-ruoli)
6. [Query di Esempio](#query-di-esempio)

---

## Schema ERD Testuale

```
┌─────────────────────────────────────────────────────────────────────┐
│                              TENANTS                                 │
├─────────────────────────────────────────────────────────────────────┤
│ PK  id                        INT UNSIGNED AUTO_INCREMENT           │
│     name                      VARCHAR(255) NOT NULL [Legacy]        │
│ NEW denominazione             VARCHAR(255) NOT NULL                 │
│ NEW codice_fiscale            VARCHAR(16) NULL                      │
│ NEW partita_iva               VARCHAR(11) NULL                      │
│ NEW sede_legale_indirizzo     VARCHAR(255) NULL                     │
│ NEW sede_legale_civico        VARCHAR(10) NULL                      │
│ NEW sede_legale_comune        VARCHAR(100) NULL                     │
│ NEW sede_legale_provincia     VARCHAR(2) NULL                       │
│ NEW sede_legale_cap           VARCHAR(5) NULL                       │
│ NEW sedi_operative            JSON NULL [Array]                     │
│ NEW settore_merceologico      VARCHAR(100) NULL                     │
│ NEW numero_dipendenti         INT NULL                              │
│ NEW capitale_sociale          DECIMAL(15,2) NULL                    │
│ NEW telefono                  VARCHAR(20) NULL                      │
│ NEW email                     VARCHAR(255) NULL                     │
│ NEW pec                       VARCHAR(255) NULL                     │
│ NEW manager_id                INT UNSIGNED NULL → users.id          │
│ NEW rappresentante_legale     VARCHAR(255) NULL                     │
│     domain                    VARCHAR(255) NULL                     │
│     status                    ENUM('active','inactive','suspended') │
│     max_users                 INT DEFAULT 10                        │
│     max_storage_gb            INT DEFAULT 100                       │
│     settings                  JSON NULL                             │
│     created_at                TIMESTAMP                             │
│     updated_at                TIMESTAMP                             │
│                                                                      │
│ CONSTRAINTS:                                                         │
│   - CHECK (codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL)   │
│   - FK manager_id → users.id ON DELETE RESTRICT                     │
│                                                                      │
│ INDEXES:                                                             │
│   - idx_tenants_manager (manager_id)                                │
│   - idx_tenants_denominazione (denominazione)                       │
│   - idx_tenants_partita_iva (partita_iva)                           │
│   - idx_tenants_codice_fiscale (codice_fiscale)                     │
└─────────────────────────────────────────────────────────────────────┘
                            ┃
                            ┃ 1
                            ┃
                            ┃ N
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                               USERS                                  │
├─────────────────────────────────────────────────────────────────────┤
│ PK  id                        INT UNSIGNED AUTO_INCREMENT           │
│ MOD tenant_id                 INT UNSIGNED NULL → tenants.id        │
│     email                     VARCHAR(255) NOT NULL                 │
│     password_hash             VARCHAR(255) NOT NULL                 │
│     first_name                VARCHAR(100) NOT NULL                 │
│     last_name                 VARCHAR(100) NOT NULL                 │
│     display_name              VARCHAR(200) NULL                     │
│ MOD role                      ENUM(                                  │
│                                 'super_admin',  ← NEW               │
│                                 'admin',                             │
│                                 'manager',                           │
│                                 'user',                              │
│                                 'guest'                              │
│                               ) DEFAULT 'user'                       │
│     permissions               JSON NULL                             │
│     status                    ENUM('active','inactive'...)          │
│     ... [altri campi esistenti]                                     │
│     created_at                TIMESTAMP                             │
│     updated_at                TIMESTAMP                             │
│     deleted_at                TIMESTAMP NULL                        │
│                                                                      │
│ CONSTRAINTS:                                                         │
│   - FK tenant_id → tenants.id ON DELETE CASCADE (nullable)          │
│   - UNIQUE (tenant_id, email)                                       │
│                                                                      │
│ INDEXES:                                                             │
│   - idx_users_role_tenant (role, tenant_id)  ← NEW                  │
│   - idx_user_tenant_status (tenant_id, status)                      │
│   - idx_user_role (tenant_id, role)                                 │
└─────────────────────────────────────────────────────────────────────┘
                            ┃
                            ┃ 1
                            ┃
                            ┃ N
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       USER_TENANT_ACCESS                             │
│                  (Multi-tenant access for Admin)                     │
├─────────────────────────────────────────────────────────────────────┤
│ PK  id                        INT UNSIGNED AUTO_INCREMENT           │
│     user_id                   INT UNSIGNED NOT NULL → users.id      │
│     tenant_id                 INT UNSIGNED NOT NULL → tenants.id    │
│ NEW role_in_tenant            ENUM('admin','manager','user','guest')│
│                               DEFAULT 'admin'                        │
│     granted_by                INT UNSIGNED NULL → users.id          │
│     granted_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP   │
│                                                                      │
│ CONSTRAINTS:                                                         │
│   - FK user_id → users.id ON DELETE CASCADE                         │
│   - FK tenant_id → tenants.id ON DELETE CASCADE                     │
│   - FK granted_by → users.id ON DELETE SET NULL                     │
│   - UNIQUE (user_id, tenant_id)                                     │
│                                                                      │
│ INDEXES:                                                             │
│   - idx_user_tenant_access_role (role_in_tenant, tenant_id) ← NEW   │
│   - idx_access_user (user_id)                                       │
│   - idx_access_tenant (tenant_id)                                   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Mapping Campi

### TABELLA: tenants

| Campo Vecchio | Campo Nuovo | Tipo | Note |
|--------------|-------------|------|------|
| `name` | `denominazione` | VARCHAR(255) NOT NULL | Migrazione automatica: `denominazione = name` |
| N/A | `codice_fiscale` | VARCHAR(16) NULL | Nuovo campo |
| N/A | `partita_iva` | VARCHAR(11) NULL | Nuovo campo |
| N/A | `sede_legale_indirizzo` | VARCHAR(255) NULL | Nuovo campo - indirizzo completo separato |
| N/A | `sede_legale_civico` | VARCHAR(10) NULL | Nuovo campo |
| N/A | `sede_legale_comune` | VARCHAR(100) NULL | Nuovo campo |
| N/A | `sede_legale_provincia` | VARCHAR(2) NULL | Nuovo campo |
| N/A | `sede_legale_cap` | VARCHAR(5) NULL | Nuovo campo |
| N/A | `sedi_operative` | JSON NULL | Nuovo campo - array di oggetti |
| N/A | `settore_merceologico` | VARCHAR(100) NULL | Nuovo campo |
| N/A | `numero_dipendenti` | INT NULL | Nuovo campo |
| N/A | `capitale_sociale` | DECIMAL(15,2) NULL | Nuovo campo |
| N/A | `telefono` | VARCHAR(20) NULL | Nuovo campo |
| N/A | `email` | VARCHAR(255) NULL | Nuovo campo |
| N/A | `pec` | VARCHAR(255) NULL | Nuovo campo |
| N/A | `manager_id` | INT UNSIGNED NULL | Nuovo campo - FK to users.id |
| N/A | `rappresentante_legale` | VARCHAR(255) NULL | Nuovo campo |
| `piano` | **ELIMINATO** | - | Colonna deprecata rimossa |
| `domain` | `domain` | VARCHAR(255) NULL | Mantenuto invariato |
| `status` | `status` | ENUM | Mantenuto invariato |
| `max_users` | `max_users` | INT | Mantenuto invariato |
| `max_storage_gb` | `max_storage_gb` | INT | Mantenuto invariato |
| `settings` | `settings` | JSON | Mantenuto invariato |

### TABELLA: users

| Campo Vecchio | Campo Nuovo | Tipo | Note |
|--------------|-------------|------|------|
| `tenant_id` | `tenant_id` | INT UNSIGNED NULL | **BREAKING**: Ora nullable per super_admin |
| `role ENUM('admin','manager','user','guest')` | `role ENUM('super_admin','admin','manager','user','guest')` | ENUM | **BREAKING**: Aggiunto 'super_admin' |
| (tutti gli altri) | (invariati) | - | Nessuna modifica |

### TABELLA: user_tenant_access

| Campo Vecchio | Campo Nuovo | Tipo | Note |
|--------------|-------------|------|------|
| N/A | `role_in_tenant` | ENUM('admin','manager','user','guest') | Nuovo campo - ruolo specifico nel tenant |
| `user_id` | `user_id` | INT UNSIGNED | Mantenuto invariato |
| `tenant_id` | `tenant_id` | INT UNSIGNED | Mantenuto invariato |
| `granted_by` | `granted_by` | INT UNSIGNED NULL | Mantenuto invariato |
| `granted_at` | `granted_at` | TIMESTAMP | Mantenuto invariato |

---

## Breaking Changes

### 1. USERS TABLE - tenant_id ora nullable

**Impatto:**
```php
// VECCHIO CODICE (potrebbe fallire):
$query = "SELECT * FROM users WHERE tenant_id = ?";

// NUOVO CODICE (gestire NULL):
$query = "SELECT * FROM users WHERE (tenant_id = ? OR role = 'super_admin')";

// Oppure filtrare esplicitamente:
$query = "SELECT * FROM users WHERE tenant_id = ? AND tenant_id IS NOT NULL";
```

**Soluzione:**
- Aggiornare tutte le query che assumono `tenant_id NOT NULL`
- Gestire logica Super Admin in PHP:
  ```php
  if ($currentUser['role'] === 'super_admin') {
      // Accesso a TUTTI i tenant
      $query = "SELECT * FROM data_table WHERE 1=1";
  } else {
      // Isolamento tenant normale
      $query = "SELECT * FROM data_table WHERE tenant_id = ?";
  }
  ```

### 2. USERS TABLE - Nuovo ruolo 'super_admin'

**Impatto:**
```php
// VECCHIO CODICE (limitato):
if (in_array($role, ['admin', 'manager'])) {
    // Permessi elevati
}

// NUOVO CODICE (include super_admin):
if (in_array($role, ['super_admin', 'admin', 'manager'])) {
    // Permessi elevati
}
```

**Soluzione:**
- Rivedere tutti i controlli di autorizzazione
- Implementare gerarchia: `super_admin > admin > manager > user > guest`

### 3. TENANTS TABLE - Campi obbligatori

**Impatto:**
- `denominazione` diventa NOT NULL dopo migrazione
- `codice_fiscale` OR `partita_iva` obbligatorio (CHECK constraint)

**Soluzione:**
```php
// Validazione PHP prima di INSERT:
if (empty($denominazione)) {
    throw new Exception("Denominazione obbligatoria");
}
if (empty($codice_fiscale) && empty($partita_iva)) {
    throw new Exception("Codice Fiscale o Partita IVA obbligatorio");
}
```

### 4. TENANTS TABLE - Foreign Key manager_id

**Impatto:**
- Non è possibile creare tenant senza manager esistente
- ON DELETE RESTRICT: eliminazione manager bloccata se assegnato come manager_id

**Soluzione:**
```php
// Ordine corretto delle operazioni:
// 1. Creare l'utente manager
$userId = createUser($email, $password, 'manager', $tenant_id_temporaneo);

// 2. Creare il tenant con manager_id
$tenantId = createTenant($denominazione, $cf, $piva, $manager_id = $userId);

// 3. Aggiornare tenant_id dell'utente
updateUser($userId, ['tenant_id' => $tenantId]);
```

### 5. USER_TENANT_ACCESS - Campo role_in_tenant

**Impatto:**
- Query esistenti potrebbero non considerare il ruolo specifico

**Soluzione:**
```php
// VECCHIO: Assumeva sempre ruolo admin
$access = "SELECT * FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?";

// NUOVO: Considera ruolo specifico
$access = "SELECT role_in_tenant FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?";

// Verifica permessi in base a role_in_tenant
if ($access['role_in_tenant'] === 'admin') {
    // Permessi admin nel tenant
}
```

---

## Script di Test e Verifica

### Script 1: Verifica Integrità Post-Migrazione

Salvare come `/mnt/c/xampp/htdocs/CollaboraNexio/database/test_aziende_migration_integrity.sql`

```sql
USE collaboranexio;

-- ============================================
-- TEST 1: Verifica Struttura Tabelle
-- ============================================
SELECT 'TEST 1: Struttura Tabelle' as TestName;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME IN (
        'denominazione', 'codice_fiscale', 'partita_iva',
        'sede_legale_indirizzo', 'manager_id', 'rappresentante_legale'
    )
ORDER BY ORDINAL_POSITION;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME IN ('tenant_id', 'role')
ORDER BY ORDINAL_POSITION;

-- ============================================
-- TEST 2: Verifica Constraint e Foreign Keys
-- ============================================
SELECT 'TEST 2: Foreign Keys e Constraints' as TestName;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND (
        (TABLE_NAME = 'tenants' AND COLUMN_NAME = 'manager_id')
        OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id')
        OR TABLE_NAME = 'user_tenant_access'
    );

-- Verifica CHECK constraint
SELECT
    CONSTRAINT_NAME,
    CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
    AND CONSTRAINT_NAME = 'chk_tenant_fiscal_code';

-- ============================================
-- TEST 3: Verifica Dati Migrati
-- ============================================
SELECT 'TEST 3: Dati Migrati' as TestName;

-- Verifica copia name → denominazione
SELECT
    COUNT(*) as TotalTenants,
    SUM(CASE WHEN denominazione IS NOT NULL THEN 1 ELSE 0 END) as WithDenominazione,
    SUM(CASE WHEN denominazione = name THEN 1 ELSE 0 END) as DenominazioneMatchesName,
    SUM(CASE WHEN codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL THEN 1 ELSE 0 END) as WithFiscalCode
FROM tenants;

-- ============================================
-- TEST 4: Verifica Ruoli Utenti
-- ============================================
SELECT 'TEST 4: Ruoli Utenti' as TestName;

SELECT
    role,
    COUNT(*) as UserCount,
    SUM(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) as WithoutTenant,
    SUM(CASE WHEN tenant_id IS NOT NULL THEN 1 ELSE 0 END) as WithTenant
FROM users
GROUP BY role
ORDER BY FIELD(role, 'super_admin', 'admin', 'manager', 'user', 'guest');

-- ============================================
-- TEST 5: Verifica Multi-Tenant Access
-- ============================================
SELECT 'TEST 5: Multi-Tenant Access' as TestName;

SELECT
    role_in_tenant,
    COUNT(*) as AccessCount,
    COUNT(DISTINCT user_id) as UniqueUsers,
    COUNT(DISTINCT tenant_id) as UniqueTenants
FROM user_tenant_access
GROUP BY role_in_tenant;

-- ============================================
-- TEST 6: Verifica Indici
-- ============================================
SELECT 'TEST 6: Indici Creati' as TestName;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as Columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
    AND (
        (TABLE_NAME = 'tenants' AND INDEX_NAME LIKE 'idx_tenants_%')
        OR (TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_tenant')
        OR (TABLE_NAME = 'user_tenant_access' AND INDEX_NAME = 'idx_user_tenant_access_role')
    )
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================
-- TEST 7: Verifica Integrità Referenziale
-- ============================================
SELECT 'TEST 7: Integrità Referenziale' as TestName;

-- Verifica manager_id esistenti
SELECT
    'Tenants con manager_id inesistente' as Issue,
    COUNT(*) as ProblematicRecords
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id
WHERE t.manager_id IS NOT NULL AND u.id IS NULL;

-- Verifica user_tenant_access con user_id inesistente
SELECT
    'User_tenant_access con user_id inesistente' as Issue,
    COUNT(*) as ProblematicRecords
FROM user_tenant_access uta
LEFT JOIN users u ON uta.user_id = u.id
WHERE u.id IS NULL;

-- Verifica user_tenant_access con tenant_id inesistente
SELECT
    'User_tenant_access con tenant_id inesistente' as Issue,
    COUNT(*) as ProblematicRecords
FROM user_tenant_access uta
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE t.id IS NULL;

-- ============================================
-- TEST 8: Test Funzionale
-- ============================================
SELECT 'TEST 8: Test Funzionale' as TestName;

-- Test: Super admin può accedere a tutti i tenant
SELECT
    u.email,
    u.role,
    u.tenant_id as primary_tenant,
    COUNT(DISTINCT t.id) as accessible_tenants
FROM users u
CROSS JOIN tenants t
WHERE u.role = 'super_admin'
GROUP BY u.id, u.email, u.role, u.tenant_id;

-- Test: Admin con multi-tenant access
SELECT
    u.email,
    u.role,
    u.tenant_id as primary_tenant,
    COUNT(uta.tenant_id) as additional_tenants,
    GROUP_CONCAT(DISTINCT t.denominazione) as accessible_companies
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t ON uta.tenant_id = t.id
WHERE u.role = 'admin'
GROUP BY u.id, u.email, u.role, u.tenant_id;

-- Test: Manager/User single-tenant
SELECT
    u.email,
    u.role,
    t.denominazione as company,
    CASE
        WHEN EXISTS(SELECT 1 FROM user_tenant_access WHERE user_id = u.id) THEN 'HAS MULTI-TENANT ACCESS'
        ELSE 'SINGLE TENANT ONLY'
    END as access_level
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
WHERE u.role IN ('manager', 'user')
ORDER BY u.role, u.email;

-- ============================================
-- SUMMARY
-- ============================================
SELECT '===========================================' as Separator;
SELECT 'MIGRATION INTEGRITY TESTS COMPLETED' as Status;
SELECT NOW() as TestedAt;
```

### Script 2: Test Creazione Nuove Aziende

Salvare come `/mnt/c/xampp/htdocs/CollaboraNexio/database/test_new_company_creation.sql`

```sql
USE collaboranexio;

-- ============================================
-- TEST SCENARIO: Creazione Completa Azienda
-- ============================================

START TRANSACTION;

-- Step 1: Creare manager temporaneo (tenant_id temporaneo)
INSERT INTO users (
    tenant_id, email, password_hash, first_name, last_name,
    role, status, email_verified_at
) VALUES (
    1, -- tenant_id temporaneo (verrà aggiornato)
    'test_manager@testcompany.it',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Test',
    'Manager',
    'manager',
    'active',
    NOW()
);

SET @manager_id = LAST_INSERT_ID();
SELECT @manager_id as ManagerCreated;

-- Step 2: Creare l'azienda con tutti i campi
INSERT INTO tenants (
    name,
    denominazione,
    codice_fiscale,
    partita_iva,
    sede_legale_indirizzo,
    sede_legale_civico,
    sede_legale_comune,
    sede_legale_provincia,
    sede_legale_cap,
    sedi_operative,
    settore_merceologico,
    numero_dipendenti,
    capitale_sociale,
    telefono,
    email,
    pec,
    manager_id,
    rappresentante_legale,
    status,
    domain
) VALUES (
    'Test Company SRL',
    'Test Company SRL',
    'TSTCMP12345678AB',
    '12345678901',
    'Via dei Test',
    '42',
    'Milano',
    'MI',
    '20100',
    JSON_ARRAY(
        JSON_OBJECT(
            'indirizzo', 'Via Secondaria',
            'civico', '15',
            'comune', 'Roma',
            'provincia', 'RM',
            'cap', '00100'
        )
    ),
    'Software Testing',
    25,
    50000.00,
    '+39 02 9999999',
    'info@testcompany.it',
    'testcompany@pec.it',
    @manager_id,
    'Test Manager',
    'active',
    'test.collaboranexio.com'
);

SET @tenant_id = LAST_INSERT_ID();
SELECT @tenant_id as TenantCreated;

-- Step 3: Aggiornare tenant_id del manager
UPDATE users
SET tenant_id = @tenant_id
WHERE id = @manager_id;

-- Step 4: Creare utenti aggiuntivi
INSERT INTO users (
    tenant_id, email, password_hash, first_name, last_name,
    role, status, email_verified_at
) VALUES
(
    @tenant_id,
    'admin@testcompany.it',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Test',
    'Admin',
    'admin',
    'active',
    NOW()
),
(
    @tenant_id,
    'user@testcompany.it',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Test',
    'User',
    'user',
    'active',
    NOW()
);

-- Step 5: Verificare creazione
SELECT
    'VERIFICA CREAZIONE' as Step,
    t.id as tenant_id,
    t.denominazione,
    t.codice_fiscale,
    t.partita_iva,
    CONCAT(t.sede_legale_indirizzo, ', ', t.sede_legale_civico) as sede_legale,
    t.sede_legale_comune,
    t.manager_id,
    u.email as manager_email,
    (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as total_users
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id
WHERE t.id = @tenant_id;

-- Verifica sedi operative JSON
SELECT
    'SEDI OPERATIVE' as Info,
    JSON_PRETTY(sedi_operative) as sedi_json
FROM tenants
WHERE id = @tenant_id;

-- Verifica utenti creati
SELECT
    'UTENTI CREATI' as Info,
    id,
    email,
    role,
    tenant_id,
    status
FROM users
WHERE tenant_id = @tenant_id
ORDER BY FIELD(role, 'manager', 'admin', 'user');

-- ROLLBACK per test (rimuovere per committare)
ROLLBACK;
-- Per committare realmente, sostituire ROLLBACK con:
-- COMMIT;

SELECT 'TEST COMPLETATO (ROLLBACK)' as Status;
```

### Script 3: Test Multi-Tenant Access

Salvare come `/mnt/c/xampp/htdocs/CollaboraNexio/database/test_multi_tenant_access.sql`

```sql
USE collaboranexio;

-- ============================================
-- TEST: Verifica Multi-Tenant Access per Admin
-- ============================================

-- Scenario: Admin con accesso a più tenant

START TRANSACTION;

-- Creare due tenant di test
INSERT INTO tenants (name, denominazione, codice_fiscale, status)
VALUES
    ('Test Tenant A', 'Test Tenant A', 'TESTA12345678901', 'active'),
    ('Test Tenant B', 'Test Tenant B', 'TESTB12345678901', 'active');

SET @tenant_a = LAST_INSERT_ID() - 1;
SET @tenant_b = LAST_INSERT_ID();

-- Creare admin con tenant primario A
INSERT INTO users (
    tenant_id, email, password_hash, first_name, last_name,
    role, status, email_verified_at
) VALUES (
    @tenant_a,
    'multiadmin@test.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Multi',
    'Admin',
    'admin',
    'active',
    NOW()
);

SET @admin_id = LAST_INSERT_ID();

-- Garantire accesso al tenant B
INSERT INTO user_tenant_access (user_id, tenant_id, role_in_tenant, granted_by)
VALUES (@admin_id, @tenant_b, 'admin', @admin_id);

-- Verifica accessi
SELECT
    'VERIFICA ACCESSI MULTI-TENANT' as Test,
    u.id,
    u.email,
    u.role,
    u.tenant_id as primary_tenant,
    ta.denominazione as primary_tenant_name,
    COUNT(uta.tenant_id) as additional_tenants
FROM users u
LEFT JOIN tenants ta ON u.tenant_id = ta.id
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
WHERE u.id = @admin_id
GROUP BY u.id;

-- Dettaglio accessi
SELECT
    'DETTAGLIO TENANT ACCESSIBILI' as Test,
    u.email,
    t.denominazione,
    CASE
        WHEN t.id = u.tenant_id THEN 'PRIMARY'
        ELSE uta.role_in_tenant
    END as role_type,
    CASE
        WHEN t.id = u.tenant_id THEN 'Primary Tenant'
        ELSE 'Additional Access'
    END as access_type
FROM users u
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t ON (t.id = u.tenant_id OR t.id = uta.tenant_id)
WHERE u.id = @admin_id
ORDER BY access_type;

-- Test funzione di accesso
SELECT
    'TEST FUNZIONE ACCESSO' as Test,
    @admin_id as user_id,
    @tenant_a as tenant_a,
    @tenant_b as tenant_b,
    can_user_access_tenant(@admin_id, @tenant_a) as can_access_a,
    can_user_access_tenant(@admin_id, @tenant_b) as can_access_b;

ROLLBACK;
SELECT 'TEST MULTI-TENANT COMPLETATO (ROLLBACK)' as Status;
```

---

## Gerarchia Ruoli

```
┌─────────────────────────────────────────────────────────────┐
│                       SUPER ADMIN                            │
│  - tenant_id: NULL                                           │
│  - Accesso: TUTTI i tenant                                   │
│  - Permessi: Gestione sistema completa                       │
│  - Può creare/modificare/eliminare tenant                    │
│  - Può gestire tutti gli utenti                              │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │ eredita tutti i permessi
                            │
┌─────────────────────────────────────────────────────────────┐
│                          ADMIN                               │
│  - tenant_id: Azienda primaria (NOT NULL)                    │
│  - Accesso: Azienda primaria + user_tenant_access           │
│  - Permessi: Gestione completa multi-tenant                  │
│  - Può approvare documenti                                   │
│  - Può gestire utenti nei propri tenant                      │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │ eredita permessi base
                            │
┌─────────────────────────────────────────────────────────────┐
│                         MANAGER                              │
│  - tenant_id: Azienda unica (NOT NULL)                       │
│  - Accesso: SOLO la propria azienda                          │
│  - Permessi: Gestione operativa                              │
│  - Può approvare documenti                                   │
│  - CRUD completo sui dati del tenant                         │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │ permessi limitati
                            │
┌─────────────────────────────────────────────────────────────┐
│                          USER                                │
│  - tenant_id: Azienda unica (NOT NULL)                       │
│  - Accesso: SOLO la propria azienda                          │
│  - Permessi: Visualizzazione e creazione                     │
│  - NON può approvare documenti                               │
│  - CRUD limitato (propri dati)                               │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │ permessi minimi
                            │
┌─────────────────────────────────────────────────────────────┐
│                          GUEST                               │
│  - tenant_id: Azienda unica (NOT NULL)                       │
│  - Accesso: SOLO la propria azienda                          │
│  - Permessi: Solo visualizzazione                            │
│  - Accesso temporaneo/limitato                               │
└─────────────────────────────────────────────────────────────┘
```

### Matrice Permessi

| Azione | Super Admin | Admin | Manager | User | Guest |
|--------|-------------|-------|---------|------|-------|
| Vedere tutti i tenant | SI | NO | NO | NO | NO |
| Gestire tenant multipli | SI | SI (via access) | NO | NO | NO |
| Creare/Eliminare tenant | SI | NO | NO | NO | NO |
| Approvare documenti | SI | SI | SI | NO | NO |
| Gestire utenti | SI | SI (proprio tenant) | Limitato | NO | NO |
| Vedere dati multi-tenant | SI | SI (assigned) | NO | NO | NO |
| CRUD completo | SI | SI | SI | Limitato | NO |

---

## Query di Esempio

### Query 1: Ottenere tutti i tenant accessibili da un utente

```sql
SELECT DISTINCT
    t.id,
    t.denominazione,
    t.partita_iva,
    t.email,
    CASE
        WHEN u.tenant_id = t.id THEN 'PRIMARY'
        WHEN u.role = 'super_admin' THEN 'SUPER_ADMIN'
        ELSE uta.role_in_tenant
    END as role_in_tenant,
    CASE
        WHEN u.role = 'super_admin' THEN 'All Tenants'
        WHEN u.tenant_id = t.id THEN 'Primary Company'
        ELSE 'Additional Access'
    END as access_type
FROM users u
CROSS JOIN tenants t
LEFT JOIN user_tenant_access uta ON (u.id = uta.user_id AND t.id = uta.tenant_id)
WHERE u.id = ? -- parametro user_id
    AND (
        u.role = 'super_admin'  -- Super admin vede tutti
        OR u.tenant_id = t.id    -- Tenant primario
        OR uta.tenant_id = t.id  -- Tenant addizionali
    )
ORDER BY access_type, t.denominazione;
```

### Query 2: Verificare se utente può accedere a specifico tenant

```sql
SELECT
    CASE
        WHEN u.role = 'super_admin' THEN TRUE
        WHEN u.tenant_id = ? THEN TRUE -- parametro tenant_id
        WHEN EXISTS(
            SELECT 1
            FROM user_tenant_access
            WHERE user_id = u.id AND tenant_id = ? -- parametro tenant_id
        ) THEN TRUE
        ELSE FALSE
    END as can_access
FROM users u
WHERE u.id = ? -- parametro user_id
LIMIT 1;
```

### Query 3: Ottenere statistiche utenti per tenant

```sql
SELECT
    t.id,
    t.denominazione,
    COUNT(DISTINCT u.id) as primary_users,
    COUNT(DISTINCT uta.user_id) as additional_admins,
    (SELECT CONCAT(first_name, ' ', last_name)
     FROM users
     WHERE id = t.manager_id) as manager_name
FROM tenants t
LEFT JOIN users u ON t.id = u.tenant_id
LEFT JOIN user_tenant_access uta ON t.id = uta.tenant_id
GROUP BY t.id, t.denominazione, t.manager_id
ORDER BY t.denominazione;
```

### Query 4: Audit log accessi multi-tenant

```sql
SELECT
    u.email,
    u.role,
    t_primary.denominazione as primary_company,
    GROUP_CONCAT(
        DISTINCT CONCAT(t_access.denominazione, ' (', uta.role_in_tenant, ')')
        ORDER BY t_access.denominazione
        SEPARATOR ', '
    ) as additional_access
FROM users u
LEFT JOIN tenants t_primary ON u.tenant_id = t_primary.id
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t_access ON uta.tenant_id = t_access.id
WHERE u.role IN ('admin', 'super_admin')
GROUP BY u.id, u.email, u.role, t_primary.denominazione
ORDER BY u.role, u.email;
```

### Query 5: Trovare aziende senza manager assegnato

```sql
SELECT
    t.id,
    t.denominazione,
    t.email,
    t.rappresentante_legale,
    CASE
        WHEN t.manager_id IS NULL THEN 'NO MANAGER'
        WHEN u.id IS NULL THEN 'INVALID MANAGER_ID'
        ELSE CONCAT(u.first_name, ' ', u.last_name)
    END as manager_status
FROM tenants t
LEFT JOIN users u ON t.manager_id = u.id
WHERE t.manager_id IS NULL OR u.id IS NULL;
```

---

## Note Implementazione PHP

### Esempio: Auth middleware con nuovo sistema

```php
// File: includes/auth_check_tenant.php

function canUserAccessTenant($userId, $tenantId) {
    global $db;

    $user = $db->fetchOne(
        "SELECT id, role, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL",
        [$userId]
    );

    if (!$user) {
        return false;
    }

    // Super admin accede a tutto
    if ($user['role'] === 'super_admin') {
        return true;
    }

    // Tenant primario
    if ($user['tenant_id'] == $tenantId) {
        return true;
    }

    // Verifica multi-tenant access (solo per admin)
    if ($user['role'] === 'admin') {
        $access = $db->fetchOne(
            "SELECT id FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?",
            [$userId, $tenantId]
        );
        return !empty($access);
    }

    return false;
}

function getUserAccessibleTenants($userId) {
    global $db;

    $user = $db->fetchOne(
        "SELECT id, role, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL",
        [$userId]
    );

    if (!$user) {
        return [];
    }

    // Super admin vede tutti i tenant
    if ($user['role'] === 'super_admin') {
        return $db->fetchAll("SELECT * FROM tenants WHERE status = 'active'");
    }

    // Tenant primario + accessi addizionali
    $query = "
        SELECT DISTINCT t.*, 'primary' as access_type
        FROM tenants t
        WHERE t.id = ?
        UNION
        SELECT DISTINCT t.*, 'additional' as access_type
        FROM tenants t
        INNER JOIN user_tenant_access uta ON t.id = uta.tenant_id
        WHERE uta.user_id = ?
        ORDER BY access_type, denominazione
    ";

    return $db->fetchAll($query, [$user['tenant_id'], $userId]);
}
```

### Esempio: Query con tenant isolation

```php
// VECCHIO PATTERN (non gestisce super_admin)
$files = $db->fetchAll(
    "SELECT * FROM files WHERE tenant_id = ? AND deleted_at IS NULL",
    [$_SESSION['tenant_id']]
);

// NUOVO PATTERN (gestisce gerarchia ruoli)
$currentUser = $auth->getCurrentUser();

if ($currentUser['role'] === 'super_admin') {
    // Super admin vede tutti i file (opzionalmente filtrare per tenant selezionato)
    $tenantFilter = isset($_GET['tenant_id']) ? "WHERE tenant_id = ?" : "WHERE 1=1";
    $params = isset($_GET['tenant_id']) ? [$_GET['tenant_id']] : [];
    $files = $db->fetchAll(
        "SELECT * FROM files $tenantFilter AND deleted_at IS NULL",
        $params
    );
} else {
    // Utenti normali: solo il proprio tenant
    $files = $db->fetchAll(
        "SELECT * FROM files WHERE tenant_id = ? AND deleted_at IS NULL",
        [$currentUser['tenant_id']]
    );
}
```

---

## Checklist Migrazione

- [ ] Backup completo database effettuato
- [ ] Verificato MySQL versione >= 8.0.16 (per CHECK constraints)
- [ ] Eseguito script `migrate_aziende_ruoli_sistema.sql`
- [ ] Verificato successo migrazione con `test_aziende_migration_integrity.sql`
- [ ] Testato creazione nuove aziende con `test_new_company_creation.sql`
- [ ] Testato multi-tenant access con `test_multi_tenant_access.sql`
- [ ] Aggiornato codice PHP per gestire `super_admin`
- [ ] Aggiornato codice PHP per gestire `tenant_id NULL`
- [ ] Aggiornato form creazione aziende con nuovi campi
- [ ] Aggiornato form creazione utenti con gestione ruoli
- [ ] Implementato tenant switcher per admin/super_admin
- [ ] Testato funzionalità con tutti i ruoli (super_admin, admin, manager, user)
- [ ] Verificato tenant isolation (utenti vedono solo i propri dati)
- [ ] Documentato breaking changes per il team
- [ ] Aggiornato manuale utente

---

## Supporto e Troubleshooting

### Errore: "Cannot add foreign key constraint"

**Causa:** Manager non esiste quando si crea tenant.

**Soluzione:**
```sql
-- Creare prima l'utente, poi il tenant, poi aggiornare tenant_id
INSERT INTO users (...) VALUES (...);
SET @mgr = LAST_INSERT_ID();
INSERT INTO tenants (manager_id, ...) VALUES (@mgr, ...);
SET @ten = LAST_INSERT_ID();
UPDATE users SET tenant_id = @ten WHERE id = @mgr;
```

### Errore: "Check constraint 'chk_tenant_fiscal_code' is violated"

**Causa:** Tentativo di creare tenant senza CF né P.IVA.

**Soluzione:**
```sql
-- Almeno uno dei due deve essere presente
INSERT INTO tenants (denominazione, codice_fiscale, ...)
-- OPPURE
INSERT INTO tenants (denominazione, partita_iva, ...)
```

### Errore: Query restituisce dati di altri tenant

**Causa:** Manca filtro `tenant_id` o gestione super_admin.

**Soluzione:** Usare pattern con controllo ruolo prima di costruire query.

---

**Fine Documentazione**
