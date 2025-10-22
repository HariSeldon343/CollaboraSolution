<?php
/**
 * Document Editor Helper Functions
 *
 * Funzioni di supporto per l'integrazione con OnlyOffice Document Server
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Ensure config.php is loaded first for BASE_URL constant
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/onlyoffice_config.php';
require_once __DIR__ . '/db.php';

/**
 * Genera un JWT token per OnlyOffice
 *
 * @param array $payload Payload del token
 * @return string JWT token
 */
function generateOnlyOfficeJWT(array $payload): string {
    if (!ONLYOFFICE_JWT_ENABLED) {
        return '';
    }

    // Header
    $header = json_encode([
        'typ' => 'JWT',
        'alg' => 'HS256'
    ]);

    // Payload con timestamp
    $payload['iat'] = time();
    $payload['exp'] = time() + ONLYOFFICE_SESSION_TIMEOUT;

    $payloadJson = json_encode($payload);

    // Encode
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));

    // Create signature
    $signature = hash_hmac(
        'sha256',
        $base64Header . '.' . $base64Payload,
        ONLYOFFICE_JWT_SECRET,
        true
    );

    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    // Create JWT
    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
}

/**
 * Verifica un JWT token da OnlyOffice
 *
 * @param string $token JWT token da verificare
 * @return array|false Payload se valido, false altrimenti
 */
function verifyOnlyOfficeJWT(string $token): array|false {
    if (!ONLYOFFICE_JWT_ENABLED || empty($token)) {
        return !ONLYOFFICE_JWT_ENABLED ? [] : false;
    }

    // Remove 'Bearer ' prefix if present
    $token = str_replace('Bearer ', '', $token);

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$base64Header, $base64Payload, $base64Signature] = $parts;

    // Verify signature
    $signature = hash_hmac(
        'sha256',
        $base64Header . '.' . $base64Payload,
        ONLYOFFICE_JWT_SECRET,
        true
    );

    $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    if (!hash_equals($expectedSignature, $base64Signature)) {
        return false;
    }

    // Decode payload
    $payload = json_decode(base64_decode($base64Payload), true);

    // Verify expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }

    return $payload;
}

/**
 * Crea o aggiorna una sessione di editing per un documento
 *
 * @param int $fileId ID del file
 * @param int $userId ID dell'utente
 * @param int $tenantId ID del tenant
 * @param string $editorKey Chiave univoca dell'editor
 * @return array Dati della sessione
 */
function createEditorSession(int $fileId, int $userId, int $tenantId, string $editorKey): array {
    $db = Database::getInstance();

    // Genera token sessione univoco
    $sessionToken = bin2hex(random_bytes(32));

    try {
        // Chiudi eventuali sessioni precedenti dello stesso utente per questo file
        $db->query(
            "UPDATE document_editor_sessions
             SET closed_at = NOW()
             WHERE file_id = ? AND user_id = ? AND closed_at IS NULL",
            [$fileId, $userId]
        );

        // Crea nuova sessione
        $sessionId = $db->insert('document_editor_sessions', [
            'file_id' => $fileId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'session_token' => $sessionToken,
            'editor_key' => $editorKey,
            'opened_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s')
        ]);

        return [
            'session_id' => $sessionId,
            'session_token' => $sessionToken,
            'editor_key' => $editorKey
        ];
    } catch (Exception $e) {
        error_log('Error creating editor session: ' . $e->getMessage());
        throw new Exception('Impossibile creare la sessione di editing');
    }
}

/**
 * Aggiorna l'attività di una sessione
 *
 * @param string $sessionToken Token della sessione
 * @return bool True se aggiornata con successo
 */
function updateSessionActivity(string $sessionToken): bool {
    $db = Database::getInstance();

    try {
        $affected = $db->update(
            'document_editor_sessions',
            ['last_activity' => date('Y-m-d H:i:s')],
            ['session_token' => $sessionToken, 'closed_at' => null]
        );

        return $affected > 0;
    } catch (Exception $e) {
        error_log('Error updating session activity: ' . $e->getMessage());
        return false;
    }
}

/**
 * Chiude una sessione di editing
 *
 * @param string $sessionToken Token della sessione
 * @param bool $changesSaved Se le modifiche sono state salvate
 * @return bool True se chiusa con successo
 */
function closeEditorSession(string $sessionToken, bool $changesSaved = false): bool {
    $db = Database::getInstance();

    try {
        $affected = $db->update(
            'document_editor_sessions',
            [
                'closed_at' => date('Y-m-d H:i:s'),
                'changes_saved' => $changesSaved ? 1 : 0
            ],
            ['session_token' => $sessionToken]
        );

        return $affected > 0;
    } catch (Exception $e) {
        error_log('Error closing editor session: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ottiene le sessioni attive per un file
 *
 * @param int $fileId ID del file
 * @return array Lista delle sessioni attive
 */
function getActiveSessionsForFile(int $fileId): array {
    $db = Database::getInstance();

    try {
        return $db->fetchAll(
            "SELECT ses.*, u.name as user_name, u.email as user_email
             FROM document_editor_sessions ses
             JOIN users u ON ses.user_id = u.id
             WHERE ses.file_id = ?
             AND ses.closed_at IS NULL
             AND ses.last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY ses.opened_at DESC",
            [$fileId, ONLYOFFICE_IDLE_TIMEOUT]
        );
    } catch (Exception $e) {
        error_log('Error getting active sessions: ' . $e->getMessage());
        return [];
    }
}

/**
 * Verifica se un utente ha una sessione attiva per un file
 *
 * @param int $fileId ID del file
 * @param int $userId ID dell'utente
 * @return array|false Dati della sessione se attiva, false altrimenti
 */
function getUserActiveSession(int $fileId, int $userId): array|false {
    $db = Database::getInstance();

    try {
        return $db->fetchOne(
            "SELECT * FROM document_editor_sessions
             WHERE file_id = ?
             AND user_id = ?
             AND closed_at IS NULL
             AND last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY opened_at DESC
             LIMIT 1",
            [$fileId, $userId, ONLYOFFICE_IDLE_TIMEOUT]
        );
    } catch (Exception $e) {
        error_log('Error getting user session: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pulisce le sessioni scadute
 *
 * @return int Numero di sessioni chiuse
 */
function cleanupExpiredSessions(): int {
    $db = Database::getInstance();

    try {
        $affected = $db->update(
            'document_editor_sessions',
            ['closed_at' => date('Y-m-d H:i:s')],
            [
                'closed_at' => null,
                'last_activity <' => date('Y-m-d H:i:s', time() - ONLYOFFICE_IDLE_TIMEOUT)
            ]
        );

        if ($affected > 0) {
            error_log("Cleaned up $affected expired editor sessions");
        }

        return $affected;
    } catch (Exception $e) {
        error_log('Error cleaning up sessions: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Ottiene informazioni dettagliate su un file per l'editor
 *
 * @param int $fileId ID del file
 * @param int $tenantId ID del tenant (per sicurezza)
 * @return array|false Informazioni del file o false se non trovato
 */
function getFileInfoForEditor(int $fileId, int $tenantId): array|false {
    $db = Database::getInstance();

    try {
        $file = $db->fetchOne(
            "SELECT f.*,
                    u.name as uploaded_by_name,
                    u.email as uploaded_by_email,
                    t.name as tenant_name
             FROM files f
             LEFT JOIN users u ON f.uploaded_by = u.id
             LEFT JOIN tenants t ON f.tenant_id = t.id
             WHERE f.id = ?
             AND f.tenant_id = ?
             AND f.deleted_at IS NULL",
            [$fileId, $tenantId]
        );

        if (!$file) {
            return false;
        }

        // Aggiungi informazioni aggiuntive
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file['extension'] = strtolower($extension);
        $file['is_editable'] = isFileEditableInOnlyOffice($extension);
        $file['is_viewonly'] = isFileViewOnlyInOnlyOffice($extension);
        $file['document_type'] = getOnlyOfficeDocumentType($extension);

        // Percorso fisico del file
        $file['physical_path'] = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $file['file_path'];

        // Genera hash del file per la chiave documento
        if (file_exists($file['physical_path'])) {
            $file['file_hash'] = md5_file($file['physical_path']);
        } else {
            $file['file_hash'] = md5($file['id'] . '_' . $file['updated_at']);
        }

        // Conta le versioni del file
        $file['version_count'] = $db->count('file_versions', ['file_id' => $fileId]);

        return $file;
    } catch (Exception $e) {
        error_log('Error getting file info: ' . $e->getMessage());
        return false;
    }
}

/**
 * Salva una nuova versione del file
 *
 * @param int $fileId ID del file
 * @param string $downloadUrl URL per scaricare la nuova versione
 * @param int $userId ID dell'utente che salva
 * @param array $changes Array con informazioni sui cambiamenti (opzionale)
 * @return bool True se salvato con successo
 */
function saveFileVersion(int $fileId, string $downloadUrl, int $userId, array $changes = []): bool {
    $db = Database::getInstance();

    try {
        // Ottieni informazioni sul file corrente
        $currentFile = $db->fetchOne(
            "SELECT * FROM files WHERE id = ? AND deleted_at IS NULL",
            [$fileId]
        );

        if (!$currentFile) {
            throw new Exception('File non trovato');
        }

        // Scarica il file da OnlyOffice
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30
            ]
        ]);

        $newContent = file_get_contents($downloadUrl, false, $context);
        if ($newContent === false) {
            throw new Exception('Impossibile scaricare il file da OnlyOffice');
        }

        // Backup della versione corrente
        $backupPath = UPLOAD_PATH . '/versions/' . $currentFile['tenant_id'];
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $versionFileName = sprintf(
            '%s_v%d_%s',
            pathinfo($currentFile['file_path'], PATHINFO_FILENAME),
            time(),
            basename($currentFile['file_path'])
        );

        $versionPath = $backupPath . '/' . $versionFileName;

        // Copia il file corrente come versione
        $currentPath = UPLOAD_PATH . '/' . $currentFile['tenant_id'] . '/' . $currentFile['file_path'];
        if (file_exists($currentPath)) {
            copy($currentPath, $versionPath);

            // Registra la versione nel database
            $db->insert('file_versions', [
                'file_id' => $fileId,
                'version_number' => $currentFile['version'] ?? 1,
                'size_bytes' => filesize($versionPath),
                'storage_path' => 'versions/' . $currentFile['tenant_id'] . '/' . $versionFileName,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'changes_description' => json_encode($changes)
            ]);
        }

        // Salva il nuovo contenuto
        file_put_contents($currentPath, $newContent);

        // Aggiorna il record del file
        $db->update('files', [
            'file_size' => strlen($newContent),
            'last_edited_by' => $userId,
            'last_edited_at' => date('Y-m-d H:i:s'),
            'version' => ($currentFile['version'] ?? 1) + 1,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $fileId]);

        // Log audit
        logDocumentAudit('document_saved', $fileId, $userId, [
            'version' => ($currentFile['version'] ?? 1) + 1,
            'size' => strlen($newContent),
            'changes' => $changes
        ]);

        return true;
    } catch (Exception $e) {
        error_log('Error saving file version: ' . $e->getMessage());
        return false;
    }
}

/**
 * Log audit per operazioni sui documenti
 *
 * @param string $action Azione eseguita
 * @param int $fileId ID del file
 * @param int $userId ID dell'utente
 * @param array $details Dettagli aggiuntivi
 */
function logDocumentAudit(string $action, int $fileId, int $userId, array $details = []): void {
    $db = Database::getInstance();

    try {
        // Ottieni tenant_id dal file
        $file = $db->fetchOne("SELECT tenant_id FROM files WHERE id = ?", [$fileId]);

        if ($file) {
            $db->insert('audit_logs', [
                'user_id' => $userId,
                'tenant_id' => $file['tenant_id'],
                'action' => $action,
                'entity_type' => 'document',
                'entity_id' => $fileId,
                'description' => json_encode($details),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'severity' => 'info',
                'status' => 'success',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to log document audit: ' . $e->getMessage());
    }
}

/**
 * Genera l'URL di download per OnlyOffice
 *
 * @param int $fileId ID del file
 * @param string $token Token di autenticazione
 * @return string URL di download
 */
function generateDownloadUrl(int $fileId, string $token): string {
    return sprintf(
        '%s?file_id=%d&token=%s',
        ONLYOFFICE_DOWNLOAD_URL,
        $fileId,
        urlencode($token)
    );
}

/**
 * Genera l'URL di callback per OnlyOffice
 *
 * @param string $editorKey Chiave dell'editor
 * @return string URL di callback
 */
function generateCallbackUrl(string $editorKey): string {
    return sprintf(
        '%s?key=%s',
        ONLYOFFICE_CALLBACK_URL,
        urlencode($editorKey)
    );
}

/**
 * Verifica i permessi di editing per un file
 *
 * @param int $fileId ID del file
 * @param int $userId ID dell'utente
 * @param string $userRole Ruolo dell'utente
 * @return array Array con permessi dettagliati
 */
function checkFileEditPermissions(int $fileId, int $userId, string $userRole): array {
    $db = Database::getInstance();

    try {
        // Ottieni info sul file
        $file = $db->fetchOne(
            "SELECT uploaded_by, tenant_id, status FROM files WHERE id = ? AND deleted_at IS NULL",
            [$fileId]
        );

        if (!$file) {
            return ['can_edit' => false, 'reason' => 'File non trovato'];
        }

        // Check if user owns the file
        $isOwner = ($file['uploaded_by'] == $userId);

        // Determina i permessi base in base al ruolo
        $permissions = getOnlyOfficePermissions($userRole, $isOwner);

        // Aggiungi controlli aggiuntivi
        if ($file['status'] === 'approvato' && $userRole === 'user') {
            $permissions['edit'] = false;
            $permissions['reason'] = 'File approvato - solo lettura per utenti base';
        }

        // Verifica se ci sono altre sessioni attive
        $activeSessions = getActiveSessionsForFile($fileId);
        if (count($activeSessions) > 0) {
            $otherUsers = array_filter($activeSessions, fn($s) => $s['user_id'] != $userId);
            if (count($otherUsers) > 0) {
                $permissions['collaborative'] = true;
                $permissions['active_users'] = array_map(fn($s) => [
                    'id' => $s['user_id'],
                    'name' => $s['user_name']
                ], $otherUsers);
            }
        }

        return $permissions;
    } catch (Exception $e) {
        error_log('Error checking permissions: ' . $e->getMessage());
        return ['can_edit' => false, 'reason' => 'Errore verifica permessi'];
    }
}

/**
 * Verifica la connettività con OnlyOffice Document Server
 *
 * @return array Stato della connessione e informazioni
 */
function checkOnlyOfficeConnectivity(): array {
    $result = [
        'available' => false,
        'version' => null,
        'error' => null,
        'response_time' => null
    ];

    try {
        $startTime = microtime(true);

        // Prova a raggiungere l'health check endpoint di OnlyOffice
        $healthUrl = ONLYOFFICE_SERVER_URL . '/healthcheck';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($healthUrl, false, $context);
        $responseTime = (microtime(true) - $startTime) * 1000; // in milliseconds

        if ($response !== false) {
            $result['available'] = true;
            $result['response_time'] = round($responseTime, 2);

            // Try to parse version if available
            if (strpos($response, 'true') !== false) {
                $result['status'] = 'healthy';
            }
        } else {
            $result['error'] = 'OnlyOffice server non raggiungibile';
        }
    } catch (Exception $e) {
        $result['error'] = 'Errore connessione: ' . $e->getMessage();
    }

    return $result;
}