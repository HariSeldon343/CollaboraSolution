<?php
/**
 * Central redirector for email deep-links.
 *
 * Goals:
 * - Force re-authentication when arriving from email (avoid wrong-user session)
 * - Preserve the intended destination ("deep link") after login
 * - Do NOT break public links (set_password, forgot_password, public/share)
 */
require_once __DIR__ . '/includes/session_init.php';

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

    // Basic hard blocks (avoid open redirects / path traversal)
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

    // Normalize to /CollaboraNexio/...
    if (str_starts_with($path, '/CollaboraNexio/')) {
        // ok
    } elseif (str_starts_with($path, 'CollaboraNexio/')) {
        $path = '/' . $path;
    } elseif (str_starts_with($path, '/')) {
        // any other absolute path is not allowed
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

/**
 * Public allowlist: these pages must NOT force re-auth.
 */
function cnx_is_public_email_target_path(string $path): bool
{
    $path = strtolower($path);
    return in_array($path, [
        '/collaboranexio/set_password.php',
        '/collaboranexio/forgot_password.php',
        '/collaboranexio/public/share.php',
    ], true);
}

$to = cnx_sanitize_internal_return_to($_GET['to'] ?? '');
if ($to === null) {
    header('Location: /CollaboraNexio/index.php');
    exit;
}

$toPath = (string)(parse_url($to, PHP_URL_PATH) ?? '');
if ($toPath !== '' && cnx_is_public_email_target_path($toPath)) {
    // Do not force reauth for public pages
    header('Location: ' . $to);
    exit;
}

// Protected destination: save for post-login redirect and force a "soft logout".
$_SESSION['post_login_redirect'] = $to;

foreach ([
    // Auth / user
    'user_id',
    'user_name',
    'user_email',
    'role',
    'user_role',
    // Tenant / scoping
    'tenant_id',
    'tenant_name',
    // CSRF/session state
    'csrf_token',
    'last_activity',
    // Misc auth error state
    'auth_error',
] as $k) {
    if (isset($_SESSION[$k])) {
        unset($_SESSION[$k]);
    }
}

// Avoid session fixation across user switches.
@session_regenerate_id(true);

header('Location: /CollaboraNexio/index.php');
exit;

