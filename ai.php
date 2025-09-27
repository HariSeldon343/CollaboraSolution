<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
$auth = new Auth();

if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>AI Assistant - CollaboraNexio</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <style>
        .ai-container {
            padding: var(--space-6);
            max-width: 1400px;
            margin: 0 auto;
        }

        .ai-header {
            text-align: center;
            margin-bottom: var(--space-8);
        }

        .ai-title {
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-2);
        }

        .ai-subtitle {
            font-size: var(--text-lg);
            color: var(--color-gray-600);
        }

        .ai-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .feature-card {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition-normal);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--color-primary), var(--color-secondary));
            transform: scaleX(0);
            transition: transform var(--transition-normal);
        }

        .feature-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: var(--space-4);
        }

        .feature-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-2);
        }

        .feature-description {
            color: var(--color-gray-600);
            line-height: 1.6;
            margin-bottom: var(--space-4);
        }

        .feature-action {
            color: var(--color-primary);
            font-weight: var(--font-medium);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-1);
        }

        .ai-chat-section {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: var(--space-8);
        }

        .chat-header {
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
            color: white;
            padding: var(--space-6);
        }

        .chat-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            margin-bottom: var(--space-2);
        }

        .chat-status {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: var(--text-sm);
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .chat-messages {
            height: 400px;
            overflow-y: auto;
            padding: var(--space-6);
            background: var(--color-gray-50);
        }

        .message {
            margin-bottom: var(--space-4);
            display: flex;
            gap: var(--space-3);
        }

        .message.user {
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: var(--font-semibold);
            flex-shrink: 0;
        }

        .message.ai .message-avatar {
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
        }

        .message-content {
            max-width: 70%;
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-lg);
            background: var(--color-white);
        }

        .message.user .message-content {
            background: var(--color-primary);
            color: white;
        }

        .chat-input-container {
            padding: var(--space-4);
            background: var(--color-white);
            border-top: 1px solid var(--color-gray-200);
        }

        .chat-input-form {
            display: flex;
            gap: var(--space-3);
        }

        .chat-input {
            flex: 1;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-lg);
            font-size: var(--text-base);
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-100);
        }

        .chat-send-btn {
            padding: var(--space-3) var(--space-6);
            background: var(--color-primary);
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-weight: var(--font-medium);
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .chat-send-btn:hover {
            background: var(--color-primary-600);
        }

        .ai-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
        }

        .stat-card {
            background: var(--color-white);
            padding: var(--space-4);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            text-align: center;
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-1);
        }

        .stat-label {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .suggestions-container {
            background: var(--color-primary-50);
            padding: var(--space-4);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-4);
        }

        .suggestions-title {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--color-gray-700);
            margin-bottom: var(--space-2);
        }

        .suggestion-chips {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
        }

        .suggestion-chip {
            padding: var(--space-2) var(--space-3);
            background: var(--color-white);
            border: 1px solid var(--color-primary);
            border-radius: var(--radius-full);
            font-size: var(--text-sm);
            color: var(--color-primary);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .suggestion-chip:hover {
            background: var(--color-primary);
            color: white;
        }

        /* Additional sidebar styles - IDENTICHE A DASHBOARD.PHP */
        .nav-section {
            margin-bottom: var(--space-6);
        }

        .nav-section-title {
            padding: var(--space-2) var(--space-4);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-sidebar-text-muted);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-4);
            color: var(--color-sidebar-text);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            font-size: var(--text-sm);
        }

        .nav-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .nav-item.active {
            background-color: rgba(255, 255, 255, 0.15);
        }

        .nav-item.active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background-color: var(--color-sidebar-active);
        }

        .icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-style: normal;
            color: var(--color-sidebar-text);
            position: relative;
        }

        /* White icon styles using CSS */
        .icon::before {
            content: '';
            display: block;
            width: 18px;
            height: 18px;
            background-color: currentColor;
            mask-size: contain;
            mask-repeat: no-repeat;
            mask-position: center;
            -webkit-mask-size: contain;
            -webkit-mask-repeat: no-repeat;
            -webkit-mask-position: center;
        }

        /* Individual icon masks - TUTTE LE ICONE */
        .icon--home::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'/%3E%3Cpolyline points='9 22 9 12 15 12 15 22'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'/%3E%3Cpolyline points='9 22 9 12 15 12 15 22'/%3E%3C/svg%3E");
        }

        .icon--folder::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
        }

        .icon--calendar::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
        }

        .icon--check::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpolyline points='9 11 12 14 22 4'/%3E%3Cpath d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpolyline points='9 11 12 14 22 4'/%3E%3Cpath d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/%3E%3C/svg%3E");
        }

        .icon--ticket::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z'/%3E%3Cpath d='M13 5v2'/%3E%3Cpath d='M13 17v2'/%3E%3Cpath d='M13 11v2'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z'/%3E%3Cpath d='M13 5v2'/%3E%3Cpath d='M13 17v2'/%3E%3Cpath d='M13 11v2'/%3E%3C/svg%3E");
        }

        .icon--shield::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E%3C/svg%3E");
        }

        .icon--cpu::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='4' y='4' width='16' height='16' rx='2'/%3E%3Crect x='9' y='9' width='6' height='6'/%3E%3Cline x1='9' y1='1' x2='9' y2='4'/%3E%3Cline x1='15' y1='1' x2='15' y2='4'/%3E%3Cline x1='9' y1='20' x2='9' y2='23'/%3E%3Cline x1='15' y1='20' x2='15' y2='23'/%3E%3Cline x1='20' y1='9' x2='23' y2='9'/%3E%3Cline x1='20' y1='14' x2='23' y2='14'/%3E%3Cline x1='1' y1='9' x2='4' y2='9'/%3E%3Cline x1='1' y1='14' x2='4' y2='14'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='4' y='4' width='16' height='16' rx='2'/%3E%3Crect x='9' y='9' width='6' height='6'/%3E%3Cline x1='9' y1='1' x2='9' y2='4'/%3E%3Cline x1='15' y1='1' x2='15' y2='4'/%3E%3Cline x1='9' y1='20' x2='9' y2='23'/%3E%3Cline x1='15' y1='20' x2='15' y2='23'/%3E%3Cline x1='20' y1='9' x2='23' y2='9'/%3E%3Cline x1='20' y1='14' x2='23' y2='14'/%3E%3Cline x1='1' y1='9' x2='4' y2='9'/%3E%3Cline x1='1' y1='14' x2='4' y2='14'/%3E%3C/svg%3E");
        }

        .icon--building::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z'/%3E%3Cpath d='M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2'/%3E%3Cpath d='M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2'/%3E%3Cpath d='M10 6h4'/%3E%3Cpath d='M10 10h4'/%3E%3Cpath d='M10 14h4'/%3E%3Cpath d='M10 18h4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z'/%3E%3Cpath d='M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2'/%3E%3Cpath d='M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2'/%3E%3Cpath d='M10 6h4'/%3E%3Cpath d='M10 10h4'/%3E%3Cpath d='M10 14h4'/%3E%3Cpath d='M10 18h4'/%3E%3C/svg%3E");
        }

        .icon--users::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='9' cy='7' r='4'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87'/%3E%3Cpath d='M16 3.13a4 4 0 0 1 0 7.75'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='9' cy='7' r='4'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87'/%3E%3Cpath d='M16 3.13a4 4 0 0 1 0 7.75'/%3E%3C/svg%3E");
        }

        .icon--chart::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 3v18h18'/%3E%3Cpath d='m19 9-5 5-4-4-3 3'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 3v18h18'/%3E%3Cpath d='m19 9-5 5-4-4-3 3'/%3E%3C/svg%3E");
        }

        .icon--settings::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3Cpath d='M12 1v6m0 6v6m4.22-13.22l4.24 4.24M1.54 13.54l4.24 4.24M6.34 6.34L2.1 2.1m13.8 13.8l4.24 4.24'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3Cpath d='M12 1v6m0 6v6m4.22-13.22l4.24 4.24M1.54 13.54l4.24 4.24M6.34 6.34L2.1 2.1m13.8 13.8l4.24 4.24'/%3E%3C/svg%3E");
        }

        .icon--user::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
        }

        .icon--logout::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/%3E%3Cpolyline points='16 17 21 12 16 7'/%3E%3Cline x1='21' y1='12' x2='9' y2='12'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/%3E%3Cpolyline points='16 17 21 12 16 7'/%3E%3Cline x1='21' y1='12' x2='9' y2='12'/%3E%3C/svg%3E");
        }

        /* Stili per logo e user info */
        .logo-icon {
            font-size: var(--text-2xl);
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary);
            color: var(--color-white);
            border-radius: var(--radius-md);
            font-weight: var(--font-bold);
        }

        .logo-text {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
        }

        .sidebar-subtitle {
            font-size: var(--text-xs);
            color: var(--color-sidebar-text-muted);
            margin-top: var(--space-1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3);
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-lg);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--color-sidebar-active);
            color: var(--color-white);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-sidebar-text);
        }

        .user-badge {
            font-size: 10px;
            color: var(--color-white);
            background: var(--color-primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            display: inline-block;
            font-weight: var(--font-semibold);
        }
    </style>
</head>
<body>
    <div class="main-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <span class="logo-icon">N</span>
                    <span class="logo-text">NEXIO</span>
                </div>
                <div class="sidebar-subtitle">Semplifica, Connetti, Cresci Insieme</div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">AREA OPERATIVA</div>
                    <a href="dashboard.php" class="nav-item"><i class="icon icon--home"></i> Dashboard</a>
                    <a href="files.php" class="nav-item"><i class="icon icon--folder"></i> File Manager</a>
                    <a href="calendar.php" class="nav-item"><i class="icon icon--calendar"></i> Calendario</a>
                    <a href="tasks.php" class="nav-item"><i class="icon icon--check"></i> Task</a>
                    <a href="ticket.php" class="nav-item"><i class="icon icon--ticket"></i> Ticket</a>
                    <a href="conformita.php" class="nav-item"><i class="icon icon--shield"></i> Conformità</a>
                    <a href="ai.php" class="nav-item active"><i class="icon icon--cpu"></i> AI</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">GESTIONE</div>
                    <a href="aziende.php" class="nav-item"><i class="icon icon--building"></i> Aziende</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">AMMINISTRAZIONE</div>
                    <a href="utenti.php" class="nav-item"><i class="icon icon--users"></i> Utenti</a>
                    <a href="audit_log.php" class="nav-item"><i class="icon icon--chart"></i> Audit Log</a>
                    <a href="configurazioni.php" class="nav-item"><i class="icon icon--settings"></i> Configurazioni</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">ACCOUNT</div>
                    <a href="profilo.php" class="nav-item"><i class="icon icon--user"></i> Il Mio Profilo</a>
                    <a href="logout.php" class="nav-item"><i class="icon icon--logout"></i> Esci</a>
                </div>
            </nav>
            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?></div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                        <div class="user-badge">SUPER ADMIN</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="header">
                <h1 class="page-title">AI Assistant</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="ai-container">
                    <!-- Header -->
                    <div class="ai-header">
                        <h2 class="ai-title">Assistente AI Intelligente</h2>
                        <p class="ai-subtitle">Ottimizza il tuo lavoro con l'intelligenza artificiale</p>
                    </div>

                    <!-- AI Features -->
                    <div class="ai-features">
                        <div class="feature-card">
                            <div class="feature-icon">🤖</div>
                            <h3 class="feature-title">Automazione Task</h3>
                            <p class="feature-description">
                                Automatizza attività ripetitive e risparmia tempo prezioso con l'AI che apprende dai tuoi processi.
                            </p>
                            <a href="#" class="feature-action">
                                Configura automazioni →
                            </a>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">📝</div>
                            <h3 class="feature-title">Generazione Documenti</h3>
                            <p class="feature-description">
                                Crea documenti professionali in pochi secondi basandoti su template e dati esistenti.
                            </p>
                            <a href="#" class="feature-action">
                                Crea documento →
                            </a>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">📊</div>
                            <h3 class="feature-title">Analisi Predittiva</h3>
                            <p class="feature-description">
                                Ottieni insights predittivi sui tuoi progetti e identifica potenziali rischi in anticipo.
                            </p>
                            <a href="#" class="feature-action">
                                Visualizza analisi →
                            </a>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">💬</div>
                            <h3 class="feature-title">Assistenza Smart</h3>
                            <p class="feature-description">
                                Ricevi suggerimenti contestuali e risposte immediate alle tue domande operative.
                            </p>
                            <a href="#" class="feature-action">
                                Chiedi all'AI →
                            </a>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">🔍</div>
                            <h3 class="feature-title">Ricerca Semantica</h3>
                            <p class="feature-description">
                                Trova rapidamente informazioni nei tuoi documenti con ricerca intelligente basata sul contesto.
                            </p>
                            <a href="#" class="feature-action">
                                Cerca nei documenti →
                            </a>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon">🎯</div>
                            <h3 class="feature-title">Suggerimenti Azioni</h3>
                            <p class="feature-description">
                                Ricevi raccomandazioni personalizzate per ottimizzare i tuoi flussi di lavoro.
                            </p>
                            <a href="#" class="feature-action">
                                Vedi suggerimenti →
                            </a>
                        </div>
                    </div>

                    <!-- AI Chat Interface -->
                    <div class="ai-chat-section">
                        <div class="chat-header">
                            <h3 class="chat-title">Assistente Virtuale</h3>
                            <div class="chat-status">
                                <span class="status-indicator"></span>
                                <span>Online e pronto ad aiutarti</span>
                            </div>
                        </div>

                        <div class="suggestions-container">
                            <div class="suggestions-title">Suggerimenti rapidi:</div>
                            <div class="suggestion-chips">
                                <button class="suggestion-chip" onclick="sendSuggestion('Come posso creare un nuovo progetto?')">
                                    Come creare un progetto?
                                </button>
                                <button class="suggestion-chip" onclick="sendSuggestion('Mostrami le attività in scadenza')">
                                    Attività in scadenza
                                </button>
                                <button class="suggestion-chip" onclick="sendSuggestion('Genera un report mensile')">
                                    Genera report mensile
                                </button>
                                <button class="suggestion-chip" onclick="sendSuggestion('Analizza produttività team')">
                                    Analizza produttività
                                </button>
                            </div>
                        </div>

                        <div class="chat-messages" id="chat-messages">
                            <div class="message ai">
                                <div class="message-avatar">AI</div>
                                <div class="message-content">
                                    Ciao! Sono il tuo assistente AI. Come posso aiutarti oggi? Posso assisterti con task, documenti, analisi e molto altro.
                                </div>
                            </div>
                        </div>

                        <div class="chat-input-container">
                            <form class="chat-input-form" onsubmit="sendMessage(event)">
                                <input type="text"
                                       class="chat-input"
                                       id="chat-input"
                                       placeholder="Scrivi un messaggio..."
                                       autocomplete="off">
                                <button type="submit" class="chat-send-btn">Invia</button>
                            </form>
                        </div>
                    </div>

                    <!-- AI Usage Stats -->
                    <div class="ai-stats">
                        <div class="stat-card">
                            <div class="stat-value">156</div>
                            <div class="stat-label">Task Automatizzati</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">42</div>
                            <div class="stat-label">Documenti Generati</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">89%</div>
                            <div class="stat-label">Accuratezza Previsioni</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">3.2h</div>
                            <div class="stat-label">Tempo Risparmiato Oggi</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        function sendMessage(event) {
            event.preventDefault();
            const input = document.getElementById('chat-input');
            const message = input.value.trim();

            if (message) {
                addMessage(message, 'user');
                input.value = '';

                // Simulate AI response
                setTimeout(() => {
                    const responses = [
                        "Sto elaborando la tua richiesta. Analizzo i dati disponibili...",
                        "Ho trovato alcune informazioni utili per te. Ecco cosa posso suggerirti...",
                        "Basandomi sui tuoi progetti precedenti, ti consiglio di...",
                        "Posso aiutarti con questo. Fammi controllare le risorse disponibili..."
                    ];
                    const randomResponse = responses[Math.floor(Math.random() * responses.length)];
                    addMessage(randomResponse, 'ai');
                }, 1000);
            }
        }

        function sendSuggestion(text) {
            document.getElementById('chat-input').value = text;
            sendMessage(new Event('submit'));
        }

        function addMessage(text, sender) {
            const messagesContainer = document.getElementById('chat-messages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${sender}`;

            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.textContent = sender === 'user' ?
                '<?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?>' : 'AI';

            const content = document.createElement('div');
            content.className = 'message-content';
            content.textContent = text;

            messageDiv.appendChild(avatar);
            messageDiv.appendChild(content);

            messagesContainer.appendChild(messageDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Initialize company filter if present
        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const companySelector = document.getElementById('company-filter');
            if (companySelector) {
                companySelector.addEventListener('change', function() {
                    // Handle company filter change
                    console.log('Company changed:', this.value);
                });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>