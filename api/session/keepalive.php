<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    verifyApiAuthentication();
    verifyApiCsrfToken();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['last_activity'] = time();

    api_success(['alive' => true], 'OK');
} catch (Throwable $e) {
    error_log('[Session Keepalive] ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

