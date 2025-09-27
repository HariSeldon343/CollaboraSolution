# Sistema di Backup Automatico CollaboraNexio

## Panoramica

Sistema completo di backup automatico per CollaboraNexio che gestisce:
- Backup giornaliero del database
- Backup settimanale dei file uploads
- Rotazione automatica dei backup (30 giorni)
- Compressione e verifica integrità
- Notifiche email in caso di errore
- Supporto per ripristino

## File del Sistema

1. **backup.php** - Script PHP principale per esecuzione backup
2. **backup.bat** - Script batch Windows per Task Scheduler
3. **backup_config.php** - File di configurazione personalizzabile

## Installazione

### 1. Prerequisiti

- PHP 8.3 o superiore
- XAMPP con MySQL/MariaDB
- Estensioni PHP richieste:
  - PDO
  - zip
  - zlib (per compressione gzip)

### 2. Configurazione Percorsi

Verifica che i percorsi nel file `backup.bat` siano corretti:
```batch
SET XAMPP_PATH=C:\xampp
SET PROJECT_PATH=%XAMPP_PATH%\htdocs\CollaboraNexio
```

### 3. Configurazione Email (Opzionale)

Modifica `backup_config.php`:
```php
define('BACKUP_ADMIN_EMAIL', ['tuo-email@esempio.com']);
define('BACKUP_EMAIL_ENABLED', true);
```

### 4. Creazione Directory

Le directory necessarie vengono create automaticamente:
- `/backups` - Directory principale backup
- `/backups/logs` - Log delle operazioni

## Configurazione Windows Task Scheduler

### Metodo 1: Interfaccia Grafica

1. Apri **Task Scheduler** (Utilità di pianificazione)
2. Clicca su **Crea attività...**
3. **Generale**:
   - Nome: `CollaboraNexio Backup`
   - Descrizione: `Backup automatico database e file`
   - Seleziona: `Esegui indipendentemente dalla connessione dell'utente`
   - Seleziona: `Esegui con i privilegi più elevati`

4. **Trigger**:
   - Nuovo trigger
   - Inizio: `In base a una pianificazione`
   - Impostazioni: `Ogni giorno`
   - Ora: `02:00:00`
   - Ricorrenza: `1 giorni`
   - Abilitato: ✓

5. **Azioni**:
   - Nuova azione
   - Azione: `Avvia un programma`
   - Programma: `C:\xampp\htdocs\CollaboraNexio\cron\backup.bat`
   - Aggiungi argomenti: (lascia vuoto)
   - Inizia in: `C:\xampp\htdocs\CollaboraNexio\cron`

6. **Condizioni**:
   - Deseleziona: `Avvia l'attività solo se il computer è alimentato da rete elettrica`

7. **Impostazioni**:
   - Seleziona: `Consenti l'esecuzione dell'attività su richiesta`
   - Seleziona: `Se l'attività fallisce, riavvia ogni: 10 minuti`
   - Tentativi di riavvio: `3`

### Metodo 2: Linea di Comando

Esegui come amministratore:

```cmd
schtasks /create /tn "CollaboraNexio Backup" /tr "C:\xampp\htdocs\CollaboraNexio\cron\backup.bat" /sc daily /st 02:00 /ru SYSTEM /rl HIGHEST
```

Per backup settimanale domenicale:
```cmd
schtasks /create /tn "CollaboraNexio Weekly Backup" /tr "C:\xampp\htdocs\CollaboraNexio\cron\backup.bat" /sc weekly /d SUN /st 03:00 /ru SYSTEM /rl HIGHEST
```

## Utilizzo Manuale

### Esecuzione da Linea di Comando

```bash
# Backup standard (incrementale)
php C:\xampp\htdocs\CollaboraNexio\cron\backup.php

# Backup completo (database + file)
php C:\xampp\htdocs\CollaboraNexio\cron\backup.php --full

# Solo backup file
php C:\xampp\htdocs\CollaboraNexio\cron\backup.php --files

# Mostra help
php C:\xampp\htdocs\CollaboraNexio\cron\backup.php --help
```

### Esecuzione tramite Batch

```cmd
# Doppio click su backup.bat o esegui:
C:\xampp\htdocs\CollaboraNexio\cron\backup.bat
```

## Ripristino Backup

### Ripristino Database

```bash
# Ripristina da un backup specifico
php backup.php --restore=2024-01-15_02-00-00
```

### Ripristino Manuale

1. **Database**:
   ```bash
   # Decomprimi il file .gz
   gzip -d db_collabora_2024-01-15_02-00-00.sql.gz

   # Importa nel database
   mysql -u root -p collabora < db_collabora_2024-01-15_02-00-00.sql
   ```

2. **File**:
   ```bash
   # Estrai l'archivio nella directory uploads
   # Fai prima un backup della directory corrente!
   unzip uploads_2024-01-15_02-00-00.zip -d C:\xampp\htdocs\CollaboraNexio\uploads
   ```

## Struttura Backup

```
/backups/
├── 2024-01-15_02-00-00/
│   ├── database/
│   │   └── db_collabora_2024-01-15_02-00-00.sql.gz
│   ├── files/
│   │   └── uploads_2024-01-15_02-00-00.zip
│   └── manifest.json
├── 2024-01-16_02-00-00/
│   └── ...
└── logs/
    ├── backup.log
    └── backup_batch.log
```

## File Manifest

Ogni backup include un `manifest.json` con:
- Metadata del backup
- Checksums SHA256
- Dimensioni file
- Conteggio tabelle e righe
- Errori eventuali
- Durata operazione

Esempio:
```json
{
    "database": {
        "file": "db_collabora_2024-01-15_02-00-00.sql.gz",
        "size": 1548672,
        "checksum": "a3f5d9e2...",
        "tables": ["users", "projects", ...],
        "rows": 15234,
        "compressed": true
    },
    "files": {
        "file": "uploads_2024-01-15_02-00-00.zip",
        "size": 54328901,
        "checksum": "b7c3a1f8...",
        "count": 1250,
        "compression_ratio": 68.5
    },
    "metadata": {
        "date": "2024-01-15_02-00-00",
        "timestamp": 1705286400,
        "type": "incremental",
        "duration": 45.23,
        "total_size": 55877573
    }
}
```

## Monitoraggio

### Log Files

- **backup.log** - Log principale operazioni PHP
- **backup_batch.log** - Log esecuzione batch Windows

### Windows Event Log

Il sistema registra eventi nel log di Windows:
- ID 100: Backup completato con successo
- ID 101: Backup fallito
- ID 102: Spazio disco insufficiente

Visualizza in Event Viewer:
1. Apri Event Viewer
2. Windows Logs → Application
3. Filtra per Source: "CollaboraNexio Backup"

### Verifica Stato

```bash
# Verifica ultimo backup
dir C:\xampp\htdocs\CollaboraNexio\backups /od

# Controlla log errori
type C:\xampp\htdocs\CollaboraNexio\backups\logs\backup.log | findstr ERROR
```

## Configurazioni Avanzate

### Retention Policy

Modifica in `backup_config.php`:
```php
define('BACKUP_RETENTION_DAYS', 30);  // Mantieni per 30 giorni
define('BACKUP_MIN_KEEP', 5);          // Mantieni sempre almeno 5 backup
```

### Compressione

```php
define('BACKUP_COMPRESS_DATABASE', true);
define('BACKUP_COMPRESSION_LEVEL', 6);  // 1-9
```

### Notifiche Email

```php
define('BACKUP_EMAIL_ENABLED', true);
define('BACKUP_EMAIL_ON_SUCCESS', false);  // Solo errori
define('BACKUP_ADMIN_EMAIL', ['admin@esempio.com']);
```

### Backup Secondario

Per copiare su disco esterno o NAS:
```php
define('BACKUP_SECONDARY_PATH', 'D:/Backups/CollaboraNexio');
```

### Esclusione File

```php
define('BACKUP_EXCLUDE_PATTERNS', [
    '*.tmp',
    '*.log',
    'cache/*'
]);
```

## Troubleshooting

### Problema: "mysqldump non trovato"

**Soluzione**: Aggiungi MySQL bin al PATH:
```cmd
setx PATH "%PATH%;C:\xampp\mysql\bin"
```

### Problema: "Permessi negati"

**Soluzione**: Esegui come amministratore o configura Task Scheduler con privilegi elevati.

### Problema: "Backup già in esecuzione"

**Soluzione**: Elimina il file lock:
```cmd
del C:\xampp\htdocs\CollaboraNexio\backups\backup.lock
```

### Problema: "Spazio disco insufficiente"

**Soluzione**:
1. Riduci retention period
2. Abilita compressione maggiore
3. Pulisci backup manualmente

### Problema: "Email non inviate"

**Soluzione**: Configura SMTP in php.ini:
```ini
[mail function]
SMTP = smtp.gmail.com
smtp_port = 587
sendmail_from = backup@esempio.com
```

## Performance

### Ottimizzazioni

1. **Backup Incrementale**: Solo database giornaliero, file settimanali
2. **Compressione**: Riduce dimensione 60-80%
3. **Chunking**: File processati in blocchi da 1MB
4. **Lock File**: Previene esecuzioni multiple

### Tempi Stimati

- Database 100MB: ~30 secondi
- Database 1GB: ~5 minuti
- File 10GB: ~15-20 minuti

### Requisiti Risorse

- RAM: 256MB minimo, 512MB consigliato
- CPU: Basso impatto con nice priority
- Disco: 2-3x dimensione dati per backup temporanei

## Sicurezza

### Best Practices

1. **Permessi Directory**:
   - `/backups`: Accesso limitato a sistema/admin
   - Non esporre via web

2. **Crittografia** (opzionale):
   ```php
   define('BACKUP_ENCRYPTION_ENABLED', true);
   define('BACKUP_ENCRYPTION_PASSWORD', 'password-sicura');
   ```

3. **Verifica Integrità**:
   - Checksums SHA256 automatici
   - Verifica archivi dopo creazione

4. **Protezione Credenziali**:
   - Non salvare password in chiaro
   - Usa account MySQL dedicato con permessi minimi

## Manutenzione

### Controlli Periodici

**Settimanale**:
- Verifica log per errori
- Controlla spazio disco

**Mensile**:
- Test ripristino da backup
- Verifica integrità archivi
- Pulisci log vecchi

**Trimestrale**:
- Rivedi retention policy
- Aggiorna configurazioni
- Test disaster recovery

### Comandi Utili

```bash
# Dimensione totale backup
dir C:\xampp\htdocs\CollaboraNexio\backups /s

# Lista backup per data
dir C:\xampp\htdocs\CollaboraNexio\backups /od

# Trova backup più grandi di 100MB
forfiles /P C:\xampp\htdocs\CollaboraNexio\backups /S /M *.* /C "cmd /c if @fsize gtr 104857600 echo @path @fsize"

# Elimina backup più vecchi di 60 giorni (manuale)
forfiles /P C:\xampp\htdocs\CollaboraNexio\backups /D -60 /C "cmd /c rmdir /s /q @path"
```

## Supporto

Per problemi o domande:
1. Controlla i log in `/backups/logs/`
2. Verifica Event Viewer di Windows
3. Esegui backup manuale con `--debug` per output dettagliato
4. Contatta il team di sviluppo

## Changelog

### v1.0.0 (2024-01-15)
- Rilascio iniziale
- Backup database giornaliero
- Backup file settimanale
- Rotazione 30 giorni
- Notifiche email
- Supporto ripristino
- Windows Task Scheduler integration

## Licenza

Sistema di backup proprietario per CollaboraNexio.
© 2024 CollaboraNexio Development Team. Tutti i diritti riservati.