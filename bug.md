# Bug Tracking - CollaboraNexio

> **Nota:** Ultimi 5 elementi (bug o criticità) aggiornati. Archivio completo: `bug_archive_20251125.md`

---

## 2025-12-19 — BUG-157: Colonna errata in shift_notification_helper.php

**Status:** FIXED

**Sintomi:**
- Le notifiche email per richieste modifica turno fallivano con errore SQL

**Root cause:**
- Il file `includes/shift_notification_helper.php` usava `requested_by` invece di `requester_id` (nome colonna corretto nello schema DB)
- 4 occorrenze errate: linee 136, 531, 622, 693

**Fix applicato:**
- Corretto `requested_by` → `requester_id` in tutte le occorrenze

**File modificato:**
- `includes/shift_notification_helper.php`

---

## 2025-12-19 — Integrazione Turni nel Calendario Principale (calendar.js)

**Status:** IMPLEMENTED

**Obiettivo:**
- Visualizzare i turni di lavoro dell'utente corrente nel calendario principale (calendar.php) insieme agli eventi, ma in modo visivamente distinto e non interattivo.

**Implementazione:**

- **JavaScript** (`assets/js/calendar.js`):
  - Aggiunto `state.shifts` nel constructor di `CalendarApp`
  - Nuovo metodo `loadShifts()`: fetch da `api/shifts/list.php` con date range e user_id
  - Nuovo metodo `processShifts()`: normalizza dati turni per rendering
  - Chiamata a `loadShifts()` in: `loadInitialData()`, `changeView()`, `navigateDate()`, polling
  - Modificato `renderEvents()` per chiamare i metodi di rendering turni
  - Nuovi metodi in `CalendarView`:
    - `renderMonthShifts()`: turni nella vista mese
    - `renderWeekShifts()`: turni nella vista settimana
    - `renderDayShifts()`: turni nella vista giorno
    - `createShiftElement()`: crea elemento DOM turno (non cliccabile)
    - `hexToRgba()`: utility per colori semi-trasparenti

- **CSS** (`assets/css/calendar.css`):
  - Classe `.calendar-shift`: bordo tratteggiato, sfondo semi-trasparente
  - `pointer-events: none` per renderli non interattivi
  - Icona orologio + nome turno + orario (in base alla vista)
  - Stili specifici per month/week/day view
  - Responsive per mobile

**Pattern rispettati:**
- BUG-104: CSRF token e credentials in fetch
- Non-blocking: errori di caricamento turni non bloccano il calendario
- Turni solo informativi (no modal, no drag & drop)

**File modificati:**
- `assets/js/calendar.js`
- `assets/css/calendar.css`

---

## 2025-12-19 — Implementazione Frontend Gestione Turni (turni.php)

**Status:** IMPLEMENTED

**Obiettivo:**
- Completare il modulo Gestione Turni con la pagina frontend.

**Implementazione:**
- **Pagina**: `turni.php` con struttura completa
  - Header con view selector (Settimana/Mese), Company Filter, pulsanti azione
  - Calendario turni con griglia dipendenti x giorni
  - Sidebar richieste per manager/admin
  - Modal per gestione tipi turno (CRUD)
  - Modal per creazione/modifica turno (singolo e bulk)
  - Modal dettaglio turno con richiesta modifica
  - Modal dettaglio richiesta (approvazione/rifiuto)

- **JavaScript**: `assets/js/shifts.js`
  - Classe ShiftsApp per gestione completa UI
  - Rendering week/month view
  - Integrazione API con CSRF

- **CSS**: `assets/css/shifts.css`
  - Design enterprise minimal coerente con altre pagine
  - Griglia turni responsiva
  - Stati badge e modali

- **Sidebar**: aggiunta voce "Turni" con icona clock

**File creati/modificati:**
- `turni.php` (nuovo)
- `assets/js/shifts.js` (nuovo)
- `assets/css/shifts.css` (nuovo)
- `includes/sidebar.php` (modificato)
- `assets/css/styles.css` (aggiunta icona clock)

---

## 2025-12-19 — Ruolo Aziendale non visibile/modificabile per Manager (utenti.php)

**Status:** FIXED (in attesa di re-test finale UI)

**Sintomi:**
- Un utente **manager** aprendo `utenti.php` vedeva errori in console e non riusciva a gestire correttamente la sezione “Ruolo Aziendale”.
- Console:
  - `GET /api/users/get-companies.php?user_id=... 403 (Forbidden)`

**Root cause:**
- `utenti.php` tentava di chiamare `api/users/get-companies.php` durante `openEditModal()` quando l’utente target era `admin`.
- L’endpoint `api/users/get-companies.php` è (correttamente) **limitato** ad `admin/super_admin`, quindi un manager otteneva 403.

**Fix applicato:**
- **Frontend**: in `utenti.php` un manager non può più eseguire azioni (modifica/stato/elimina) su utenti `admin/super_admin`.
- **Guard** in `openEditModal()` per bloccare l’apertura del modal su target non gestibili.

**File modificato:**
- `utenti.php`

---

## 2025-12-19 — Permessi per-tenant su chi può assegnare “Ruolo Aziendale”

**Status:** IMPLEMENTED

**Obiettivo:**
- Di default solo **super_admin** può assegnare ruoli aziendali.
- Il super_admin può abilitare, **per singolo tenant**, quali ruoli di sistema (`admin`, `manager`, `user`) possono assegnare “Ruolo Aziendale”.

**Implementazione:**
- **DB**: nuova colonna `tenants.tenant_role_assignment_roles` (JSON array in TEXT)
- **API**:
  - `api/tenants/update.php`: solo super_admin può aggiornare `tenant_role_assignment_roles`
  - `api/tenant-roles/list.php`: aggiunge `can_assign_custom_roles`
  - `api/users/tenant_role.php`: enforcement server-side (403 se non autorizzato)
- **UI**:
  - `aziende.php`: pannello permessi nel modal “Gestione Ruoli Aziendali” (solo super_admin)
  - `utenti.php`: mostra il dropdown “Ruolo Aziendale” solo se `can_assign_custom_roles=true`.

**File coinvolti:**
- `database/migrations/21_tenant_role_assignment_permissions.sql`
- `api/tenants/update.php`
- `api/tenants/list.php`
- `api/tenant-roles/list.php`
- `api/users/tenant_role.php`
- `aziende.php`
- `utenti.php`

---

## 2025-12-18 — Diagnosi “comportamento vecchio” su produzione (cache/stale)

**Status:** RESOLVED (strumentazione + verifica)

**Sintomi:**
- Su `app.nexiosolution.it` sembrava rimanere una versione “vecchia” di `utenti.php`.

**Root cause (confermata):**
- Non era OPcache (su produzione risulta **non disponibile**), e Cloudflare risponde `cf-cache-status: DYNAMIC`.

**Fix / Strumentazione:**
- Header **no-cache** su `utenti.php` e `aziende.php`.
- Marker di build per verifica “view source”:
  - `<!-- CNX_BUILD_ID: ... -->`
  - `window.CNX_BUILD_ID = ...`
- Tool diagnostico super_admin `tools/force_clear_opcache.php` aggiornato per:
  - mostrare timestamps file e check “stringa presente nel sorgente”
  - verificare schema DB e valori per-tenant

**File coinvolti:**
- `utenti.php`
- `aziende.php`
- `tools/force_clear_opcache.php`
- `force_clear_opcache.php` (ora redirect sicuro)

---

## 2025-12-17 — Update tenant: vincolo UNIQUE su tenant_locations (sede_legale)

**Status:** RESOLVED

**Sintomi:**
- Aggiornando un’azienda: HTTP 500 con duplicate key su `tenant_locations` (sede_legale primaria).

**Root cause:**
- `tenant_locations` ha UNIQUE su `(tenant_id, location_type, is_primary)` che non considera `deleted_at`.
- Pattern “soft-delete + insert” causava collisione.

**Fix:**
- Aggiornamento “in place” della sede legale primaria (UPDATE se esiste, INSERT solo se assente).
- Audit log reso non-blocking.

**File modificato:**
- `api/tenants/update.php`

---

## 2025-12-17 — Users list: compatibilità migrazione Tenant Roles

**Status:** RESOLVED

**Sintomi:**
- `api/users/list.php` falliva se lo schema non aveva ancora `tenant_role_id`/`tenant_roles`.

**Fix:**
- Feature-detect via `information_schema` e query dinamica.

**File modificato:**
- `api/users/list.php`
