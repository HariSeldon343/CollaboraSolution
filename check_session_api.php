<?php
/**
 * Session Debug Script
 * Check what's in the session and test API access
 */

session_start();

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Get session data
$sessionData = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_data' => $_SESSION,
    'headers' => getallheaders(),
    'server' => [
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
        'HTTP_X_CSRF_TOKEN' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'not set',
    ],
    'auth_check' => [
        'has_user_id' => isset($_SESSION['user_id']),
        'has_user_role' => isset($_SESSION['user_role']),
        'has_role' => isset($_SESSION['role']),
        'has_csrf_token' => isset($_SESSION['csrf_token']),
        'has_logged_in' => isset($_SESSION['logged_in']),
    ],
    'user_info' => [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'user_email' => $_SESSION['user_email'] ?? null,
        'user_role' => $_SESSION['user_role'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
    ]
];

// Output as JSON
echo json_encode($sessionData, JSON_PRETTY_PRINT);
?>