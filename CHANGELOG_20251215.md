# Changelog - CollaboraNexio
## Sessione 2025-12-15

---

## Riepilogo Generale

| Metrica | Valore |
|---------|--------|
| Bug Risolti | 2 (BUG-153, BUG-154) |
| Feature Implementate | 1 (Tenant Roles / Ruoli Aziendali) |
| File Creati | 9 |
| File Modificati | 12 |
| Tabelle Database | +1 (tenant_roles) |
| Colonne Aggiunte | +2 (user_tenant_access.tenant_role_id, tenants.has_custom_roles) |
| Foreign Keys | +3 |
| Backup | CollaboraNexio_backup_20251215.tar.gz (2.1MB) |

---

## 1. BUG-153: Ticket Respond 403 Error

**Problema:** Quando un ticket veniva chiuso, la sezione risposta rimaneva visibile. Tentando di rispondere, l'API restituiva 403 Forbidden.

**Soluzione:** Nascondere la sezione risposta e mostrare un notice quando il ticket e chiuso.

**File Modificati:**
- `/assets/js/tickets.js` - Logica per nascondere reply section
- `/ticket.php` - Aggiunto elemento HTML per notice chiusura

---

## 2. BUG-154: Ticket Email Notification Gap

**Problema:** Quando lo stato di un ticket cambiava via `/api/tickets/update.php`, solo il creator riceveva l'email. L'utente assegnato NON veniva notificato.

**Soluzione:** Sostituito il metodo legacy `sendStatusChangedNotification()` con `sendTicketStatusChangedNotification()` che notifica sia il creator che l'assegnatario.

**File Modificati:**
- `/api/tickets/update.php` - Linee 255-264

---

## 3. FEATURE: Tenant Roles (Ruoli Aziendali)

### Descrizione
Nuova funzionalita che permette alle aziende di definire ruoli personalizzati (es. Commerciale, Tecnico, Amministrativo) e assegnarli agli utenti. Separazione tra "Tipo Utente" (ruolo di sistema) e "Ruolo Aziendale" (ruolo custom).

### Database - Migration 19

**Nuova Tabella: `tenant_roles`**
```sql
CREATE TABLE tenant_roles (
    id INT UNSIGNED AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,      -- FK CASCADE
    name VARCHAR(100) NOT NULL,           -- "Commerciale"
    code VARCHAR(50) NOT NULL,            -- "commerciale"
    description TEXT NULL,
    color VARCHAR(7) DEFAULT '#6366f1',   -- Colore badge
    icon VARCHAR(50) NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    created_by INT UNSIGNED NULL          -- FK SET NULL
);
```

**Colonne Aggiunte:**
- `user_tenant_access.tenant_role_id` INT UNSIGNED NULL (FK to tenant_roles SET NULL)
- `tenants.has_custom_roles` TINYINT(1) DEFAULT 0

**File Migration:**
- `/database/migrations/19_add_tenant_roles.sql`
- `/database/migrations/19_add_tenant_roles_rollback.sql`

### API - Nuovi Endpoint

| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `/api/tenant-roles/list.php` | GET | Lista ruoli per tenant |
| `/api/tenant-roles/create.php` | POST | Crea nuovo ruolo |
| `/api/tenant-roles/update.php` | POST | Modifica ruolo |
| `/api/tenant-roles/delete.php` | POST | Soft delete ruolo |

**Parametri Create:**
```json
{
    "tenant_id": 11,
    "name": "Commerciale",
    "description": "Gestione clienti e vendite",
    "color": "#10b981"
}
```

**Parametri Update:**
```json
{
    "role_id": 1,
    "name": "Commerciale Senior",
    "color": "#059669",
    "is_active": true
}
```

### API - Modifiche Esistenti

**`/api/users/list.php`**
- Aggiunto LEFT JOIN a tenant_roles
- Response include oggetto `tenant_role` con id, name, code, color

**`/api/users/create.php`**
- Nuovo parametro `tenant_role_id`
- Validazione: se tenant ha ruoli custom, tenant_role_id e obbligatorio
- Manager puo creare solo utenti con role='user'

**`/api/users/update.php`**
- Nuovo parametro `tenant_role_id`
- Valore -1 per rimuovere il ruolo
- Validazione tenant_role appartiene stesso tenant

### Frontend - utenti.php

**Modifiche Terminologia:**
- "Ruolo" rinominato in "Tipo Utente" (per ruoli di sistema)
- Aggiunta colonna "Ruolo Aziendale" nella tabella utenti

**Nuove Funzionalita:**
- Badge colorato per ruolo aziendale
- Dropdown condizionale nel modal crea/modifica utente
- Funzioni JS: `getTenantRoleDisplay()`, `loadTenantRoles()`, `showTenantRoleGroup()`, `hideTenantRoleGroup()`

### Frontend - aziende.php

**Nuovo Modal #rolesModal:**
- Toggle per abilitare/disabilitare ruoli custom
- Tabella CRUD per gestione ruoli
- Color picker con preview live del badge
- Protezione eliminazione ruoli con utenti assegnati
- Funzioni JS: `openRolesModal()`, `loadTenantRoles()`, `saveRole()`, `deleteRole()`

---

## File Creati (9)

| File | Tipo | Linee |
|------|------|-------|
| `/api/tenant-roles/list.php` | API | ~200 |
| `/api/tenant-roles/create.php` | API | ~330 |
| `/api/tenant-roles/update.php` | API | ~390 |
| `/api/tenant-roles/delete.php` | API | ~250 |
| `/database/migrations/19_add_tenant_roles.sql` | SQL | ~180 |
| `/database/migrations/19_add_tenant_roles_rollback.sql` | SQL | ~80 |
| `/docs/TENANT_ROLES_ARCHITECTURE.md` | Doc | ~860 |
| `/backups/CollaboraNexio_backup_20251215.tar.gz` | Backup | 2.1MB |
| `/CHANGELOG_20251215.md` | Doc | questo file |

---

## File Modificati (12)

| File | Modifiche |
|------|-----------|
| `/assets/js/tickets.js` | BUG-153: hide reply section when closed |
| `/ticket.php` | BUG-153: closed ticket notice HTML |
| `/api/tickets/update.php` | BUG-154: use new notification method |
| `/api/users/list.php` | tenant_role JOIN and response |
| `/api/users/create.php` | tenant_role_id parameter |
| `/api/users/update.php` | tenant_role_id parameter |
| `/utenti.php` | Tipo Utente + Ruolo Aziendale UI |
| `/aziende.php` | Role management modal |
| `/bug.md` | BUG-153, BUG-154, FEATURE entries |
| `/progression.md` | Session documentation |
| `/CLAUDE.md` | Database status, feature docs |
| `/docs/TENANT_ROLES_ARCHITECTURE.md` | Architecture document |

---

## Statistiche Database Post-Implementazione

| Metrica | Prima | Dopo |
|---------|-------|------|
| Tabelle | 69 | 70 |
| Foreign Keys | 209 | 212 |
| Indici | ~430 | ~438 |
| Multi-tenant tables | 61 | 62 |
| Soft delete tables | 57 | 58 |

---

## Come Testare

### Test Ruoli Aziendali

1. **Abilitare ruoli per un'azienda:**
   - Aprire `aziende.php`
   - Cliccare icona utenti (gestione ruoli) su un'azienda
   - Attivare toggle "Abilita ruoli aziendali personalizzati"

2. **Creare un ruolo:**
   - Cliccare "Aggiungi Ruolo"
   - Inserire nome (es. "Commerciale")
   - Selezionare colore
   - Salvare

3. **Assegnare ruolo a utente:**
   - Aprire `utenti.php`
   - Modificare un utente dell'azienda con ruoli
   - Selezionare "Ruolo Aziendale" dal dropdown
   - Salvare

4. **Verificare:**
   - Badge colorato visibile nella lista utenti
   - Dropdown ruolo appare solo per aziende con ruoli attivi

### Test Bug Fix

1. **BUG-153:**
   - Chiudere un ticket
   - Riaprire il dettaglio del ticket
   - Verificare che la sezione risposta sia nascosta
   - Verificare che appaia il notice "Ticket chiuso"

2. **BUG-154:**
   - Assegnare un ticket a un utente
   - Cambiare lo stato del ticket
   - Verificare che sia il creator che l'assegnatario ricevano email

---

## Rollback (se necessario)

### Database
```bash
mysql -u root collaboranexio < database/migrations/19_add_tenant_roles_rollback.sql
```

### File
Ripristinare dal backup:
```bash
cd /mnt/c/xampp/htdocs
tar -xzf backups/CollaboraNexio_backup_20251215.tar.gz
```

---

## Note Tecniche

### Pattern Utilizzati

1. **Multi-tenant:** Tutte le query includono `tenant_id` e `deleted_at IS NULL`
2. **Soft delete:** Eliminazione tramite `deleted_at` timestamp
3. **CSRF:** Token validato su tutte le API
4. **Transaction:** Operazioni DB in transazione con rollback su errore
5. **Audit:** Log delle operazioni critiche

### Autorizzazioni API

| Endpoint | admin | manager | user |
|----------|-------|---------|------|
| tenant-roles/list | Si | Si (proprio tenant) | No |
| tenant-roles/create | Si | No | No |
| tenant-roles/update | Si | No | No |
| tenant-roles/delete | Si | No | No |

### Terminologia Finale

| Termine UI | Colonna DB | Valori |
|------------|-----------|--------|
| Tipo Utente | users.role | super_admin, admin, manager, user |
| Ruolo Aziendale | tenant_roles.name | Custom per azienda |

---

**Autore:** Claude Code
**Data:** 2025-12-15
**Versione CollaboraNexio:** Post-Migration 19
**Total Bugs Resolved:** 154
