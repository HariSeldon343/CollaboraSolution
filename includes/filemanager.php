<?php
declare(strict_types=1);

/**
 * FileManager Class
 *
 * Gestione sicura dei file con isolamento multi-tenant
 * Supporta upload, download, gestione cartelle e utilità varie
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */
class FileManager {
    private PDO $pdo;
    private int $tenantId;
    private int $userId;

    // Configurazione
    private array $config = [
        'base_upload_path' => '/var/uploads', // Fuori dalla document root
        'max_file_size' => 104857600, // 100MB default
        'chunk_size' => 2097152, // 2MB per chunk
        'thumbnail_width' => 150,
        'thumbnail_height' => 150,
        'rate_limit_downloads' => 10, // Downloads per minuto
        'allowed_mime_types' => [
            // Documenti
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',

            // Immagini
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',

            // Archivi
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',

            // Audio/Video
            'audio/mpeg',
            'audio/wav',
            'video/mp4',
            'video/mpeg',
            'video/quicktime'
        ],
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js',
            'jar', 'msi', 'app', 'deb', 'rpm', 'dmg', 'pkg'
        ],
        'image_mime_types' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp'
        ]
    ];

    /**
     * Costruttore
     *
     * @param PDO $pdo Connessione database
     * @param int $tenantId ID del tenant
     * @param int $userId ID dell'utente
     */
    public function __construct(PDO $pdo, int $tenantId, int $userId) {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->userId = $userId;

        // Verifica che la directory base esista
        if (!is_dir($this->config['base_upload_path'])) {
            mkdir($this->config['base_upload_path'], 0755, true);
        }
    }

    /**
     * Upload sicuro di un file
     *
     * @param array $uploadedFile Array $_FILES element
     * @param string $category Categoria del file (opzionale)
     * @param array $metadata Metadati aggiuntivi (opzionale)
     * @return array Informazioni sul file caricato
     * @throws Exception Se l'upload fallisce
     */
    public function uploadFile(array $uploadedFile, string $category = 'general', array $metadata = []): array {
        try {
            // Validazione iniziale
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception($this->getUploadErrorMessage($uploadedFile['error']));
            }

            // Validazione completa del file
            $this->validateUploadedFile($uploadedFile['tmp_name'], $uploadedFile['name']);

            // Genera percorso sicuro
            $securePath = $this->generateSecurePath($this->tenantId);
            $secureFilename = $this->generateSecureFilename($uploadedFile['name']);
            $fullPath = $securePath . '/' . $secureFilename;

            // Crea directory se non esiste
            if (!is_dir($securePath)) {
                mkdir($securePath, 0755, true);
            }

            // Calcola hash del file per deduplicazione
            $fileHash = $this->calculateFileHash($uploadedFile['tmp_name']);

            // Verifica se il file esiste già (deduplicazione)
            $existingFile = $this->findFileByHash($fileHash);
            if ($existingFile) {
                // Crea solo un riferimento al file esistente
                return $this->createFileReference($existingFile['id'], $uploadedFile['name'], $category, $metadata);
            }

            // Sposta il file nella posizione sicura
            if (!move_uploaded_file($uploadedFile['tmp_name'], $fullPath)) {
                throw new Exception('Impossibile spostare il file caricato');
            }

            // Imposta permessi sicuri
            chmod($fullPath, 0644);

            // Genera thumbnail se è un'immagine
            $thumbnailPath = null;
            $mimeType = $this->getRealMimeType($fullPath);
            if (in_array($mimeType, $this->config['image_mime_types'])) {
                $thumbnailPath = $this->generateThumbnail($fullPath,
                    $this->config['thumbnail_width'],
                    $this->config['thumbnail_height']
                );
            }

            // Registra nel database
            $fileId = $this->registerFile([
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'original_name' => $uploadedFile['name'],
                'secure_name' => $secureFilename,
                'path' => $fullPath,
                'thumbnail_path' => $thumbnailPath,
                'mime_type' => $mimeType,
                'size' => filesize($fullPath),
                'hash' => $fileHash,
                'category' => $category,
                'metadata' => json_encode($metadata)
            ]);

            // Log dell'attività
            $this->logActivity('upload', $fileId, [
                'original_name' => $uploadedFile['name'],
                'size' => filesize($fullPath)
            ]);

            // Hook per scansione antivirus (se configurato)
            $this->scanForViruses($fullPath);

            return [
                'success' => true,
                'file_id' => $fileId,
                'original_name' => $uploadedFile['name'],
                'secure_name' => $secureFilename,
                'size' => filesize($fullPath),
                'mime_type' => $mimeType,
                'thumbnail' => $thumbnailPath ? basename($thumbnailPath) : null
            ];

        } catch (Exception $e) {
            // Pulizia in caso di errore
            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }
            throw $e;
        }
    }

    /**
     * Upload di file in chunk per file di grandi dimensioni
     *
     * @param array $chunk Chunk del file
     * @param string $uploadId ID univoco dell'upload
     * @param int $chunkNumber Numero del chunk corrente
     * @param int $totalChunks Numero totale di chunk
     * @return array Status dell'upload
     */
    public function uploadChunk(array $chunk, string $uploadId, int $chunkNumber, int $totalChunks): array {
        try {
            // Validazione parametri
            if ($chunkNumber < 0 || $chunkNumber >= $totalChunks) {
                throw new Exception('Numero chunk non valido');
            }

            // Percorso temporaneo per i chunk
            $tempPath = $this->config['base_upload_path'] . '/temp/' . $this->tenantId;
            if (!is_dir($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $chunkPath = $tempPath . '/' . $uploadId . '.part' . $chunkNumber;

            // Salva il chunk
            if (!move_uploaded_file($chunk['tmp_name'], $chunkPath)) {
                throw new Exception('Impossibile salvare il chunk');
            }

            // Verifica se tutti i chunk sono stati caricati
            $uploadedChunks = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (file_exists($tempPath . '/' . $uploadId . '.part' . $i)) {
                    $uploadedChunks++;
                }
            }

            if ($uploadedChunks === $totalChunks) {
                // Combina tutti i chunk
                return $this->combineChunks($uploadId, $totalChunks);
            }

            return [
                'success' => true,
                'uploaded_chunks' => $uploadedChunks,
                'total_chunks' => $totalChunks,
                'completed' => false
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Combina i chunk in un file completo
     *
     * @param string $uploadId ID dell'upload
     * @param int $totalChunks Numero totale di chunk
     * @return array Informazioni sul file combinato
     */
    private function combineChunks(string $uploadId, int $totalChunks): array {
        $tempPath = $this->config['base_upload_path'] . '/temp/' . $this->tenantId;
        $finalPath = $this->generateSecurePath($this->tenantId);
        $finalFilename = $this->generateSecureFilename($uploadId);
        $finalFile = $finalPath . '/' . $finalFilename;

        // Crea directory di destinazione se necessario
        if (!is_dir($finalPath)) {
            mkdir($finalPath, 0755, true);
        }

        // Apri file di destinazione
        $output = fopen($finalFile, 'wb');
        if (!$output) {
            throw new Exception('Impossibile creare il file finale');
        }

        // Combina i chunk
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $tempPath . '/' . $uploadId . '.part' . $i;
            $chunk = fopen($chunkPath, 'rb');
            if (!$chunk) {
                fclose($output);
                unlink($finalFile);
                throw new Exception('Impossibile leggere il chunk ' . $i);
            }

            // Copia il contenuto del chunk
            stream_copy_to_stream($chunk, $output);
            fclose($chunk);

            // Elimina il chunk dopo la copia
            unlink($chunkPath);
        }

        fclose($output);

        // Registra il file nel database
        $fileHash = $this->calculateFileHash($finalFile);
        $mimeType = $this->getRealMimeType($finalFile);

        $fileId = $this->registerFile([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'original_name' => $uploadId,
            'secure_name' => $finalFilename,
            'path' => $finalFile,
            'mime_type' => $mimeType,
            'size' => filesize($finalFile),
            'hash' => $fileHash,
            'category' => 'chunked_upload'
        ]);

        return [
            'success' => true,
            'completed' => true,
            'file_id' => $fileId,
            'size' => filesize($finalFile),
            'mime_type' => $mimeType
        ];
    }

    /**
     * Download sicuro di un file
     *
     * @param int $fileId ID del file
     * @param bool $inline True per visualizzare inline, false per download
     * @return void Output diretto del file
     */
    public function downloadFile(int $fileId, bool $inline = false): void {
        try {
            // Verifica permessi
            if (!$this->hasFileAccess($fileId)) {
                http_response_code(403);
                die('Accesso negato');
            }

            // Verifica rate limiting
            if (!$this->checkDownloadRateLimit()) {
                http_response_code(429);
                die('Troppe richieste. Riprova tra poco.');
            }

            // Recupera informazioni file
            $file = $this->getFileInfo($fileId);
            if (!$file || !file_exists($file['path'])) {
                http_response_code(404);
                die('File non trovato');
            }

            // Log attività download
            $this->logActivity('download', $fileId, [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);

            // Imposta headers appropriati
            $this->setDownloadHeaders($file, $inline);

            // Supporto per Range/Resume
            $this->streamFile($file['path']);

        } catch (Exception $e) {
            error_log('Errore download file: ' . $e->getMessage());
            http_response_code(500);
            die('Errore durante il download');
        }
    }

    /**
     * Stream del file con supporto Range
     *
     * @param string $filepath Percorso del file
     */
    private function streamFile(string $filepath): void {
        $filesize = filesize($filepath);
        $offset = 0;
        $length = $filesize;

        // Gestione Range requests
        if (isset($_SERVER['HTTP_RANGE'])) {
            preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
            $offset = intval($matches[1]);

            if (isset($matches[2])) {
                $length = intval($matches[2]) - $offset + 1;
            } else {
                $length = $filesize - $offset;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $filesize);
        }

        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');

        // Stream del file
        $file = fopen($filepath, 'rb');
        if (!$file) {
            throw new Exception('Impossibile aprire il file per lo streaming');
        }

        // Posiziona il puntatore se necessario
        if ($offset > 0) {
            fseek($file, $offset);
        }

        // Output del contenuto a blocchi per efficienza memoria
        $chunkSize = 8192; // 8KB chunks
        while (!feof($file) && $length > 0) {
            $read = min($chunkSize, $length);
            echo fread($file, $read);
            $length -= $read;
            flush();
        }

        fclose($file);
    }

    /**
     * Imposta headers per il download
     *
     * @param array $file Informazioni del file
     * @param bool $inline True per visualizzazione inline
     */
    private function setDownloadHeaders(array $file, bool $inline): void {
        // Pulisce eventuali output precedenti
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Content type
        header('Content-Type: ' . $file['mime_type']);

        // Disposition
        $disposition = $inline ? 'inline' : 'attachment';
        $filename = $this->sanitizeHeaderFilename($file['original_name']);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');

        // Security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');

        // Cache control
        header('Cache-Control: private, max-age=3600');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    }

    /**
     * Crea una nuova cartella
     *
     * @param string $path Percorso relativo della cartella
     * @param int $parentId ID della cartella padre (opzionale)
     * @return array Informazioni sulla cartella creata
     */
    public function createFolder(string $path, int $parentId = 0): array {
        try {
            // Sanitizza il percorso
            $safePath = $this->sanitizePath($path);

            // Genera percorso completo con isolamento tenant
            $fullPath = $this->config['base_upload_path'] . '/' . $this->tenantId . '/' . $safePath;

            // Verifica che non esista già
            if (is_dir($fullPath)) {
                throw new Exception('La cartella esiste già');
            }

            // Crea la cartella con permessi appropriati
            if (!mkdir($fullPath, 0755, true)) {
                throw new Exception('Impossibile creare la cartella');
            }

            // Registra nel database
            $stmt = $this->pdo->prepare("
                INSERT INTO folders (tenant_id, parent_id, name, path, created_by, created_at)
                VALUES (:tenant_id, :parent_id, :name, :path, :user_id, NOW())
            ");

            $folderName = basename($safePath);
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':parent_id' => $parentId,
                ':name' => $folderName,
                ':path' => $safePath,
                ':user_id' => $this->userId
            ]);

            $folderId = $this->pdo->lastInsertId();

            // Log attività
            $this->logActivity('create_folder', $folderId, ['path' => $safePath]);

            return [
                'success' => true,
                'folder_id' => $folderId,
                'name' => $folderName,
                'path' => $safePath
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Sposta un file o cartella
     *
     * @param string $source Percorso sorgente
     * @param string $destination Percorso destinazione
     * @param bool $overwrite Sovrascrivere se esiste
     * @return bool Success
     */
    public function move(string $source, string $destination, bool $overwrite = false): bool {
        try {
            // Sanitizza i percorsi
            $safeSource = $this->sanitizePath($source);
            $safeDestination = $this->sanitizePath($destination);

            // Percorsi completi con isolamento tenant
            $fullSource = $this->config['base_upload_path'] . '/' . $this->tenantId . '/' . $safeSource;
            $fullDestination = $this->config['base_upload_path'] . '/' . $this->tenantId . '/' . $safeDestination;

            // Verifica che la sorgente esista
            if (!file_exists($fullSource)) {
                throw new Exception('Sorgente non trovata');
            }

            // Verifica conflitti
            if (file_exists($fullDestination) && !$overwrite) {
                throw new Exception('Destinazione già esistente');
            }

            // Crea directory di destinazione se necessario
            $destDir = dirname($fullDestination);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            // Esegui lo spostamento
            if (!rename($fullSource, $fullDestination)) {
                throw new Exception('Impossibile spostare il file/cartella');
            }

            // Aggiorna database
            $this->updateFilePaths($safeSource, $safeDestination);

            // Log attività
            $this->logActivity('move', 0, [
                'source' => $safeSource,
                'destination' => $safeDestination
            ]);

            return true;

        } catch (Exception $e) {
            error_log('Errore spostamento: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Elimina una cartella
     *
     * @param string $path Percorso della cartella
     * @param bool $force Forza eliminazione anche se non vuota
     * @return bool Success
     */
    public function deleteFolder(string $path, bool $force = false): bool {
        try {
            // Sanitizza il percorso
            $safePath = $this->sanitizePath($path);
            $fullPath = $this->config['base_upload_path'] . '/' . $this->tenantId . '/' . $safePath;

            // Verifica che esista
            if (!is_dir($fullPath)) {
                throw new Exception('Cartella non trovata');
            }

            // Verifica se è vuota (se non forzato)
            if (!$force) {
                $files = scandir($fullPath);
                if (count($files) > 2) { // . e .. sono sempre presenti
                    throw new Exception('La cartella non è vuota');
                }
            }

            // Elimina ricorsivamente se forzato
            if ($force) {
                $this->deleteRecursive($fullPath);
            } else {
                rmdir($fullPath);
            }

            // Rimuovi dal database
            $stmt = $this->pdo->prepare("
                DELETE FROM folders
                WHERE tenant_id = :tenant_id AND path = :path
            ");
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':path' => $safePath
            ]);

            // Log attività
            $this->logActivity('delete_folder', 0, [
                'path' => $safePath,
                'forced' => $force
            ]);

            return true;

        } catch (Exception $e) {
            error_log('Errore eliminazione cartella: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calcola dimensione ricorsiva di una cartella
     *
     * @param string $path Percorso della cartella
     * @param bool $useCache Usa cache se disponibile
     * @return int Dimensione in bytes
     */
    public function calculateFolderSize(string $path, bool $useCache = true): int {
        $safePath = $this->sanitizePath($path);
        $fullPath = $this->config['base_upload_path'] . '/' . $this->tenantId . '/' . $safePath;

        if (!is_dir($fullPath)) {
            return 0;
        }

        // Controlla cache se abilitata
        if ($useCache) {
            $cachedSize = $this->getCachedFolderSize($safePath);
            if ($cachedSize !== null) {
                return $cachedSize;
            }
        }

        // Calcola dimensione ricorsivamente
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        // Salva in cache
        if ($useCache) {
            $this->cacheFolderSize($safePath, $size);
        }

        return $size;
    }

    /**
     * Genera breadcrumb per navigazione
     *
     * @param string $path Percorso corrente
     * @return array Array di breadcrumb
     */
    public function generateBreadcrumbs(string $path): array {
        $safePath = $this->sanitizePath($path);
        $parts = explode('/', trim($safePath, '/'));
        $breadcrumbs = [];
        $currentPath = '';

        // Home/Root
        $breadcrumbs[] = [
            'name' => 'Home',
            'path' => '/',
            'active' => empty($safePath)
        ];

        // Costruisci breadcrumb per ogni parte del percorso
        foreach ($parts as $part) {
            if (empty($part)) continue;

            $currentPath .= '/' . $part;
            $breadcrumbs[] = [
                'name' => $part,
                'path' => $currentPath,
                'active' => ($currentPath === '/' . $safePath)
            ];
        }

        return $breadcrumbs;
    }

    // ========== FUNZIONI UTILITY ==========

    /**
     * Formatta dimensione file in formato leggibile
     *
     * @param int $bytes Dimensione in bytes
     * @param int $decimals Numero di decimali
     * @return string Dimensione formattata
     */
    public function formatFileSize(int $bytes, int $decimals = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);

        if ($factor >= count($units)) {
            $factor = count($units) - 1;
        }

        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Ottiene classe icona per tipo MIME
     *
     * @param string $mimeType Tipo MIME
     * @return string Classe CSS per l'icona
     */
    public function getMimeIcon(string $mimeType): string {
        $iconMap = [
            'application/pdf' => 'fa-file-pdf',
            'application/msword' => 'fa-file-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'fa-file-word',
            'application/vnd.ms-excel' => 'fa-file-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'fa-file-excel',
            'application/vnd.ms-powerpoint' => 'fa-file-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'fa-file-powerpoint',
            'text/plain' => 'fa-file-text',
            'text/csv' => 'fa-file-csv',
            'image/jpeg' => 'fa-file-image',
            'image/png' => 'fa-file-image',
            'image/gif' => 'fa-file-image',
            'image/webp' => 'fa-file-image',
            'image/svg+xml' => 'fa-file-image',
            'application/zip' => 'fa-file-archive',
            'application/x-rar-compressed' => 'fa-file-archive',
            'application/x-7z-compressed' => 'fa-file-archive',
            'audio/mpeg' => 'fa-file-audio',
            'audio/wav' => 'fa-file-audio',
            'video/mp4' => 'fa-file-video',
            'video/mpeg' => 'fa-file-video',
            'video/quicktime' => 'fa-file-video'
        ];

        return $iconMap[$mimeType] ?? 'fa-file';
    }

    /**
     * Verifica se il tipo di file è permesso
     *
     * @param string $mimeType Tipo MIME
     * @return bool True se permesso
     */
    public function isAllowedFileType(string $mimeType): bool {
        return in_array($mimeType, $this->config['allowed_mime_types']);
    }

    /**
     * Genera percorso sicuro con isolamento tenant
     *
     * @param int $tenantId ID del tenant
     * @return string Percorso sicuro
     */
    public function generateSecurePath(int $tenantId): string {
        $year = date('Y');
        $month = date('m');

        $path = $this->config['base_upload_path'] . '/tenant_' . $tenantId . '/' . $year . '/' . $month;

        // Crea la struttura di directory se non esiste
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Sanitizza nome file
     *
     * @param string $filename Nome file originale
     * @return string Nome file sanitizzato
     */
    public function sanitizeFileName(string $filename): string {
        // Rimuovi caratteri pericolosi
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Rimuovi punti multipli
        $filename = preg_replace('/\.+/', '.', $filename);

        // Rimuovi punti all'inizio
        $filename = ltrim($filename, '.');

        // Limita lunghezza
        if (strlen($filename) > 255) {
            $ext = $this->getFileExtension($filename);
            $name = substr($filename, 0, 250 - strlen($ext));
            $filename = $name . '.' . $ext;
        }

        return $filename ?: 'unnamed';
    }

    /**
     * Estrae estensione file in modo sicuro
     *
     * @param string $filename Nome del file
     * @return string Estensione (senza punto)
     */
    public function getFileExtension(string $filename): string {
        $parts = explode('.', $filename);
        if (count($parts) > 1) {
            $ext = strtolower(array_pop($parts));
            // Verifica che l'estensione sia valida
            if (preg_match('/^[a-z0-9]+$/', $ext)) {
                return $ext;
            }
        }
        return '';
    }

    /**
     * Genera thumbnail per immagine
     *
     * @param string $filepath Percorso file originale
     * @param int $width Larghezza thumbnail
     * @param int $height Altezza thumbnail
     * @return string|null Percorso thumbnail o null se fallisce
     */
    public function generateThumbnail(string $filepath, int $width, int $height): ?string {
        try {
            $mimeType = $this->getRealMimeType($filepath);

            // Crea immagine sorgente basata sul tipo
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($filepath);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($filepath);
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($filepath);
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($filepath);
                    break;
                default:
                    return null;
            }

            if (!$source) {
                return null;
            }

            // Calcola dimensioni proporzionali
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $ratio = min($width / $sourceWidth, $height / $sourceHeight);

            $newWidth = (int)($sourceWidth * $ratio);
            $newHeight = (int)($sourceHeight * $ratio);

            // Crea thumbnail
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

            // Preserva trasparenza per PNG e WebP
            if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);
            }

            // Ridimensiona
            imagecopyresampled(
                $thumbnail, $source,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $sourceWidth, $sourceHeight
            );

            // Genera nome e percorso thumbnail
            $pathInfo = pathinfo($filepath);
            $thumbnailPath = $pathInfo['dirname'] . '/thumbs/' . $pathInfo['filename'] . '_thumb.jpg';

            // Crea directory thumbnails se non esiste
            $thumbDir = dirname($thumbnailPath);
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            // Salva thumbnail come JPEG per efficienza
            imagejpeg($thumbnail, $thumbnailPath, 85);

            // Cleanup
            imagedestroy($source);
            imagedestroy($thumbnail);

            return $thumbnailPath;

        } catch (Exception $e) {
            error_log('Errore generazione thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calcola hash SHA256 del file
     *
     * @param string $filepath Percorso del file
     * @return string Hash SHA256
     */
    public function calculateFileHash(string $filepath): string {
        return hash_file('sha256', $filepath);
    }

    /**
     * Validazione completa file caricato
     *
     * @param string $tmpFile File temporaneo
     * @param string $originalName Nome originale
     * @throws Exception Se validazione fallisce
     */
    public function validateUploadedFile(string $tmpFile, string $originalName): void {
        // Verifica che sia un upload valido
        if (!is_uploaded_file($tmpFile)) {
            throw new Exception('File non valido');
        }

        // Verifica dimensione
        $size = filesize($tmpFile);
        if ($size > $this->config['max_file_size']) {
            throw new Exception('File troppo grande. Massimo: ' .
                $this->formatFileSize($this->config['max_file_size']));
        }

        // Verifica tipo MIME reale
        $mimeType = $this->getRealMimeType($tmpFile);
        if (!$this->isAllowedFileType($mimeType)) {
            throw new Exception('Tipo di file non permesso');
        }

        // Verifica estensione
        $extension = $this->getFileExtension($originalName);
        if (in_array($extension, $this->config['blocked_extensions'])) {
            throw new Exception('Estensione file bloccata');
        }

        // Verifica contenuto per file eseguibili
        $this->checkForExecutableContent($tmpFile);
    }

    // ========== FUNZIONI PRIVATE DI SUPPORTO ==========

    /**
     * Ottiene tipo MIME reale usando finfo
     *
     * @param string $filepath Percorso del file
     * @return string Tipo MIME
     */
    private function getRealMimeType(string $filepath): string {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Genera nome file sicuro univoco
     *
     * @param string $originalName Nome originale
     * @return string Nome file sicuro
     */
    private function generateSecureFilename(string $originalName): string {
        $extension = $this->getFileExtension($originalName);
        $uniqueId = bin2hex(random_bytes(16));
        $timestamp = time();

        return $timestamp . '_' . $uniqueId . ($extension ? '.' . $extension : '');
    }

    /**
     * Verifica accesso al file
     *
     * @param int $fileId ID del file
     * @return bool True se ha accesso
     */
    private function hasFileAccess(int $fileId): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM files
            WHERE id = :file_id
            AND tenant_id = :tenant_id
            AND (
                user_id = :user_id
                OR id IN (
                    SELECT file_id FROM file_permissions
                    WHERE user_id = :user_id2 AND can_read = 1
                )
            )
        ");

        $stmt->execute([
            ':file_id' => $fileId,
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId,
            ':user_id2' => $this->userId
        ]);

        return $stmt->fetch() !== false;
    }

    /**
     * Registra file nel database
     *
     * @param array $data Dati del file
     * @return int ID del file inserito
     */
    private function registerFile(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO files (
                tenant_id, user_id, original_name, secure_name,
                path, thumbnail_path, mime_type, size, hash,
                category, metadata, created_at
            ) VALUES (
                :tenant_id, :user_id, :original_name, :secure_name,
                :path, :thumbnail_path, :mime_type, :size, :hash,
                :category, :metadata, NOW()
            )
        ");

        $stmt->execute([
            ':tenant_id' => $data['tenant_id'],
            ':user_id' => $data['user_id'],
            ':original_name' => $data['original_name'],
            ':secure_name' => $data['secure_name'],
            ':path' => $data['path'],
            ':thumbnail_path' => $data['thumbnail_path'] ?? null,
            ':mime_type' => $data['mime_type'],
            ':size' => $data['size'],
            ':hash' => $data['hash'],
            ':category' => $data['category'] ?? 'general',
            ':metadata' => $data['metadata'] ?? null
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Trova file per hash (deduplicazione)
     *
     * @param string $hash Hash del file
     * @return array|null Dati del file se trovato
     */
    private function findFileByHash(string $hash): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM files
            WHERE tenant_id = :tenant_id AND hash = :hash
            LIMIT 1
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':hash' => $hash
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crea riferimento a file esistente (deduplicazione)
     *
     * @param int $existingFileId ID file esistente
     * @param string $originalName Nome originale nuovo
     * @param string $category Categoria
     * @param array $metadata Metadati
     * @return array Informazioni riferimento
     */
    private function createFileReference(int $existingFileId, string $originalName,
                                         string $category, array $metadata): array {
        $stmt = $this->pdo->prepare("
            INSERT INTO file_references (
                tenant_id, file_id, user_id, original_name,
                category, metadata, created_at
            ) VALUES (
                :tenant_id, :file_id, :user_id, :original_name,
                :category, :metadata, NOW()
            )
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':file_id' => $existingFileId,
            ':user_id' => $this->userId,
            ':original_name' => $originalName,
            ':category' => $category,
            ':metadata' => json_encode($metadata)
        ]);

        return [
            'success' => true,
            'file_id' => $existingFileId,
            'reference_id' => $this->pdo->lastInsertId(),
            'original_name' => $originalName,
            'deduplicated' => true
        ];
    }

    /**
     * Log attività file
     *
     * @param string $action Azione eseguita
     * @param int $fileId ID del file
     * @param array $details Dettagli aggiuntivi
     */
    private function logActivity(string $action, int $fileId, array $details = []): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO file_activity_log (
                    tenant_id, user_id, file_id, action,
                    details, ip_address, created_at
                ) VALUES (
                    :tenant_id, :user_id, :file_id, :action,
                    :details, :ip, NOW()
                )
            ");

            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':user_id' => $this->userId,
                ':file_id' => $fileId,
                ':action' => $action,
                ':details' => json_encode($details),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log('Errore log attività: ' . $e->getMessage());
        }
    }

    /**
     * Verifica rate limiting download
     *
     * @return bool True se può scaricare
     */
    private function checkDownloadRateLimit(): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as download_count
            FROM file_activity_log
            WHERE tenant_id = :tenant_id
            AND user_id = :user_id
            AND action = 'download'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':user_id' => $this->userId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['download_count'] < $this->config['rate_limit_downloads'];
    }

    /**
     * Recupera informazioni file
     *
     * @param int $fileId ID del file
     * @return array|null Dati del file
     */
    private function getFileInfo(int $fileId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM files
            WHERE id = :file_id AND tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':file_id' => $fileId,
            ':tenant_id' => $this->tenantId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Sanitizza nome file per header HTTP
     *
     * @param string $filename Nome file
     * @return string Nome sanitizzato
     */
    private function sanitizeHeaderFilename(string $filename): string {
        // Rimuovi caratteri non ASCII
        $filename = preg_replace('/[^\x20-\x7E]/', '', $filename);
        // Escape quotes
        $filename = str_replace('"', '\\"', $filename);
        return $filename;
    }

    /**
     * Sanitizza percorso per prevenire path traversal
     *
     * @param string $path Percorso da sanitizzare
     * @return string Percorso sicuro
     */
    private function sanitizePath(string $path): string {
        // Rimuovi caratteri pericolosi
        $path = str_replace(['..', '\\', "\0"], '', $path);
        // Normalizza separatori
        $path = str_replace('//', '/', $path);
        // Rimuovi slash iniziali e finali
        $path = trim($path, '/');

        return $path;
    }

    /**
     * Elimina ricorsivamente una directory
     *
     * @param string $dir Directory da eliminare
     */
    private function deleteRecursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Aggiorna percorsi nel database dopo spostamento
     *
     * @param string $oldPath Vecchio percorso
     * @param string $newPath Nuovo percorso
     */
    private function updateFilePaths(string $oldPath, string $newPath): void {
        // Aggiorna files
        $stmt = $this->pdo->prepare("
            UPDATE files
            SET path = REPLACE(path, :old_path, :new_path)
            WHERE tenant_id = :tenant_id
            AND path LIKE :path_pattern
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':old_path' => $oldPath,
            ':new_path' => $newPath,
            ':path_pattern' => $oldPath . '%'
        ]);

        // Aggiorna folders
        $stmt = $this->pdo->prepare("
            UPDATE folders
            SET path = REPLACE(path, :old_path, :new_path)
            WHERE tenant_id = :tenant_id
            AND path LIKE :path_pattern
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':old_path' => $oldPath,
            ':new_path' => $newPath,
            ':path_pattern' => $oldPath . '%'
        ]);
    }

    /**
     * Ottiene dimensione cartella dalla cache
     *
     * @param string $path Percorso cartella
     * @return int|null Dimensione o null se non in cache
     */
    private function getCachedFolderSize(string $path): ?int {
        $stmt = $this->pdo->prepare("
            SELECT size FROM folder_size_cache
            WHERE tenant_id = :tenant_id
            AND path = :path
            AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':path' => $path
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['size'] : null;
    }

    /**
     * Salva dimensione cartella in cache
     *
     * @param string $path Percorso cartella
     * @param int $size Dimensione in bytes
     */
    private function cacheFolderSize(string $path, int $size): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO folder_size_cache (tenant_id, path, size, updated_at)
            VALUES (:tenant_id, :path, :size, NOW())
            ON DUPLICATE KEY UPDATE
                size = VALUES(size),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':path' => $path,
            ':size' => $size
        ]);
    }

    /**
     * Verifica contenuto eseguibile nel file
     *
     * @param string $filepath Percorso del file
     * @throws Exception Se trovato contenuto eseguibile
     */
    private function checkForExecutableContent(string $filepath): void {
        $content = file_get_contents($filepath, false, null, 0, 1024);

        // Check for common executable signatures
        $signatures = [
            'MZ', // DOS/Windows executable
            "\x7fELF", // Linux ELF
            "\xca\xfe\xba\xbe", // Mach-O (Mac)
            "\xfe\xed\xfa", // Mach-O (Mac)
            '#!/', // Shell script
            '<?php' // PHP script
        ];

        foreach ($signatures as $signature) {
            if (strpos($content, $signature) === 0) {
                throw new Exception('Contenuto eseguibile rilevato');
            }
        }
    }

    /**
     * Hook per scansione antivirus
     *
     * @param string $filepath Percorso del file
     */
    private function scanForViruses(string $filepath): void {
        // Implementazione placeholder per integrazione antivirus
        // Può essere integrato con ClamAV o altri scanner

        // Esempio con ClamAV (se installato):
        /*
        if (function_exists('cl_scanfile')) {
            $retcode = 0;
            $virusname = '';
            if (cl_scanfile($filepath, $virusname, $retcode) === CL_VIRUS) {
                unlink($filepath);
                throw new Exception('Virus rilevato: ' . $virusname);
            }
        }
        */

        // Per ora, solo log dell'intenzione
        error_log('Virus scan hook chiamato per: ' . $filepath);
    }

    /**
     * Ottiene messaggio di errore per codice upload
     *
     * @param int $errorCode Codice errore PHP upload
     * @return string Messaggio di errore
     */
    private function getUploadErrorMessage(int $errorCode): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Il file supera la dimensione massima consentita dal server',
            UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima specificata nel form',
            UPLOAD_ERR_PARTIAL => 'Il file è stato caricato solo parzialmente',
            UPLOAD_ERR_NO_FILE => 'Nessun file è stato caricato',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file su disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloccato da estensione PHP'
        ];

        return $errors[$errorCode] ?? 'Errore sconosciuto durante l\'upload';
    }

    /**
     * Configura parametri personalizzati
     *
     * @param array $config Array di configurazione
     */
    public function setConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Ottiene configurazione corrente
     *
     * @return array Configurazione
     */
    public function getConfig(): array {
        return $this->config;
    }
}