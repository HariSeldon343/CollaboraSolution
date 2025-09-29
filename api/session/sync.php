<?php
/**
 * API per sincronizzare le sessioni tra ambienti
 * Utile per mantenere la sessione quando si passa da localhost a produzione
 */

// Inizializzazione sicura
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/cors_helper.php';
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/api_response.php';

// Configura CORS per permettere richieste cross-domain
setupCORS();

// Gestisci le diverse azioni
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'check':
            // Verifica lo stato della sessione
            $sessionData = [
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session_id' => session_id(),
                'session_name' => session_name(),
                'is_authenticated' => isset($_SESSION['user_id']),
                'environment' => ENVIRONMENT,
                'host' => $_SERVER['HTTP_HOST'] ?? 'unknown'
            ];

            if (isset($_SESSION['user_id'])) {
                $sessionData['user'] = [
                    'id' => $_SESSION['user_id'],
                    'name' => $_SESSION['user_name'] ?? '',
                    'email' => $_SESSION['user_email'] ?? '',
                    'role' => $_SESSION['role'] ?? '',
                    'tenant_id' => $_SESSION['tenant_id'] ?? null
                ];
            }

            api_success($sessionData, 'Session status retrieved');
            break;

        case 'validate':
            // Valida un token CSRF
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

            if (empty($token)) {
                api_error('CSRF token mancante', 400);
            }

            $isValid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);

            api_success([
                'valid' => $isValid,
                'environment' => ENVIRONMENT
            ], $isValid ? 'Token valido' : 'Token non valido');
            break;

        case 'refresh':
            // Rinnova il token CSRF
            if (!isset($_SESSION['user_id'])) {
                api_error('Non autenticato', 401);
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            api_success([
                'csrf_token' => $_SESSION['csrf_token'],
                'session_id' => session_id()
            ], 'Token rinnovato');
            break;

        case 'export':
            // Esporta i dati della sessione (solo per debug in development)
            if (!DEBUG_MODE) {
                api_error('Non disponibile in produzione', 403);
            }

            $exportData = [
                'session_id' => session_id(),
                'session_data' => $_SESSION,
                'cookie_params' => session_get_cookie_params(),
                'environment' => ENVIRONMENT
            ];

            api_success($exportData, 'Session exported');
            break;

        case 'import':
            // Importa i dati della sessione (solo per debug in development)
            if (!DEBUG_MODE) {
                api_error('Non disponibile in produzione', 403);
            }

            $data = json_decode(file_get_contents('php://input'), true);

            if (!empty($data['session_data'])) {
                foreach ($data['session_data'] as $key => $value) {
                    $_SESSION[$key] = $value;
                }
                api_success(['imported' => true], 'Session imported');
            } else {
                api_error('Nessun dato da importare', 400);
            }
            break;

        case 'bridge':
            // Crea un bridge token per trasferire la sessione tra domini
            if (!isset($_SESSION['user_id'])) {
                api_error('Non autenticato', 401);
            }

            // Genera un token temporaneo univoco
            $bridgeToken = bin2hex(random_bytes(32));
            $bridgeData = [
                'user_id' => $_SESSION['user_id'],
                'tenant_id' => $_SESSION['tenant_id'],
                'role' => $_SESSION['role'],
                'user_name' => $_SESSION['user_name'] ?? '',
                'user_email' => $_SESSION['user_email'] ?? '',
                'csrf_token' => $_SESSION['csrf_token'] ?? '',
                'created_at' => time(),
                'expires_at' => time() + 60 // Valido per 60 secondi
            ];

            // Salva temporaneamente il bridge data
            $_SESSION['bridge_tokens'][$bridgeToken] = $bridgeData;

            // Pulisci i token scaduti
            if (isset($_SESSION['bridge_tokens'])) {
                foreach ($_SESSION['bridge_tokens'] as $token => $data) {
                    if ($data['expires_at'] < time()) {
                        unset($_SESSION['bridge_tokens'][$token]);
                    }
                }
            }

            api_success([
                'bridge_token' => $bridgeToken,
                'expires_in' => 60
            ], 'Bridge token created');
            break;

        case 'restore':
            // Ripristina la sessione da un bridge token
            $bridgeToken = $_POST['bridge_token'] ?? $_GET['bridge_token'] ?? '';

            if (empty($bridgeToken)) {
                api_error('Bridge token mancante', 400);
            }

            // Cerca il token in tutte le sessioni (solo in development)
            if (isset($_SESSION['bridge_tokens'][$bridgeToken])) {
                $bridgeData = $_SESSION['bridge_tokens'][$bridgeToken];

                // Verifica che non sia scaduto
                if ($bridgeData['expires_at'] < time()) {
                    unset($_SESSION['bridge_tokens'][$bridgeToken]);
                    api_error('Bridge token scaduto', 401);
                }

                // Ripristina i dati della sessione
                $_SESSION['user_id'] = $bridgeData['user_id'];
                $_SESSION['tenant_id'] = $bridgeData['tenant_id'];
                $_SESSION['role'] = $bridgeData['role'];
                $_SESSION['user_name'] = $bridgeData['user_name'];
                $_SESSION['user_email'] = $bridgeData['user_email'];
                $_SESSION['csrf_token'] = $bridgeData['csrf_token'];
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                // Rimuovi il token usato
                unset($_SESSION['bridge_tokens'][$bridgeToken]);

                api_success([
                    'restored' => true,
                    'user_id' => $_SESSION['user_id']
                ], 'Session restored successfully');
            } else {
                api_error('Bridge token non valido', 401);
            }
            break;

        default:
            api_error('Azione non valida', 400);
    }

} catch (Exception $e) {
    error_log('Session sync error: ' . $e->getMessage());
    api_error('Errore durante la sincronizzazione', 500);
} finally {
    ob_end_flush();
}