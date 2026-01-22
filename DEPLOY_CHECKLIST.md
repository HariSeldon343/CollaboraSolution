# Checklist Deploy Produzione

## File da caricare su https://app.nexiosolution.it/CollaboraNexio/

### API Backend (CRITICI - causano 404)

| File Locale | Path Remoto |
|-------------|-------------|
| `api/workflow/roles/create.php` | `/CollaboraNexio/api/workflow/roles/create.php` |
| `api/workflow/roles/list.php` | `/CollaboraNexio/api/workflow/roles/list.php` |
| `api/events.php` | `/CollaboraNexio/api/events.php` |
| `api/documents/workflow/history.php` | `/CollaboraNexio/api/documents/workflow/history.php` |

### Include PHP

| File Locale | Path Remoto |
|-------------|-------------|
| `includes/calendar.php` | `/CollaboraNexio/includes/calendar.php` |

### JavaScript Frontend

| File Locale | Path Remoto |
|-------------|-------------|
| `assets/js/calendar.js` | `/CollaboraNexio/assets/js/calendar.js` |
| `assets/js/document_workflow_v2.js` | `/CollaboraNexio/assets/js/document_workflow_v2.js` |
| `assets/js/filemanager_enhanced.js` | `/CollaboraNexio/assets/js/filemanager_enhanced.js` |
| `assets/js/dashboard_manager.js` | `/CollaboraNexio/assets/js/dashboard_manager.js` |

### Migrazioni DB (nuove feature)

- **Folder ZIP approval (Migration 24)**:
  - File: `database/migrations/24_folder_zip_download_requests.sql`
  - Esegui su **produzione** (phpMyAdmin/CLI) prima di testare “Scarica cartella ZIP” per utenti `user`.
  - Verifica tabella:

```sql
SHOW TABLES LIKE 'folder_zip_download_requests';
```

  - Se non esiste: importa/exec il file SQL della migration.

### Tool di Pulizia (opzionale)

| File Locale | Path Remoto |
|-------------|-------------|
| `tools/purge_calendar_events.php` | `/CollaboraNexio/tools/purge_calendar_events.php` |

---

## Istruzioni Deploy

### 1. Via FTP/SFTP

1. Connettiti al server di produzione
2. Naviga a `/public_html/CollaboraNexio/` (o path equivalente)
3. Carica tutti i file elencati sopra **mantenendo la struttura cartelle**
4. Verifica i permessi: file PHP = 644, cartelle = 755

### 2. Via Pannello Hosting (cPanel/Plesk)

1. Accedi al File Manager del pannello
2. Naviga a `/CollaboraNexio/`
3. Upload dei file uno per uno nei rispettivi path

### 3. Clear Cache (IMPORTANTE)

Dopo il deploy, esegui:
- **PHP OPcache**: se hai accesso SSH, esegui `service apache2 reload` o riavvia PHP-FPM
- **Browser**: hard reload con Ctrl+Shift+R su ogni pagina modificata

---

## SQL per Pulizia Eventi Produzione

Se vuoi svuotare anche il calendario in produzione, esegui questa query in phpMyAdmin:

```sql
-- Backup prima! Questa query cancella TUTTI gli eventi del tenant 11
-- Modifica tenant_id se necessario

-- Soft delete (raccomandato - mantiene storico)
UPDATE events SET deleted_at = NOW(), deleted_by = 1 WHERE tenant_id = 11;
UPDATE event_participants SET deleted_at = NOW() WHERE event_id IN (SELECT id FROM events WHERE tenant_id = 11);
UPDATE event_reminders SET deleted_at = NOW() WHERE event_id IN (SELECT id FROM events WHERE tenant_id = 11);

-- Hard delete (IRREVERSIBILE - usa solo se necessario)
-- DELETE FROM event_reminders WHERE event_id IN (SELECT id FROM events WHERE tenant_id = 11);
-- DELETE FROM event_participants WHERE event_id IN (SELECT id FROM events WHERE tenant_id = 11);
-- DELETE FROM events WHERE tenant_id = 11;
```

---

## Verifica Post-Deploy

1. **Calendario**: Vai su `calendar.php`, crea un nuovo evento senza ricorrenza
2. **Dashboard**: Vai su `dashboard.php`, verifica che l'evento appaia nel mini-calendario e nella lista "Prossimi Eventi"
3. **Workflow**: Vai su `files.php`, apri modal ruoli, clicca "Rimuovi" su un validatore - deve funzionare senza errore 404
4. **Console**: Nessun errore 404 su `api/workflow/roles/create.php`

---

## Troubleshooting

### Errore 404 persiste
- Verifica che la cartella `api/workflow/roles/` esista sul server
- Verifica permessi file (644) e cartella (755)
- Verifica `.htaccess` non blocchi l'accesso

### Eventi non appaiono in dashboard
- Crea un evento con data FUTURA (oggi o domani)
- Ricarica la dashboard con Ctrl+Shift+R
- Controlla console per errori API

### Cache JS persistente
- Aggiungi `?v=timestamp` ai link JS nel HTML
- Oppure hard reload con Ctrl+Shift+R

