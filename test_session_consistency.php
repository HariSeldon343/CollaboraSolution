<?php
/**
 * Test script to verify session consistency across different authentication endpoints
 * This script tests if sessions are properly shared between auth_api.php and other pages
 */

// Initialize session with centralized configuration
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/config.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Gather session information
$sessionInfo = [
    'session_name' => session_name(),
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_cookie_params' => session_get_cookie_params(),
    'session_data' => $_SESSION,
    'cookies_received' => $_COOKIE,
    'current_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server_info' => [
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    ]
];

// Check authentication status
$isAuthenticated = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

$response = [
    'success' => true,
    'is_authenticated' => $isAuthenticated,
    'session_info' => $sessionInfo,
    'user_info' => $isAuthenticated ? [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'user_email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['role'] ?? $_SESSION['user_role'] ?? null,
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'tenant_name' => $_SESSION['tenant_name'] ?? null,
    ] : null,
    'expected_session_name' => 'COLLAB_SID',
    'session_name_matches' => session_name() === 'COLLAB_SID',
    'recommendations' => []
];

// Add recommendations if issues are detected
if (session_name() !== 'COLLAB_SID') {
    $response['recommendations'][] = 'Session name mismatch. Expected COLLAB_SID, got ' . session_name();
}

if (!$isAuthenticated && isset($_COOKIE['COLLAB_SID'])) {
    $response['recommendations'][] = 'COLLAB_SID cookie exists but session is not authenticated. Session may be lost or corrupted.';
}

if ($isAuthenticated && !isset($_COOKIE['COLLAB_SID'])) {
    $response['recommendations'][] = 'User is authenticated but COLLAB_SID cookie is missing. Cookie settings may be incorrect.';
}

// Check for old PHPSESSID cookie
if (isset($_COOKIE['PHPSESSID'])) {
    $response['recommendations'][] = 'Old PHPSESSID cookie still exists. Clear browser cookies to ensure clean session.';
    $response['old_phpsessid'] = $_COOKIE['PHPSESSID'];
}

// Output the response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>