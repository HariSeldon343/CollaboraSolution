<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Redirect to dashboard if already authenticated
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();

if ($auth->checkAuth()) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token
$csrfToken = $auth->generateCSRFToken();

// Check if timeout parameter is present
$showTimeoutMessage = isset($_GET['timeout']) && $_GET['timeout'] == '1';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Login - CollaboraNexio</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/login.css">
    <style>
        .timeout-message {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .timeout-message svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <!-- Logo and Branding -->
            <div class="login-header">
                <div class="login-logo">
                    <img src="assets/images/logo.png" alt="CollaboraNexio Logo" class="logo-img">
                    <h1 class="logo-text">NEXIO</h1>
                </div>
                <p class="login-subtitle">Collaboration Suite</p>
            </div>

            <?php if ($showTimeoutMessage): ?>
            <!-- Timeout Warning Message -->
            <div class="timeout-message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span><strong>Sessione scaduta per inattivita.</strong> Effettua nuovamente il login per continuare.</span>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="login-form" id="loginForm">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="Enter your email"
                        required
                        autofocus>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Enter your password"
                        required>
                </div>

                <div class="form-group">
                    <label class="form-checkbox-label">
                        <input type="checkbox" name="remember" class="form-checkbox">
                        <span>Remember me</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-full" id="loginBtn">
                    Sign In
                </button>

                <!-- Error Message Container -->
                <div class="error-message hidden" id="errorMessage"></div>
            </form>

            <!-- Footer Links -->
            <div class="login-footer">
                <a href="forgot_password.php" class="footer-link">Password dimenticata?</a>
                <span class="footer-separator">•</span>
                <a href="mailto:support@collaboranexio.com" class="footer-link">Contatta Supporto</a>
            </div>
        </div>

        <!-- Background decoration -->
        <div class="login-bg-decoration">
            <div class="decoration-circle decoration-circle-1"></div>
            <div class="decoration-circle decoration-circle-2"></div>
            <div class="decoration-circle decoration-circle-3"></div>
        </div>
    </div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <!-- Login JavaScript - v2.0 with cache busting -->
    <script src="assets/js/login.js?v=<?php echo time(); ?>"></script>
</body>
</html>