<?php
/**
 * File Helper Class
 *
 * Utility class per operazioni su file e validazioni
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

class FileHelper {

    /**
     * Estensioni permesse per l'upload
     */
    const ALLOWED_EXTENSIONS = [
        // Documenti
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'rtf', 'csv', 'xml', 'json',
        // Immagini
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico',
        // Archivi
        'zip', 'rar', '7z', 'tar', 'gz',
        // Audio/Video
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'wav', 'ogg',
        // Altri
        'html', 'css', 'js', 'md'
    ];

    /**
     * Estensioni bloccate per sicurezza
     */
    const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phps',
        'exe', 'bat', 'cmd', 'com', 'msi', 'app', 'deb', 'rpm',
        'sh', 'bash', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse',
        'jar', 'scr', 'dll', 'asp', 'aspx', 'cgi', 'pl', 'py',
        'htaccess', 'htpasswd'
    ];

    /**
     * Formati editabili con OnlyOffice
     */
    const EDITABLE_FORMATS = [
        'docx' => 'word',
        'doc' => 'word',
        'odt' => 'word',
        'xlsx' => 'cell',
        'xls' => 'cell',
        'ods' => 'cell',
        'csv' => 'cell',
        'pptx' => 'slide',
        'ppt' => 'slide',
        'odp' => 'slide',
        'txt' => 'word',
        'rtf' => 'word'
    ];

    /**
     * Dimensione massima file (100MB)
     */
    const MAX_FILE_SIZE = 104857600; // 100MB in bytes

    /**
     * Dimensione chunk per upload di file grandi
     */
    const CHUNK_SIZE = 1048576; // 1MB

    /**
     * Ottiene il MIME type di un file
     *
     * @param string $filePath Percorso del file
     * @return string MIME type
     */
    public static function getMimeType(string $filePath): string {
        if (!file_exists($filePath)) {
            return 'application/octet-stream';
        }

        // Usa finfo per rilevare il MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // Fallback per alcuni tipi specifici
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeOverrides = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation'
        ];

        if (isset($mimeOverrides[$extension])) {
            return $mimeOverrides[$extension];
        }

        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Verifica se un file è editabile con OnlyOffice
     *
     * @param string $extension Estensione del file
     * @return bool
     */
    public static function isEditable(string $extension): bool {
        return isset(self::EDITABLE_FORMATS[strtolower($extension)]);
    }

    /**
     * Ottiene il formato editor per OnlyOffice
     *
     * @param string $mimeType MIME type del file
     * @return string|null Formato editor o null
     */
    public static function getEditorFormat(string $mimeType): ?string {
        $extension = self::getExtensionFromMimeType($mimeType);
        return self::EDITABLE_FORMATS[strtolower($extension)] ?? null;
    }

    /**
     * Ottiene l'estensione dal MIME type
     *
     * @param string $mimeType MIME type
     * @return string Estensione
     */
    private static function getExtensionFromMimeType(string $mimeType): string {
        $mimeToExt = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/msword' => 'doc',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif'
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }

    /**
     * Genera un nome file sicuro prevenendo sovrascritture
     *
     * @param string $originalName Nome originale del file
     * @param string $folderPath Percorso della cartella di destinazione
     * @return string Nome file sicuro
     */
    public static function generateSafeFilename(string $originalName, string $folderPath): string {
        // Rimuove caratteri pericolosi
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

        // Previene directory traversal
        $safeName = str_replace(['..', '/', '\\'], '_', $safeName);

        // Limita la lunghezza del nome
        $extension = pathinfo($safeName, PATHINFO_EXTENSION);
        $basename = pathinfo($safeName, PATHINFO_FILENAME);

        if (strlen($basename) > 100) {
            $basename = substr($basename, 0, 100);
        }

        $safeName = $basename . '.' . $extension;

        // Se il file esiste già, aggiungi un timestamp
        $fullPath = rtrim($folderPath, '/') . '/' . $safeName;
        if (file_exists($fullPath)) {
            $timestamp = date('Ymd_His');
            $safeName = $basename . '_' . $timestamp . '.' . $extension;
        }

        return $safeName;
    }

    /**
     * Formatta la dimensione del file in formato leggibile
     *
     * @param int $bytes Dimensione in bytes
     * @return string Dimensione formattata
     */
    public static function formatFileSize(int $bytes): string {
        if ($bytes == 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1); // Prevent array overflow

        return sprintf("%.2f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
    }

    /**
     * Valida l'estensione del file
     *
     * @param string $extension Estensione da validare
     * @return bool True se valida
     */
    public static function isAllowedExtension(string $extension): bool {
        $extension = strtolower($extension);

        // Prima controlla se è nella blacklist
        if (in_array($extension, self::BLOCKED_EXTENSIONS)) {
            return false;
        }

        // Poi controlla se è nella whitelist
        return in_array($extension, self::ALLOWED_EXTENSIONS);
    }

    /**
     * Crea una thumbnail per le immagini
     *
     * @param string $sourcePath Percorso immagine originale
     * @param string $thumbnailPath Percorso thumbnail
     * @param int $maxWidth Larghezza massima
     * @param int $maxHeight Altezza massima
     * @return bool True se creata con successo
     */
    public static function createThumbnail(
        string $sourcePath,
        string $thumbnailPath,
        int $maxWidth = 200,
        int $maxHeight = 200
    ): bool {
        if (!file_exists($sourcePath)) {
            return false;
        }

        // Ottieni informazioni sull'immagine
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        list($width, $height, $type) = $imageInfo;

        // Calcola le nuove dimensioni mantenendo l'aspect ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = intval($width * $ratio);
        $newHeight = intval($height * $ratio);

        // Crea l'immagine sorgente
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }

        if (!$source) {
            return false;
        }

        // Crea la thumbnail
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

        // Mantieni la trasparenza per PNG e GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumbnail, imagecolorallocate($thumbnail, 0, 0, 0));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        // Ridimensiona l'immagine
        imagecopyresampled(
            $thumbnail, $source,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );

        // Crea la directory se non esiste
        $thumbnailDir = dirname($thumbnailPath);
        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        // Salva la thumbnail
        $success = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $success = imagejpeg($thumbnail, $thumbnailPath, 85);
                break;
            case IMAGETYPE_PNG:
                $success = imagepng($thumbnail, $thumbnailPath, 8);
                break;
            case IMAGETYPE_GIF:
                $success = imagegif($thumbnail, $thumbnailPath);
                break;
            case IMAGETYPE_WEBP:
                $success = imagewebp($thumbnail, $thumbnailPath, 85);
                break;
        }

        // Pulisci la memoria
        imagedestroy($source);
        imagedestroy($thumbnail);

        return $success;
    }

    /**
     * Valida un file caricato
     *
     * @param array $file Array $_FILES per il file
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateUploadedFile(array $file): array {
        // Controlla errori di upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Il file supera la dimensione massima consentita dal server',
                UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima consentita',
                UPLOAD_ERR_PARTIAL => 'Il file è stato caricato solo parzialmente',
                UPLOAD_ERR_NO_FILE => 'Nessun file caricato',
                UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante',
                UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file su disco',
                UPLOAD_ERR_EXTENSION => 'Upload bloccato da un\'estensione PHP'
            ];

            return [
                'valid' => false,
                'error' => $errors[$file['error']] ?? 'Errore sconosciuto durante l\'upload'
            ];
        }

        // Controlla la dimensione
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'valid' => false,
                'error' => 'Il file supera la dimensione massima di ' . self::formatFileSize(self::MAX_FILE_SIZE)
            ];
        }

        // Controlla l'estensione
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!self::isAllowedExtension($extension)) {
            return [
                'valid' => false,
                'error' => 'Tipo di file non consentito: .' . $extension
            ];
        }

        // Verifica che sia un upload valido
        if (!is_uploaded_file($file['tmp_name'])) {
            return [
                'valid' => false,
                'error' => 'File upload non valido'
            ];
        }

        // Controlla il MIME type per sicurezza aggiuntiva
        $mimeType = self::getMimeType($file['tmp_name']);
        $dangerousMimes = [
            'application/x-php',
            'application/x-httpd-php',
            'application/php',
            'text/x-php',
            'application/x-executable'
        ];

        if (in_array($mimeType, $dangerousMimes)) {
            return [
                'valid' => false,
                'error' => 'Tipo di file non sicuro rilevato'
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Crea il percorso di upload per un tenant
     *
     * @param int $tenantId ID del tenant
     * @param string $subFolder Sottocartella opzionale
     * @return string Percorso completo
     */
    public static function getTenantUploadPath(int $tenantId, string $subFolder = ''): string {
        $basePath = dirname(__DIR__) . '/uploads/' . $tenantId;

        if ($subFolder) {
            // Sanitizza il percorso della sottocartella
            $subFolder = str_replace(['..', '/', '\\'], '', $subFolder);
            $basePath .= '/' . $subFolder;
        }

        // Crea la directory se non esiste
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        return $basePath;
    }

    /**
     * Ottiene l'icona per un tipo di file
     *
     * @param string $extension Estensione del file
     * @return string Nome dell'icona
     */
    public static function getFileIcon(string $extension): string {
        $extension = strtolower($extension);

        $iconMap = [
            // Documenti
            'pdf' => 'file-pdf',
            'doc' => 'file-word',
            'docx' => 'file-word',
            'xls' => 'file-excel',
            'xlsx' => 'file-excel',
            'ppt' => 'file-powerpoint',
            'pptx' => 'file-powerpoint',
            // Immagini
            'jpg' => 'file-image',
            'jpeg' => 'file-image',
            'png' => 'file-image',
            'gif' => 'file-image',
            'svg' => 'file-image',
            // Archivi
            'zip' => 'file-archive',
            'rar' => 'file-archive',
            '7z' => 'file-archive',
            // Audio/Video
            'mp3' => 'file-audio',
            'mp4' => 'file-video',
            'avi' => 'file-video',
            // Codice
            'html' => 'file-code',
            'css' => 'file-code',
            'js' => 'file-code',
            'php' => 'file-code',
            'json' => 'file-code',
            // Testo
            'txt' => 'file-text',
            'md' => 'file-text',
            'rtf' => 'file-text'
        ];

        return $iconMap[$extension] ?? 'file';
    }

    /**
     * Calcola l'hash di un file per verifiche di integrità
     *
     * @param string $filePath Percorso del file
     * @return string Hash SHA256 del file
     */
    public static function getFileHash(string $filePath): string {
        if (!file_exists($filePath)) {
            return '';
        }

        return hash_file('sha256', $filePath);
    }

    /**
     * Verifica se un file è un'immagine
     *
     * @param string $mimeType MIME type del file
     * @return bool
     */
    public static function isImage(string $mimeType): bool {
        return strpos($mimeType, 'image/') === 0;
    }

    /**
     * Ottiene le dimensioni di un'immagine
     *
     * @param string $filePath Percorso del file
     * @return array|null ['width' => int, 'height' => int] o null
     */
    public static function getImageDimensions(string $filePath): ?array {
        if (!file_exists($filePath)) {
            return null;
        }

        $info = getimagesize($filePath);
        if (!$info) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1]
        ];
    }
}