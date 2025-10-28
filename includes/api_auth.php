<?php
/**
 * Helper centralizzato per autenticazione e autorizzazione API
 * Fornisce funzioni comuni per tutti gli endpoint API
 */

declare(strict_types=1);

/**
 * Inizializza la sessione e prepara l'ambiente API
 * DEVE essere chiamata all'inizio di ogni endpoint API
 */
function initializeApiEnvironment(): void {
    // Suppress all PHP warnings/notices from being output
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');

    // Start output buffering to catch any unexpected output
    ob_start();

    // Usa il file di inizializzazione centralizzato delle sessioni
    require_once __DIR__ . '/session_init.php';

    // Set JSON headers immediately
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

/**
 * Verifica l'autenticazione dell'utente
 * Restituisce true se autenticato, altrimenti termina con errore 401
 */
function verifyApiAuthentication(): bool {
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato', 'success' => false]));
    }

    // Aggiorna timestamp ultima attività
    $_SESSION['last_activity'] = time();

    return true;
}

/**
 * Ottiene il token CSRF da varie fonti possibili
 * Supporta header, GET e POST per massima compatibilità
 */
function getCsrfTokenFromRequest(): ?string {
    // 1. Prova dagli header (vari formati)
    // getallheaders() might not be available in CLI mode, so use fallback
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headerKeys = ['X-CSRF-Token', 'x-csrf-token', 'X-Csrf-Token', 'csrf-token', 'CSRF-Token'];

    foreach ($headers as $key => $value) {
        if (in_array($key, $headerKeys, true) || strcasecmp($key, 'x-csrf-token') === 0) {
            return $value;
        }
    }

    // 2. Fallback a $_SERVER per header non catturati da getallheaders()
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    // 3. Prova dai parametri GET
    if (!empty($_GET['csrf_token'])) {
        return $_GET['csrf_token'];
    }

    // 4. Prova dai parametri POST
    if (!empty($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    // 5. Prova dal body JSON
    $input = file_get_contents('php://input');
    if ($input) {
        $data = json_decode($input, true);
        if (isset($data['csrf_token'])) {
            return $data['csrf_token'];
        }
    }

    return null;
}

/**
 * Verifica il token CSRF
 * @param bool $required Se true, termina con errore 403 se non valido
 * @return bool True se valido, false altrimenti
 */
function verifyApiCsrfToken(bool $required = true): bool {
    $csrfToken = getCsrfTokenFromRequest();

    // Debug logging solo in development
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log('API CSRF Check - Token received: ' . substr($csrfToken ?? 'none', 0, 10) . '...');
        error_log('API CSRF Check - Session token: ' . substr($_SESSION['csrf_token'] ?? 'none', 0, 10) . '...');
    }

    $isValid = !empty($csrfToken) &&
               isset($_SESSION['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $csrfToken);

    if (!$isValid && $required) {
        ob_clean();
        http_response_code(403);
        die(json_encode([
            'error' => 'Token CSRF non valido',
            'success' => false,
            'debug' => DEBUG_MODE ? 'CSRF mismatch or missing' : null
        ]));
    }

    return $isValid;
}

/**
 * Ottiene le informazioni dell'utente corrente dalla sessione
 * Gestisce sia 'role' che 'user_role' per retrocompatibilità
 */
function getApiUserInfo(): array {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'role' => $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user',
        'user_name' => $_SESSION['user_name'] ?? '',
        'user_email' => $_SESSION['user_email'] ?? '',
        'is_multi_tenant' => $_SESSION['is_multi_tenant'] ?? false
    ];
}

/**
 * Verifica se l'utente ha un ruolo specifico o superiore
 * @param string $requiredRole Il ruolo minimo richiesto
 * @return bool True se l'utente ha il ruolo o superiore
 */
function hasApiRole(string $requiredRole): bool {
    $userInfo = getApiUserInfo();
    $userRole = $userInfo['role'];

    // Gerarchia dei ruoli
    $roleHierarchy = [
        'super_admin' => 4,
        'admin' => 3,
        'manager' => 2,
        'user' => 1
    ];

    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;

    return $userLevel >= $requiredLevel;
}

/**
 * Richiede un ruolo specifico o superiore
 * Termina con errore 403 se non autorizzato
 */
function requireApiRole(string $requiredRole): void {
    if (!hasApiRole($requiredRole)) {
        ob_clean();
        http_response_code(403);
        die(json_encode([
            'error' => 'Accesso negato - Ruolo insufficiente',
            'success' => false
        ]));
    }
}

/**
 * Pulisce l'output buffer e restituisce una risposta JSON di successo
 */
function apiSuccess($data = null, string $message = 'Operazione completata con successo'): void {
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Pulisce l'output buffer e restituisce una risposta JSON di errore
 */
function apiError(string $message, int $httpCode = 500, $additionalData = null): void {
    ob_clean();
    http_response_code($httpCode);
    $response = [
        'success' => false,
        'error' => $message
    ];

    if ($additionalData !== null) {
        $response['data'] = $additionalData;
    }

    echo json_encode($response);
    exit();
}

/**
 * Log degli errori solo lato server senza esporre dettagli all'utente
 */
function logApiError(string $context, Exception $e): void {
    error_log(sprintf('[%s] API Error in %s: %s', date('Y-m-d H:i:s'), $context, $e->getMessage()));

    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log('Stack trace: ' . $e->getTraceAsString());
    }
}

/**
 * Normalizza i dati della sessione per retrocompatibilità
 * Assicura che sia 'role' che 'user_role' siano impostati
 */
function normalizeSessionData(): void {
    // Se esiste solo user_role, copia in role
    if (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
        $_SESSION['role'] = $_SESSION['user_role'];
    }

    // Se esiste solo role, copia in user_role per retrocompatibilità
    if (isset($_SESSION['role']) && !isset($_SESSION['user_role'])) {
        $_SESSION['user_role'] = $_SESSION['role'];
    }
}

/**
 * Snake_case aliases for backward compatibility
 * Added 2025-10-25 to fix BUG-021: Task Management API 500 errors
 *
 * The task management API endpoints were written using snake_case function calls
 * (api_success, api_error) but the actual functions are camelCase (apiSuccess, apiError).
 *
 * Rather than updating all 8 task API endpoints, these aliases provide backward compatibility.
 */

/**
 * Snake_case alias for apiSuccess()
 * @see apiSuccess()
 */
function api_success($data = null, string $message = 'Operazione completata con successo'): void {
    apiSuccess($data, $message);
}

/**
 * Snake_case alias for apiError()
 * @see apiError()
 */
function api_error(string $message, int $httpCode = 500, $additionalData = null): void {
    apiError($message, $httpCode, $additionalData);
}