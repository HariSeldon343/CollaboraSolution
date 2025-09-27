<?php
/**
 * Public Share Access Page for CollaboraNexio
 *
 * Allows external users to access shared files without authentication
 * Features password protection, download limits, and complete security isolation
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

declare(strict_types=1);

// Security configuration
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Include minimal required files for database and sharing functionality
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/sharing.php';

// Initialize share manager with default tenant (1)
$tenantId = 1; // Public access uses default tenant
$shareManager = new ShareManager($tenantId, null);

// Process token from URL
$token = $_GET['t'] ?? '';

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'validate':
            $password = $_POST['password'] ?? null;
            $result = $shareManager->validateAccess($token, $password);

            if ($result['success']) {
                $_SESSION['share_access_' . $token] = [
                    'validated' => true,
                    'data' => $result['data'],
                    'timestamp' => time()
                ];
            }

            echo json_encode($result);
            exit;

        case 'download':
            if (!isset($_SESSION['share_access_' . $token]) || !$_SESSION['share_access_' . $token]['validated']) {
                echo json_encode(['success' => false, 'error' => 'Access not validated']);
                exit;
            }

            $shareData = $_SESSION['share_access_' . $token]['data'];

            if (!$shareData['permissions']['download']) {
                echo json_encode(['success' => false, 'error' => 'Download not permitted']);
                exit;
            }

            // Track download
            $shareManager->trackAccess($shareData['link_id'], 'download', ['success' => true]);

            // Update download counter
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE share_links SET current_downloads = current_downloads + 1 WHERE id = ?");
            $stmt->execute([$shareData['link_id']]);

            echo json_encode([
                'success' => true,
                'download_url' => 'share.php?t=' . $token . '&stream=1'
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
}

// Handle file streaming for downloads
if (isset($_GET['stream']) && isset($_SESSION['share_access_' . $token]) && $_SESSION['share_access_' . $token]['validated']) {
    $shareData = $_SESSION['share_access_' . $token]['data'];

    if (!$shareData['permissions']['download']) {
        header('HTTP/1.1 403 Forbidden');
        exit('Download not permitted');
    }

    // Get file path from database
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT file_path, file_name, mime_type, file_size FROM files WHERE id = ?");
    $stmt->execute([$shareData['file']['id']]);
    $file = $stmt->fetch();

    if (!$file) {
        header('HTTP/1.1 404 Not Found');
        exit('File not found');
    }

    $filePath = BASE_PATH . '/uploads/' . $file['file_path'];

    if (!file_exists($filePath)) {
        header('HTTP/1.1 404 Not Found');
        exit('File not found on server');
    }

    // Stream file to browser
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
    header('Content-Length: ' . $file['file_size']);
    header('Cache-Control: no-cache, must-revalidate');

    readfile($filePath);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Condivisione File</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #4F46E5;
            --primary-dark: #4338CA;
            --primary-light: #818CF8;
            --secondary-color: #F59E0B;
            --error-color: #EF4444;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --text-primary: #1F2937;
            --text-secondary: #6B7280;
            --bg-primary: #FFFFFF;
            --bg-secondary: #F9FAFB;
            --bg-tertiary: #F3F4F6;
            --border-color: #E5E7EB;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --border-radius: 0.5rem;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --text-primary: #F9FAFB;
                --text-secondary: #D1D5DB;
                --bg-primary: #1F2937;
                --bg-secondary: #111827;
                --bg-tertiary: #374151;
                --border-color: #4B5563;
            }
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: var(--text-primary);
        }

        .container {
            width: 100%;
            max-width: 600px;
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card {
            background: var(--bg-primary);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            transition: var(--transition);
        }

        .card-header {
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.25rem;
        }

        .brand-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .loading-spinner {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 3px solid rgba(79, 70, 229, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
            margin: 2rem auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-container {
            text-align: center;
            padding: 3rem 0;
        }

        .loading-text {
            color: var(--text-secondary);
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        .error-container {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
        }

        .error-icon {
            width: 48px;
            height: 48px;
            background: var(--error-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 24px;
        }

        .error-title {
            color: var(--error-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .error-message {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .password-container {
            display: none;
        }

        .password-container.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            padding-right: 3rem;
            font-size: 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: var(--transition);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 1rem;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .toggle-password:hover {
            color: var(--primary-color);
        }

        .btn {
            width: 100%;
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            margin-top: 0.5rem;
        }

        .btn-secondary:hover:not(:disabled) {
            background: var(--border-color);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .file-preview {
            display: none;
        }

        .file-preview.active {
            display: block;
            animation: fadeInUp 0.5s ease-out;
        }

        .file-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .file-icon-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .file-icon {
            width: 48px;
            height: 48px;
            background: var(--primary-light);
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            word-break: break-all;
        }

        .file-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .file-description {
            margin: 1rem 0;
            padding: 1rem;
            background: var(--bg-primary);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .shared-by {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--bg-tertiary);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .shared-by-avatar {
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .limits-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .limit-item {
            padding: 0.75rem;
            background: var(--bg-primary);
            border-radius: 0.375rem;
            text-align: center;
        }

        .limit-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .limit-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .actions-container {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease-out;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            max-width: 400px;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast.success {
            background: var(--success-color);
            color: white;
        }

        .toast.error {
            background: var(--error-color);
            color: white;
        }

        .toast.info {
            background: var(--primary-color);
            color: white;
        }

        .preview-image {
            width: 100%;
            border-radius: var(--border-radius);
            margin-top: 1rem;
            box-shadow: var(--shadow-md);
        }

        .folder-contents {
            margin-top: 1rem;
        }

        .folder-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--bg-primary);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .folder-item:hover {
            background: var(--bg-tertiary);
            transform: translateX(4px);
        }

        .folder-item-icon {
            width: 24px;
            height: 24px;
            color: var(--text-secondary);
        }

        .folder-item-name {
            flex: 1;
            font-size: 0.875rem;
            color: var(--text-primary);
        }

        .folder-item-size {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            flex-wrap: wrap;
        }

        .breadcrumb-item {
            cursor: pointer;
            transition: var(--transition);
        }

        .breadcrumb-item:hover {
            color: var(--primary-color);
        }

        .breadcrumb-separator {
            color: var(--border-color);
        }

        @media (max-width: 640px) {
            .container {
                padding: 0;
            }

            .card {
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
            }

            .card-body {
                padding: 1.5rem;
            }

            .toast {
                right: 1rem;
                left: 1rem;
                max-width: calc(100% - 2rem);
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .container {
                max-width: 100%;
            }

            .card {
                box-shadow: none;
                border: 1px solid var(--border-color);
            }

            .btn, .toast, .toggle-password {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <div class="brand">
                    <div class="brand-icon">C</div>
                    <div class="brand-text">CollaboraNexio</div>
                </div>
                <div class="subtitle">Accesso Sicuro ai File Condivisi</div>
            </div>
            <div class="card-body">
                <div id="loadingContainer" class="loading-container">
                    <div class="loading-spinner"></div>
                    <div class="loading-text">Caricamento in corso...</div>
                </div>

                <div id="errorContainer" class="error-container" style="display: none;">
                    <div class="error-icon">⚠</div>
                    <div class="error-title">Accesso Negato</div>
                    <div class="error-message" id="errorMessage">Link non valido o scaduto</div>
                </div>

                <div id="passwordContainer" class="password-container">
                    <div class="form-group">
                        <label class="form-label">Password richiesta per accedere al file</label>
                        <div class="input-group">
                            <input type="password"
                                   id="passwordInput"
                                   class="form-input"
                                   placeholder="Inserisci la password"
                                   autocomplete="off">
                            <button type="button" id="togglePassword" class="toggle-password">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path id="eyeIcon" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button id="submitPassword" class="btn btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                        Accedi al File
                    </button>
                </div>

                <div id="filePreview" class="file-preview">
                    <div class="file-card">
                        <div class="file-icon-container">
                            <div class="file-icon" id="fileIcon">📄</div>
                            <div class="file-info">
                                <div class="file-name" id="fileName">-</div>
                                <div class="file-meta">
                                    <span id="fileSize">-</span> •
                                    <span id="fileType">-</span>
                                </div>
                            </div>
                        </div>

                        <div id="sharedBy" class="shared-by">
                            <div class="shared-by-avatar" id="sharedAvatar">-</div>
                            <div>
                                <div style="font-weight: 500; color: var(--text-primary);">Condiviso da <span id="sharedByName">-</span></div>
                                <div style="font-size: 0.75rem;" id="sharedDate">-</div>
                            </div>
                        </div>

                        <div id="fileDescription" class="file-description" style="display: none;"></div>

                        <div class="limits-info">
                            <div class="limit-item" id="downloadsLimit" style="display: none;">
                                <div class="limit-label">Download rimanenti</div>
                                <div class="limit-value" id="downloadsRemaining">-</div>
                            </div>
                            <div class="limit-item" id="expirationLimit" style="display: none;">
                                <div class="limit-label">Valido fino al</div>
                                <div class="limit-value" id="expirationDate">-</div>
                            </div>
                        </div>

                        <div id="previewContent"></div>
                    </div>

                    <div class="actions-container">
                        <button id="downloadBtn" class="btn btn-primary" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Scarica File
                        </button>
                        <button id="viewBtn" class="btn btn-secondary" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            Visualizza nel Browser
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Public Share Access Module
         * Handles secure file sharing without authentication
         */
        class PublicShare {
            constructor() {
                this.config = {
                    apiBase: 'share.php',
                    pollInterval: 2000,
                    maxRetries: 3,
                    retryDelay: 1000
                };

                this.state = {
                    token: null,
                    shareData: null,
                    passwordVisible: false,
                    attempts: 0
                };

                this.init();
            }

            init() {
                this.extractToken();
                this.bindEvents();
                this.validateAccess();
            }

            bindEvents() {
                // Password form events
                const passwordContainer = document.getElementById('passwordContainer');
                if (passwordContainer) {
                    passwordContainer.addEventListener('click', (e) => {
                        if (e.target.closest('#togglePassword')) {
                            this.togglePasswordVisibility();
                        }

                        if (e.target.closest('#submitPassword')) {
                            this.submitPassword();
                        }
                    });

                    document.getElementById('passwordInput').addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') {
                            this.submitPassword();
                        }
                    });
                }

                // File actions
                const filePreview = document.getElementById('filePreview');
                if (filePreview) {
                    filePreview.addEventListener('click', (e) => {
                        if (e.target.closest('#downloadBtn')) {
                            this.downloadFile();
                        }

                        if (e.target.closest('#viewBtn')) {
                            this.viewFile();
                        }
                    });
                }
            }

            extractToken() {
                const urlParams = new URLSearchParams(window.location.search);
                this.state.token = urlParams.get('t') || '';

                if (!this.state.token) {
                    this.showError('Link non valido', 'Token mancante nell\'URL');
                }
            }

            async validateAccess(password = null) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'validate');
                    if (password) {
                        formData.append('password', password);
                    }

                    const response = await fetch(`${this.config.apiBase}?t=${this.state.token}`, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const result = await response.json();

                    if (result.success) {
                        this.state.shareData = result.data;
                        this.hideLoading();
                        this.showFilePreview();
                    } else {
                        if (result.code === 'PASSWORD_REQUIRED' && !password) {
                            this.hideLoading();
                            this.showPasswordForm();
                        } else {
                            this.showError(result.error || 'Accesso negato', result.code);
                        }
                    }
                } catch (error) {
                    console.error('Validation error:', error);
                    this.showError('Errore di connessione', 'Impossibile validare il link');
                }
            }

            showPasswordForm() {
                document.getElementById('passwordContainer').classList.add('active');
                document.getElementById('passwordInput').focus();
            }

            togglePasswordVisibility() {
                const input = document.getElementById('passwordInput');
                const icon = document.getElementById('eyeIcon');

                this.state.passwordVisible = !this.state.passwordVisible;

                if (this.state.passwordVisible) {
                    input.type = 'text';
                    icon.setAttribute('d', 'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22');
                } else {
                    input.type = 'password';
                    icon.setAttribute('d', 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z');
                }
            }

            async submitPassword() {
                const password = document.getElementById('passwordInput').value.trim();

                if (!password) {
                    this.showToast('Inserisci una password', 'error');
                    return;
                }

                const btn = document.getElementById('submitPassword');
                btn.disabled = true;
                btn.innerHTML = '<span class="loading-spinner" style="width: 16px; height: 16px; border-width: 2px;"></span> Verifica...';

                await this.validateAccess(password);

                btn.disabled = false;
                btn.innerHTML = `
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    Accedi al File
                `;

                if (!this.state.shareData) {
                    this.state.attempts++;
                    document.getElementById('passwordInput').value = '';

                    if (this.state.attempts >= 3) {
                        this.showToast(`Troppi tentativi falliti (${this.state.attempts})`, 'error');
                    }
                }
            }

            showFilePreview() {
                const data = this.state.shareData;

                // Hide password form
                document.getElementById('passwordContainer').classList.remove('active');

                // Populate file information
                document.getElementById('fileName').textContent = data.file.name;
                document.getElementById('fileSize').textContent = this.formatFileSize(data.file.size);
                document.getElementById('fileType').textContent = this.getFileTypeLabel(data.file.mime_type);

                // Set file icon
                document.getElementById('fileIcon').textContent = this.getFileIcon(data.file.mime_type);

                // Shared by info
                if (data.metadata.shared_by) {
                    const names = data.metadata.shared_by.split(' ');
                    const initials = names.map(n => n[0]).join('').toUpperCase();
                    document.getElementById('sharedAvatar').textContent = initials.substring(0, 2);
                    document.getElementById('sharedByName').textContent = data.metadata.shared_by;
                    document.getElementById('sharedDate').textContent = this.formatDate(data.metadata.shared_at);
                }

                // Description/message
                if (data.metadata.description || data.metadata.message) {
                    const desc = document.getElementById('fileDescription');
                    desc.textContent = data.metadata.description || data.metadata.message;
                    desc.style.display = 'block';
                }

                // Limits
                if (data.limits.downloads_remaining !== null) {
                    document.getElementById('downloadsLimit').style.display = 'block';
                    document.getElementById('downloadsRemaining').textContent = data.limits.downloads_remaining;
                }

                if (data.limits.expires_at) {
                    document.getElementById('expirationLimit').style.display = 'block';
                    document.getElementById('expirationDate').textContent = this.formatDate(data.limits.expires_at);
                }

                // Show action buttons based on permissions
                if (data.permissions.download) {
                    document.getElementById('downloadBtn').style.display = 'flex';
                }

                if (data.permissions.view && this.isViewableType(data.file.mime_type)) {
                    document.getElementById('viewBtn').style.display = 'flex';
                }

                // Show preview for images
                if (data.permissions.view && data.file.mime_type.startsWith('image/')) {
                    this.showImagePreview(data.file);
                }

                // Show file preview container
                document.getElementById('filePreview').classList.add('active');
            }

            showImagePreview(file) {
                const previewContent = document.getElementById('previewContent');
                previewContent.innerHTML = `<img src="share.php?t=${this.state.token}&stream=1" class="preview-image" alt="${file.name}">`;
            }

            async downloadFile() {
                const btn = document.getElementById('downloadBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="loading-spinner" style="width: 16px; height: 16px; border-width: 2px;"></span> Download...';

                try {
                    const formData = new FormData();
                    formData.append('action', 'download');

                    const response = await fetch(`${this.config.apiBase}?t=${this.state.token}`, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const result = await response.json();

                    if (result.success) {
                        // Start download
                        window.location.href = result.download_url;

                        // Update remaining downloads
                        if (this.state.shareData.limits.downloads_remaining !== null) {
                            this.state.shareData.limits.downloads_remaining--;
                            document.getElementById('downloadsRemaining').textContent =
                                this.state.shareData.limits.downloads_remaining;

                            if (this.state.shareData.limits.downloads_remaining <= 0) {
                                btn.disabled = true;
                                btn.textContent = 'Limite download raggiunto';
                            }
                        }

                        this.showToast('Download avviato', 'success');
                    } else {
                        this.showToast(result.error || 'Errore nel download', 'error');
                    }
                } catch (error) {
                    console.error('Download error:', error);
                    this.showToast('Errore nel download', 'error');
                } finally {
                    if (!btn.disabled) {
                        btn.innerHTML = `
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Scarica File
                        `;
                    }
                }
            }

            viewFile() {
                window.open(`share.php?t=${this.state.token}&stream=1`, '_blank');
            }

            formatFileSize(bytes) {
                if (!bytes) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            formatDate(dateString) {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('it-IT', {
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            getFileIcon(mimeType) {
                if (!mimeType) return '📄';

                const iconMap = {
                    'application/pdf': '📕',
                    'image/': '🖼️',
                    'video/': '🎥',
                    'audio/': '🎵',
                    'text/': '📝',
                    'application/zip': '📦',
                    'application/x-': '📦',
                    'application/msword': '📘',
                    'application/vnd.ms-excel': '📊',
                    'application/vnd.ms-powerpoint': '📙',
                    'application/vnd.openxmlformats': '📘'
                };

                for (const [key, icon] of Object.entries(iconMap)) {
                    if (mimeType.includes(key)) return icon;
                }

                return '📄';
            }

            getFileTypeLabel(mimeType) {
                if (!mimeType) return 'File';

                const typeMap = {
                    'application/pdf': 'PDF',
                    'image/jpeg': 'JPEG',
                    'image/png': 'PNG',
                    'image/gif': 'GIF',
                    'video/mp4': 'Video MP4',
                    'audio/mpeg': 'Audio MP3',
                    'text/plain': 'Testo',
                    'application/zip': 'Archivio ZIP',
                    'application/msword': 'Word',
                    'application/vnd.ms-excel': 'Excel',
                    'application/vnd.ms-powerpoint': 'PowerPoint'
                };

                return typeMap[mimeType] || mimeType.split('/')[1]?.toUpperCase() || 'File';
            }

            isViewableType(mimeType) {
                const viewableTypes = [
                    'application/pdf',
                    'image/',
                    'text/',
                    'video/mp4',
                    'audio/mpeg'
                ];

                return viewableTypes.some(type => mimeType.includes(type));
            }

            showError(title, message) {
                this.hideLoading();
                document.getElementById('errorContainer').style.display = 'block';
                document.getElementById('errorMessage').textContent = message || title;
            }

            hideLoading() {
                document.getElementById('loadingContainer').style.display = 'none';
            }

            showToast(message, type = 'info') {
                const existingToast = document.querySelector('.toast');
                if (existingToast) {
                    existingToast.remove();
                }

                const toast = document.createElement('div');
                toast.className = `toast ${type}`;

                const icons = {
                    success: '✓',
                    error: '✕',
                    info: 'ⓘ'
                };

                toast.innerHTML = `
                    <span style="font-size: 1.25rem;">${icons[type]}</span>
                    <span>${message}</span>
                `;

                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.style.animation = 'slideOut 0.3s ease-in';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            window.publicShare = new PublicShare();
        });
    </script>
</body>
</html>