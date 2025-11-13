# Sistema Gestione Aziende e Ruoli Multi-Tenant - README

## PANORAMICA

Questa migrazione introduce un sistema completo di gestione aziende con ruoli gerarchici e supporto multi-tenant avanzato per CollaboraNexio.

### COSA CAMBIA

1. **Tabella `tenants`**: Trasformata in gestione aziende completa con dati fiscali, sedi, manager
2. **Tabella `users`**: Aggiunto ruolo `super_admin` e supporto `tenant_id NULL`
3. **Tabella `user_tenant_access`**: Aggiunto campo `role_in_tenant` per ruoli specifici

---

## FILE DELLA MIGRAZIONE

### File Principali

| File | Scopo | Quando Usare |
|------|-------|--------------|
| `migrate_aziende_ruoli_sistema.sql` | Script migrazione completo | ESEGUIRE PRIMA |
| `AZIENDE_MIGRATION_DOCUMENTATION.md` | Documentazione completa (ERD, mapping, esempi) | Consultazione sviluppo |
| `test_aziende_migration_integrity.sql` | Test integrità post-migrazione | ESEGUIRE DOPO migrazione |
| `AZIENDE_MIGRATION_README.md` | Questo file - quick start | Quick reference |

### Test Aggiuntivi

- `test_new_company_creation.sql` - Test creazione aziende (incluso in documentazione)
- `test_multi_tenant_access.sql` - Test accessi multi-tenant (incluso in documentazione)

---

## QUICK START - ESECUZIONE MIGRAZIONE

### PASSO 1: BACKUP

```bash
# OBBLIGATORIO: Backup database prima della migrazione
cd /mnt/c/xampp/htdocs/CollaboraNexio/database
mysqldump -u root -p collaboranexio > backup_before_migration_$(date +%Y%m%d_%H%M%S).sql
```

### PASSO 2: VERIFICA PRE-REQUISITI

```bash
# Verifica versione MySQL (richiesto >= 8.0.16 per CHECK constraints)
mysql -u root -p -e "SELECT VERSION();"

# Verifica stato attuale
mysql -u root -p collaboranexio -e "
    SELECT COUNT(*) as tenants FROM tenants;
    SELECT COUNT(*) as users FROM users;
    SELECT COUNT(*) as access_records FROM user_tenant_access;
"
```

### PASSO 3: ESECUZIONE MIGRAZIONE

```bash
# Esegui lo script di migrazione
mysql -u root -p collaboranexio < /mnt/c/xampp/htdocs/CollaboraNexio/database/migrate_aziende_ruoli_sistema.sql

# OPPURE da PHP:
php -r "
require_once '/mnt/c/xampp/htdocs/CollaboraNexio/includes/db.php';
\$db = Database::getInstance();
\$sql = file_get_contents('/mnt/c/xampp/htdocs/CollaboraNexio/database/migrate_aziende_ruoli_sistema.sql');
\$db->getConnection()->exec(\$sql);
echo 'Migrazione completata\n';
"
```

### PASSO 4: VERIFICA INTEGRITÀ

```bash
# Esegui test integrità
mysql -u root -p collaboranexio < /mnt/c/xampp/htdocs/CollaboraNexio/database/test_aziende_migration_integrity.sql > migration_test_results.txt

# Controlla risultati
cat migration_test_results.txt | grep -E "ERROR|WARNING|ISSUE"
```

### PASSO 5: VERIFICA VISUALE

Accedi al sistema e verifica:
- [ ] Login funziona correttamente
- [ ] Utenti vedono la propria azienda
- [ ] Admin possono switchare tenant (se hanno multi-tenant access)
- [ ] Creazione nuovi utenti funziona
- [ ] Creazione nuove aziende funziona

---

## GERARCHIA RUOLI IMPLEMENTATA

```
SUPER ADMIN (tenant_id NULL)
    │
    ├─ Accesso: TUTTI i tenant
    ├─ Permessi: Gestione sistema completa
    └─ Può gestire tenant e utenti globalmente

ADMIN (tenant_id NOT NULL + user_tenant_access)
    │
    ├─ Accesso: Tenant primario + tenant addizionali
    ├─ Permessi: Gestione completa multi-tenant
    └─ Può approvare documenti

MANAGER (tenant_id NOT NULL)
    │
    ├─ Accesso: SOLO il proprio tenant
    ├─ Permessi: Gestione operativa
    └─ Può approvare documenti

USER (tenant_id NOT NULL)
    │
    ├─ Accesso: SOLO il proprio tenant
    ├─ Permessi: Visualizzazione e creazione
    └─ NON può approvare documenti

GUEST (tenant_id NOT NULL)
    │
    ├─ Accesso: SOLO il proprio tenant
    ├─ Permessi: Solo visualizzazione
    └─ Accesso limitato/temporaneo
```

---

## NUOVI CAMPI TENANTS (AZIENDE)

### Identificazione

- `denominazione` (VARCHAR 255, NOT NULL) - Ragione sociale
- `codice_fiscale` (VARCHAR 16, NULL) - Codice fiscale
- `partita_iva` (VARCHAR 11, NULL) - Partita IVA
- **Constraint**: Almeno uno tra CF e P.IVA obbligatorio

### Sede Legale (Indirizzo Completo)

- `sede_legale_indirizzo` (VARCHAR 255)
- `sede_legale_civico` (VARCHAR 10)
- `sede_legale_comune` (VARCHAR 100)
- `sede_legale_provincia` (VARCHAR 2)
- `sede_legale_cap` (VARCHAR 5)

### Sedi Operative

- `sedi_operative` (JSON) - Array di oggetti con multiple sedi

Esempio JSON:
```json
[
  {
    "indirizzo": "Via Roma",
    "civico": "123",
    "comune": "Milano",
    "provincia": "MI",
    "cap": "20100"
  },
  {
    "indirizzo": "Corso Vittorio",
    "civico": "45",
    "comune": "Roma",
    "provincia": "RM",
    "cap": "00100"
  }
]
```

### Informazioni Aziendali

- `settore_merceologico` (VARCHAR 100)
- `numero_dipendenti` (INT)
- `capitale_sociale` (DECIMAL 15,2)

### Contatti

- `telefono` (VARCHAR 20)
- `email` (VARCHAR 255)
- `pec` (VARCHAR 255)

### Gestione

- `manager_id` (INT UNSIGNED, FK to users.id) - Responsabile aziendale
- `rappresentante_legale` (VARCHAR 255) - Nome rappresentante legale

---

## MODIFICHE TABELLA USERS

### Campo `role`

**PRIMA:**
```sql
role ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'user'
```

**DOPO:**
```sql
role ENUM('super_admin', 'admin', 'manager', 'user', 'guest') DEFAULT 'user'
```

### Campo `tenant_id`

**PRIMA:**
```sql
tenant_id INT UNSIGNED NOT NULL
```

**DOPO:**
```sql
tenant_id INT UNSIGNED NULL  -- Nullable per super_admin
```

---

## MODIFICHE TABELLA USER_TENANT_ACCESS

### Nuovo Campo: `role_in_tenant`

Permette di specificare il ruolo che l'utente ha in uno specifico tenant.

```sql
role_in_tenant ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'admin'
```

**Esempio:**
Un admin può avere ruolo 'admin' nel Tenant A e ruolo 'manager' nel Tenant B.

---

## BREAKING CHANGES E MITIGAZIONI

### 1. users.tenant_id ora nullable

**Problema:**
```php
// VECCHIO CODICE - Fallisce con super_admin
$users = $db->fetchAll("SELECT * FROM users WHERE tenant_id = ?", [$tenantId]);
```

**Soluzione:**
```php
// NUOVO CODICE - Gestisce super_admin
if ($currentUser['role'] === 'super_admin') {
    $users = $db->fetchAll("SELECT * FROM users WHERE 1=1");
} else {
    $users = $db->fetchAll("SELECT * FROM users WHERE tenant_id = ?", [$tenantId]);
}
```

### 2. Nuovo ruolo 'super_admin'

**Problema:**
```php
// VECCHIO CODICE - Non considera super_admin
if ($role === 'admin') { /* permessi elevati */ }
```

**Soluzione:**
```php
// NUOVO CODICE - Include super_admin
if (in_array($role, ['super_admin', 'admin'])) { /* permessi elevati */ }
```

### 3. tenants.manager_id foreign key

**Problema:**
Non è possibile creare tenant prima dell'utente manager.

**Soluzione:**
```php
// 1. Creare utente manager con tenant temporaneo
$managerId = createUser($email, $password, 'manager', 1);

// 2. Creare tenant
$tenantId = createTenant($denominazione, $cf, $piva, $managerId);

// 3. Aggiornare tenant_id dell'utente
updateUser($managerId, ['tenant_id' => $tenantId]);
```

---

## ESEMPI CODICE PHP

### Verifica Accesso Tenant

```php
function canUserAccessTenant($userId, $tenantId) {
    global $db;

    $user = $db->fetchOne(
        "SELECT role, tenant_id FROM users WHERE id = ?",
        [$userId]
    );

    if (!$user) return false;

    // Super admin accede a tutto
    if ($user['role'] === 'super_admin') {
        return true;
    }

    // Tenant primario
    if ($user['tenant_id'] == $tenantId) {
        return true;
    }

    // Multi-tenant access (solo admin)
    if ($user['role'] === 'admin') {
        $access = $db->fetchOne(
            "SELECT id FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?",
            [$userId, $tenantId]
        );
        return !empty($access);
    }

    return false;
}
```

### Creazione Azienda Completa

```php
function createCompanyWithManager($data) {
    global $db;

    $db->beginTransaction();

    try {
        // 1. Creare manager temporaneo
        $managerId = $db->insert('users', [
            'tenant_id' => 1, // temporaneo
            'email' => $data['manager_email'],
            'password_hash' => password_hash($data['manager_password'], PASSWORD_BCRYPT),
            'first_name' => $data['manager_first_name'],
            'last_name' => $data['manager_last_name'],
            'role' => 'manager',
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s')
        ]);

        // 2. Creare tenant
        $tenantId = $db->insert('tenants', [
            'name' => $data['denominazione'],
            'denominazione' => $data['denominazione'],
            'codice_fiscale' => $data['codice_fiscale'],
            'partita_iva' => $data['partita_iva'],
            'sede_legale_indirizzo' => $data['sede_legale_indirizzo'],
            'sede_legale_civico' => $data['sede_legale_civico'],
            'sede_legale_comune' => $data['sede_legale_comune'],
            'sede_legale_provincia' => $data['sede_legale_provincia'],
            'sede_legale_cap' => $data['sede_legale_cap'],
            'telefono' => $data['telefono'],
            'email' => $data['email'],
            'pec' => $data['pec'],
            'manager_id' => $managerId,
            'rappresentante_legale' => $data['rappresentante_legale'],
            'status' => 'active'
        ]);

        // 3. Aggiornare tenant_id del manager
        $db->update('users', ['tenant_id' => $tenantId], ['id' => $managerId]);

        $db->commit();

        return [
            'success' => true,
            'tenant_id' => $tenantId,
            'manager_id' => $managerId
        ];

    } catch (Exception $e) {
        $db->rollback();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
```

### Query Tenant Accessibili

```php
function getUserAccessibleTenants($userId) {
    global $db;

    $user = $db->fetchOne(
        "SELECT role, tenant_id FROM users WHERE id = ?",
        [$userId]
    );

    if (!$user) return [];

    // Super admin vede tutti i tenant
    if ($user['role'] === 'super_admin') {
        return $db->fetchAll("SELECT * FROM tenants WHERE status = 'active'");
    }

    // Tenant primario + multi-tenant access
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

---

## QUERY SQL UTILI

### Ottenere tutti i tenant di un utente

```sql
SELECT DISTINCT
    t.id,
    t.denominazione,
    t.partita_iva,
    CASE
        WHEN u.tenant_id = t.id THEN 'PRIMARY'
        WHEN u.role = 'super_admin' THEN 'SUPER_ADMIN'
        ELSE uta.role_in_tenant
    END as role_type
FROM users u
CROSS JOIN tenants t
LEFT JOIN user_tenant_access uta ON (u.id = uta.user_id AND t.id = uta.tenant_id)
WHERE u.id = 1 -- user_id
    AND (
        u.role = 'super_admin'
        OR u.tenant_id = t.id
        OR uta.tenant_id = t.id
    );
```

### Statistiche utenti per tenant

```sql
SELECT
    t.denominazione,
    COUNT(DISTINCT u.id) as primary_users,
    COUNT(DISTINCT uta.user_id) as additional_admins,
    u_mgr.email as manager_email
FROM tenants t
LEFT JOIN users u ON t.id = u.tenant_id
LEFT JOIN user_tenant_access uta ON t.id = uta.tenant_id
LEFT JOIN users u_mgr ON t.manager_id = u_mgr.id
GROUP BY t.id, t.denominazione, u_mgr.email;
```

### Audit multi-tenant access

```sql
SELECT
    u.email,
    u.role,
    t_primary.denominazione as primary_company,
    GROUP_CONCAT(
        CONCAT(t_access.denominazione, ' (', uta.role_in_tenant, ')')
        SEPARATOR ', '
    ) as additional_access
FROM users u
LEFT JOIN tenants t_primary ON u.tenant_id = t_primary.id
LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
LEFT JOIN tenants t_access ON uta.tenant_id = t_access.id
WHERE u.role IN ('admin', 'super_admin')
GROUP BY u.id;
```

---

## ROLLBACK

In caso di problemi, il rollback è incluso in `migrate_aziende_ruoli_sistema.sql` (SECTION 12).

### Rollback Manuale

```sql
START TRANSACTION;

-- Revert users
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'user';
ALTER TABLE users MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;
DROP INDEX IF EXISTS idx_users_role_tenant ON users;

-- Revert tenants
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
```

---

## CHECKLIST POST-MIGRAZIONE

### Verifica Tecnica

- [ ] Tutti i test in `test_aziende_migration_integrity.sql` passati
- [ ] Nessun errore in `migration_test_results.txt`
- [ ] Foreign keys corrette (`SHOW CREATE TABLE tenants;`)
- [ ] Indici creati correttamente
- [ ] CHECK constraint su `codice_fiscale/partita_iva` attivo

### Verifica Dati

- [ ] Tutti i tenant hanno `denominazione` popolato
- [ ] Nessun utente non-super_admin con `tenant_id NULL`
- [ ] Nessun super_admin con `tenant_id NOT NULL`
- [ ] Manager assegnati ai tenant (se applicabile)

### Verifica Codice

- [ ] Aggiornato `includes/auth_simple.php` per gestire super_admin
- [ ] Aggiornato codice controllo permessi (include super_admin)
- [ ] Query con tenant_id gestiscono NULL correttamente
- [ ] Form creazione aziende include nuovi campi
- [ ] Form creazione utenti gestisce ruoli corretti

### Verifica Funzionale

- [ ] Login funziona per tutti i ruoli
- [ ] Super admin vede tutti i tenant
- [ ] Admin può switchare tenant (se ha multi-tenant access)
- [ ] Manager/User vedono solo il proprio tenant
- [ ] Creazione nuova azienda funziona
- [ ] Assegnazione manager a tenant funziona
- [ ] Multi-tenant access per admin funziona

---

## SUPPORTO

### Log Errors

Controllare log per errori:
```bash
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

### Verifica MySQL

```bash
# Verifica constraint
mysql -u root -p collaboranexio -e "SHOW CREATE TABLE tenants\G"
mysql -u root -p collaboranexio -e "SHOW CREATE TABLE users\G"

# Verifica indici
mysql -u root -p collaboranexio -e "SHOW INDEX FROM tenants;"
mysql -u root -p collaboranexio -e "SHOW INDEX FROM users;"
```

### Problemi Comuni

**Errore: "Cannot add foreign key constraint"**
- Verificare che manager esista prima di creare tenant
- Seguire ordine: creare user → creare tenant → aggiornare user.tenant_id

**Errore: "Check constraint violated"**
- Almeno uno tra `codice_fiscale` e `partita_iva` deve essere presente
- Non lasciare entrambi NULL

**Errore: Query restituisce dati di altri tenant**
- Verificare gestione super_admin nelle query
- Aggiungere controllo ruolo prima di costruire WHERE clause

---

## CONTATTI E RIFERIMENTI

- **Documentazione Completa**: `AZIENDE_MIGRATION_DOCUMENTATION.md`
- **Schema ERD**: Vedi sezione "Schema ERD Testuale" nella documentazione
- **Esempi Query**: Vedi sezione "Query di Esempio" nella documentazione
- **Project Instructions**: `/mnt/c/xampp/htdocs/CollaboraNexio/CLAUDE.md`

---

**IMPORTANTE**: Questa migrazione è IRREVERSIBILE senza rollback. Assicurarsi di aver effettuato il backup completo prima di procedere.

---

**Fine README**
