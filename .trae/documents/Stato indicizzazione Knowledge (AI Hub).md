* Aggiungere in api/ai/knowledge\_index.php una nuova azione GET `action=status` che:

  * Verifica presenza tabelle migrazione 48 (come già fatto in `run`).

  * Risolve `tenant_id` (querystring o tenant corrente) e verifica accesso tenant.

  * Legge eventuale source attiva (`ai_knowledge_sources`) e lo stato (`ai_knowledge_index_state`).

  * Calcola contatori (file distinti indicizzati, chunk totali) da `ai_knowledge_chunks`.

  * Ritorna JSON con `storage_available`, `configured`, `last_indexed_at`, `last_error`, `counts`, `folder`.

* Aggiornare assets/js/ai\_hub.js per chiamare `ai/knowledge_index.php?action=status` all’avvio e mostrare un messaggio in `#aiWarning` (es.Indic “izzato il …” / “Migrazione mancante” / “Knowledge non configurata”).

* Eseguire check sintassi: `php -l api/ai/knowledge_index.php` e `node -c assets/js/ai_hub.js`.

