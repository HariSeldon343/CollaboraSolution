<?php
/**
 * Debug script to check session and API authentication
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();

$debugInfo = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_data' => $_SESSION ?? [],
    'is_authenticated' => $auth->checkAuth(),
    'current_user' => $auth->getCurrentUser(),
    'csrf_token' => $_SESSION['csrf_token'] ?? null,
    'cookies' => $_COOKIE ?? [],
    'server_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'headers' => [
        'X-CSRF-Token' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'missing',
        'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? 'missing',
        'Cookie' => $_SERVER['HTTP_COOKIE'] ?? 'missing'
    ]
];

echo json_encode($debugInfo, JSON_PRETTY_PRINT);
