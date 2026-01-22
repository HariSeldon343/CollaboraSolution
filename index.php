<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Auth helper
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();

/**
 * Normalize + validate an internal return URL.
 *
 * Returns a normalized internal URL in the form:
 *   /CollaboraNexio/<path>[?query][#fragment]
 * or null if invalid.
 */
function cnx_sanitize_internal_return_to($to): ?string
{
    $to = is_string($to) ? trim($to) : '';
    if ($to === '') {
        return null;
    }

    if (strpos($to, '://') !== false) {
        return null;
    }
    if (strpos($to, '\\') !== false) {
        return null;
    }
    if (strpos($to, '..') !== false) {
        return null;
    }
    if (str_starts_with($to, '//')) {
        return null;
    }

    $parsed = parse_url($to);
    if ($parsed === false) {
        return null;
    }
    if (isset($parsed['scheme']) || isset($parsed['host'])) {
        return null;
    }

    $path = (string)($parsed['path'] ?? '');
    if ($path === '') {
        return null;
    }

    if (str_starts_with($path, '/CollaboraNexio/')) {
        // ok
    } elseif (str_starts_with($path, 'CollaboraNexio/')) {
        $path = '/' . $path;
    } elseif (str_starts_with($path, '/')) {
        return null;
    } else {
        $path = '/CollaboraNexio/' . ltrim($path, '/');
    }

    if (!str_starts_with($path, '/CollaboraNexio/')) {
        return null;
    }

    $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
    $fragment = isset($parsed['fragment']) ? ('#' . $parsed['fragment']) : '';
    return $path . $query . $fragment;
}

$returnTo = cnx_sanitize_internal_return_to($_GET['return_to'] ?? ($_SESSION['post_login_redirect'] ?? ''));
$isAuthed = $auth->checkAuth();

// Server-side login fallback (no-JS / extension-safe)
if (!$isAuthed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedEmail = trim((string)($_POST['email'] ?? ''));
    $postedPassword = (string)($_POST['password'] ?? '');
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');

    // Recompute return_to from POST or existing stored redirect
    $postReturnTo = cnx_sanitize_internal_return_to($_POST['return_to'] ?? ($_SESSION['post_login_redirect'] ?? ''));

    // Validate CSRF
    if (!$auth->verifyCSRFToken($postedCsrf)) {
        $_SESSION['error_message'] = 'Token di sicurezza non valido. Riprova.';
        header('Location: index.php');
        exit;
    }

    // Basic validation
    if ($postedEmail === '' || $postedPassword === '') {
        $_SESSION['error_message'] = 'Email e password richiesti';
        header('Location: index.php');
        exit;
    }

    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $stmt = $pdo->prepare("
            SELECT u.*, t.name as tenant_name, t.code as tenant_code, t.status as tenant_status
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE u.email = ? AND u.is_active = 1 AND u.deleted_at IS NULL
        ");
        $stmt->execute([$postedEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($postedPassword, (string)($user['password_hash'] ?? ''))) {
            $_SESSION['error_message'] = 'Credenziali non valide';
            header('Location: index.php');
            exit;
        }

        // Password expiry check (consistent with api/auth.php)
        if (!empty($user['password_expires_at'])) {
            $expiryDate = strtotime((string)$user['password_expires_at']);
            if ($expiryDate !== false && $expiryDate < time()) {
                $_SESSION['error_message'] = 'La tua password è scaduta. Devi cambiarla prima di accedere.';
                header('Location: change_password.php?user_id=' . urlencode((string)$user['id']));
                exit;
            }
        }

        // Deterministic tenant/role login validation (no stored-procedure)
        $role = (string)($user['role'] ?? 'user');
        $tenantId = $user['tenant_id'] ?? null;
        $tenantStatus = (string)($user['tenant_status'] ?? '');

        if (!in_array($role, ['super_admin', 'admin'], true)) {
            if (empty($tenantId)) {
                $_SESSION['error_message'] = 'Il tuo account non è associato a nessuna azienda. Contatta l\'amministratore.';
                header('Location: index.php');
                exit;
            }
            if ($tenantStatus !== 'active') {
                $_SESSION['error_message'] = 'L\'azienda associata al tuo account non è attiva.';
                header('Location: index.php');
                exit;
            }
        }

        // Set session (same keys used across the app)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $role;
        $_SESSION['role'] = $role;
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['tenant_name'] = $user['tenant_name'] ?? 'No Company';
        $_SESSION['last_activity'] = time();

        // For admin/super_admin with multiple tenant access, get accessible tenants
        if (in_array($role, ['admin', 'super_admin'], true)) {
            $tenantStmt = $pdo->prepare("
                SELECT DISTINCT t.id, t.name
                FROM tenants t
                LEFT JOIN user_tenant_access uta ON t.id = uta.tenant_id
                WHERE (uta.user_id = ? OR ? = 'super_admin')
                AND t.status = 'active'
                ORDER BY t.name
            ");
            $tenantStmt->execute([$user['id'], $role]);
            $_SESSION['accessible_tenants'] = $tenantStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Update last_login best-effort
        try {
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);
        } catch (Throwable $e) {
            // ignore
        }

        unset($_SESSION['post_login_redirect']);
        $target = $postReturnTo ?: 'dashboard.php';
        header('Location: ' . $target);
        exit;
    } catch (Throwable $e) {
        $_SESSION['error_message'] = 'Errore server durante il login. Riprova.';
        header('Location: index.php');
        exit;
    }
}

if ($isAuthed) {
    if ($returnTo !== null) {
        unset($_SESSION['post_login_redirect']);
        header('Location: ' . $returnTo);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

// Not authenticated: persist a valid return destination for post-login redirect
if ($returnTo !== null) {
    $_SESSION['post_login_redirect'] = $returnTo;
}

// Generate CSRF token
$csrfToken = $auth->generateCSRFToken();

// Check if timeout parameter is present
$showTimeoutMessage = isset($_GET['timeout']) && $_GET['timeout'] == '1';

// Check if error message is present (from URL or session)
$errorMessage = null;
if (isset($_GET['error'])) {
    $errorMessage = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
} elseif (isset($_SESSION['error_message'])) {
    $errorMessage = htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8');
    unset($_SESSION['error_message']); // Clear the session message after displaying
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Login - Nexio</title>

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
                    <img src="assets/images/logo.png" alt="Nexio Logo" class="logo-img">
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

            <?php if ($errorMessage): ?>
            <!-- Error Message -->
            <div class="timeout-message" style="background: #fee2e2; border-color: #dc2626; color: #991b1b;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <span><strong>Accesso Negato.</strong> <?= $errorMessage ?></span>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="login-form" id="loginForm" method="POST" action="index.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
    <!-- Optional return_to (deep link) -->
    <input type="hidden" id="returnTo" value="<?php echo htmlspecialchars($returnTo ?? '', ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Login JavaScript - v2.0 with cache busting -->
    <script src="assets/js/login.js?v=<?php echo time(); ?>"></script>
</body>
</html>