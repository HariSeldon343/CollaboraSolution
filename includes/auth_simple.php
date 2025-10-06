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
     * Effettua il logout
     */
    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/session_init.php';
        }

        // Pulisci tutte le variabili di sessione
        $_SESSION = [];

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

            setcookie(session_name(), '', time() - 42000,
                $params["path"], $cookieDomain,
                $params["secure"], $params["httponly"]
            );
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