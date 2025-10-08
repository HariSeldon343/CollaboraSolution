<?php
/**
 * Sistema di autenticazione semplificato per CollaboraNexio
 */

class AuthSimple {
    private ?PDO $pdo = null;

    public function __construct() {
        // Non inizializzare sessione qui, sarà gestita esternamente
    }

    /**
     * Verifica se l'utente è autenticato
     */
    public function checkAuth(): bool {
        // Assicurati che la sessione sia avviata
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/session_init.php';
        }

        // Verifica timeout inattivita (10 minuti)
        $inactivity_timeout = 600; // 10 minuti
        if (isset($_SESSION['last_activity'])) {
            $elapsed = time() - $_SESSION['last_activity'];
            if ($elapsed > $inactivity_timeout) {
                // Timeout scaduto - effettua logout
                $this->logout();
                header('Location: /CollaboraNexio/index.php?timeout=1');
                exit();
            }
        }

        // Aggiorna last_activity
        $_SESSION['last_activity'] = time();

        // Verifica se user_id è presente nella sessione
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Ottiene i dati dell'utente corrente dalla sessione
     */
    public function getCurrentUser(): ?array {
        if (!$this->checkAuth()) {
            return null;
        }

        return [
            'id' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['user_name'] ?? 'User',
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user',
            'tenant_id' => $_SESSION['tenant_id'] ?? null,
            'tenant_name' => $_SESSION['tenant_name'] ?? 'Default'
        ];
    }

    /**
     * Effettua il logout e distrugge completamente la sessione
     */
    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/session_init.php';
        }

        // Pulisci tutte le variabili di sessione
        $_SESSION = array();

        // Distruggi il cookie di sessione
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();

            // Determina il dominio del cookie basato sull'ambiente
            $cookieDomain = $params['domain'];
            if (empty($cookieDomain)) {
                $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                if (strpos($currentHost, 'nexiosolution.it') !== false) {
                    $cookieDomain = '.nexiosolution.it';
                }
            }

            // Cancella il cookie di sessione impostandolo nel passato
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params["path"],
                'domain' => $cookieDomain,
                'secure' => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => $params["samesite"] ?? 'Lax'
            ]);
        }

        // Distruggi la sessione
        session_destroy();
    }

    /**
     * Genera un token CSRF (placeholder per compatibilità)
     */
    public function generateCSRFToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifica un token CSRF (placeholder per compatibilità)
     */
    public function verifyCSRFToken(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Alias for verifyCSRFToken for compatibility
     */
    public function validateCSRFToken(string $token): bool {
        return $this->verifyCSRFToken($token);
    }
}

// Per compatibilità con il codice esistente
class Auth extends AuthSimple {}