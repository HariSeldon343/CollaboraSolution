# Development Progression - CollaboraNexio

> **Nota:** Ultime 3 sessioni di sviluppo (aggiornato "ad ora"). Archivio completo: `progression_archive_20251125.md`

---

## 2025-12-19 — FEATURE COMPLETA: Gestione Turni di Lavoro (Work Shifts)

**Status:** PRODUCTION-READY

**Riepilogo implementazione completa:**

La funzionalità "Gestione Turni di Lavoro" è stata implementata integralmente:

1. **Database** (`database/migrations/22_work_shifts_feature.sql`):
   - `shift_types`: tipi turno per tenant
   - `work_shifts`: assegnazioni turni
   - `shift_change_requests`: richieste modifica con workflow
   - Feature flag `tenants.has_shift_management`

2. **API Backend** (`api/shifts/`):
   - `types.php`: CRUD tipi turno
   - `list.php`: lista turni per calendario
   - `manage.php`: CRUD turni + bulk create
   - `requests.php`: workflow richieste modifica

3. **Email Notifications**:
   - Helper: `includes/shift_notification_helper.php`
   - 6 template in `includes/email_templates/shifts/`

4. **Frontend**:
   - Pagina: `turni.php`
   - JavaScript: `assets/js/shifts.js`
   - CSS: `assets/css/shifts.css`
   - Sidebar aggiornata con voce "Turni"

5. **Integrazione Calendario** (`calendar.js`):
   - Visualizzazione turni utente nel calendario principale
   - Stile distintivo (bordo tratteggiato, semi-trasparente)
   - Non interattivi (solo informativi)

**Permessi:**
- super_admin: gestione globale
- admin/manager: gestione tenant assegnati
- user: visualizzazione propri turni + richieste modifica

**Bug fix:** BUG-157 (colonna `requested_by` → `requester_id` in shift_notification_helper.php)

**Prossimi passi:**
- Eseguire migration: `mysql -u root collaboranexio < database/migrations/22_work_shifts_feature.sql`

---

## 2025-12-19 — Integrazione Turni nel Calendario Principale (SHIFT-INTEGRATION)

**Obiettivo sessione:**
- Integrare la visualizzazione dei turni di lavoro dell'utente nel calendario principale (`calendar.php`), mostrandoli insieme agli eventi ma in modo visivamente distinto e non interattivo.

**Attivita principali:**

1. **Modifica `CalendarApp` (constructor + state):**
   - Aggiunto `state.shifts = []` per memorizzare i turni caricati

2. **Nuovo metodo `loadShifts()`:**
   - Fetch da `api/shifts/list.php` con parametri `start_date`, `end_date`, `user_id`
   - Usa `currentUserId` da hidden input
   - Non-blocking: errori non mostrano toast (turni sono opzionali)
   - Pattern BUG-104: credentials + CSRF token

3. **Nuovo metodo `processShifts()`:**
   - Normalizza datetime strings
   - Aggiunge flag `isShift: true` per distinguere da eventi
   - Estrae `shiftTypeName` e `shiftColor`

4. **Integrazione in flussi esistenti:**
   - `loadInitialData()`: chiama `loadShifts()` dopo `loadEvents()`
   - `changeView()`: ricarica turni quando cambia vista
   - `navigateDate()`: ricarica turni quando si naviga
   - `setupPolling()`: include `loadShifts()` nel polling tick

5. **Modifiche a `CalendarView.renderEvents()`:**
   - Dopo render eventi, chiama render turni per ogni vista
   - Month: `renderMonthShifts()`
   - Week: `renderWeekShifts()`
   - Day: `renderDayShifts()`

6. **Nuovi metodi di rendering turni:**
   - `renderMonthShifts()`: appende badge turno sotto eventi nella cella
   - `renderWeekShifts()`: appende badge nella sezione all-day
   - `renderDayShifts()`: appende badge nella sezione all-day del giorno
   - `createShiftElement()`: crea DOM element con icona orologio, nome, orario
   - `hexToRgba()`: utility per colori semi-trasparenti

7. **CSS per turni (`assets/css/calendar.css`):**
   - `.calendar-shift`: bordo tratteggiato, sfondo semi-trasparente
   - `pointer-events: none`: non cliccabile
   - `.shift-icon`, `.shift-name`, `.shift-time`: layout interno
   - Varianti per month/week/day
   - Responsive per mobile

**Pattern rispettati:**
- BUG-104: CSRF e credentials in tutti i fetch
- SHIFT-INTEGRATION: prefisso commenti per tracciabilita
- Non-blocking: errori turni non bloccano calendario
- Separazione visiva: turni vs eventi

**File modificati:**
- `assets/js/calendar.js`
- `assets/css/calendar.css`

---

## 2025-12-19 — Frontend UI: Pagina Gestione Turni (turni.php)

**Obiettivo sessione:**
- Creare la pagina frontend per la gestione turni di lavoro, completando il modulo.

**Attivita principali:**
- Creata pagina `turni.php`:
  - Header con titolo, view selector (Settimana/Mese), Company Filter, bottoni azione
  - Calendario turni con griglia settimanale/mensile
  - Sidebar richieste per manager (lista richieste pending con approvazione rapida)
  - Modal gestione tipi turno (CRUD completo)
  - Modal creazione/modifica turno (singolo e bulk)
  - Modal dettaglio turno con form richiesta modifica (per utenti)
  - Modal dettaglio richiesta (per manager con approvazione/rifiuto)
  - Toast notifications per feedback utente

- Creato `assets/js/shifts.js`:
  - Classe `ShiftsApp` per gestione UI completa
  - Rendering calendario week/month view
  - Fetch API con CSRF (pattern BUG-104)
  - Gestione modali (open/close/data population)
  - Gestione CRUD turni e tipi turno
  - Workflow richieste modifica
  - Localizzazione italiana (giorni, mesi, stati)

- Creato `assets/css/shifts.css`:
  - Layout griglia turni responsivo
  - Stili badge turno con colori dinamici
  - Sidebar richieste con lista scrollabile
  - Stili modali consistent con altre pagine
  - Toast notifications
  - Stati turno (scheduled, confirmed, cancelled, etc.)
  - Responsive per tablet/mobile

- Aggiornato `includes/sidebar.php`:
  - Aggiunta voce "Turni" con icona clock

- Aggiornato `assets/css/styles.css`:
  - Aggiunta icona `.icon--clock::before` per sidebar

**Pattern rispettati:**
- Autenticazione 8-step da CLAUDE.md
- Header no-cache per prevenire stale content
- Build marker per debug produzione (CNX_BUILD_ID)
- Fetch con credentials: 'same-origin' e X-CSRF-Token (BUG-104)
- Design minimal enterprise (no gradients, no glassmorphism)
- Permessi UI basati su ruolo (canManageShifts, canApproveRequests)
- Company Filter per admin/super_admin

**Output:**
- Pagina `turni.php` production-ready
- JavaScript `assets/js/shifts.js` completo
- CSS `assets/css/shifts.css` con design coerente
- Sidebar aggiornata con voce "Turni"
- Modulo Gestione Turni COMPLETO (DB + API + Email + Frontend)

---

## 2025-12-19 — Sistema Notifiche Email per Gestione Turni

**Obiettivo sessione:**
- Creare il sistema di notifiche email per la funzionalita "Gestione Turni di Lavoro".

**Attivita principali:**
- Creata directory `includes/email_templates/shifts/` con 6 template email:

1. **shift_assigned.html**: notifica al dipendente quando gli viene assegnato un turno
2. **shift_updated.html**: notifica al dipendente quando il suo turno viene modificato
3. **shift_cancelled.html**: notifica al dipendente quando il suo turno viene cancellato
4. **shift_request_received.html**: notifica ai manager quando un dipendente crea una richiesta
5. **shift_request_approved.html**: notifica al dipendente quando la sua richiesta viene approvata
6. **shift_request_rejected.html**: notifica al dipendente quando la sua richiesta viene rifiutata

- Creato `includes/shift_notification_helper.php`:
  - Classe `ShiftNotificationHelper` con metodi statici
  - `notifyShiftAssigned()`: turno assegnato
  - `notifyShiftUpdated()`: turno modificato (con tracciamento modifiche)
  - `notifyShiftCancelled()`: turno cancellato
  - `notifyChangeRequestReceived()`: richiesta modifica ricevuta (notifica tutti i manager del tenant)
  - `notifyRequestApproved()`: richiesta approvata
  - `notifyRequestRejected()`: richiesta rifiutata
  - `notifyBulkShiftsAssigned()`: notifiche multiple per creazione bulk

**Pattern rispettati:**
- Layout email `<table>` per compatibilita client email (BUG-150a)
- Non-blocking: try/catch con error_log su fallimenti
- Usa `Database::getInstance()` per query
- Integrazione con `mailer.php`, `email_layout.php`, `email_template_renderer.php`
- Metodi `public static` per chiamata da API senza istanziazione
- Template in italiano con placeholder Mustache-style

**Output:**
- 6 template email production-ready in `includes/email_templates/shifts/`
- Helper PHP completo in `includes/shift_notification_helper.php`
- Pronto per integrazione con `api/shifts/*.php`

---

## 2025-12-19 — API Gestione Turni di Lavoro (Work Shifts)

**Obiettivo sessione:**
- Sviluppare le API PHP per la funzionalita "Gestione Turni di Lavoro" basata sulla migration 22.

**Attivita principali:**
- Creata directory `api/shifts/` con 4 endpoint completi:

1. **api/shifts/types.php** (CRUD tipi turno):
   - GET: lista tipi turno attivi per tenant (con conteggio turni)
   - POST action=create: crea tipo turno (admin/manager/super_admin)
   - POST action=update: modifica tipo turno con validazione duplicati
   - POST action=delete: soft delete con check turni attivi

2. **api/shifts/list.php** (Lista turni per calendario):
   - GET con parametri: tenant_id, start_date, end_date, user_id, status, shift_type_id
   - Formattazione compatibile con FullCalendar (title, start, end, backgroundColor)
   - Gestione turni notturni (overnight shifts)
   - Summary opzionale per dashboard

3. **api/shifts/manage.php** (CRUD turni assegnati):
   - POST action=create: assegna turno a utente con validazione
   - POST action=update: modifica turno (orari override, status, note)
   - POST action=delete: soft delete turno + cancella richieste pending
   - POST action=bulk_create: creazione multipla con pattern (giorni settimana, range date)

4. **api/shifts/requests.php** (Richieste modifica turno):
   - GET: lista richieste (pending per manager, proprie per user)
   - POST action=create: user crea richiesta (change/swap/cancel)
   - POST action=approve: manager approva con applicazione automatica modifiche
   - POST action=reject: manager rifiuta con motivazione obbligatoria
   - POST action=cancel: user cancella propria richiesta pending

**Pattern rispettati:**
- Autenticazione API standard (initializeApiEnvironment, verifyApiAuthentication, verifyApiCsrfToken)
- BUG-066: array wrappati in chiave nominata (shift_types, shifts, requests)
- BUG-156: feature-detect per colonne opzionali
- BUG-145a: uso di $db->query() per SQL raw
- Transazioni con rollback prima di api_error
- Audit logging non-blocking
- Super_admin bypass tenant isolation
- Validazione FK per user/shift_type nello stesso tenant

**Output:**
- 4 file API production-ready in `api/shifts/`
- Documentazione DocBlock completa in italiano
- Compatibilita calendario (FullCalendar format)
- Bulk create per pianificazione settimanale/mensile

---

## 2025-12-19 — Database Schema: Gestione Turni di Lavoro (Work Shifts)

**Obiettivo sessione:**
- Progettare e creare lo schema database per la nuova funzionalita "Gestione Turni di Lavoro".

**Attività principali:**
- Creata migration `database/migrations/22_work_shifts_feature.sql`:
  - **shift_types**: definizione tipi di turno per tenant (nome, codice, orari, colore)
  - **work_shifts**: assegnazione turni a utenti per date specifiche
  - **shift_change_requests**: richieste modifica/scambio/annullamento turno con workflow approvativo
  - Aggiunta colonna `tenants.has_shift_management` (feature flag)
- Creato rollback `database/migrations/22_work_shifts_feature_rollback.sql`

**Schema creato:**
1. `shift_types`:
   - Tipi turno tenant-scoped (es: "Mattina 6-14", "Pomeriggio 14-22", "Notte 22-6")
   - Campi: name, code, start_time, end_time, duration_minutes, color, icon
   - Unique: (tenant_id, code, deleted_at), (tenant_id, name, deleted_at)

2. `work_shifts`:
   - Assegnazione turno a utente per data specifica
   - Status: scheduled, confirmed, in_progress, completed, cancelled, no_show
   - Override orari per singolo turno
   - Tracking tempo effettivo (actual_start_time, actual_end_time)
   - Unique: (tenant_id, user_id, shift_date, shift_type_id, deleted_at)

3. `shift_change_requests`:
   - Tipi richiesta: change, swap, cancel
   - Workflow: pending -> approved/rejected/cancelled/expired
   - Per swap: target_user_id, target_accepted, target_accepted_at
   - Note manager e motivo rifiuto

**Pattern rispettati:**
- tenant_id INT UNSIGNED NOT NULL su tutte le tabelle
- deleted_at TIMESTAMP NULL per soft delete
- created_at, updated_at audit fields
- Indici: (tenant_id, created_at), (tenant_id, deleted_at), (tenant_id, status, deleted_at)
- FK con ON DELETE CASCADE/RESTRICT/SET NULL appropriati
- Feature-detect pattern BUG-156 per colonna tenants.has_shift_management

**Output:**
- Migration pronta per esecuzione
- Rollback disponibile per eventuale revert
- Query di esempio per integrazione calendario incluse come commenti

---

## 2025-12-19 — Ruoli Aziendali: permessi per-tenant + fix Manager su utenti.php

**Obiettivo sessione:**
- Rendere configurabile (per tenant) quali tipi utente possono **assegnare** un “Ruolo Aziendale”.
- Ripristinare la gestione utenti da account **manager** (rimozione 403 e UX coerente).

**Attività principali (cronologia):**
- Creata migration `database/migrations/21_tenant_role_assignment_permissions.sql`:
  - aggiunta colonna `tenants.tenant_role_assignment_roles` (JSON array in TEXT)
- Estesa `api/tenants/update.php`:
  - super_admin-only per modificare `tenant_role_assignment_roles`
  - feature-detect colonna (pattern BUG-156)
- Estesa `api/tenants/list.php`:
  - include `tenant_role_assignment_roles` nella risposta
- Estesa `api/tenant-roles/list.php`:
  - calcolo `can_assign_custom_roles` (default deny, super_admin always true)
- Enforcement server-side:
  - `api/users/tenant_role.php` blocca con 403 se il ruolo non è abilitato per il tenant
- UI aziende:
  - `aziende.php` aggiunto pannello “Permessi assegnazione Ruolo Aziendale” (solo super_admin)
- UI utenti:
  - `utenti.php`
    - fallback per manager senza Company Filter (usa tenant selezionato nel modal)
    - fix 403: un manager non può modificare utenti `admin/super_admin` (niente chiamate a endpoint admin-only)

**Output:**
- Funzionalità “permessi assegnazione Ruolo Aziendale” disponibile per tenant.
- Manager non genera più 403 su `api/users/get-companies.php` (azioni bloccate su target non gestibili).

---

## 2025-12-18 — Diagnosi produzione: “comportamento vecchio” e strumenti di verifica

**Obiettivo sessione:**
- Capire in modo deterministico se produzione stesse servendo file PHP vecchi o se il problema fosse logico.

**Attività principali:**
- Aggiunti header no-cache su `utenti.php` e `aziende.php`.
- Inseriti marker:
  - `CNX_BUILD_ID` (commento HTML + `window.CNX_BUILD_ID`)
- Creato/rafforzato tool super_admin:
  - `tools/force_clear_opcache.php` (reset se disponibile + diagnostica timestamps + check presenza stringhe nel codice + check schema DB)
- Reso sicuro il vecchio entrypoint:
  - `force_clear_opcache.php` ora fa redirect al tool in `tools/`

**Risultato:**
- Verificato su produzione che OPcache non è disponibile e che i marker risultano presenti: problema non era “stale file”, ma logica/permessi.

---

## 2025-12-17 — Stabilizzazione tenant e utenti (fix aggiornamento aziende + compatibilità users list)

**Obiettivo sessione:**
- Eliminare duplicazioni tenant e rendere gli update persistenti.
- Rendere `api/users/list.php` compatibile con schema non ancora migrato.

**Attività principali:**
- Fix `api/tenants/update.php`:
  - update sede legale in place (evita collisioni su UNIQUE)
  - audit non-blocking
- Fix `api/users/list.php`:
  - feature-detect `tenant_role_id` e query dinamica (BUG-156)

**Risultato:**
- Update tenant stabile.
- Lista utenti robusta su DB con/ senza feature opzionali.
