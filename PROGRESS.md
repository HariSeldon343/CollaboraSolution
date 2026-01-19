# Planning 2026 — Stabilizzazione immediata (tenant 28)

Obiettivo: eliminare **500** su “Calendario proposto (bozza)” e garantire che tutte le attività **non-call** ragionino a **slot da 0.5 giorni** (mai 0.25 / 0.3 / 0.0).

## Checklist operativa
- [x] **Pre-flight**: lettura `docs/AGENT_CONTEXT_START_HERE.md`, analisi file chiave (`items_generate_from_estimate.php`, `schedule_generate.php`, rendering UI), verifica log `logs/php_errors.log`
- [x] **Fix root-cause 500**: completare helper disponibilità calendario (metodi mancanti in `includes/calendar.php`)
- [x] **schedule_generate.php**: no 500, output sempre JSON, idempotenza rigenerazione bozze, ordine fasi coerente, slot 0.5gg, hard no-overlap con eventi reali + altri draft/confirmed; **409** se impossibile trovare slot entro finestra
- [x] **items_generate_from_estimate.php**: allocazione non-call solo a **0.5gg** (min 0.5), merge micro-fasi se fasi > unità disponibili, call/communication in minuti/ore (non “0.00 gg”)
- [x] **planning.js**: formattazione durata robusta (call in minuti/ore; non-call in multipli 0.5 senza “0.3”), nessun errore console
- [x] **Verifica integrità**: feature detection colonne, nessun errore JS/PHP (`php -l`, `node --check`)
- [x] **Docs**: aggiornato `docs/AGENT_CONTEXT_START_HERE.md` con regole 0.5gg + comportamento 409 + note compatibilità

