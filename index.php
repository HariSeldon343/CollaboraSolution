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
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <!-- Logo and Branding -->
            <div class="login-header">
                <div class="login-logo">
                    <span class="logo-icon">◆</span>
                    <h1 class="logo-text">NEXIO</h1>
                </div>
                <p class="login-subtitle">Collaboration Suite</p>
            </div>

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

                <!-- Demo Credentials -->
                <div class="demo-info">
                    <p class="demo-title">Demo Credentials</p>
                    <div class="demo-credentials">
                        <div class="credential-item">
                            <span class="credential-label">Admin:</span>
                            <span class="credential-value">admin@demo.local / Admin123!</span>
                        </div>
                        <div class="credential-item">
                            <span class="credential-label">Manager:</span>
                            <span class="credential-value">manager@demo.local / Admin123!</span>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Footer Links -->
            <div class="login-footer">
                <a href="#" class="footer-link">Forgot Password?</a>
                <span class="footer-separator">•</span>
                <a href="#" class="footer-link">Contact Support</a>
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