<?php
/**
 * Configurazione Sistema Backup CollaboraNexio
 *
 * Questo file contiene tutte le configurazioni personalizzabili
 * per il sistema di backup automatico.
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// ===================================================================
// CONFIGURAZIONE RETENTION POLICY
// ===================================================================

/**
 * Numero di giorni per mantenere i backup
 * I backup più vecchi verranno eliminati automaticamente
 */
define('BACKUP_RETENTION_DAYS', 30);

/**
 * Numero minimo di backup da mantenere sempre
 * Indipendentemente dalla retention policy
 */
define('BACKUP_MIN_KEEP', 5);

/**
 * Giorno per backup settimanale completo
 * 0 = Domenica, 1 = Lunedì, ..., 6 = Sabato
 */
define('BACKUP_WEEKLY_DAY', 0);

// ===================================================================
// CONFIGURAZIONE EMAIL NOTIFICHE
// ===================================================================

/**
 * Abilita/disabilita invio notifiche email
 */
define('BACKUP_EMAIL_ENABLED', true);

/**
 * Email amministratore per notifiche
 * Può essere una stringa singola o array per più destinatari
 */
define('BACKUP_ADMIN_EMAIL', [
    'admin@collaboranexio.com',
    // 'backup-admin@collaboranexio.com',
]);

/**
 * Email mittente per notifiche backup
 */
define('BACKUP_FROM_EMAIL', 'backup@collaboranexio.com');
define('BACKUP_FROM_NAME', 'CollaboraNexio Backup System');

/**
 * Configurazione SMTP (se necessario)
 * Decommenta e configura se vuoi usare SMTP invece di mail()
 */
// define('BACKUP_SMTP_HOST', 'smtp.gmail.com');
// define('BACKUP_SMTP_PORT', 587);
// define('BACKUP_SMTP_USER', 'your-email@gmail.com');
// define('BACKUP_SMTP_PASS', 'your-password');
// define('BACKUP_SMTP_SECURE', 'tls'); // 'tls' o 'ssl'

/**
 * Template oggetto email
 */
define('BACKUP_EMAIL_SUBJECT_SUCCESS', '[CollaboraNexio] Backup Completato - %date%');
define('BACKUP_EMAIL_SUBJECT_FAILURE', '[CollaboraNexio] ATTENZIONE: Backup Fallito - %date%');
define('BACKUP_EMAIL_SUBJECT_WARNING', '[CollaboraNexio] Warning: Backup con Avvertimenti - %date%');

/**
 * Invia email anche per backup di successo
 * false = invia solo in caso di errore
 */
define('BACKUP_EMAIL_ON_SUCCESS', false);

// ===================================================================
// CONFIGURAZIONE COMPRESSIONE
// ===================================================================

/**
 * Abilita compressione backup database
 */
define('BACKUP_COMPRESS_DATABASE', true);

/**
 * Abilita compressione backup file
 * I file vengono già compressi in ZIP, questa opzione aggiunge ulteriore compressione
 */
define('BACKUP_COMPRESS_FILES', false);

/**
 * Livello di compressione (1-9)
 * 1 = veloce ma meno compresso
 * 9 = lento ma massima compressione
 */
define('BACKUP_COMPRESSION_LEVEL', 6);

/**
 * Algoritmo di compressione
 * 'gzip' o 'bzip2' (se disponibile)
 */
define('BACKUP_COMPRESSION_TYPE', 'gzip');

// ===================================================================
// CONFIGURAZIONE PERCORSI
// ===================================================================

/**
 * Directory principale backup
 * Usa percorso assoluto o relativo a BASE_PATH
 */
define('BACKUP_STORAGE_PATH', BASE_PATH . '/backups');

/**
 * Directory alternativa per backup (es. disco esterno, NAS)
 * Lascia vuoto per disabilitare
 */
define('BACKUP_SECONDARY_PATH', '');
// define('BACKUP_SECONDARY_PATH', 'D:/Backups/CollaboraNexio');

/**
 * Directory da includere nel backup file
 * Percorsi relativi a BASE_PATH
 */
define('BACKUP_INCLUDE_DIRS', [
    'uploads',
    // 'documents',
    // 'attachments',
]);

/**
 * Pattern file da escludere dal backup
 * Usa pattern glob
 */
define('BACKUP_EXCLUDE_PATTERNS', [
    '*.tmp',
    '*.temp',
    '.DS_Store',
    'Thumbs.db',
    '*.log',
    '.git/*',
]);

// ===================================================================
// CONFIGURAZIONE DATABASE
// ===================================================================

/**
 * Opzioni aggiuntive per mysqldump
 */
define('BACKUP_MYSQLDUMP_OPTIONS', [
    '--single-transaction',
    '--routines',
    '--triggers',
    '--events',
    '--complete-insert',
    '--extended-insert',
    '--quick',
    '--lock-tables=false',
    '--default-character-set=utf8mb4',
]);

/**
 * Escludi tabelle dal backup database
 * Utile per escludere tabelle temporanee o di log
 */
define('BACKUP_EXCLUDE_TABLES', [
    // 'sessions',
    // 'cache',
    // 'logs',
]);

/**
 * Backup struttura senza dati per queste tabelle
 * Utile per tabelle di log che non necessitano dati storici
 */
define('BACKUP_STRUCTURE_ONLY_TABLES', [
    // 'activity_log',
    // 'email_log',
]);

// ===================================================================
// CONFIGURAZIONE PERFORMANCE
// ===================================================================

/**
 * Limite memoria PHP per operazioni backup
 */
define('BACKUP_MEMORY_LIMIT', '512M');

/**
 * Timeout massimo esecuzione (0 = illimitato)
 */
define('BACKUP_TIME_LIMIT', 0);

/**
 * Dimensione chunk per operazioni file (bytes)
 */
define('BACKUP_CHUNK_SIZE', 1048576); // 1MB

/**
 * Numero massimo file per archivio ZIP
 * Se superato, crea archivi multipli
 */
define('BACKUP_MAX_FILES_PER_ZIP', 10000);

/**
 * Dimensione massima archivio ZIP (bytes)
 * Se superata, crea archivi multipli
 */
define('BACKUP_MAX_ZIP_SIZE', 2147483648); // 2GB

// ===================================================================
// CONFIGURAZIONE MONITORAGGIO
// ===================================================================

/**
 * Abilita monitoraggio dettagliato performance
 */
define('BACKUP_MONITORING_ENABLED', true);

/**
 * Invia metriche a sistema monitoraggio esterno
 * Configura endpoint se abilitato
 */
define('BACKUP_METRICS_ENABLED', false);
// define('BACKUP_METRICS_ENDPOINT', 'https://metrics.collaboranexio.com/backup');

/**
 * Livello log
 * DEBUG, INFO, WARNING, ERROR, CRITICAL
 */
define('BACKUP_LOG_LEVEL', 'INFO');

/**
 * Mantieni log per giorni
 */
define('BACKUP_LOG_RETENTION_DAYS', 90);

// ===================================================================
// CONFIGURAZIONE SICUREZZA
// ===================================================================

/**
 * Cripta backup con password
 * IMPORTANTE: Conserva la password in modo sicuro!
 */
define('BACKUP_ENCRYPTION_ENABLED', false);
// define('BACKUP_ENCRYPTION_PASSWORD', 'your-strong-password-here');

/**
 * Algoritmo crittografia
 * 'aes-256-cbc', 'aes-128-cbc', etc.
 */
define('BACKUP_ENCRYPTION_METHOD', 'aes-256-cbc');

/**
 * Verifica checksum dopo backup
 */
define('BACKUP_VERIFY_CHECKSUM', true);

/**
 * Algoritmo checksum
 * 'md5', 'sha256', 'sha512'
 */
define('BACKUP_CHECKSUM_ALGORITHM', 'sha256');

// ===================================================================
// CONFIGURAZIONE RIPRISTINO
// ===================================================================

/**
 * Abilita funzione ripristino automatico
 * ATTENZIONE: Usare con cautela!
 */
define('BACKUP_RESTORE_ENABLED', true);

/**
 * Richiedi conferma per ripristino
 * Solo per CLI interattivo
 */
define('BACKUP_RESTORE_CONFIRM', true);

/**
 * Crea backup prima di ripristino
 * Sicurezza aggiuntiva
 */
define('BACKUP_BEFORE_RESTORE', true);

// ===================================================================
// CONFIGURAZIONE SCHEDULING
// ===================================================================

/**
 * Schedule backup automatici (formato cron)
 * Usato solo se il sistema supporta cron interno
 */
define('BACKUP_SCHEDULE', [
    'database_daily' => '0 2 * * *',     // Ogni giorno alle 2:00
    'files_weekly' => '0 3 * * 0',       // Domenica alle 3:00
    'full_monthly' => '0 4 1 * *',       // Primo del mese alle 4:00
]);

// ===================================================================
// CONFIGURAZIONE CLOUD STORAGE (Opzionale)
// ===================================================================

/**
 * Abilita upload su cloud storage
 */
define('BACKUP_CLOUD_ENABLED', false);

/**
 * Provider cloud storage
 * Supportati: 's3', 'gcs', 'azure', 'dropbox', 'ftp'
 */
define('BACKUP_CLOUD_PROVIDER', 's3');

/**
 * Configurazione provider (esempio AWS S3)
 */
// define('BACKUP_S3_KEY', 'your-access-key');
// define('BACKUP_S3_SECRET', 'your-secret-key');
// define('BACKUP_S3_REGION', 'eu-west-1');
// define('BACKUP_S3_BUCKET', 'collaboranexio-backups');
// define('BACKUP_S3_PATH', 'production/');

/**
 * Configurazione FTP (alternativa)
 */
// define('BACKUP_FTP_HOST', 'ftp.example.com');
// define('BACKUP_FTP_USER', 'username');
// define('BACKUP_FTP_PASS', 'password');
// define('BACKUP_FTP_PATH', '/backups/');
// define('BACKUP_FTP_SSL', true);

/**
 * Mantieni copia locale dopo upload cloud
 */
define('BACKUP_KEEP_LOCAL_AFTER_CLOUD', true);

// ===================================================================
// CONFIGURAZIONE WEBHOOKS (Opzionale)
// ===================================================================

/**
 * Invia notifiche via webhook
 */
define('BACKUP_WEBHOOK_ENABLED', false);

/**
 * URL webhook per notifiche
 * Supporta Slack, Discord, Teams, webhook custom
 */
// define('BACKUP_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');

/**
 * Tipo webhook
 * 'slack', 'discord', 'teams', 'custom'
 */
define('BACKUP_WEBHOOK_TYPE', 'slack');

// ===================================================================
// CONFIGURAZIONE AVANZATA
// ===================================================================

/**
 * Modalità debug (mostra output dettagliato)
 */
define('BACKUP_DEBUG_MODE', false);

/**
 * Simula backup senza eseguire operazioni (dry run)
 */
define('BACKUP_DRY_RUN', false);

/**
 * Pausa tra operazioni intensive (microsecondi)
 * Utile per ridurre carico server
 */
define('BACKUP_OPERATION_DELAY', 0);

/**
 * Priorità processo (nice value per Linux)
 * 19 = priorità minima, -20 = priorità massima
 */
define('BACKUP_PROCESS_PRIORITY', 10);

/**
 * Usa transazioni per backup database
 * Garantisce consistenza ma può rallentare
 */
define('BACKUP_USE_TRANSACTIONS', true);

/**
 * Backup incrementale abilitato
 * Richiede sistema di tracking modifiche
 */
define('BACKUP_INCREMENTAL_ENABLED', false);

/**
 * Percorso file stato per backup incrementale
 */
define('BACKUP_STATE_FILE', BACKUP_STORAGE_PATH . '/.backup_state');

// ===================================================================
// VALIDAZIONE CONFIGURAZIONE
// ===================================================================

// Verifica configurazioni critiche
if (!defined('BASE_PATH')) {
    die('ERRORE: BASE_PATH non definito. Includere config.php prima di backup_config.php');
}

if (!is_dir(BASE_PATH)) {
    die('ERRORE: BASE_PATH non valido: ' . BASE_PATH);
}

if (BACKUP_RETENTION_DAYS < 1) {
    die('ERRORE: BACKUP_RETENTION_DAYS deve essere almeno 1');
}

if (BACKUP_COMPRESSION_LEVEL < 1 || BACKUP_COMPRESSION_LEVEL > 9) {
    die('ERRORE: BACKUP_COMPRESSION_LEVEL deve essere tra 1 e 9');
}

// Crea directory backup se non esiste
if (!is_dir(BACKUP_STORAGE_PATH)) {
    @mkdir(BACKUP_STORAGE_PATH, 0755, true);
    if (!is_dir(BACKUP_STORAGE_PATH)) {
        die('ERRORE: Impossibile creare directory backup: ' . BACKUP_STORAGE_PATH);
    }
}

// ===================================================================
// FUNZIONI HELPER CONFIGURAZIONE
// ===================================================================

/**
 * Ottiene configurazione come array
 *
 * @return array Tutte le configurazioni
 */
function getBackupConfig(): array {
    $config = [];
    $constants = get_defined_constants(true)['user'] ?? [];

    foreach ($constants as $name => $value) {
        if (strpos($name, 'BACKUP_') === 0) {
            $config[$name] = $value;
        }
    }

    return $config;
}

/**
 * Verifica se una feature è abilitata
 *
 * @param string $feature Nome feature
 * @return bool True se abilitata
 */
function isBackupFeatureEnabled(string $feature): bool {
    $constantName = 'BACKUP_' . strtoupper($feature) . '_ENABLED';
    return defined($constantName) && constant($constantName) === true;
}

/**
 * Ottiene valore configurazione con default
 *
 * @param string $key Chiave configurazione
 * @param mixed $default Valore default
 * @return mixed Valore configurazione
 */
function getBackupConfigValue(string $key, $default = null) {
    $constantName = 'BACKUP_' . strtoupper($key);
    return defined($constantName) ? constant($constantName) : $default;
}