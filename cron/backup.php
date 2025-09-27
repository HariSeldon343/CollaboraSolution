<?php
/**
 * Sistema di Backup Automatico per CollaboraNexio
 *
 * Script completo per backup di database e file con rotazione,
 * compressione, verifica integrità e notifiche email
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Previene timeout per operazioni lunghe
set_time_limit(0);
ini_set('memory_limit', '512M');

// Carica configurazione
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

/**
 * Classe principale per gestione backup
 */
class BackupManager {

    // Configurazione backup
    private const BACKUP_DIR = BASE_PATH . '/backups';
    private const DB_BACKUP_DIR = 'database';
    private const FILES_BACKUP_DIR = 'files';
    private const UPLOADS_DIR = BASE_PATH . '/uploads';
    private const LOG_FILE = 'backup.log';
    private const LOCK_FILE = 'backup.lock';
    private const MANIFEST_FILE = 'manifest.json';

    // Politica di retention (giorni)
    private const RETENTION_DAYS = 30;
    private const WEEKLY_BACKUP_DAY = 0; // Domenica per backup settimanale file

    // Configurazione email
    private const ADMIN_EMAIL = 'admin@collaboranexio.com';
    private const FROM_EMAIL = 'backup@collaboranexio.com';
    private const EMAIL_SUBJECT_SUCCESS = 'CollaboraNexio - Backup Completato';
    private const EMAIL_SUBJECT_FAILURE = 'CollaboraNexio - ERRORE Backup';

    // Configurazione compressione
    private const USE_GZIP = true;
    private const COMPRESSION_LEVEL = 9; // 1-9, 9 = massima compressione

    // Variabili istanza
    private string $backupDate;
    private string $backupPath;
    private array $manifest = [];
    private float $startTime;
    private int $backupSize = 0;
    private array $errors = [];
    private bool $isFullBackup = false;
    private ?PDO $pdo = null;

    /**
     * Costruttore
     */
    public function __construct() {
        $this->backupDate = date('Y-m-d_H-i-s');
        $this->startTime = microtime(true);
        $this->initializeDirectories();
    }

    /**
     * Esegue il backup completo
     *
     * @param array $options Opzioni di backup
     * @return bool True se successo
     */
    public function execute(array $options = []): bool {
        try {
            // Verifica lock per prevenire esecuzioni multiple
            if (!$this->acquireLock()) {
                $this->log('WARNING', 'Backup già in esecuzione');
                return false;
            }

            $this->log('INFO', '=== INIZIO BACKUP ===');

            // Determina tipo di backup
            $this->isFullBackup = $options['full'] ?? false;
            $backupType = $this->isFullBackup ? 'FULL' : 'INCREMENTAL';
            $this->log('INFO', "Tipo backup: $backupType");

            // Crea directory per questo backup
            $this->backupPath = self::BACKUP_DIR . '/' . $this->backupDate;
            if (!mkdir($this->backupPath, 0755, true)) {
                throw new Exception('Impossibile creare directory backup');
            }

            // Esegue backup database (sempre)
            $dbSuccess = $this->backupDatabase();

            // Esegue backup file (settimanale o su richiesta)
            $filesSuccess = true;
            if ($this->shouldBackupFiles($options)) {
                $filesSuccess = $this->backupFiles();
            }

            // Genera manifest
            $this->generateManifest();

            // Pulisce vecchi backup
            $this->cleanOldBackups();

            // Calcola statistiche
            $duration = round(microtime(true) - $this->startTime, 2);
            $sizeFormatted = $this->formatBytes($this->backupSize);

            $this->log('INFO', "Backup completato in {$duration}s, dimensione: $sizeFormatted");

            // Invia notifica
            $success = $dbSuccess && $filesSuccess && empty($this->errors);
            $this->sendNotification($success, $duration, $sizeFormatted);

            $this->log('INFO', '=== FINE BACKUP ===');

            return $success;

        } catch (Exception $e) {
            $this->log('CRITICAL', 'Errore fatale: ' . $e->getMessage());
            $this->errors[] = $e->getMessage();
            $this->sendNotification(false, 0, '0');
            return false;

        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Backup del database MySQL
     *
     * @return bool True se successo
     */
    private function backupDatabase(): bool {
        try {
            $this->log('INFO', 'Inizio backup database');

            $dbBackupPath = $this->backupPath . '/' . self::DB_BACKUP_DIR;
            if (!mkdir($dbBackupPath, 0755, true)) {
                throw new Exception('Impossibile creare directory database backup');
            }

            // Nome file backup
            $backupFile = $dbBackupPath . '/db_' . DB_NAME . '_' . $this->backupDate . '.sql';

            // Costruisce comando mysqldump
            $command = sprintf(
                'mysqldump --host=%s --port=%d --user=%s --password=%s ' .
                '--single-transaction --routines --triggers --events ' .
                '--complete-insert --extended-insert --quick --lock-tables=false ' .
                '--default-character-set=utf8mb4 %s > %s 2>&1',
                escapeshellarg(DB_HOST),
                DB_PORT,
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg(DB_NAME),
                escapeshellarg($backupFile)
            );

            // Esegue mysqldump
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $error = implode("\n", $output);
                throw new Exception("mysqldump fallito: $error");
            }

            // Verifica che il file sia stato creato e non vuoto
            if (!file_exists($backupFile) || filesize($backupFile) === 0) {
                throw new Exception('File backup database vuoto o non creato');
            }

            $originalSize = filesize($backupFile);
            $this->log('INFO', 'Database dump completato: ' . $this->formatBytes($originalSize));

            // Compressione con gzip
            if (self::USE_GZIP) {
                $gzipFile = $backupFile . '.gz';
                $this->log('INFO', 'Compressione database in corso...');

                if (!$this->compressFile($backupFile, $gzipFile)) {
                    throw new Exception('Compressione database fallita');
                }

                // Rimuove file non compresso
                unlink($backupFile);
                $backupFile = $gzipFile;

                $compressedSize = filesize($gzipFile);
                $ratio = round((1 - $compressedSize / $originalSize) * 100, 2);
                $this->log('INFO', "Database compresso: {$this->formatBytes($compressedSize)} (riduzione $ratio%)");
            }

            // Verifica integrità
            if (!$this->verifyDatabaseBackup($backupFile)) {
                throw new Exception('Verifica integrità database fallita');
            }

            // Aggiorna manifest
            $this->manifest['database'] = [
                'file' => basename($backupFile),
                'size' => filesize($backupFile),
                'checksum' => hash_file('sha256', $backupFile),
                'tables' => $this->getDatabaseTables(),
                'rows' => $this->getDatabaseRowCount(),
                'compressed' => self::USE_GZIP
            ];

            $this->backupSize += filesize($backupFile);
            $this->log('INFO', 'Backup database completato con successo');

            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore backup database: ' . $e->getMessage());
            $this->errors[] = 'Database: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Backup dei file uploads
     *
     * @return bool True se successo
     */
    private function backupFiles(): bool {
        try {
            $this->log('INFO', 'Inizio backup file uploads');

            if (!is_dir(self::UPLOADS_DIR)) {
                $this->log('WARNING', 'Directory uploads non trovata');
                return true;
            }

            $filesBackupPath = $this->backupPath . '/' . self::FILES_BACKUP_DIR;
            if (!mkdir($filesBackupPath, 0755, true)) {
                throw new Exception('Impossibile creare directory files backup');
            }

            // Nome archivio
            $archiveFile = $filesBackupPath . '/uploads_' . $this->backupDate . '.zip';

            // Crea archivio ZIP
            $zip = new ZipArchive();
            if ($zip->open($archiveFile, ZipArchive::CREATE) !== true) {
                throw new Exception('Impossibile creare archivio ZIP');
            }

            // Conta file e calcola dimensione
            $fileCount = 0;
            $totalSize = 0;

            // Funzione ricorsiva per aggiungere file
            $addFiles = function($dir, $zipPath = '') use ($zip, &$fileCount, &$totalSize, &$addFiles) {
                $files = scandir($dir);

                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;

                    $fullPath = $dir . '/' . $file;
                    $localPath = $zipPath . ($zipPath ? '/' : '') . $file;

                    if (is_dir($fullPath)) {
                        $zip->addEmptyDir($localPath);
                        $addFiles($fullPath, $localPath);
                    } else {
                        $zip->addFile($fullPath, $localPath);
                        $fileCount++;
                        $totalSize += filesize($fullPath);

                        // Log progressione ogni 100 file
                        if ($fileCount % 100 === 0) {
                            $this->log('INFO', "Processati $fileCount file...");
                        }
                    }
                }
            };

            // Aggiungi tutti i file
            $this->log('INFO', 'Archiviazione file in corso...');
            $addFiles(self::UPLOADS_DIR);

            // Chiude archivio
            $zip->close();

            $archiveSize = filesize($archiveFile);
            $this->log('INFO', "Archiviati $fileCount file, dimensione: " . $this->formatBytes($archiveSize));

            // Verifica integrità archivio
            if (!$this->verifyZipArchive($archiveFile)) {
                throw new Exception('Verifica integrità archivio fallita');
            }

            // Aggiorna manifest
            $this->manifest['files'] = [
                'file' => basename($archiveFile),
                'size' => $archiveSize,
                'checksum' => hash_file('sha256', $archiveFile),
                'count' => $fileCount,
                'original_size' => $totalSize,
                'compression_ratio' => round((1 - $archiveSize / $totalSize) * 100, 2)
            ];

            $this->backupSize += $archiveSize;
            $this->log('INFO', 'Backup file completato con successo');

            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore backup file: ' . $e->getMessage());
            $this->errors[] = 'Files: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Determina se fare backup dei file
     *
     * @param array $options Opzioni
     * @return bool True se deve fare backup file
     */
    private function shouldBackupFiles(array $options): bool {
        // Forza backup file se richiesto
        if (isset($options['files']) && $options['files']) {
            return true;
        }

        // Backup settimanale la domenica
        if (date('w') == self::WEEKLY_BACKUP_DAY) {
            return true;
        }

        // Backup completo
        if ($this->isFullBackup) {
            return true;
        }

        return false;
    }

    /**
     * Comprime un file con gzip
     *
     * @param string $source File sorgente
     * @param string $dest File destinazione
     * @return bool True se successo
     */
    private function compressFile(string $source, string $dest): bool {
        try {
            $fp_in = fopen($source, 'rb');
            if (!$fp_in) return false;

            $fp_out = gzopen($dest, 'wb' . self::COMPRESSION_LEVEL);
            if (!$fp_out) {
                fclose($fp_in);
                return false;
            }

            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 1048576)); // 1MB chunks
            }

            fclose($fp_in);
            gzclose($fp_out);

            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore compressione: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica integrità backup database
     *
     * @param string $file File backup
     * @return bool True se valido
     */
    private function verifyDatabaseBackup(string $file): bool {
        try {
            // Verifica esistenza e dimensione
            if (!file_exists($file) || filesize($file) === 0) {
                return false;
            }

            // Se compresso, verifica che si possa decomprimere
            if (str_ends_with($file, '.gz')) {
                $gz = gzopen($file, 'rb');
                if (!$gz) return false;

                // Legge primi bytes per verificare
                $test = gzread($gz, 1024);
                gzclose($gz);

                if ($test === false || empty($test)) {
                    return false;
                }
            } else {
                // Per file SQL, verifica header
                $handle = fopen($file, 'r');
                if (!$handle) return false;

                $header = fread($handle, 1024);
                fclose($handle);

                // Verifica che contenga statement SQL
                if (!str_contains($header, 'MySQL') && !str_contains($header, 'CREATE')) {
                    return false;
                }
            }

            $this->log('INFO', 'Verifica integrità database: OK');
            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore verifica database: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica integrità archivio ZIP
     *
     * @param string $file File ZIP
     * @return bool True se valido
     */
    private function verifyZipArchive(string $file): bool {
        try {
            $zip = new ZipArchive();
            $result = $zip->open($file, ZipArchive::CHECKCONS);

            if ($result !== true) {
                $this->log('ERROR', 'Archivio ZIP corrotto');
                return false;
            }

            $numFiles = $zip->numFiles;
            $zip->close();

            if ($numFiles === 0) {
                $this->log('WARNING', 'Archivio ZIP vuoto');
                return false;
            }

            $this->log('INFO', "Verifica integrità archivio: OK ($numFiles file)");
            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore verifica archivio: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Genera manifest del backup
     */
    private function generateManifest(): void {
        try {
            $this->manifest['metadata'] = [
                'date' => $this->backupDate,
                'timestamp' => time(),
                'type' => $this->isFullBackup ? 'full' : 'incremental',
                'duration' => round(microtime(true) - $this->startTime, 2),
                'total_size' => $this->backupSize,
                'server' => [
                    'hostname' => gethostname(),
                    'php_version' => PHP_VERSION,
                    'os' => PHP_OS
                ],
                'errors' => $this->errors
            ];

            $manifestFile = $this->backupPath . '/' . self::MANIFEST_FILE;
            file_put_contents(
                $manifestFile,
                json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            $this->log('INFO', 'Manifest generato');

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore generazione manifest: ' . $e->getMessage());
        }
    }

    /**
     * Pulisce backup vecchi secondo retention policy
     */
    private function cleanOldBackups(): void {
        try {
            $this->log('INFO', 'Pulizia backup vecchi...');

            $cutoffTime = strtotime('-' . self::RETENTION_DAYS . ' days');
            $deletedCount = 0;
            $freedSpace = 0;

            // Scansiona directory backup
            $dirs = glob(self::BACKUP_DIR . '/*', GLOB_ONLYDIR);

            foreach ($dirs as $dir) {
                // Estrae data dal nome directory
                $dirName = basename($dir);
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dirName, $matches)) {
                    $backupTime = strtotime($matches[0]);

                    if ($backupTime < $cutoffTime) {
                        // Calcola dimensione prima di eliminare
                        $size = $this->getDirectorySize($dir);

                        // Elimina directory
                        if ($this->deleteDirectory($dir)) {
                            $deletedCount++;
                            $freedSpace += $size;
                            $this->log('INFO', "Eliminato backup vecchio: $dirName");
                        }
                    }
                }
            }

            if ($deletedCount > 0) {
                $this->log('INFO', "Eliminati $deletedCount backup, liberati " . $this->formatBytes($freedSpace));
            } else {
                $this->log('INFO', 'Nessun backup da eliminare');
            }

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore pulizia backup: ' . $e->getMessage());
        }
    }

    /**
     * Elimina directory ricorsivamente
     *
     * @param string $dir Directory da eliminare
     * @return bool True se successo
     */
    private function deleteDirectory(string $dir): bool {
        if (!is_dir($dir)) return false;

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Calcola dimensione directory
     *
     * @param string $dir Directory
     * @return int Dimensione in bytes
     */
    private function getDirectorySize(string $dir): int {
        $size = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Ottiene lista tabelle database
     *
     * @return array Lista tabelle
     */
    private function getDatabaseTables(): array {
        try {
            $pdo = $this->getPDO();
            $stmt = $pdo->query("SHOW TABLES");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->log('ERROR', 'Errore recupero tabelle: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Conta righe totali database
     *
     * @return int Numero totale righe
     */
    private function getDatabaseRowCount(): int {
        try {
            $pdo = $this->getPDO();
            $total = 0;

            $tables = $this->getDatabaseTables();
            foreach ($tables as $table) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                $total += (int) $stmt->fetchColumn();
            }

            return $total;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore conteggio righe: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Ottiene connessione PDO
     *
     * @return PDO
     */
    private function getPDO(): PDO {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $this->pdo;
    }

    /**
     * Invia notifica email
     *
     * @param bool $success Stato backup
     * @param float $duration Durata in secondi
     * @param string $size Dimensione formattata
     */
    private function sendNotification(bool $success, float $duration, string $size): void {
        try {
            $subject = $success ? self::EMAIL_SUBJECT_SUCCESS : self::EMAIL_SUBJECT_FAILURE;

            $message = "Backup Report - " . date('Y-m-d H:i:s') . "\n";
            $message .= str_repeat('=', 50) . "\n\n";

            $message .= "Stato: " . ($success ? 'SUCCESSO' : 'FALLITO') . "\n";
            $message .= "Tipo: " . ($this->isFullBackup ? 'COMPLETO' : 'INCREMENTALE') . "\n";
            $message .= "Durata: {$duration} secondi\n";
            $message .= "Dimensione: $size\n";
            $message .= "Path: {$this->backupPath}\n\n";

            if (!empty($this->manifest['database'])) {
                $message .= "DATABASE:\n";
                $message .= "- File: " . $this->manifest['database']['file'] . "\n";
                $message .= "- Dimensione: " . $this->formatBytes($this->manifest['database']['size']) . "\n";
                $message .= "- Tabelle: " . count($this->manifest['database']['tables']) . "\n";
                $message .= "- Righe: " . number_format($this->manifest['database']['rows']) . "\n\n";
            }

            if (!empty($this->manifest['files'])) {
                $message .= "FILE:\n";
                $message .= "- File: " . $this->manifest['files']['file'] . "\n";
                $message .= "- Dimensione: " . $this->formatBytes($this->manifest['files']['size']) . "\n";
                $message .= "- Numero file: " . $this->manifest['files']['count'] . "\n";
                $message .= "- Compressione: " . $this->manifest['files']['compression_ratio'] . "%\n\n";
            }

            if (!empty($this->errors)) {
                $message .= "ERRORI:\n";
                foreach ($this->errors as $error) {
                    $message .= "- $error\n";
                }
            }

            // Headers email
            $headers = [
                'From' => self::FROM_EMAIL,
                'Reply-To' => self::FROM_EMAIL,
                'X-Mailer' => 'PHP/' . phpversion(),
                'Content-Type' => 'text/plain; charset=UTF-8'
            ];

            // Invia email (solo se non in modalità debug)
            if (!DEBUG_MODE) {
                mail(
                    self::ADMIN_EMAIL,
                    $subject,
                    $message,
                    $headers
                );
                $this->log('INFO', 'Notifica email inviata');
            } else {
                $this->log('DEBUG', 'Email non inviata (DEBUG_MODE attivo)');
                $this->log('DEBUG', "Subject: $subject");
                $this->log('DEBUG', "Message:\n$message");
            }

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore invio notifica: ' . $e->getMessage());
        }
    }

    /**
     * Acquisisce lock per prevenire esecuzioni multiple
     *
     * @return bool True se lock acquisito
     */
    private function acquireLock(): bool {
        $lockFile = self::BACKUP_DIR . '/' . self::LOCK_FILE;

        // Verifica se esiste già un lock
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            $age = time() - $lockTime;

            // Se il lock è più vecchio di 2 ore, consideralo stale
            if ($age > 7200) {
                $this->log('WARNING', 'Rimosso lock stale');
                unlink($lockFile);
            } else {
                return false;
            }
        }

        // Crea lock file
        return file_put_contents($lockFile, getmypid() . "\n" . date('Y-m-d H:i:s')) !== false;
    }

    /**
     * Rilascia lock
     */
    private function releaseLock(): void {
        $lockFile = self::BACKUP_DIR . '/' . self::LOCK_FILE;
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Inizializza directory necessarie
     */
    private function initializeDirectories(): void {
        $dirs = [
            self::BACKUP_DIR,
            self::BACKUP_DIR . '/logs'
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Scrive nel file di log
     *
     * @param string $level Livello log
     * @param string $message Messaggio
     */
    private function log(string $level, string $message): void {
        $logFile = self::BACKUP_DIR . '/logs/' . self::LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [$level] $message" . PHP_EOL;

        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Output su console se eseguito da CLI
        if (php_sapi_name() === 'cli') {
            echo $logLine;
        }
    }

    /**
     * Formatta bytes in formato leggibile
     *
     * @param int $bytes Dimensione in bytes
     * @return string Dimensione formattata
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Ripristina backup
     *
     * @param string $backupDate Data del backup da ripristinare
     * @param array $options Opzioni ripristino
     * @return bool True se successo
     */
    public function restore(string $backupDate, array $options = []): bool {
        try {
            $this->log('INFO', "=== INIZIO RIPRISTINO BACKUP $backupDate ===");

            $backupPath = self::BACKUP_DIR . '/' . $backupDate;
            if (!is_dir($backupPath)) {
                throw new Exception("Backup non trovato: $backupDate");
            }

            // Carica manifest
            $manifestFile = $backupPath . '/' . self::MANIFEST_FILE;
            if (!file_exists($manifestFile)) {
                throw new Exception('Manifest non trovato');
            }

            $manifest = json_decode(file_get_contents($manifestFile), true);

            // Ripristina database se richiesto
            if ($options['database'] ?? true) {
                if (!$this->restoreDatabase($backupPath, $manifest)) {
                    throw new Exception('Ripristino database fallito');
                }
            }

            // Ripristina file se richiesto
            if ($options['files'] ?? false) {
                if (!$this->restoreFiles($backupPath, $manifest)) {
                    throw new Exception('Ripristino file fallito');
                }
            }

            $this->log('INFO', '=== RIPRISTINO COMPLETATO ===');
            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore ripristino: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ripristina database da backup
     *
     * @param string $backupPath Path backup
     * @param array $manifest Manifest backup
     * @return bool True se successo
     */
    private function restoreDatabase(string $backupPath, array $manifest): bool {
        try {
            if (empty($manifest['database'])) {
                $this->log('WARNING', 'Nessun backup database nel manifest');
                return true;
            }

            $dbFile = $backupPath . '/' . self::DB_BACKUP_DIR . '/' . $manifest['database']['file'];

            if (!file_exists($dbFile)) {
                throw new Exception("File database non trovato: $dbFile");
            }

            // Se compresso, decomprime temporaneamente
            $sqlFile = $dbFile;
            if (str_ends_with($dbFile, '.gz')) {
                $this->log('INFO', 'Decompressione database...');
                $sqlFile = str_replace('.gz', '', $dbFile);

                $gz = gzopen($dbFile, 'rb');
                $out = fopen($sqlFile, 'wb');

                while (!gzeof($gz)) {
                    fwrite($out, gzread($gz, 1048576));
                }

                gzclose($gz);
                fclose($out);
            }

            // Ripristina con mysql
            $command = sprintf(
                'mysql --host=%s --port=%d --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg(DB_HOST),
                DB_PORT,
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg(DB_NAME),
                escapeshellarg($sqlFile)
            );

            $this->log('INFO', 'Ripristino database in corso...');
            exec($command, $output, $returnCode);

            // Rimuove file temporaneo se era compresso
            if ($sqlFile !== $dbFile) {
                unlink($sqlFile);
            }

            if ($returnCode !== 0) {
                throw new Exception('mysql restore fallito: ' . implode("\n", $output));
            }

            $this->log('INFO', 'Database ripristinato con successo');
            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore ripristino database: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ripristina file da backup
     *
     * @param string $backupPath Path backup
     * @param array $manifest Manifest backup
     * @return bool True se successo
     */
    private function restoreFiles(string $backupPath, array $manifest): bool {
        try {
            if (empty($manifest['files'])) {
                $this->log('WARNING', 'Nessun backup file nel manifest');
                return true;
            }

            $archiveFile = $backupPath . '/' . self::FILES_BACKUP_DIR . '/' . $manifest['files']['file'];

            if (!file_exists($archiveFile)) {
                throw new Exception("Archivio file non trovato: $archiveFile");
            }

            $this->log('INFO', 'Estrazione archivio file...');

            $zip = new ZipArchive();
            if ($zip->open($archiveFile) !== true) {
                throw new Exception('Impossibile aprire archivio ZIP');
            }

            // Backup directory corrente prima di sovrascrivere
            $tempBackup = self::UPLOADS_DIR . '_backup_' . date('YmdHis');
            if (is_dir(self::UPLOADS_DIR)) {
                rename(self::UPLOADS_DIR, $tempBackup);
                $this->log('INFO', "Backup temporaneo uploads in: $tempBackup");
            }

            // Estrae archivio
            $zip->extractTo(self::UPLOADS_DIR);
            $zip->close();

            $this->log('INFO', 'File ripristinati con successo');
            return true;

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore ripristino file: ' . $e->getMessage());
            return false;
        }
    }
}

// ===================================
// ESECUZIONE DA CLI
// ===================================

if (php_sapi_name() === 'cli') {
    // Parse argomenti CLI
    $options = getopt('', ['full', 'files', 'restore:', 'help']);

    if (isset($options['help'])) {
        echo "Utilizzo: php backup.php [opzioni]\n";
        echo "Opzioni:\n";
        echo "  --full       Esegue backup completo (database + file)\n";
        echo "  --files      Forza backup dei file\n";
        echo "  --restore=DATA  Ripristina backup dalla data specificata\n";
        echo "  --help       Mostra questo messaggio\n";
        exit(0);
    }

    $backup = new BackupManager();

    // Modalità ripristino
    if (isset($options['restore'])) {
        $success = $backup->restore($options['restore']);
        exit($success ? 0 : 1);
    }

    // Modalità backup
    $backupOptions = [
        'full' => isset($options['full']),
        'files' => isset($options['files'])
    ];

    $success = $backup->execute($backupOptions);
    exit($success ? 0 : 1);
}

// Se chiamato via web, mostra errore
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Questo script può essere eseguito solo da CLI');
}