# Planning 2026 — Stabilizzazione (tenant 28)

Obiettivo: eliminare **500** su “Calendario proposto (bozza)”, rendere la proposta **sensata** (ordine fasi + no overlap) e garantire che tutte le attività **giornate‑based** siano a **slot da 0.5 giorni** (mai 0.25 / 0.3 / 0.0). Inoltre, “Tipo intervento” deve essere **obbligatorio** perché guida **le attività create** (non solo la stima).

## Stato implementazioni (code)
- [x] **Wizard stima**: “Tipo intervento” obbligatorio (blocco UI su Step 3, stima e creazione piano)
- [x] **estimate_days.php**:
  - `intervention_type` obbligatorio (400) + recertification ⇒ baseline **on-site** più alta
  - ritorna `preview_phases` per ogni servizio (workplan server) ⇒ preview wizard coerente con backend
- [x] **items_generate_from_estimate.php**:
  - step **0.5g** + quantizzazione totale
  - merge fasi 0-unità (no righe day-based a 0/0.25)
  - RECERT: enforcement **Audit interno** + **Supporto audit esterno** (>=0.5g) e **forzatura on-site** per fasi chiave
- [x] **schedule_generate.php**:
  - no-overlap hard (eventi reali + draft/confirmed + piano/cliente)
  - blocchi non-call sempre **240m (0.5g)** + chiamate 30–60m
  - RECERT: “supporto audit esterno” spinto verso fine periodo (best‑effort)
  - error handling con `error_id` su 500
  - reason tags arricchite (strategy/range/slot) in `explain_json` (best-effort)
- [x] **schedule_suggest.php**: preferred times più granulari per call/communication
- [x] **planning.js preview attività**:
  - merge fasi 0-unità + RECERT (fasi chiave on-site) + durata pulita (0.5g/1g, call in minuti)
  - usa `preview_phases` dall’API quando disponibili (coerenza preview ↔ attività create)
- [x] **planning.js calendario bozza**: reason sempre valorizzato (fallback su slot/strategy se manca `explain_json.tags`)
- [x] **allocation_suggest.php**: fix schema OpenAI strict (no map `additionalProperties`; usa `assignments[]` + `reasons[]` + `notes[]`)
- [x] **Provisioning IMS**: label/help checkbox “create tasks” chiarita (deliverable compliance, non attività piano) + abilitazione best-effort solo quando sensato

## Checklist Progetto SGQ (add-on, opt-in)
- [x] **DB**: migrazione `database/migrations/64_consulting_project_checklists.sql`
  - tabelle: `consulting_project_checklists`, `consulting_project_checklist_items` (schema-drift safe, senza FK)
- [x] **DB (v2)**: migrazione `database/migrations/65_consulting_project_checklists_objective_evidence.sql`
  - colonna: `consulting_project_checklists.objective_text` (OBIETTIVO checklist)
  - tabella: `consulting_project_checklist_evidence` (evidenze normalizzate; compatibile con `evidence_json`)
- [x] **Template**:
  - `configs/checklists/sgq_iso9001_bando.json` (NUOVA IMPLEMENTAZIONE, 9 sezioni, nessun testo ISO/UNI)
  - `configs/checklists/iso9001_recertification_mini.json` (RICERTIFICAZIONE “mini”, raccolta evidenze chiave)
  - `configs/checklists/cefalu_sgq_iso9001_iso7101.json` (SANITÀ: ISO9001 + ISO7101, assessment/raccolta dati, nessun testo norma)
- [x] **API (tenant 28 + CSRF + auth)**:
  - `api/consulting_plans/checklists/templates_list.php`
  - `api/consulting_plans/checklists/checklist_get.php`
  - `api/consulting_plans/checklists/checklist_create.php` (idempotente su plan+template)
    - accetta anche `template_code` (alias) + `objective_text` (quando supportato)
  - `api/consulting_plans/checklists/checklist_update.php` (update header: objective/status)
  - `api/consulting_plans/checklists/checklist_item_update.php`
  - `api/consulting_plans/checklists/checklist_evidence_add.php` / `checklist_evidence_clear.php` (evidenze idempotenti, file_id validato nel tenant cliente)
  - `api/consulting_plans/checklists/checklist_autofill_from_docs.php`:
    - best-effort matching su file name/path (+ tags/keywords da doc_profile quando presenti)
    - **non distruttivo**: merge `evidence_json` + dedup (non sovrascrive evidenze inserite manualmente)
    - best-effort: upsert anche `consulting_project_checklist_evidence` se disponibile
  - `api/consulting_plans/checklists/checklist_export_xlsx.php` (export XLSX senza dipendenze)
  - Alias endpoint “spec name” in `api/consulting_plans/`:
    - `checklist_templates.php`, `checklist_get_or_create.php`, `checklist_item_upsert.php`,
      `checklist_evidence_add.php`, `checklist_evidence_clear.php`, `checklist_export_xlsx.php`
- [x] **UI**: nuovo tab “Checklist (Progetto SGQ)” in `planning.php` + logica in `assets/js/planning.js`
  - progress bar + sezioni 1..9
  - azioni: crea checklist, rileva documenti (best-effort), reindicizza, analizza documenti
  - render **tabellare** per sezione (TABLE) + salvataggio `answer_text` con debounce (UX)
  - selezione template (dropdown) + filtri fase/stato
  - campo **Obiettivo** (obbligatorio) + auto-save (se migrazione 65 applicata)
  - evidenze: add/remove/clear via nuove API (con fallback su `evidence_json`)
  - export Excel (XLSX)
  - analisi documenti: usa standard_codes del piano (ISO9001 + ISO7101 quando presenti) + snapshot→reindex→analyze best-effort
  - nessuna modifica automatica a stime/attività/provisioning (solo azioni esplicite)
- [x] **Doc Intelligence hardening**:
  - reindex: **throttle 10 min** + **lock** per evitare parallelismo (cron/API)
  - analyze: aggiunto `doc_evidence[]` (file_id/name/path + tags/matched_keywords) per facilitare autofill checklist

## Test manuali (da eseguire in UI)
- [ ] Wizard: non posso procedere senza “Tipo intervento”
- [ ] ISO9001 RECERT: anteprima include “Supporto audit esterno / certificazione” **ONSITE**
- [ ] Dopo generazione attività: nessuna riga non-call con GG < 0.5 o GG=0
- [ ] Genera calendario bozza: HTTP 200, nessun 500, nessun overlap, blocchi 0.5g=4h, ordine fasi ok, supporto audit esterno verso fine periodo (se periodo lungo)
- [ ] Calendario bozza: colonna “Reason” valorizzata (non “—”)
- [ ] Provisioning IMS: checkbox “Crea task…” chiara; rilancio provisioning non duplica task/documenti
- [ ] Nessun errore JS in console

## Bugfix extra (turni / ticket)
- [x] **Turni (wizard)**: fix mismatch date preview/CSV (evita `toISOString()` che scala di 1 giorno in timezone locali; usa `formatDateISO()`).
- [x] **Ticket (ruoli user)**: hardening UI per garantire che i pulsanti “Nuovo Ticket” e “Crea Ticket” restino **visibili/abilitati** anche per ruoli non Manager/Admin (creazione ticket è consentita server-side).

### Test manuali — Turni/Ticket
- [ ] `turni.php` → Wizard Turni:
  - le date inserite (inizio/fine) corrispondono alle date mostrate in preview
  - export CSV: date coerenti con preview
  - dopo “Crea turni”: le date create corrispondono al periodo selezionato
- [ ] `ticket.php` → ruolo **user** (non admin/manager):
  - pulsante “Nuovo Ticket” visibile
  - modal creazione: pulsante “Crea Ticket” visibile e cliccabile
  - creazione ticket OK (nessun 403 CSRF, nessun 500)

### Test manuali — Checklist Progetto SGQ (OBBLIGATORI)
- [ ] Applica migrazione `64_consulting_project_checklists.sql` (se non già applicata)
- [ ] Applica migrazione `65_consulting_project_checklists_objective_evidence.sql` (obiettivo + evidenze normalizzate)
- [ ] In Planning (tenant 28) seleziona un piano → tab “Checklist (Progetto SGQ)” visibile e navigabile
- [ ] Clic “Crea checklist da template”:
  - crea 1 checklist (idempotente su retry)
  - al refresh la checklist e le risposte persistono
- [ ] Campo “Obiettivo”:
  - è obbligatorio (blocca creazione se vuoto)
  - salva su DB (refresh → resta)
- [ ] Aggiorna uno o più item (status/answer/evidence) → salva senza 500 e persiste
- [ ] Evidenze:
  - “Aggiungi evidenza” con `file_id` valido → appare, link “Apri” ok, “Rimuovi” ok, persiste
- [ ] Export:
  - bottone “Export Excel” scarica `.xlsx` compilato
- [ ] Clic “Rileva documenti esistenti”:
  - non crasha se knowledge index assente/vuoto
  - se trova match, imposta status=present e popola evidence_json (file_id + path + name)
- [ ] “Reindicizza” + “Analizza documenti”: nessun 500; analisi mostrata nel box (maturità/gap) quando disponibile

## AI Guided Onboarding / Data Collection (multi-tenant)
- [x] Analisi contesto: AI Hub, compliance wizard, knowledge indexer e checklist planning esistenti.
- [x] Migrazioni nuove per tenant indexing/chat/checklist (migration 67 + tool apply).
- [x] Helper indicizzazione tenant + chunking + redazione (includes/ai/tenant_docs_indexer.php).
- [x] Endpoint snapshot/analyze/reindex + worker cron-friendly.
- [x] Chat onboarding (API) con RAG + azioni whitelist.
- [x] UI compliance: chat + checklist tabellare + stato indicizzazione.
- [x] Export XLSX checklist (tenant_checklists).

## Come riprendere se interrompo
- Verifica `git status` e che siano presenti:
  - `database/migrations/64_consulting_project_checklists.sql`
  - `configs/checklists/sgq_iso9001_bando.json`
  - `api/consulting_plans/checklists/*`
  - modifiche in `planning.php` + `assets/js/planning.js`
- Se UI mostra “Checklist non disponibile”: applica migrazione 64.
- Per autofill da documenti: serve (best-effort) knowledge index attivo (migrazione 48 + indicizzazione).

