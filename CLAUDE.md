# CLAUDE.md

Guida operativa (per agenti AI e sviluppatori) per lavorare su **CollaboraNexio**.

CollaboraNexio è una piattaforma multi-tenant per aziende italiane: gestione documentale, workflow approvativi/validazione, calendario aziendale, task management, ticketing, audit log, notifiche email e integrazione con **OnlyOffice**.

> Obiettivo di questo file: descrivere **in modo dettagliato** cosa fa la piattaforma e fissare le **regole non negoziabili** che evitano regressioni (bug storici) su multi-tenancy, sicurezza, API/JS e DB.

---

## 1) Ambienti, URL, stack

- **Dev locale (XAMPP/Windows)**: `http://localhost:8888/CollaboraNexio`
- **Produzione (Cloudflare)**: `https://app.nexiosolution.it/CollaboraNexio`

### Stack
- **Backend**: PHP “vanilla” (senza framework)
- **DB**: MySQL/MariaDB 10.4+
- **OnlyOffice Document Server**: Docker (locale o remoto)
- **Frontend**: HTML/CSS/JS vanilla, `fetch()` verso endpoint PHP

### Configurazione
- `config.php`: sviluppo
- `config.production.php`: produzione
- Auto-detection via hostname/ambiente

### Health / debug rapidi
- `system_check.php`: check di salute (dev)
- Log runtime:
  - `logs/php_errors.log`
  - `logs/database_errors.log`
  - `logs/mailer_error.log`
  - `logs/ticket_deletions.log`

> **Nota sicurezza**: le credenziali demo (se presenti) sono per ambienti controllati. In produzione usare credenziali reali e rotazione password.

---

## 2) Cosa fa la piattaforma (feature complete)

### 2.1 Gestione autenticazione e sessioni
- Login via `api/auth.php?action=login` (JSON)
- Sessione PHP server-side con `includes/session_init.php`
- In sessione vengono mantenuti:
  - `user_id`, `user_name`, `user_email`
  - `tenant_id` (azienda primaria)
  - `user_role` (ruolo di sistema)
  - info accessi multi-tenant (per admin/super_admin)
- Protezione CSRF per pagine e API.
- Policy password:
  - supporto scadenza password, warning e redirect a cambio password
  - endpoint per reinvio codice scadenza (super_admin)

### 2.2 Multi-tenancy (azienda/tenant)
- Ogni record “tenant-scoped” è legato a `tenant_id`.
- **Soft delete**: `deleted_at` su quasi tutte le tabelle.
- Vincoli FK con `ON DELETE CASCADE` dove appropriato.

#### Tipi utente (ruoli di sistema)
- `super_admin`: visibilità globale (bypass tenant isolation)
- `admin`: può gestire più aziende (multi-tenant)
- `manager`: gestisce una singola azienda
- `user`: utente standard

### 2.3 Pagine principali (UI)

- `dashboard.php`
  - widget riepilogo (documenti recenti, eventi in arrivo, etc.)
  - feed attività / metriche tenant

- `files.php`
  - file manager tenant-scoped
  - upload/download
  - assegnazioni file (workflow)
  - versioning (cartella `uploads/versions/...`)

- `calendar.php`
  - calendari multipli
  - eventi, partecipanti, RSVP
  - promemoria schedulati (cron)
  - **SHIFT-INTEGRATION**: visualizzazione turni lavoro utente (badge informativi, non cliccabili)
  - privacy: eventi “personali” filtrati per owner

- `tasks.php`
  - task con progress, scadenze
  - assegnazioni con validazione FK (assignees nello stesso tenant)
  - notifiche email task (creazione/assegnazione/update/rimozione)

- `ticket.php`
  - ticketing: creazione, assegnazione, risposta
  - stati e SLA (tempo prima risposta)
  - allegati ticket (upload e download)
  - UI “closed state”: niente reply quando ticket chiuso

- `aziende.php`
  - CRUD aziende (tenant)
  - sedi legale/operative (tabella `tenant_locations`)
  - toggle ruoli aziendali personalizzati
  - gestione ruoli aziendali (CRUD `tenant_roles`)
  - **permessi per-tenant** su chi può assegnare “Ruolo Aziendale” (vedi § 2.4)

- `utenti.php`
  - CRUD utenti
  - assegnazioni aziende (multi-tenant per admin)
  - colonna “Tipo Utente” (ruolo di sistema)
  - colonna “Ruolo Aziendale” (business role per-tenant)
  - badge colorati con contrasto automatico
  - restrizioni: un **manager non può gestire utenti admin/super_admin**

- `audit_log.php`
  - consultazione log audit (azioni create/update/delete su entità)

- `configurazioni.php`
  - impostazioni di sistema
  - **Page Visibility** (visibilità pagine per ruolo/tenant)

### 2.4 Ruoli aziendali personalizzati (Tenant Roles)

Obiettivo: per alcune aziende serve distinguere gli utenti non solo per “tipo utente” (ruolo di sistema) ma anche per **ruolo business** specifico dell’azienda.

#### Modello dati
- `tenant_roles`: definizioni ruolo business per tenant
- `user_tenant_access.tenant_role_id`: associazione (utente, tenant) → ruolo business
- `tenants.has_custom_roles`: flag abilita/disabilita feature per tenant

#### Permesso assegnazione Ruolo Aziendale (per-tenant)
Per default l’assegnazione dei ruoli business è possibile **solo al super_admin**.

- Colonna: `tenants.tenant_role_assignment_roles` (JSON array in TEXT)
  - es: `["admin","manager"]`
- Significato: per quel tenant, abilita quali ruoli di sistema possono **assegnare** un “Ruolo Aziendale” agli utenti.
- Enforcement:
  - UI: dropdown “Ruolo Aziendale” visibile solo se `can_assign_custom_roles=true`
  - API: `api/users/tenant_role.php` blocca con 403 se il ruolo corrente non è abilitato per quel tenant.

#### Endpoint principali
- `GET api/tenant-roles/list.php?tenant_id=...`
  - ritorna: `roles`, `tenant_has_custom_roles`, `can_assign_custom_roles`
- CRUD:
  - `api/tenant-roles/create.php`
  - `api/tenant-roles/update.php`
  - `api/tenant-roles/delete.php` (soft delete)
- Get/set assegnazione per-tenant:
  - `api/users/tenant_role.php` (GET/POST)

---

## 3) Regole CRITICHE (non negoziabili)

### 3.1 Multi-tenant + soft delete (BUG-072, BUG-127)
**Ogni query tenant-scoped** deve includere:

```php
WHERE tenant_id = ? AND deleted_at IS NULL
```

Eccezione: `super_admin` può bypassare l’isolamento.

### 3.2 CSRF obbligatorio ovunque (BUG-104)
- Ogni `fetch()` deve includere:
  - `credentials: 'same-origin'`
  - header `X-CSRF-Token`
- Le API devono chiamare `verifyApiCsrfToken()`.

### 3.3 Formato risposta API (BUG-066)
- Sempre `api_success([...], 'msg')`
- Le liste vanno **incapsulate** in una chiave nominata:

```php
api_success(['users' => $users], 'OK');
```

### 3.4 Transazioni e rollback (CRITICO)
- Se si è in transazione e si deve terminare con errore: **rollback prima** di `apiError/api_error`.
- Dopo stored procedure con OUT param: `closeCursor()` sempre.

### 3.5 Audit logging non-blocking
Audit log è **obbligatorio**, ma **non deve mai rompere** la logica di business:

```php
try { ... } catch (Exception $e) { error_log(...); }
```

### 3.6 Validazioni OnlyOffice (BUG-135 v3)
Prima di aprire/salvare via OnlyOffice:
- file esiste
- size > 0

### 3.7 Compatibilità migrazioni (BUG-156)
Quando introduci nuove colonne/feature:
- controlla `information_schema` prima di usare colonne opzionali
- degrada con fallback pulito

### 3.8 Mai includere file con logica esecutiva (BUG-155)
- `require_once` solo di file “lib/helper”, non di endpoint che eseguono azioni.

### 3.9 Router e fallback (BUG-137/138/139/140)
- Preferire pattern “unified endpoint” con `action` nel body
- Fallback solo su 404, non su 401/403/500.

---

## 4) Pattern di autenticazione

### 4.1 Pagine
Template minimo consigliato:

```php
<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
  header('Location: index.php');
  exit;
}
$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();
?>
```

### 4.2 API

```php
<?php
require_once __DIR__ . '/../../includes/api_auth.php';
initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
verifyApiAuthentication();
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

api_success(['ok' => true], 'OK');
?>
```

---

## 5) Database: principi e convenzioni

### 5.1 Schema tenant-scoped standard
- `tenant_id INT NOT NULL`
- `deleted_at TIMESTAMP NULL`
- `created_at`, `updated_at`
- indici su `(tenant_id, created_at)` e `(tenant_id, deleted_at)`

### 5.2 Accesso DB
Usare `Database::getInstance()`:
- `fetchOne`, `fetchAll`, `insert`, `update`

**Nota**: non esiste `$db->execute()` (BUG-145a).

### 5.3 Vincoli e unicità con soft delete
Attenzione ai vincoli `UNIQUE`: se una tabella ha unique “globale” (es. `tenant_id, location_type, is_primary`) i record soft-deleted **contano comunque**.
- Evitare pattern “soft-delete + insert” se c’è `UNIQUE` senza `deleted_at`.
- Preferire “update in place”.

---

## 6) Moduli principali (approfondimento tecnico)

### 6.1 Documenti e File Manager
- Upload con validazione size > 0 pre e post `move_uploaded_file()` (BUG-136)
- Download con check permessi tenant
- Integrazione OnlyOffice:
  - open/save callback
  - URL download differenziati per Docker locale vs remoto
  - validazione file fisico e size

### 6.2 Workflow documentale
Stati:

```
bozza → in_validazione → validato → in_approvazione → approvato
          ↓ reject              ↓ reject
       rifiutato ←───────────────┘
```

- Ruoli di workflow:
  - default: admin + manager come validator/approver
  - `workflow_roles.is_active` sempre filtrato nelle query
- Partecipanti workflow:
  - NON esistono `validator_name/approver_name` su `document_workflow` (BUG-150b+)
  - usare helper che ricava partecipanti selezionati

### 6.3 Calendar
- Tabelle: `calendars`, `calendar_permissions`, `events`, `event_participants`, `event_reminders`
- Privacy: eventi personali filtrati per owner_id (BUG-122)
- Promemoria: cron `cron/calendar_reminders.php`

### 6.4 Tasks
- Endpoint unified: `api/tasks.php` con `action`
- Validazione assignees nel tenant (BUG-142)
- Notifiche email

### 6.5 Tickets
- Tabelle: `tickets`, `ticket_responses`, `ticket_assignments`, `ticket_notifications`, `ticket_history`, `ticket_attachments`
- Allegati:
  - max size e whitelist MIME
  - path: `uploads/tickets/{tenant_id}/...`
- Notifiche:
  - usare helper “nuovo” (BUG-154)
- UI stato chiuso:
  - nascondere reply e mostrare avviso (BUG-153)

### 6.6 Audit Log
- Logging azioni CRUD
- Non bloccare flussi business in caso di errore audit.

### 6.7 Page Visibility
- Tabella: `page_visibility_settings`
- `tenant_id NULL` = globale
- super_admin vede sempre tutto
- Helper: `includes/page_visibility_helper.php`

### 6.8 Work Shifts (Gestione Turni)
- Tabelle: `shift_types`, `work_shifts`, `shift_change_requests`
- Feature flag: `tenants.has_shift_management`
- Tipi turno: definizioni per tenant (nome, codice, orari, colore)
- Turni assegnati: user_id + shift_date + shift_type_id, con override orari
- Richieste modifica: change/swap/cancel con workflow approvativo

#### Endpoint API
- `api/shifts/types.php`: CRUD tipi turno
  - GET: lista tipi turno attivi
  - POST action=create/update/delete
- `api/shifts/list.php`: GET turni per range date (calendario)
  - Formato compatibile FullCalendar
  - Gestione turni notturni (overnight)
- `api/shifts/manage.php`: CRUD turni assegnati
  - POST action=create/update/delete/bulk_create
  - Bulk create per pattern settimanali
- `api/shifts/requests.php`: Richieste modifica turno
  - GET: lista richieste
  - POST action=create/approve/reject/cancel

#### Permessi per ruolo
- super_admin: accesso globale
- admin/manager: gestione turni del tenant
- user: visualizzazione propri turni + richieste modifica

#### Notifiche email turni
Helper: `includes/shift_notification_helper.php` (classe `ShiftNotificationHelper`)
Template: `includes/email_templates/shifts/`

Metodi statici disponibili:
- `notifyShiftAssigned($shiftId, $assignedBy)`: turno assegnato
- `notifyShiftUpdated($shiftId, $updatedBy, $changes)`: turno modificato
- `notifyShiftCancelled($shiftId, $cancelledBy, $reason)`: turno cancellato
- `notifyChangeRequestReceived($requestId)`: richiesta modifica ricevuta (a manager)
- `notifyRequestApproved($requestId, $approvedBy, $notes)`: richiesta approvata
- `notifyRequestRejected($requestId, $rejectedBy, $reason)`: richiesta rifiutata
- `notifyBulkShiftsAssigned($shiftIds, $assignedBy)`: notifiche bulk

Tutti i metodi sono non-blocking (try/catch con error_log).

---

## 7) Frontend: regole e best practice

### 7.1 Fetch standard (obbligatorio)

```js
await fetch('/CollaboraNexio/api/endpoint.php', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': document.getElementById('csrfToken').value
  },
  body: JSON.stringify({ ... })
});
```

### 7.2 Async UI updates (BUG-147a)
- ogni funzione async che aggiorna UI va `await`
- mostrare loading state quando serve

### 7.3 Event handler
- non usare `event` implicito (BUG-132)

### 7.4 Email template
- layout con `<table>` (BUG-150a)
- evitare flexbox/gap

---

## 8) Migrazioni, strumenti e manutenzione

### 8.1 Migrazioni
- directory: `database/migrations/`
- esecuzione tipica:

```bash
mysql -u root collaboranexio < database/migrations/<file>.sql
```

### 8.2 Backup / restore “one-click”
- cartella: `backups/full-backup-YYYYMMDD-HHMMSS/`
- script PowerShell:
  - `BACKUP.ps1`
  - `RESTORE.ps1`

### 8.3 Cron
- `cron/calendar_reminders.php`
- `cron/check_assignment_expirations.php`
- `cron/send_password_expiry_notices.php`

---

## 9) Anti-regressioni: elenco bug storici (regole sintetiche)

- BUG-157: shift_notification_helper usare `requester_id` non `requested_by`
- BUG-156: feature-detect colonne in `information_schema`
- BUG-155: non includere file con logica esecutiva
- BUG-154: notifiche ticket usare helper corretto
- BUG-153: UI ticket chiuso non deve permettere reply
- BUG-151: metodi chiamati esternamente devono essere `public`
- BUG-150a: email layout a table
- BUG-150b: chiavi array coerenti con alias SQL
- BUG-150c: `array_unique()` su azioni UI
- BUG-149a: NOT EXISTS non deve filtrare per deleted_at
- BUG-149d: default workflow = admin + manager
- BUG-148c/146a: PDO non supporta parametri named duplicati
- BUG-147c: colonna ticket `first_response_time_minutes`
- BUG-147b: workflow_roles is_active sempre
- BUG-145a: usare `$db->query()` per SQL raw
- BUG-144: non esistono `users.active_tenant_id` e `user_companies`
- BUG-136: validare upload size > 0 pre e post move
- BUG-135: OnlyOffice validate file exists + size
- BUG-104: CSRF ovunque
- BUG-066: wrap arrays in named keys

---

## 10) Checklist quando tocchi codice (pratico)

- Hai aggiunto/alterato query tenant-scoped?
  - ✅ `tenant_id` + ✅ `deleted_at IS NULL`
- Hai aggiunto una nuova colonna?
  - ✅ migration con check in `information_schema` + ✅ fallback
- Hai aggiunto/modificato un fetch in JS?
  - ✅ `credentials: 'same-origin'` + ✅ `X-CSRF-Token`
- Hai introdotto una transazione?
  - ✅ rollback prima di error
- Hai toccato OnlyOffice?
  - ✅ check file exists + size
- Hai toccato email?
  - ✅ layout table

---

**Ultimo aggiornamento**: 2025-12-19
per ogni nuova operazione, procedi sempre prima a leggere il file @CLAUDE.md. Poi inizia in sequenza a fare queste operazioni: 1) leggi @bug.md  per capire gli ultimi bug 
risolti, e poi leggi @progression.md  per capire lo stato di sviluppo.2) pinifica le attività che svilupperai con l'ausio dei tui agenti. 3) esegui le attività pianificate anche        
operando test e script e quant'altro serva in autonomia. 4)elimina tutti i file di test e/o simili creati e rendi la piattaforma pulita senza dati aggiuntivi utilizzati da te nel       
test. 5) se non hai i risultati sperati, ricomincia da capo. 6) Prima di terminare ogni operazioni, dammi la % di contesto utilizzata e quella libera. Al termine aggiorna in serie      
@CLAUDE.md , @bug.md  e @progression.md .