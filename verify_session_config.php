<?php
/**
 * Verify Session Configuration
 * Shows current session settings and timeout configuration
 */

require_once __DIR__ . '/includes/session_init.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Session Configuration</title>";
    echo "<style>
        body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 900px; margin: 30px auto; padding: 20px; background: #f5f5f5; }
        .config-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { border-bottom: 3px solid #667eea; padding-bottom: 10px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style></head><body>";
    echo "<h1 style='text-align: center; color: #667eea;'>🔒 Session Configuration Verification</h1>";
}

echo "<div class='config-section'>\n";
echo "<h2>Session Settings</h2>\n";
echo "<table>\n";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>\n";

// Session cookie lifetime
$cookieLifetime = ini_get('session.cookie_lifetime');
$lifetimeOk = $cookieLifetime == '0';
echo "<tr>";
echo "<td>Cookie Lifetime</td>";
echo "<td>{$cookieLifetime} (0 = browser close)</td>";
echo "<td class='" . ($lifetimeOk ? 'success' : 'error') . "'>" . ($lifetimeOk ? '✓ Correct' : '✗ Should be 0') . "</td>";
echo "</tr>\n";

// Session GC maxlifetime (10 minutes = 600 seconds)
$gcMaxlifetime = ini_get('session.gc_maxlifetime');
$gcOk = $gcMaxlifetime == '600';
echo "<tr>";
echo "<td>GC Max Lifetime (Timeout)</td>";
echo "<td>{$gcMaxlifetime} seconds (" . round($gcMaxlifetime / 60, 2) . " minutes)</td>";
echo "<td class='" . ($gcOk ? 'success' : 'error') . "'>" . ($gcOk ? '✓ Correct (10 min)' : '✗ Should be 600') . "</td>";
echo "</tr>\n";

// HTTPOnly
$httpOnly = ini_get('session.cookie_httponly');
$httpOnlyOk = $httpOnly == '1';
echo "<tr>";
echo "<td>HTTP Only</td>";
echo "<td>" . ($httpOnly ? 'Enabled' : 'Disabled') . "</td>";
echo "<td class='" . ($httpOnlyOk ? 'success' : 'error') . "'>" . ($httpOnlyOk ? '✓ Secure' : '✗ Should be enabled') . "</td>";
echo "</tr>\n";

// Use only cookies
$useOnlyCookies = ini_get('session.use_only_cookies');
$useOnlyCookiesOk = $useOnlyCookies == '1';
echo "<tr>";
echo "<td>Use Only Cookies</td>";
echo "<td>" . ($useOnlyCookies ? 'Enabled' : 'Disabled') . "</td>";
echo "<td class='" . ($useOnlyCookiesOk ? 'success' : 'error') . "'>" . ($useOnlyCookiesOk ? '✓ Secure' : '✗ Should be enabled') . "</td>";
echo "</tr>\n";

// Cookie secure (depends on environment)
$cookieSecure = ini_get('session.cookie_secure');
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProduction = strpos($currentHost, 'nexiosolution.it') !== false;
$cookieSecureExpected = $isProduction ? '1' : '0';
$cookieSecureOk = $cookieSecure == $cookieSecureExpected;
echo "<tr>";
echo "<td>Cookie Secure (HTTPS)</td>";
echo "<td>" . ($cookieSecure ? 'Enabled' : 'Disabled') . " (Environment: " . ($isProduction ? 'Production' : 'Development') . ")</td>";
echo "<td class='" . ($cookieSecureOk ? 'success' : 'info') . "'>" . ($cookieSecureOk ? '✓ Correct for environment' : 'ℹ Set according to environment') . "</td>";
echo "</tr>\n";

// Cookie SameSite
$sameSite = ini_get('session.cookie_samesite');
$sameSiteOk = strtolower($sameSite) === 'lax';
echo "<tr>";
echo "<td>Cookie SameSite</td>";
echo "<td>{$sameSite}</td>";
echo "<td class='" . ($sameSiteOk ? 'success' : 'info') . "'>" . ($sameSiteOk ? '✓ Lax (recommended)' : 'ℹ ' . $sameSite) . "</td>";
echo "</tr>\n";

// Session name
$sessionName = session_name();
$sessionNameOk = $sessionName === 'COLLAB_SID';
echo "<tr>";
echo "<td>Session Name</td>";
echo "<td>{$sessionName}</td>";
echo "<td class='" . ($sessionNameOk ? 'success' : 'info') . "'>" . ($sessionNameOk ? '✓ Correct' : 'ℹ Custom name') . "</td>";
echo "</tr>\n";

// Cookie domain
$cookieDomain = ini_get('session.cookie_domain');
echo "<tr>";
echo "<td>Cookie Domain</td>";
echo "<td>" . ($cookieDomain ?: '(empty - current domain)') . "</td>";
echo "<td class='info'>ℹ " . ($isProduction ? 'Production: .nexiosolution.it' : 'Development: empty') . "</td>";
echo "</tr>\n";

// Cookie path
$cookiePath = ini_get('session.cookie_path');
$cookiePathOk = $cookiePath === '/CollaboraNexio/';
echo "<tr>";
echo "<td>Cookie Path</td>";
echo "<td>{$cookiePath}</td>";
echo "<td class='" . ($cookiePathOk ? 'success' : 'info') . "'>" . ($cookiePathOk ? '✓ Correct' : 'ℹ ' . $cookiePath) . "</td>";
echo "</tr>\n";

echo "</table>\n";
echo "</div>\n";

// Current session info
echo "<div class='config-section'>\n";
echo "<h2>Current Session Info</h2>\n";
echo "<table>\n";
echo "<tr><th>Property</th><th>Value</th></tr>\n";

echo "<tr><td>Session ID</td><td>" . session_id() . "</td></tr>\n";
echo "<tr><td>Session Status</td><td>" . (session_status() === PHP_SESSION_ACTIVE ? '<span class="success">Active</span>' : 'Not Active') . "</td></tr>\n";

if (isset($_SESSION['last_activity'])) {
    $lastActivity = $_SESSION['last_activity'];
    $elapsed = time() - $lastActivity;
    $remaining = 600 - $elapsed; // 10 minutes timeout

    echo "<tr><td>Last Activity</td><td>" . date('Y-m-d H:i:s', $lastActivity) . "</td></tr>\n";
    echo "<tr><td>Elapsed Time</td><td>{$elapsed} seconds (" . round($elapsed / 60, 2) . " minutes)</td></tr>\n";
    echo "<tr><td>Time Until Timeout</td><td class='info'>{$remaining} seconds (" . round($remaining / 60, 2) . " minutes)</td></tr>\n";

    if ($remaining < 60) {
        echo "<tr><td colspan='2' style='background:#fff3cd;'><strong>⚠️ Session will timeout soon!</strong></td></tr>\n";
    }
} else {
    echo "<tr><td>Last Activity</td><td><span class='info'>Not set (first request)</span></td></tr>\n";
}

if (isset($_SESSION['user_id'])) {
    echo "<tr><td>User ID</td><td>{$_SESSION['user_id']}</td></tr>\n";
    echo "<tr><td>User Email</td><td>" . ($_SESSION['user_email'] ?? 'N/A') . "</td></tr>\n";
    echo "<tr><td>User Role</td><td>" . ($_SESSION['user_role'] ?? 'N/A') . "</td></tr>\n";
    echo "<tr><td>Tenant ID</td><td>" . ($_SESSION['tenant_id'] ?? 'NULL (super_admin)') . "</td></tr>\n";
} else {
    echo "<tr><td colspan='2' style='background:#f8d7da;'><strong>❌ Not Authenticated</strong></td></tr>\n";
}

echo "</table>\n";
echo "</div>\n";

// Timeout behavior
echo "<div class='config-section'>\n";
echo "<h2>Timeout Behavior</h2>\n";
echo "<h3>✓ Expected Behavior:</h3>\n";
echo "<ul>\n";
echo "<li><strong>Inactivity Timeout:</strong> 10 minutes (600 seconds)</li>\n";
echo "<li><strong>Browser Close:</strong> Session expires immediately when browser is closed</li>\n";
echo "<li><strong>Auto-redirect:</strong> After timeout, redirect to /CollaboraNexio/index.php?timeout=1</li>\n";
echo "<li><strong>Activity Update:</strong> Every page request resets the timeout counter</li>\n";
echo "</ul>\n";

echo "<h3>🧪 Testing Instructions:</h3>\n";
echo "<ol>\n";
echo "<li>Login to the system via <a href='/CollaboraNexio/index.php'>index.php</a></li>\n";
echo "<li>Navigate to any page (e.g., dashboard.php)</li>\n";
echo "<li>Wait 10 minutes without clicking anything</li>\n";
echo "<li>Try to navigate or refresh - you should be redirected to login with timeout message</li>\n";
echo "<li>Alternatively: Close the browser completely and reopen - session should be gone</li>\n";
echo "</ol>\n";

echo "<h3>📊 Real-time Monitoring:</h3>\n";
echo "<p>Refresh this page to see updated session timers. The 'Time Until Timeout' will decrease each time.</p>\n";
echo "<p><a href='?refresh=1' class='info' style='display:inline-block; padding:10px 20px; background:#667eea; color:white; text-decoration:none; border-radius:5px;'>🔄 Refresh Now</a></p>\n";
echo "</div>\n";

// Summary
$allCorrect = $lifetimeOk && $gcOk && $httpOnlyOk && $useOnlyCookiesOk && $cookieSecureOk && $sessionNameOk && $cookiePathOk;

echo "<div class='config-section'>\n";
echo "<h2>Summary</h2>\n";

if ($allCorrect) {
    echo "<p class='success' style='font-size:20px;'>✅ All session settings are correctly configured!</p>\n";
    echo "<p>Session timeout (10 minutes) and browser-close expiration are active.</p>\n";
} else {
    echo "<p class='error' style='font-size:20px;'>⚠️ Some settings may need attention</p>\n";
    echo "<p>Review the table above for details.</p>\n";
}

echo "</div>\n";

if (!$isCli) {
    echo "</body></html>";
}
?>
