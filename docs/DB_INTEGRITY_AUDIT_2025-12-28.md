# DB Integrity Audit (Full Scan) - 2025-12-28

## Ambiente

- **Database**: `collaboranexio`
- **Server**: MariaDB `10.4.32-MariaDB`
- **Tabelle (BASE TABLE)**: **74**

## Dimensionamento (high level)

- Tabelle più “pesanti” per spazio (da `information_schema.tables`):
  - **`italian_municipalities`** (~7.9k righe)
  - **`audit_logs`** (~1.5k righe)
  - Tutto il resto è piccolo (decine/centinaia di righe, molte tabelle 0 righe).

## Row counts (full scan)

Eseguito `COUNT(*)` su tutte le tabelle (scan completo) tramite tooling interno (PDO). Alcuni valori rilevanti:

- **`audit_logs`**: 1518
- **`tickets`**: 13
- **`ticket_history`**: 32
- **`ticket_attachments`**: 1
- **`tasks`**: 8
- **`task_history`**: 18
- **`events`**: 15
- **`work_shifts`**: 8
- **`shift_change_requests`**: 1
- **`users`**: 7
- **`tenants`**: 16
- **`user_tenant_access`**: 0

Nota: sono presenti anche tabelle “backup” nel DB (es. `audit_logs_backup_20251028`, `files_backup_20250927_134246`, `files_path_backup_20251015`), che non fanno parte del modello applicativo “core” ma impattano l’inventario e i controlli.

## Integrità strutturale (PK/FK/Types)

- **FK dichiarate**: presenti (molte relazioni sono vincolate a livello DB).
- **Mismatch di tipo tra colonna FK e PK referenziata**: **0** (nessuna differenza tra `COLUMN_TYPE` child vs parent nelle FK dichiarate).
- **Orphan rows (referenze mancanti)**: controlli principali eseguiti su tenant e principali relazioni (users/tickets/tasks/events/files) → **0**.

## Findings (coerenza tenant_id)

Sono emerse inconsistenze **tenant_id** “cross-tenant” tra entità correlate (queste non sono bloccate dalle FK, perché le FK non verificano la coerenza tenant).

### Conteggi

- **Tickets**:
  - `tickets.created_by` (utente) con `users.tenant_id != tickets.tenant_id`: **4**
- **Tasks**:
  - `tasks.created_by` con mismatch tenant: **3**
  - `tasks.assigned_to` con mismatch tenant: **3**
- **Calendari / Eventi**:
  - `events.organizer_id` con mismatch tenant: **5**
  - `events.calendar_id` con mismatch tenant: **1**
  - `calendars.owner_id` con mismatch tenant: **3**

### Esempi (ID-only)

- `tickets` (tenant 11) creati da utenti con tenant 28 (es. `ticket_id`: 20,19,5,4 con `created_by`: 33/32).
- `tasks` (tenant 11) creati/assegnati a utenti con tenant 28 o super_admin con tenant 1 (es. `task_id`: 8,7,6).
- `events` (tenant 11) creati da organizer con tenant 28 o super_admin con tenant 1 (es. `event_id`: 11..15).
- Un `event_id = 15` (tenant 11) punta a `calendar_id = 9` con `calendar.tenant_id = 1`.

### Interpretazione

Queste anomalie sono **coerenti** con uno scenario in cui:

- `users.tenant_id` rappresenta il “tenant principale” dell’utente
- l’accesso multi-tenant è demandato a `user_tenant_access`

ma in questo database `user_tenant_access` risulta **vuota** (0 righe), quindi i cross-tenant risultano attualmente **non riconciliati** da una tabella di accesso.

## Azioni consigliate

1. **Decidere la regola canonica**:
   - se l’app vuole “tenant_id sempre coerente tra entità collegate” → bisogna correggere i record con mismatch (tickets/tasks/events/calendars) oppure riallineare `users.tenant_id`.
   - se l’app supporta multi-tenant → bisogna **popolare `user_tenant_access`** coerentemente (almeno per gli utenti che hanno creato/gestito contenuti in tenant diversi dal loro `users.tenant_id`) e aggiornare i controlli applicativi dove necessario.

2. **Per Page Visibility (`turni`)**:
   - la tabella `page_visibility_settings` contiene ancora 11 pagine × 3 ruoli = 33 righe; **manca `turni`** (atteso 3 righe).
   - il codice ora inserisce automaticamente le righe mancanti alla prima chiamata a `api/system/page_visibility.php?action=get`.

## Script ripetibile

- **SQL diagnostico**: `database/diagnostics/db_integrity_checks.sql`
  - include inventario PK/IDX/FK + controlli deterministici (COUNT) + check coerenza tenant.

