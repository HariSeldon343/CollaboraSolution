## ISO 9001 Pilot — Leva 2/3/4 (Dev To-dos)

Regole:
- Questo file è un **registro di sviluppo**, non un dato applicativo.
- ID stabili: `ISO9001-PILOT-001...`
- Stato: `pending | in_progress | completed | blocked`

### To-do list

- ISO9001-PILOT-001 — Migrazione 41 + tool apply: `compliance_programs`, `compliance_artifacts`, `compliance_provisioning_runs` (schema drift safe) — **status: in_progress**
- ISO9001-PILOT-002 — Migrazione 42 + tool apply: `compliance_task_links` (+ `compliance_event_links` opzionale) — status: pending
- ISO9001-PILOT-003 — API common + tenant access helper: `api/compliance/_common.php` (auth/CSRF + accesso tenant cliente via `user_tenant_access`) — status: pending
- ISO9001-PILOT-004 — API Leva 2: `api/compliance/provision.php` (idempotenza folder/artifact/doc) + placeholder template non-ISO — status: pending
- ISO9001-PILOT-005 — API Leva 3: `api/compliance/tasks_sync.php` (task non assegnati) + milestone eventi opzionali — status: pending
- ISO9001-PILOT-006 — API Leva 4: `api/compliance/programs.php`, `api/compliance/artifacts.php`, `api/compliance/coverage.php` — status: pending
- ISO9001-PILOT-007 — UI Planning: CTA “Provisiona IMS” + “Crea task” + opz. milestone + modale opzioni + output conteggi — status: pending
- ISO9001-PILOT-008 — Dashboard tenant cliente: `compliance.php` (+ JS/CSS minimi) con CompanyFilter per consulenti — status: pending
- ISO9001-PILOT-009 — Documentazione append-only: update `docs/AGENT_CONTEXT_START_HERE.md` per Leva 2/3/4 + riferimenti migrazioni/API — status: pending

