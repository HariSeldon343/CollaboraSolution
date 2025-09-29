<?php
/**
 * Sistema di autenticazione multi-tenant
 * Gestisce login, logout, sessioni e controlli di sicurezza
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

class Auth {
    private PDO $pdo;
    private const SESSION_LIFETIME = 1800; // 30 minuti in secondi
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minuti in secondi

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->initializeSession();
    }

    /**
     * Inizializza la sessione con configurazioni di sicurezza
     */
    private function initializeSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Usa il file di inizializzazione centralizzato delle sessioni
            require_once __DIR__ . '/session_init.php';
        }

        // Verifica timeout sessione
        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > self::SESSION_LIFETIME)) {
            $this->logout();
            return;
        }

        // Aggiorna timestamp ultima attività
        $_SESSION['last_activity'] = time();

        // Protezione contro session hijacking
        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        } elseif ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $this->logout();
            return;
        }

        // Protezione contro session fixation - rigenera ID periodicamente
        if (!isset($_SESSION['regenerate_time'])) {
            $_SESSION['regenerate_time'] = time();
        } elseif (time() - $_SESSION['regenerate_time'] > 300) { // Ogni 5 minuti
            session_regenerate_id(true);
            $_SESSION['regenerate_time'] = time();
        }
    }

    /**
     * Effettua il login dell'utente
     *
     * @param string $email Email dell'utente
     * @param string $password Password in chiaro
     * @return array Risultato del login con success e eventuale messaggio
     */
    public function login(string $email, string $password): array {
        try {
            // Sanitizzazione input
            $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Email non valida'];
            }

            // Verifica tentativi di login falliti
            if ($this->isLockedOut($email)) {
                return ['success' => false, 'message' => 'Account temporaneamente bloccato per troppi tentativi falliti'];
            }

            // Cerca l'utente e identifica automaticamente il tenant
            $stmt = $this->pdo->prepare("
                SELECT u.*, t.name as tenant_name, t.status as tenant_status
                FROM users u
                INNER JOIN tenants t ON u.tenant_id = t.id
                WHERE u.email = :email
                AND u.status = 'active'
                AND u.deleted_at IS NULL
                LIMIT 1
            ");

            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->recordLoginAttempt($email, false);
                return ['success' => false, 'message' => 'Credenziali non valide'];
            }

            // Verifica stato del tenant
            if ($user['tenant_status'] !== 'active') {
                return ['success' => false, 'message' => 'Tenant non attivo'];
            }

            // Verifica password
            if (!password_verify($password, $user['password_hash'])) {
                $this->recordLoginAttempt($email, false, $user['id'], $user['tenant_id']);
                return ['success' => false, 'message' => 'Credenziali non valide'];
            }

            // Verifica se la password necessita di rehash
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $updateStmt = $this->pdo->prepare("UPDATE users SET password_hash = :password WHERE id = :id");
                $updateStmt->execute([':password' => $newHash, ':id' => $user['id']]);
            }

            // Gestione utenti multi-tenant - verifica accessi aggiuntivi
            $tenants = $this->getUserTenants($user['id']);

            // Rigenera ID sessione per prevenire session fixation
            session_regenerate_id(true);

            // Imposta variabili di sessione
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['role'] = $user['role'];  // Cambiato da user_role a role
            $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['regenerate_time'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['ip_address'] = $this->getClientIp();

            // Memorizza tenant aggiuntivi se presenti
            if (count($tenants) > 1) {
                $_SESSION['available_tenants'] = $tenants;
                $_SESSION['is_multi_tenant'] = true;
            } else {
                $_SESSION['is_multi_tenant'] = false;
            }

            // Aggiorna ultimo accesso
            $this->updateLastLogin($user['id']);

            // Registra accesso nei log
            $this->logActivity($user['id'], $user['tenant_id'], 'login', 'Login effettuato con successo');

            // Registra tentativo riuscito
            $this->recordLoginAttempt($email, true, $user['id'], $user['tenant_id']);

            return [
                'success' => true,
                'message' => 'Login effettuato con successo',
                'user' => [
                    'id' => $user['id'],
                    'name' => $_SESSION['user_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'tenant_id' => $user['tenant_id'],
                    'is_multi_tenant' => $_SESSION['is_multi_tenant']
                ]
            ];

        } catch (Exception $e) {
            error_log('Errore login: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Errore durante il login'];
        }
    }

    /**
     * Effettua il logout dell'utente
     *
     * @return bool True se il logout è riuscito
     */
    public function logout(): bool {
        try {
            // Registra logout nei log se l'utente è autenticato
            if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_id'])) {
                $this->logActivity($_SESSION['user_id'], $_SESSION['tenant_id'], 'logout', 'Logout effettuato');
            }

            // Invalida il cookie di sessione
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();

                // Determina il dominio del cookie basato sull'ambiente
                $cookieDomain = $params['domain'];
                if (empty($cookieDomain)) {
                    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    if (strpos($currentHost, 'nexiosolution.it') !== false) {
                        $cookieDomain = '.nexiosolution.it';
                    }
                }

                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $cookieDomain,
                    $params['secure'],
                    $params['httponly']
                );
            }

            // Pulisce tutte le variabili di sessione
            $_SESSION = [];

            // Distrugge la sessione
            session_destroy();

            // Avvia una nuova sessione pulita
            session_start();
            session_regenerate_id(true);

            return true;

        } catch (Exception $e) {
            error_log('Errore logout: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica se l'utente è autenticato
     *
     * @return bool True se l'utente è autenticato
     */
    public function checkAuth(): bool {
        // Verifica presenza variabili di sessione essenziali
        if (!isset($_SESSION['user_id']) ||
            !isset($_SESSION['tenant_id']) ||
            !isset($_SESSION['role'])) {
            return false;
        }

        // Verifica timeout sessione
        if (!isset($_SESSION['last_activity']) ||
            (time() - $_SESSION['last_activity'] > self::SESSION_LIFETIME)) {
            $this->logout();
            return false;
        }

        // Verifica user agent per protezione contro hijacking
        if (!isset($_SESSION['user_agent']) ||
            $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $this->logout();
            return false;
        }

        // Verifica cambio IP (opzionale, può essere disabilitato per reti dinamiche)
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $this->getClientIp()) {
            // Log del cambio IP sospetto
            $this->logActivity(
                $_SESSION['user_id'],
                $_SESSION['tenant_id'],
                'security_warning',
                'Cambio IP rilevato durante la sessione'
            );
        }

        // Aggiorna timestamp ultima attività
        $_SESSION['last_activity'] = time();

        // Rigenera periodicamente l'ID di sessione
        if (!isset($_SESSION['regenerate_time']) ||
            (time() - $_SESSION['regenerate_time'] > 300)) {
            session_regenerate_id(true);
            $_SESSION['regenerate_time'] = time();
        }

        return true;
    }

    /**
     * Ottiene i dati dell'utente corrente
     *
     * @return array|null Dati dell'utente o null se non autenticato
     */
    public function getCurrentUser(): ?array {
        if (!$this->checkAuth()) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email, u.role,
                       u.avatar_url as avatar, u.tenant_id,
                       t.name as tenant_name
                FROM users u
                INNER JOIN tenants t ON u.tenant_id = t.id
                WHERE u.id = :user_id
                AND u.status = 'active'
                AND u.deleted_at IS NULL
                AND t.status = 'active'
            ");

            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->logout();
                return null;
            }

            // Aggiungi il nome completo
            $user['name'] = trim($user['first_name'] . ' ' . $user['last_name']);

            // Aggiungi informazioni multi-tenant se disponibili
            if (isset($_SESSION['is_multi_tenant']) && $_SESSION['is_multi_tenant']) {
                $user['available_tenants'] = $_SESSION['available_tenants'] ?? [];
                $user['is_multi_tenant'] = true;
            }

            return $user;

        } catch (Exception $e) {
            error_log('Errore getCurrentUser: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica se l'utente ha un determinato ruolo
     *
     * @param string $role Ruolo da verificare
     * @return bool True se l'utente ha il ruolo specificato
     */
    public function hasRole(string $role): bool {
        if (!$this->checkAuth()) {
            return false;
        }

        $userRole = $_SESSION['role'] ?? '';

        // Gerarchia dei ruoli
        $roleHierarchy = [
            'super_admin' => 4,
            'admin' => 3,
            'manager' => 2,
            'user' => 1
        ];

        $userLevel = $roleHierarchy[$userRole] ?? 0;
        $requiredLevel = $roleHierarchy[$role] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Cambia il tenant attivo per utenti multi-tenant
     *
     * @param int $tenantId ID del tenant a cui passare
     * @return array Risultato del cambio tenant
     */
    public function switchTenant(int $tenantId): array {
        if (!$this->checkAuth()) {
            return ['success' => false, 'message' => 'Non autenticato'];
        }

        if (!isset($_SESSION['is_multi_tenant']) || !$_SESSION['is_multi_tenant']) {
            return ['success' => false, 'message' => 'Utente non multi-tenant'];
        }

        try {
            // Verifica che l'utente abbia accesso al tenant
            $stmt = $this->pdo->prepare("
                SELECT uta.*, t.name as tenant_name, t.status as tenant_status
                FROM user_tenant_access uta
                INNER JOIN tenants t ON uta.tenant_id = t.id
                WHERE uta.user_id = :user_id
                AND uta.tenant_id = :tenant_id
                AND t.status = 'active'
            ");

            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':tenant_id' => $tenantId
            ]);

            $access = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$access) {
                return ['success' => false, 'message' => 'Accesso al tenant non autorizzato'];
            }

            // Aggiorna sessione con nuovo tenant
            $oldTenantId = $_SESSION['tenant_id'];
            $_SESSION['tenant_id'] = $tenantId;
            $_SESSION['role'] = $access['role'] ?? $_SESSION['role'];

            // Registra il cambio nei log
            $this->logActivity(
                $_SESSION['user_id'],
                $tenantId,
                'tenant_switch',
                "Cambio tenant da ID {$oldTenantId} a ID {$tenantId}"
            );

            return [
                'success' => true,
                'message' => 'Tenant cambiato con successo',
                'tenant' => [
                    'id' => $tenantId,
                    'name' => $access['tenant_name']
                ]
            ];

        } catch (Exception $e) {
            error_log('Errore switchTenant: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Errore durante il cambio tenant'];
        }
    }

    /**
     * Ottiene tutti i tenant a cui l'utente ha accesso
     *
     * @param int $userId ID dell'utente
     * @return array Lista dei tenant accessibili
     */
    private function getUserTenants(int $userId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT t.id, t.name,
                       COALESCE(uta.role, u.role) as role
                FROM tenants t
                LEFT JOIN users u ON u.tenant_id = t.id AND u.id = :user_id
                LEFT JOIN user_tenant_access uta ON uta.tenant_id = t.id AND uta.user_id = :user_id2
                WHERE t.status = 'active'
                AND (u.id IS NOT NULL OR uta.user_id IS NOT NULL)
                ORDER BY t.name
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log('Errore getUserTenants: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Registra attività nel log
     *
     * @param int $userId ID dell'utente
     * @param int $tenantId ID del tenant
     * @param string $action Azione eseguita
     * @param string $details Dettagli dell'azione
     */
    private function logActivity(int $userId, int $tenantId, string $action, string $details = ''): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (tenant_id, user_id, action, details, ip_address, user_agent, created_at)
                VALUES (:tenant_id, :user_id, :action, :details, :ip_address, :user_agent, NOW())
            ");

            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':user_id' => $userId,
                ':action' => $action,
                ':details' => $details,
                ':ip_address' => $this->getClientIp(),
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

        } catch (Exception $e) {
            error_log('Errore logActivity: ' . $e->getMessage());
        }
    }

    /**
     * Registra un tentativo di login
     *
     * @param string $email Email utilizzata
     * @param bool $success Se il login è riuscito
     * @param int|null $userId ID dell'utente se trovato
     * @param int|null $tenantId ID del tenant se trovato
     */
    private function recordLoginAttempt(string $email, bool $success, ?int $userId = null, ?int $tenantId = null): void {
        try {
            // Prima crea la tabella se non esiste
            $this->createLoginAttemptsTable();

            $stmt = $this->pdo->prepare("
                INSERT INTO login_attempts (email, user_id, tenant_id, success, ip_address, user_agent, attempted_at)
                VALUES (:email, :user_id, :tenant_id, :success, :ip_address, :user_agent, NOW())
            ");

            $stmt->execute([
                ':email' => $email,
                ':user_id' => $userId,
                ':tenant_id' => $tenantId,
                ':success' => $success ? 1 : 0,
                ':ip_address' => $this->getClientIp(),
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

        } catch (Exception $e) {
            error_log('Errore recordLoginAttempt: ' . $e->getMessage());
        }
    }

    /**
     * Crea la tabella login_attempts se non esiste
     */
    private function createLoginAttemptsTable(): void {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255),
                user_id INT NULL,
                tenant_id INT NULL,
                success TINYINT DEFAULT 0,
                ip_address VARCHAR(45),
                user_agent TEXT,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_time (email, attempted_at),
                INDEX idx_user (user_id)
            )";
            $this->pdo->exec($sql);
        } catch (Exception $e) {
            error_log('Errore creazione tabella login_attempts: ' . $e->getMessage());
        }
    }

    /**
     * Verifica se un account è temporaneamente bloccato
     *
     * @param string $email Email da verificare
     * @return bool True se l'account è bloccato
     */
    private function isLockedOut(string $email): bool {
        try {
            // Prima assicurati che la tabella esista
            $this->createLoginAttemptsTable();

            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as attempts
                FROM login_attempts
                WHERE email = :email
                AND success = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL :lockout_time SECOND)
            ");

            $stmt->execute([
                ':email' => $email,
                ':lockout_time' => self::LOCKOUT_TIME
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return ($result['attempts'] >= self::MAX_LOGIN_ATTEMPTS);

        } catch (Exception $e) {
            error_log('Errore isLockedOut: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggiorna l'ultimo accesso dell'utente
     *
     * @param int $userId ID dell'utente
     */
    private function updateLastLogin(int $userId): void {
        try {
            // Prima verifica se la colonna esiste
            $stmt = $this->pdo->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'last_login_at'
            ");
            $stmt->execute();

            if (!$stmt->fetch()) {
                // Aggiungi la colonna se non esiste
                $this->pdo->exec("ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL");
            }

            $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $userId]);
        } catch (Exception $e) {
            error_log('Errore updateLastLogin: ' . $e->getMessage());
        }
    }

    /**
     * Ottiene l'indirizzo IP del client
     *
     * @return string Indirizzo IP del client
     */
    private function getClientIp(): string {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Genera un token CSRF
     *
     * @return string Token CSRF generato
     */
    public function generateCSRFToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifica un token CSRF
     *
     * @param string $token Token da verificare
     * @return bool True se il token è valido
     */
    public function verifyCSRFToken(string $token): bool {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Verifica se la sessione è scaduta
     *
     * @return bool True se la sessione è scaduta
     */
    public function isSessionExpired(): bool {
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        return (time() - $_SESSION['last_activity'] > self::SESSION_LIFETIME);
    }

    /**
     * Rinnova la sessione
     */
    public function renewSession(): void {
        if ($this->checkAuth()) {
            session_regenerate_id(true);
            $_SESSION['last_activity'] = time();
            $_SESSION['regenerate_time'] = time();
        }
    }
}

// Crea istanza globale per retrocompatibilità
$auth = new Auth();

// Funzioni wrapper per retrocompatibilità
function checkAuth(): bool {
    global $auth;
    return $auth->checkAuth();
}

function hasRole(string $role): bool {
    global $auth;
    return $auth->hasRole($role);
}

function getCurrentUser(): ?array {
    global $auth;
    return $auth->getCurrentUser();
}

function requireAuth(): void {
    global $auth;
    if (!$auth->checkAuth()) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Non autorizzato']));
    }
}

function requireRole(string $role): void {
    global $auth;
    requireAuth();
    if (!$auth->hasRole($role)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Accesso negato']));
    }
}