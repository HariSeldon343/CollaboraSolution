# Planning 2026 — Stabilizzazione (tenant 28)

Obiettivo: eliminare **500** su “Calendario proposto (bozza)”, rendere la proposta **sensata** (ordine fasi + no overlap) e garantire che tutte le attività **giornate‑based** siano a **slot da 0.5 giorni** (mai 0.25 / 0.3 / 0.0). Inoltre, “Tipo intervento” deve essere **obbligatorio** perché guida **le attività create** (non solo la stima).

## Stato implementazioni (code)
- [x] **Wizard stima**: “Tipo intervento” obbligatorio (blocco UI su Step 3, stima e creazione piano)
- [x] **estimate_days.php**: `intervention_type` obbligatorio (400) + recertification ⇒ baseline **on-site** più alta
- [x] **items_generate_from_estimate.php**:
  - step **0.5g** + quantizzazione totale
  - merge fasi 0-unità (no righe day-based a 0/0.25)
  - RECERT: enforcement **Audit interno** + **Supporto audit esterno** (>=0.5g) e **forzatura on-site** per fasi chiave
- [x] **schedule_generate.php**:
  - no-overlap hard (eventi reali + draft/confirmed + piano/cliente)
  - blocchi non-call sempre **240m (0.5g)** + chiamate 30–60m
  - RECERT: “supporto audit esterno” spinto verso fine periodo (best‑effort)
  - error handling con `error_id` su 500
- [x] **schedule_suggest.php**: preferred times più granulari per call/communication
- [x] **planning.js preview attività**: merge fasi 0-unità + RECERT (fasi chiave on-site) + durata pulita (0.5g/1g, call in minuti)
- [x] **allocation_suggest.php**: fix schema OpenAI strict (no map `additionalProperties`; usa `assignments[]` + `reasons[]` + `notes[]`)

## Test manuali (da eseguire in UI)
- [ ] Wizard: non posso procedere senza “Tipo intervento”
- [ ] ISO9001 RECERT: anteprima include “Supporto audit esterno / certificazione” **ONSITE**
- [ ] Dopo generazione attività: nessuna riga non-call con GG < 0.5 o GG=0
- [ ] Genera calendario bozza: HTTP 200, nessun 500, nessun overlap, blocchi 0.5g=4h, ordine fasi ok, supporto audit esterno verso fine periodo (se periodo lungo)
- [ ] Nessun errore JS in console

