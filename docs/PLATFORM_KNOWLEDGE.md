### CollaboraNexio — Platform Knowledge (2026-01-04)

Questa guida raccoglie la conoscenza operativa della piattaforma **CollaboraNexio** così come è implementata nel codebase corrente. È pensata per:
- capire **cosa fa già** la piattaforma
- capire **dove** si trova la logica (file / API / DB)
- avere una visione completa della parte **Pianificazione** (`planning.php`) e del relativo backend

> Nota: la piattaforma è un’app PHP “monorepo” con pagine in root (es. `dashboard.php`) e API JSON sotto `api/`. Il DB è MySQL/MariaDB e i moduli sono in gran parte “feature folders” lato API.

---

### 1) Architettura ad alto livello

- **UI**: pagine PHP in root (`*.php`) che renderizzano HTML + includono layout comuni e caricano JS/CSS in `assets/`.
- **Backend JSON**: endpoint in `api/**` che rispondono con JSON e usano helper comuni per sessione/auth/CSRF.
- **DB**: schema in `database/` + migrazioni in `database/migrations/` + tool di deploy/diagnostica in `tools/`.
- **Storage file**: cartelle `uploads/`, `storage/`, e logica per “root folder per tenant” usata dal File Manager.

---

### 2) Sessione, autenticazione, CSRF, timeout inattività

#### 2.1 Session init e sicurezza di base
- **File**: `includes/session_init.php`
  - centralizza l’avvio sessione
  - imposta parametri cookie sessione
  - gestisce `$_SESSION['last_activity']` per inattività
  - genera un `login_nonce` (usato per eventi “una volta per login”, come banner privacy/cookie)

#### 2.2 Auth
- **File**: `includes/auth_simple.php` (+ uso in quasi tutte le pagine)
- **Login**: `api/auth.php`
  - supporta body JSON e form-encoded
  - risponde con JSON includendo `redirect: dashboard.php` (per UI)
  - aggiorna `$_SESSION['last_activity']` in login

#### 2.3 CSRF per API
- **File**: `includes/api_auth.php`
  - `initializeApiEnvironment()` → headers JSON + session init + buffering
  - `verifyApiAuthentication()` → richiede `$_SESSION['user_id']`
  - `verifyApiCsrfToken()` → legge token da header/GET/POST/body JSON
  - `apiSuccess()` / `apiError()` → standardizza output JSON

#### 2.4 Timeout inattività (300s)
- **Server-side**: `includes/session_init.php` invalida/gestisce la sessione se inattiva oltre soglia
- **Client-side**: `assets/js/session-timeout.js`
  - warning tipicamente a 270s, logout a 300s (valori letti da meta in head)
  - viene incluso nelle pagine autenticate via layout comune

---

### 3) Tenant, isolamento e “root folder per tenant”

#### 3.1 Concetto
- La piattaforma è multi-tenant: molte entità sono filtrate per `tenant_id` o accessibili in base ai permessi di tenant.
- Un tenant corrisponde ad una “azienda” gestita da `aziende.php` e dall’API `api/tenants/*`.

#### 3.2 Tenant access check
- **File**: `includes/tenant_access_check.php`
  - impone che l’utente abbia accesso ad un tenant “attivo” (super admin bypass)

#### 3.3 Creazione cartella root del tenant
- **File**: `includes/tenant_folder_helper.php`
  - `cnx_ensure_tenant_root_folder($db, $tenantId, $tenantName)` crea/assicura la root folder per il File Manager
  - richiede un `$_SESSION['user_id']` (anche per tool CLI va impostato `--user-id`)

---

### 4) Page visibility / RBAC (ruoli)

#### 4.1 Page visibility configurabile
- **File**: `includes/page_visibility_helper.php`
  - `isPageVisibleForRole(page, role, tenantId)`
  - `super_admin` vede sempre tutto (page visibility non lo blocca)
  - ruoli gestiti: `admin`, `manager`, `user`

#### 4.2 Enforce access sulle pagine
- **File**: `includes/page_access_check.php`
  - `checkPageAccess('nome_pagina')` blocca pagina se non visibile

#### 4.3 Audit Log RBAC specifico
- `audit_log.php` e `api/audit_log/*` sono **solo** per `super_admin` e `manager` (manager isolato sul proprio tenant)

---

### 5) Moduli principali (overview rapido)

- **Dashboard**: `dashboard.php`, `api/dashboard/*`
- **File Manager**: `files.php`, `api/files/*` + struttura folders per tenant
- **Calendario**: `calendar.php`, `api/calendar/*`
- **Turni**: `turni.php`, `api/shifts/*`
- **Task**: `tasks.php`, `api/tasks/*`
- **Ticket**: `ticket.php`, `api/tickets/*` (se presente nel repo)
- **Utenti**: `utenti.php`, `api/users/*`
  - `api/users/create_simple.php` supporta ripristino utenti soft-deleted (riuso email)
- **Aziende**: `aziende.php`, `api/tenants/*`
  - bulk delete: `api/tenants/bulk_delete.php`
  - import: tool in `tools/`
- **Audit**: `audit_log.php`, `assets/js/audit_log.js`, `api/audit_log/*`
  - default “all-time” paginato (30/page), filtri opzionali, stat cards cliccabili
- **Config**: `configurazioni.php`, `api/system/config.php`, `api/system/page_visibility.php`
- **Legal/Privacy**: `privacy.php`, `cookie-policy.php`, `includes/legal_policy.php`, `api/legal/status.php`
  - banner cookie/privacy “una volta per login” con flag server-side di sessione

---

### 6) Pianificazione Consulenze (S.CO) — Deep Dive

La pianificazione è un modulo “internal tools” destinato al tenant **S.CO** (tenant #28). Consente di:
- gestire un **catalogo attività** (pesi e regole call)
- creare **piani consulenza** per aziende clienti (tenant vari)
- aggiungere **righe attività** (giornate, tariffa, km, extra, note…)
- generare una **bozza calendario** (slot) evitando conflitti sugli eventi dei consulenti selezionati
- modificare gli slot e poi **confermare** creando eventi reali in calendario
- eliminare piani (soft delete)

#### 6.1 Gate / Accesso
- **Pagina**: `planning.php`
  - richiede login (`includes/auth_simple.php`)
  - richiede tenant access (super_admin bypass) (`includes/tenant_access_check.php`)
  - page visibility: `checkPageAccess('planning')`
  - gate tenant #28: `includes/tenant28_access_check.php` → `requireTenant28AccessPage($currentUser)`
  - audit page access: `includes/audit_page_access.php` → `trackPageAccess('planning')`

#### 6.2 UI: struttura della pagina `planning.php`

Layout a due colonne:
- **Colonna sinistra — Piani**
  - filtro cliente (`planningClientFilter`)
  - filtro stato (`planningStatusFilter`)
  - ricerca (`planningSearch`)
  - lista piani (`planningPlansList`)
  - pulsante `+ Nuovo` → modal “Crea Piano”

- **Colonna destra — Piano attivo**
  - titolo piano attivo (`planningActivePlanTitle`)
  - azioni:
    - `Crea piano guidato` (wizard step-by-step)
    - `Catalogo attività` (catalogo pesi/call)
    - `+ Aggiungi attività` (riga nel piano)
    - `Salva tutto` (bulk save delle righe)
  - barra consulenti selezionati per disponibilità (`planningConsultantsBar`)
  - tabella righe attività (`planningItemsTbody`) + totale (`planningTotalValue`)
  - sezione “Calendario proposto (bozza)” (`planningScheduleTbody`) con:
    - ricarica
    - genera proposta
    - conferma (crea eventi)

Modal principali:
- `planningPlanModal` → crea piano (cliente/titolo/stato/note/periodo/consulenti)
- `planningConsultantsModal` → selezione consulenti per piano
- `planningActivityCatalogModal` + `planningActivityTypeModal` → catalogo attività (CRUD)
- `planningCalendarModal` → creare manualmente evento calendario collegato ad una riga
- `planningGuidedWizardModal` → wizard guidato

JS principale:
- `assets/js/planning.js` (versionato via `?v=filemtime`)

#### 6.3 Data model (DB)

> La pianificazione dipende dalle migrazioni “consulting/planning” (es. Migration 35). In produzione eventuali 503 su API planning sono spesso sintomo di tabelle mancanti o schema drift.

Tabelle tipiche (nomi indicativi coerenti con API):
- `consulting_plans`
  - piano per un cliente (`client_tenant_id`), stato, periodo, note, soft delete (`deleted_at`)
- `consulting_plan_items`
  - righe del piano: tipo/data/giornate/tariffa/km/extra/note + collegamento a “domain activity type”
- `consulting_activity_types`
  - catalogo attività (nome obbligatorio; peso/call opzionali con default)
- `consulting_activity_type_overrides` (se presente)
  - override per tenant/cliente delle regole del catalogo
- `consulting_plan_consultants` (o equivalente)
  - associazione piano ↔ consulenti selezionati
- `consulting_schedule_drafts`
  - bozza slot calendario (generati e modificabili prima della conferma)

Migrazioni / tool correlati:
- Migrazione base: `database/migrations/35_consulting_activity_catalog_and_schedule.sql`
- Tool apply: `tools/apply_migration_35_consulting_activity_catalog_and_schedule.php`
- Fix FK/types: `database/migrations/36_consulting_fk_and_types.sql` + tool apply 36

#### 6.4 API principali della pianificazione (backend)

Piani:
- `api/consulting_plans/create.php` → crea piano (supporta `consultant_user_ids[]`)
- `api/consulting_plans/list.php` (o equivalente) → lista piani con filtri
- `api/consulting_plans/delete.php` → soft delete del piano

Righe piano:
- `api/consulting_plans/items_list.php` (o equivalente) → righe per piano
- `api/consulting_plans/items_upsert.php` → crea/aggiorna righe (supporta domain activity type + override call)
- `api/consulting_plans/items_delete.php` (o equivalente) → elimina riga

Catalogo attività:
- `api/consulting_plans/activity_types.php`
  - `list` / `create` / `update` / `delete`
  - `seed_base` → inserisce set base attività (idempotente per nome)
  - campi opzionali con default (peso/call) e **nome obbligatorio**

Calendario proposto:
- `api/consulting_plans/schedule_suggest.php` → genera proposta slot evitando conflitti calendari consulenti
- `api/consulting_plans/schedule_confirm.php` → conferma bozza e crea eventi reali in calendario

#### 6.5 Flusso utente (come si usa)

1) **Crea piano**
   - `+ Nuovo` oppure `Crea piano guidato`
   - scegli azienda cliente, titolo, (opzionale) periodo e note
   - seleziona 1+ consulenti (serve per proposta calendario e assegnazione)

2) **Aggiungi attività al piano**
   - `+ Aggiungi attività`
   - imposta tipo (remoto / in sito / call), giornate, tariffa, km, extra, note
   - opzionale: collega una attività dal catalogo (domain activity type)
   - `Salva tutto` per persistere righe

3) **Gestisci catalogo attività**
   - `Catalogo attività`
   - `+ Nuova attività` con **Nome obbligatorio**; peso/call sono facoltativi (default)
   - se catalogo vuoto può essere “seedato” automaticamente (base activities)

4) **Genera bozza calendario**
   - `Genera proposta`
   - backend controlla conflitti sugli eventi esistenti dei consulenti selezionati
   - produce una lista di slot **modificabili**

5) **Conferma calendario**
   - `Conferma (crea eventi)`
   - crea eventi reali nel modulo Calendario e marca bozza come confermata

6) **Elimina un piano**
   - dalla lista piani, azione “Delete” (soft delete via API)

#### 6.6 Troubleshooting (Planning)

Sintomi comuni e cause:
- **503 su planning (catalogo/consulenti/schedule)**:
  - quasi sempre tabelle mancanti (migrazione 35/36 non applicata) o schema drift
  - usare tool “apply migration” e/o tool di audit DB

- **Tool migrazione 35 fallisce** con errori SQL:
  - splitter SQL non gestiva commenti o statement complessi → tool hardened per strip comment + exec statement

- **UI planning non modifica nulla**:
  - verificare in console che `assets/js/planning.js` sia caricato (cache busting `?v=`)
  - verificare che API rispondano JSON e non HTML (login scaduto)
  - verificare session timeout / CSRF header

- **Problemi catalogo attività**:
  - campi opzionali: se `weight/call` vuoti, backend deve impostare default
  - `seed_base` aiuta a ripartire se catalogo vuoto

---

### 7) Tooling & operazioni (tools/)

Tool tipici:
- Migrazioni: `tools/apply_migration_35_consulting_activity_catalog_and_schedule.php`, `tools/apply_migration_36_consulting_fk_and_types.php`
- Deploy check: `tools/deploy_check_planning35.php`
- Smoke test piattaforma: `tools/platform_self_test.php`
- DB integrity audit: `tools/db_integrity_audit.php`
- Import tenant:
  - `tools/import_tenants_from_csv.php` (formato specifico precedente)
  - `tools/import_tenants_from_planning_final_csv.php` (Aziende_Planning_FINALE.CSV `;`)
  - `tools/import_tenants_from_elenco_verificato_csv.php` (elenco-completo-verificato.csv `,`)

---

### 8) Cosa manca / scelte progettuali importanti

- **Soft-deleted tenants**: molti import risultano “tenant esistente ma soft-deleted”.
  - Decisione: ripristino automatico sì/no?
  - Se richiesto, si può implementare modalità tool `--restore-soft-deleted` (restore + re-enable root folder).

- **Normalizzazione denominazione**:
  - alcuni CSV forniscono “denominazione breve” e “ragione sociale completa” in campi diversi
  - è importante non sovrascrivere con note non coerenti (es. “Sede operativa: …”)

---

### 9) Indice file (rapido)

- **Planning UI**: `planning.php`, `assets/js/planning.js`, `assets/css/planning.css`
- **Planning API**: `api/consulting_plans/*`
- **Auth/API helpers**: `includes/auth_simple.php`, `includes/api_auth.php`, `includes/session_init.php`
- **Page visibility**: `includes/page_visibility_helper.php`, `includes/page_access_check.php`
- **Tenant**: `aziende.php`, `api/tenants/*`, `includes/tenant_folder_helper.php`
- **Audit**: `audit_log.php`, `assets/js/audit_log.js`, `api/audit_log/*`, `includes/audit_integrity.php`

