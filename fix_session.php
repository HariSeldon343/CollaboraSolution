<?php
/**
 * Fix Session Script
 * Fixes the session variable mismatch between 'role' and 'user_role'
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Fix the role field mismatch
if (isset($_SESSION['role']) && !isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = $_SESSION['role'];
    echo json_encode([
        'success' => true,
        'message' => 'Session fixed: user_role set from role',
        'user_role' => $_SESSION['user_role']
    ]);
} elseif (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
    $_SESSION['role'] = $_SESSION['user_role'];
    echo json_encode([
        'success' => true,
        'message' => 'Session fixed: role set from user_role',
        'role' => $_SESSION['role']
    ]);
} else {
    echo json_encode([
        'success' => true,
        'message' => 'Session already correct',
        'user_role' => $_SESSION['user_role'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ]);
}

// Ensure CSRF token exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Save session
session_write_close();
?>