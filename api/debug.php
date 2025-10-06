<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'debug_info' => [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
        'path_info' => $_SERVER['PATH_INFO'] ?? 'not set',
        'query_string' => $_SERVER['QUERY_STRING'] ?? 'not set',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'not set',
        'php_version' => phpversion(),
        'session_id' => session_id(),
        'authenticated' => isset($_SESSION['user_id']),
        'user_id' => $_SESSION['user_id'] ?? null,
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'headers' => getallheaders()
    ]
]);