<?php
/**
 * Sistema di gestione condivisione file sicuro per CollaboraNexio
 *
 * Gestisce la creazione e validazione di link di condivisione sicuri
 * con supporto multi-tenant, rate limiting e tracking completo degli accessi
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Classe ShareManager - Gestione completa della condivisione file
 *
 * Fornisce funzionalità enterprise-grade per la condivisione sicura di file
 * con tracking completo, rate limiting e supporto multi-tenant
 */
class ShareManager {

    private PDO $pdo;
    private int $tenantId;
    private ?int $userId;

    // Configurazione sicurezza
    private const TOKEN_LENGTH = 32;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW = 300; // 5 minuti
    private const RATE_LIMIT_MAX_ATTEMPTS = 10;
    private const SESSION_PREFIX = 'share_';
    private const PREVIEW_TOKEN_LIFETIME = 3600; // 1 ora

    // Configurazione password
    private const PASSWORD_ALGO = PASSWORD_ARGON2ID;
    private const PASSWORD_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ];

    /**
     * Costruttore
     *
     * @param int $tenantId ID del tenant corrente
     * @param int|null $userId ID dell'utente corrente (null per accessi anonimi)
     */
    public function __construct(int $tenantId, ?int $userId = null) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->initializeSession();
    }

    /**
     * Inizializza la sessione con configurazioni di sicurezza
     */
    private function initializeSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', '1');
            ini_set('session.cookie_samesite', 'Strict');

            session_start();
        }
    }

    /**
     * Genera un token crittograficamente sicuro
     *
     * @param int $length Lunghezza del token (default 32 bytes)
     * @return string Token sicuro codificato in base64url
     */
    public function generateSecureToken(int $length = self::TOKEN_LENGTH): string {
        try {
            // Genera bytes casuali crittograficamente sicuri
            $bytes = random_bytes($length);

            // Codifica in base64url (URL-safe)
            $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

            // Verifica unicità nel database
            $maxAttempts = 10;
            $attempts = 0;

            while ($attempts < $maxAttempts) {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM share_links WHERE unique_token = :token"
                );
                $stmt->execute([':token' => $token]);

                if ($stmt->fetchColumn() == 0) {
                    return $token;
                }

                // Se il token esiste già, genera uno nuovo
                $bytes = random_bytes($length);
                $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
                $attempts++;
            }

            throw new RuntimeException("Impossibile generare un token univoco dopo {$maxAttempts} tentativi");

        } catch (Exception $e) {
            error_log("Errore generazione token sicuro: " . $e->getMessage());
            throw new RuntimeException("Errore nella generazione del token sicuro");
        }
    }

    /**
     * Crea un link di condivisione con opzioni avanzate
     *
     * @param array $resource Risorsa da condividere ['type' => 'file'|'folder', 'id' => int]
     * @param array $options Opzioni di configurazione del link
     * @return array Informazioni sul link creato
     * @throws InvalidArgumentException Per parametri non validi
     * @throws RuntimeException Per errori di sistema
     */
    public function createShareLink(array $resource, array $options = []): array {
        // Validazione parametri risorsa
        if (!isset($resource['type']) || !isset($resource['id'])) {
            throw new InvalidArgumentException("Parametri risorsa non validi");
        }

        if (!in_array($resource['type'], ['file', 'folder'], true)) {
            throw new InvalidArgumentException("Tipo risorsa non valido");
        }

        $resourceId = filter_var($resource['id'], FILTER_VALIDATE_INT);
        if ($resourceId === false || $resourceId <= 0) {
            throw new InvalidArgumentException("ID risorsa non valido");
        }

        // Verifica permessi utente sulla risorsa
        if (!$this->userCanShareResource($resource['type'], $resourceId)) {
            throw new RuntimeException("Permessi insufficienti per condividere questa risorsa");
        }

        // Estrai e valida opzioni
        $validatedOptions = $this->validateShareOptions($options);

        try {
            $this->pdo->beginTransaction();

            // Genera token sicuro
            $token = $this->generateSecureToken();

            // Hash della password se fornita
            $passwordHash = null;
            if (!empty($validatedOptions['password'])) {
                $passwordHash = password_hash(
                    $validatedOptions['password'],
                    self::PASSWORD_ALGO,
                    self::PASSWORD_OPTIONS
                );
            }

            // Prepara query di inserimento
            $sql = "INSERT INTO share_links (
                        tenant_id, file_id, unique_token, created_by,
                        password_hash, requires_authentication,
                        expiration_date, max_downloads,
                        allow_download, allow_view, allow_comment, allow_upload_version,
                        title, description, custom_message,
                        is_active, created_at
                    ) VALUES (
                        :tenant_id, :file_id, :token, :created_by,
                        :password_hash, :requires_auth,
                        :expiration, :max_downloads,
                        :allow_download, :allow_view, :allow_comment, :allow_upload,
                        :title, :description, :custom_message,
                        1, NOW()
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':file_id' => $resourceId,
                ':token' => $token,
                ':created_by' => $this->userId ?? 0,
                ':password_hash' => $passwordHash,
                ':requires_auth' => !empty($passwordHash) ? 1 : 0,
                ':expiration' => $validatedOptions['expiration_date'],
                ':max_downloads' => $validatedOptions['max_downloads'],
                ':allow_download' => $validatedOptions['allow_download'] ? 1 : 0,
                ':allow_view' => $validatedOptions['allow_view'] ? 1 : 0,
                ':allow_comment' => $validatedOptions['allow_comment'] ? 1 : 0,
                ':allow_upload' => $validatedOptions['allow_upload_version'] ? 1 : 0,
                ':title' => $validatedOptions['title'],
                ':description' => $validatedOptions['description'],
                ':custom_message' => $validatedOptions['custom_message']
            ]);

            $linkId = (int)$this->pdo->lastInsertId();

            // Aggiungi permessi email-specifici se forniti
            if (!empty($validatedOptions['allowed_emails'])) {
                $this->addEmailPermissions($linkId, $validatedOptions['allowed_emails']);
            }

            // Registra evento nei log di audit
            $this->logShareCreation($linkId, $resource, $validatedOptions);

            // Se richiesto, invia notifiche
            if (!empty($validatedOptions['send_notification'])) {
                $this->sendShareNotifications($linkId, $validatedOptions);
            }

            // Webhook per eventi esterni
            if (!empty($validatedOptions['webhook_url'])) {
                $this->triggerWebhook($validatedOptions['webhook_url'], 'share_created', [
                    'link_id' => $linkId,
                    'token' => $token,
                    'resource' => $resource
                ]);
            }

            $this->pdo->commit();

            // Costruisci URL completo
            $shareUrl = $this->buildShareUrl($token);

            return [
                'success' => true,
                'data' => [
                    'id' => $linkId,
                    'token' => $token,
                    'url' => $shareUrl,
                    'short_url' => $this->generateShortUrl($token),
                    'qr_code' => $this->generateQRCode($shareUrl),
                    'expires_at' => $validatedOptions['expiration_date'],
                    'password_protected' => !empty($passwordHash),
                    'permissions' => [
                        'download' => $validatedOptions['allow_download'],
                        'view' => $validatedOptions['allow_view'],
                        'comment' => $validatedOptions['allow_comment'],
                        'upload' => $validatedOptions['allow_upload_version']
                    ]
                ]
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Errore creazione share link: " . $e->getMessage());
            throw new RuntimeException("Impossibile creare il link di condivisione");
        }
    }

    /**
     * Valida l'accesso a un link di condivisione
     *
     * @param string $token Token del link
     * @param string|null $password Password se richiesta
     * @return array Risultato validazione con dettagli accesso
     */
    public function validateAccess(string $token, ?string $password = null): array {
        // Sanificazione token
        $token = $this->sanitizeToken($token);

        // Verifica rate limiting
        if (!$this->checkRateLimit($token)) {
            $this->trackAccess(null, 'denied', [
                'reason' => 'rate_limit_exceeded',
                'token' => $token
            ]);

            return [
                'success' => false,
                'error' => 'Troppi tentativi. Riprova tra qualche minuto.',
                'code' => 'RATE_LIMIT_EXCEEDED'
            ];
        }

        try {
            // Recupera informazioni del link
            $sql = "SELECT sl.*, f.file_name, f.file_path, f.mime_type, f.file_size,
                           u.first_name, u.last_name
                    FROM share_links sl
                    LEFT JOIN files f ON sl.file_id = f.id
                    LEFT JOIN users u ON sl.created_by = u.id
                    WHERE sl.unique_token = :token
                    AND sl.tenant_id = :tenant_id
                    AND sl.is_active = 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':token' => $token,
                ':tenant_id' => $this->tenantId
            ]);

            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                $this->trackAccess(null, 'denied', [
                    'reason' => 'invalid_token',
                    'token' => $token
                ]);

                return [
                    'success' => false,
                    'error' => 'Link non valido o scaduto',
                    'code' => 'INVALID_TOKEN'
                ];
            }

            $linkId = (int)$link['id'];

            // Verifica scadenza
            if ($link['expiration_date'] && strtotime($link['expiration_date']) < time()) {
                $this->trackAccess($linkId, 'denied', ['reason' => 'expired']);

                return [
                    'success' => false,
                    'error' => 'Il link è scaduto',
                    'code' => 'LINK_EXPIRED'
                ];
            }

            // Verifica limite download
            if ($link['max_downloads'] !== null &&
                $link['current_downloads'] >= $link['max_downloads']) {
                $this->trackAccess($linkId, 'denied', ['reason' => 'download_limit']);

                return [
                    'success' => false,
                    'error' => 'Limite download raggiunto',
                    'code' => 'DOWNLOAD_LIMIT_REACHED'
                ];
            }

            // Verifica password se richiesta
            if ($link['requires_authentication'] && $link['password_hash']) {
                if (empty($password)) {
                    return [
                        'success' => false,
                        'error' => 'Password richiesta',
                        'code' => 'PASSWORD_REQUIRED',
                        'require_password' => true
                    ];
                }

                if (!password_verify($password, $link['password_hash'])) {
                    $this->incrementFailedAttempts($token);
                    $this->trackAccess($linkId, 'denied', ['reason' => 'wrong_password']);

                    return [
                        'success' => false,
                        'error' => 'Password non corretta',
                        'code' => 'INVALID_PASSWORD'
                    ];
                }
            }

            // Verifica permessi email se configurati
            if ($this->hasEmailRestrictions($linkId)) {
                $email = $_SESSION[self::SESSION_PREFIX . 'email'] ?? null;
                if (!$this->isEmailAllowed($linkId, $email)) {
                    $this->trackAccess($linkId, 'denied', ['reason' => 'email_not_allowed']);

                    return [
                        'success' => false,
                        'error' => 'Accesso non consentito per questo indirizzo email',
                        'code' => 'EMAIL_NOT_ALLOWED'
                    ];
                }
            }

            // Genera token di sessione per accesso temporaneo
            $sessionToken = $this->createSessionToken($linkId);

            // Aggiorna ultimo accesso
            $this->updateLastAccess($linkId);

            // Track accesso riuscito
            $accessId = $this->trackAccess($linkId, 'view', ['success' => true]);

            // Prepara risposta con dati completi
            return [
                'success' => true,
                'data' => [
                    'link_id' => $linkId,
                    'session_token' => $sessionToken,
                    'file' => [
                        'id' => $link['file_id'],
                        'name' => $link['file_name'],
                        'size' => $link['file_size'],
                        'mime_type' => $link['mime_type']
                    ],
                    'permissions' => [
                        'download' => (bool)$link['allow_download'],
                        'view' => (bool)$link['allow_view'],
                        'comment' => (bool)$link['allow_comment'],
                        'upload' => (bool)$link['allow_upload_version']
                    ],
                    'metadata' => [
                        'title' => $link['title'],
                        'description' => $link['description'],
                        'message' => $link['custom_message'],
                        'shared_by' => $link['first_name'] . ' ' . $link['last_name'],
                        'shared_at' => $link['created_at']
                    ],
                    'limits' => [
                        'downloads_remaining' => $link['max_downloads'] ?
                            ($link['max_downloads'] - $link['current_downloads']) : null,
                        'expires_at' => $link['expiration_date']
                    ],
                    'access_id' => $accessId
                ]
            ];

        } catch (Exception $e) {
            error_log("Errore validazione accesso: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Errore durante la validazione',
                'code' => 'VALIDATION_ERROR'
            ];
        }
    }

    /**
     * Traccia tutti gli accessi ai link di condivisione
     *
     * @param int|null $linkId ID del link (null per accessi falliti senza link valido)
     * @param string $action Tipo di azione (view, download, comment, upload, denied)
     * @param array $details Dettagli aggiuntivi dell'accesso
     * @return int ID del log di accesso creato
     */
    public function trackAccess(?int $linkId, string $action, array $details = []): int {
        try {
            // Raccolta informazioni di sistema
            $ipAddress = $this->getClientIpAddress();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $referer = $_SERVER['HTTP_REFERER'] ?? null;

            // Geolocalizzazione (se disponibile servizio esterno)
            $geoData = $this->getGeolocation($ipAddress);

            // Determina metodo di autenticazione
            $authMethod = null;
            if (isset($details['password_used'])) {
                $authMethod = 'password';
            } elseif (isset($_SESSION['user_id'])) {
                $authMethod = 'oauth';
            } elseif ($linkId) {
                $authMethod = 'link_only';
            } else {
                $authMethod = 'failed';
            }

            // Genera ID sessione univoco per tracking
            $sessionId = $_SESSION[self::SESSION_PREFIX . 'id'] ?? $this->generateSessionId();
            $_SESSION[self::SESSION_PREFIX . 'id'] = $sessionId;

            // Prepara dati per inserimento
            $sql = "INSERT INTO share_access_logs (
                        tenant_id, share_link_id, access_type,
                        ip_address, user_agent, referer_url,
                        authenticated_user_id, authentication_method,
                        session_id, country_code, city,
                        success, failure_reason,
                        bytes_transferred, duration_ms,
                        accessed_at
                    ) VALUES (
                        :tenant_id, :link_id, :access_type,
                        :ip, :user_agent, :referer,
                        :user_id, :auth_method,
                        :session_id, :country, :city,
                        :success, :failure_reason,
                        :bytes, :duration,
                        NOW()
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':link_id' => $linkId,
                ':access_type' => $action,
                ':ip' => $ipAddress,
                ':user_agent' => $userAgent,
                ':referer' => $referer,
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':auth_method' => $authMethod,
                ':session_id' => $sessionId,
                ':country' => $geoData['country_code'] ?? null,
                ':city' => $geoData['city'] ?? null,
                ':success' => $details['success'] ?? ($action !== 'denied' ? 1 : 0),
                ':failure_reason' => $details['reason'] ?? null,
                ':bytes' => $details['bytes_transferred'] ?? null,
                ':duration' => $details['duration_ms'] ?? null
            ]);

            $logId = (int)$this->pdo->lastInsertId();

            // Trigger webhook se configurato
            if ($linkId) {
                $this->checkAndTriggerAccessWebhook($linkId, $action, $logId);
            }

            // Invia notifiche per eventi importanti
            if ($action === 'download' || ($action === 'denied' && isset($details['reason']))) {
                $this->sendAccessNotification($linkId, $action, $details);
            }

            return $logId;

        } catch (Exception $e) {
            error_log("Errore tracking accesso: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Verifica se l'utente può condividere la risorsa
     *
     * @param string $type Tipo di risorsa (file/folder)
     * @param int $resourceId ID della risorsa
     * @return bool True se l'utente ha i permessi
     */
    private function userCanShareResource(string $type, int $resourceId): bool {
        if (!$this->userId) {
            return false;
        }

        try {
            $table = $type === 'file' ? 'files' : 'folders';

            $sql = "SELECT owner_id, is_shared
                    FROM {$table}
                    WHERE id = :id
                    AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $resourceId,
                ':tenant_id' => $this->tenantId
            ]);

            $resource = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$resource) {
                return false;
            }

            // Proprietario può sempre condividere
            if ($resource['owner_id'] == $this->userId) {
                return true;
            }

            // Verifica permessi di condivisione se non proprietario
            // TODO: Implementare controllo permessi avanzato basato su ruoli

            return false;

        } catch (Exception $e) {
            error_log("Errore verifica permessi: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida e sanifica le opzioni di condivisione
     *
     * @param array $options Opzioni grezze
     * @return array Opzioni validate e sanificate
     */
    private function validateShareOptions(array $options): array {
        $validated = [
            'password' => null,
            'expiration_date' => null,
            'max_downloads' => null,
            'allow_download' => true,
            'allow_view' => true,
            'allow_comment' => false,
            'allow_upload_version' => false,
            'title' => null,
            'description' => null,
            'custom_message' => null,
            'allowed_emails' => [],
            'send_notification' => false,
            'webhook_url' => null
        ];

        // Password
        if (!empty($options['password'])) {
            if (strlen($options['password']) < 8) {
                throw new InvalidArgumentException("La password deve essere di almeno 8 caratteri");
            }
            $validated['password'] = $options['password'];
        }

        // Data scadenza
        if (!empty($options['expiration_date'])) {
            $expDate = strtotime($options['expiration_date']);
            if ($expDate === false || $expDate <= time()) {
                throw new InvalidArgumentException("Data di scadenza non valida");
            }
            $validated['expiration_date'] = date('Y-m-d H:i:s', $expDate);
        }

        // Limite download
        if (isset($options['max_downloads'])) {
            $maxDl = filter_var($options['max_downloads'], FILTER_VALIDATE_INT);
            if ($maxDl === false || $maxDl < 1) {
                throw new InvalidArgumentException("Limite download non valido");
            }
            $validated['max_downloads'] = $maxDl;
        }

        // Permessi booleani
        foreach (['allow_download', 'allow_view', 'allow_comment', 'allow_upload_version'] as $perm) {
            if (isset($options[$perm])) {
                $validated[$perm] = filter_var($options[$perm], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Testi
        if (!empty($options['title'])) {
            $validated['title'] = substr(filter_var($options['title'], FILTER_SANITIZE_STRING), 0, 255);
        }

        if (!empty($options['description'])) {
            $validated['description'] = filter_var($options['description'], FILTER_SANITIZE_STRING);
        }

        if (!empty($options['custom_message'])) {
            $validated['custom_message'] = filter_var($options['custom_message'], FILTER_SANITIZE_STRING);
        }

        // Email consentite
        if (!empty($options['allowed_emails'])) {
            $emails = is_array($options['allowed_emails']) ?
                      $options['allowed_emails'] :
                      explode(',', $options['allowed_emails']);

            $validated['allowed_emails'] = array_filter(
                array_map('trim', $emails),
                function($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                }
            );
        }

        // Notifiche
        if (isset($options['send_notification'])) {
            $validated['send_notification'] = filter_var($options['send_notification'], FILTER_VALIDATE_BOOLEAN);
        }

        // Webhook URL
        if (!empty($options['webhook_url'])) {
            if (filter_var($options['webhook_url'], FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("URL webhook non valido");
            }
            $validated['webhook_url'] = $options['webhook_url'];
        }

        return $validated;
    }

    /**
     * Sanifica un token per prevenire injection
     *
     * @param string $token Token da sanificare
     * @return string Token sanificato
     */
    private function sanitizeToken(string $token): string {
        // Rimuovi caratteri non validi per base64url
        return preg_replace('/[^A-Za-z0-9\-_]/', '', $token);
    }

    /**
     * Verifica rate limiting per prevenire brute force
     *
     * @param string $identifier Identificatore per il rate limiting (token/IP)
     * @return bool True se entro i limiti, false altrimenti
     */
    private function checkRateLimit(string $identifier): bool {
        $key = 'rate_limit_' . md5($identifier);
        $window = time() - self::RATE_LIMIT_WINDOW;

        try {
            // Pulisci tentativi vecchi
            $sql = "DELETE FROM share_access_logs
                    WHERE ip_address = :ip
                    AND accessed_at < FROM_UNIXTIME(:window)
                    AND success = 0";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':ip' => $this->getClientIpAddress(),
                ':window' => $window
            ]);

            // Conta tentativi recenti
            $sql = "SELECT COUNT(*)
                    FROM share_access_logs
                    WHERE ip_address = :ip
                    AND accessed_at >= FROM_UNIXTIME(:window)
                    AND success = 0";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':ip' => $this->getClientIpAddress(),
                ':window' => $window
            ]);

            $attempts = (int)$stmt->fetchColumn();

            return $attempts < self::RATE_LIMIT_MAX_ATTEMPTS;

        } catch (Exception $e) {
            error_log("Errore verifica rate limit: " . $e->getMessage());
            // In caso di errore, consenti l'accesso per non bloccare utenti legittimi
            return true;
        }
    }

    /**
     * Ottiene l'indirizzo IP del client
     *
     * @return string Indirizzo IP
     */
    private function getClientIpAddress(): string {
        // Controlla vari header per IP dietro proxy
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // CloudFlare
            'HTTP_CLIENT_IP',             // Proxy
            'HTTP_X_FORWARDED_FOR',       // Load Balancer
            'HTTP_X_FORWARDED',           // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',   // Cluster
            'HTTP_FORWARDED_FOR',         // Proxy
            'HTTP_FORWARDED',             // Proxy
            'REMOTE_ADDR'                 // Default
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                // Valida IP
                if (filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Ottiene dati di geolocalizzazione per un IP
     *
     * @param string $ipAddress Indirizzo IP
     * @return array Dati geolocalizzazione
     */
    private function getGeolocation(string $ipAddress): array {
        // TODO: Implementare integrazione con servizio di geolocalizzazione
        // Esempio: MaxMind GeoIP2, IP-API, IPinfo

        return [
            'country_code' => null,
            'city' => null,
            'region' => null,
            'latitude' => null,
            'longitude' => null
        ];
    }

    /**
     * Genera un ID di sessione univoco
     *
     * @return string Session ID
     */
    private function generateSessionId(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Incrementa i tentativi falliti per un token
     *
     * @param string $token Token del link
     */
    private function incrementFailedAttempts(string $token): void {
        $key = 'failed_attempts_' . md5($token);

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = 0;
        }

        $_SESSION[$key]++;
        $_SESSION[$key . '_last'] = time();
    }

    /**
     * Verifica se un link ha restrizioni per email
     *
     * @param int $linkId ID del link
     * @return bool True se ci sono restrizioni
     */
    private function hasEmailRestrictions(int $linkId): bool {
        $sql = "SELECT COUNT(*) FROM share_link_permissions
                WHERE share_link_id = :link_id
                AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':link_id' => $linkId,
            ':tenant_id' => $this->tenantId
        ]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Verifica se un'email è autorizzata per un link
     *
     * @param int $linkId ID del link
     * @param string|null $email Email da verificare
     * @return bool True se autorizzata
     */
    private function isEmailAllowed(int $linkId, ?string $email): bool {
        if (empty($email)) {
            return false;
        }

        $sql = "SELECT can_access FROM share_link_permissions
                WHERE share_link_id = :link_id
                AND email = :email
                AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':link_id' => $linkId,
            ':email' => $email,
            ':tenant_id' => $this->tenantId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['can_access'] == 1;
    }

    /**
     * Crea un token di sessione temporaneo per accesso
     *
     * @param int $linkId ID del link
     * @return string Token di sessione
     */
    private function createSessionToken(int $linkId): string {
        $token = bin2hex(random_bytes(16));

        $_SESSION[self::SESSION_PREFIX . 'tokens'][$token] = [
            'link_id' => $linkId,
            'created_at' => time(),
            'expires_at' => time() + self::PREVIEW_TOKEN_LIFETIME
        ];

        return $token;
    }

    /**
     * Aggiorna l'ultimo accesso a un link
     *
     * @param int $linkId ID del link
     */
    private function updateLastAccess(int $linkId): void {
        $sql = "UPDATE share_links
                SET last_accessed_at = NOW()
                WHERE id = :id
                AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $linkId,
            ':tenant_id' => $this->tenantId
        ]);
    }

    /**
     * Aggiunge permessi basati su email per un link
     *
     * @param int $linkId ID del link
     * @param array $emails Lista di email autorizzate
     */
    private function addEmailPermissions(int $linkId, array $emails): void {
        $sql = "INSERT INTO share_link_permissions
                (tenant_id, share_link_id, email, can_access, created_at)
                VALUES (:tenant_id, :link_id, :email, 1, NOW())";

        $stmt = $this->pdo->prepare($sql);

        foreach ($emails as $email) {
            try {
                $stmt->execute([
                    ':tenant_id' => $this->tenantId,
                    ':link_id' => $linkId,
                    ':email' => $email
                ]);
            } catch (PDOException $e) {
                // Ignora duplicati
                if ($e->getCode() != 23000) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Registra la creazione di un link nei log di audit
     *
     * @param int $linkId ID del link creato
     * @param array $resource Risorsa condivisa
     * @param array $options Opzioni utilizzate
     */
    private function logShareCreation(int $linkId, array $resource, array $options): void {
        // TODO: Implementare logging in tabella audit_logs
        error_log(sprintf(
            "Share link created: ID=%d, Resource=%s:%d, User=%d, Tenant=%d",
            $linkId,
            $resource['type'],
            $resource['id'],
            $this->userId ?? 0,
            $this->tenantId
        ));
    }

    /**
     * Invia notifiche per la creazione del link
     *
     * @param int $linkId ID del link
     * @param array $options Opzioni con dettagli notifica
     */
    private function sendShareNotifications(int $linkId, array $options): void {
        // TODO: Implementare invio email/notifiche
        // Integrare con sistema di notifiche di CollaboraNexio
    }

    /**
     * Trigger webhook per eventi
     *
     * @param string $url URL del webhook
     * @param string $event Tipo di evento
     * @param array $data Dati dell'evento
     */
    private function triggerWebhook(string $url, string $event, array $data): void {
        try {
            $payload = json_encode([
                'event' => $event,
                'timestamp' => time(),
                'data' => $data
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-CollaboraNexio-Event: ' . $event
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            curl_exec($ch);
            curl_close($ch);

        } catch (Exception $e) {
            error_log("Errore trigger webhook: " . $e->getMessage());
        }
    }

    /**
     * Costruisce l'URL completo per un link di condivisione
     *
     * @param string $token Token del link
     * @return string URL completo
     */
    private function buildShareUrl(string $token): string {
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost';
        return $baseUrl . '/share/' . $token;
    }

    /**
     * Genera un URL breve per il link
     *
     * @param string $token Token del link
     * @return string URL breve
     */
    private function generateShortUrl(string $token): string {
        // TODO: Implementare servizio di URL shortening
        // Integrare con servizio esterno o implementazione interna
        return $this->buildShareUrl(substr($token, 0, 8));
    }

    /**
     * Genera codice QR per il link
     *
     * @param string $url URL del link
     * @return string URL del QR code o data URI
     */
    private function generateQRCode(string $url): string {
        // TODO: Implementare generazione QR code
        // Utilizzare libreria come endroid/qr-code
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    }

    /**
     * Verifica e trigger webhook per accessi
     *
     * @param int $linkId ID del link
     * @param string $action Azione eseguita
     * @param int $logId ID del log di accesso
     */
    private function checkAndTriggerAccessWebhook(int $linkId, string $action, int $logId): void {
        // TODO: Recuperare configurazione webhook dal link o dalle impostazioni tenant
        // e triggerare se configurato
    }

    /**
     * Invia notifica per eventi di accesso
     *
     * @param int|null $linkId ID del link
     * @param string $action Azione eseguita
     * @param array $details Dettagli dell'evento
     */
    private function sendAccessNotification(?int $linkId, string $action, array $details): void {
        // TODO: Implementare notifiche per download e accessi negati
        // Integrare con sistema di notifiche di CollaboraNexio
    }

    /**
     * Ottiene statistiche di accesso per un link
     *
     * @param int $linkId ID del link
     * @return array Statistiche di accesso
     */
    public function getAccessStatistics(int $linkId): array {
        try {
            // Verifica permessi
            if (!$this->canViewStatistics($linkId)) {
                throw new RuntimeException("Permessi insufficienti per visualizzare le statistiche");
            }

            // Query statistiche base
            $sql = "SELECT
                        COUNT(*) as total_accesses,
                        COUNT(DISTINCT ip_address) as unique_visitors,
                        COUNT(DISTINCT session_id) as unique_sessions,
                        SUM(CASE WHEN access_type = 'download' THEN 1 ELSE 0 END) as total_downloads,
                        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
                        MIN(accessed_at) as first_access,
                        MAX(accessed_at) as last_access,
                        AVG(duration_ms) as avg_duration,
                        SUM(bytes_transferred) as total_bytes
                    FROM share_access_logs
                    WHERE share_link_id = :link_id
                    AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':link_id' => $linkId,
                ':tenant_id' => $this->tenantId
            ]);

            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Query per distribuzione geografica
            $sql = "SELECT country_code, city, COUNT(*) as access_count
                    FROM share_access_logs
                    WHERE share_link_id = :link_id
                    AND tenant_id = :tenant_id
                    AND country_code IS NOT NULL
                    GROUP BY country_code, city
                    ORDER BY access_count DESC
                    LIMIT 10";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':link_id' => $linkId,
                ':tenant_id' => $this->tenantId
            ]);

            $geoStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Query per user agent più comuni
            $sql = "SELECT
                        user_agent,
                        COUNT(*) as count
                    FROM share_access_logs
                    WHERE share_link_id = :link_id
                    AND tenant_id = :tenant_id
                    GROUP BY user_agent
                    ORDER BY count DESC
                    LIMIT 5";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':link_id' => $linkId,
                ':tenant_id' => $this->tenantId
            ]);

            $userAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => [
                    'summary' => $stats,
                    'geography' => $geoStats,
                    'user_agents' => $userAgents,
                    'link_id' => $linkId
                ]
            ];

        } catch (Exception $e) {
            error_log("Errore recupero statistiche: " . $e->getMessage());
            throw new RuntimeException("Impossibile recuperare le statistiche");
        }
    }

    /**
     * Verifica se l'utente può visualizzare le statistiche
     *
     * @param int $linkId ID del link
     * @return bool True se autorizzato
     */
    private function canViewStatistics(int $linkId): bool {
        if (!$this->userId) {
            return false;
        }

        $sql = "SELECT created_by FROM share_links
                WHERE id = :id
                AND tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $linkId,
            ':tenant_id' => $this->tenantId
        ]);

        $createdBy = $stmt->fetchColumn();

        return $createdBy == $this->userId;
    }

    /**
     * Revoca un link di condivisione
     *
     * @param int $linkId ID del link da revocare
     * @return bool True se revocato con successo
     */
    public function revokeShareLink(int $linkId): bool {
        try {
            // Verifica permessi
            if (!$this->canRevokeLink($linkId)) {
                throw new RuntimeException("Permessi insufficienti per revocare il link");
            }

            $sql = "UPDATE share_links
                    SET is_active = 0,
                        updated_at = NOW()
                    WHERE id = :id
                    AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $linkId,
                ':tenant_id' => $this->tenantId
            ]);

            // Log revoca
            $this->logShareRevocation($linkId);

            return $stmt->rowCount() > 0;

        } catch (Exception $e) {
            error_log("Errore revoca link: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica se l'utente può revocare il link
     *
     * @param int $linkId ID del link
     * @return bool True se autorizzato
     */
    private function canRevokeLink(int $linkId): bool {
        return $this->canViewStatistics($linkId); // Stessi permessi per ora
    }

    /**
     * Registra la revoca di un link
     *
     * @param int $linkId ID del link revocato
     */
    private function logShareRevocation(int $linkId): void {
        error_log(sprintf(
            "Share link revoked: ID=%d, User=%d, Tenant=%d",
            $linkId,
            $this->userId ?? 0,
            $this->tenantId
        ));
    }
}