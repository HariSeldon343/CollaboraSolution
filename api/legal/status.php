<?php
/**
 * Legal policy status (Privacy/Cookie) - tenant-aware
 * Returns whether the current user needs to acknowledge the current policy version.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/legal_policy.php';

initializeApiEnvironment();

// Strong no-cache headers (Cloudflare / tunnel / custom browsers safety)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('Surrogate-Control: no-store');
header('CDN-Cache-Control: no-store');

verifyApiAuthentication();
// CSRF: do not require for idempotent GET status checks (prevents noisy 403 on pages without #csrfToken).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    verifyApiCsrfToken();
}

$db = Database::getInstance();
$userInfo = getApiUserInfo();
$userId = (int)($userInfo['id'] ?? $userInfo['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$role = (string)($userInfo['role'] ?? ($_SESSION['role'] ?? 'user'));

// Determine tenant context:
// - Normal users/managers: session tenant_id
// - Admin/super_admin: if Company Filter is set, prefer the selected tenant id
$tenantId = (int)($_SESSION['tenant_id'] ?? ($userInfo['tenant_id'] ?? 0));

// Requirement: super_admin should always see S.CO as tenant for the legal banner
if ($role === 'super_admin') {
    $tenantId = 28;
} elseif ($role === 'admin') {
    $cfId = (int)($_SESSION['company_filter_id'] ?? 0);
    if ($cfId > 0) {
        $tenantId = $cfId;
    } else {
        // Multi-select support: pick the first selected tenant for notice purposes
        $cfIds = $_SESSION['company_filter_ids'] ?? [];
        if (is_array($cfIds) && !empty($cfIds)) {
            $first = (int)($cfIds[0] ?? 0);
            if ($first > 0) $tenantId = $first;
        }
    }
}

$policyVersion = cnx_get_legal_policy_version();
$sector = cnx_get_tenant_sector($db, $tenantId);
$tenantName = cnx_get_tenant_display_name($db, $tenantId);

// One-time per login session: do not show the banner on every page load
$alreadyShownThisLogin = !empty($_SESSION['cnx_legal_notice_shown']);

// Schema drift safety: if storage table missing, don't block UX (return storage_available=false).
$storageAvailable = cnx_table_exists($db, 'privacy_acknowledgements');
if (!$storageAvailable) {
    api_success([
        'policy_version' => $policyVersion,
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'tenant_sector' => $sector,
        'storage_available' => false,
        'show_notice' => false,
        'needs_ack_privacy' => false,
        'needs_ack_cookie' => false,
        'acknowledged' => [
            'privacy' => false,
            'cookie' => false
        ]
    ]);
}

// If we cannot resolve user or tenant, avoid hard-blocking.
if ($userId <= 0 || $tenantId <= 0) {
    api_success([
        'policy_version' => $policyVersion,
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'tenant_sector' => $sector,
        'storage_available' => true,
        'show_notice' => false,
        'needs_ack_privacy' => false,
        'needs_ack_cookie' => false,
        'acknowledged' => [
            'privacy' => false,
            'cookie' => false
        ]
    ]);
}

try {
    $rows = $db->fetchAll(
        "SELECT ack_type
         FROM privacy_acknowledgements
         WHERE tenant_id = ?
           AND user_id = ?
           AND policy_version = ?
         LIMIT 10",
        [$tenantId, $userId, $policyVersion]
    );

    $acks = ['privacy' => false, 'cookie' => false];
    foreach ($rows as $r) {
        $t = (string)($r['ack_type'] ?? '');
        if ($t === 'privacy') $acks['privacy'] = true;
        if ($t === 'cookie') $acks['cookie'] = true;
    }

    api_success([
        'policy_version' => $policyVersion,
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'tenant_sector' => $sector,
        'storage_available' => true,
        // Show the banner only once per login session (even if user clicks "Più tardi").
        'show_notice' => (!$alreadyShownThisLogin) && (!$acks['privacy'] || !$acks['cookie']),
        'needs_ack_privacy' => !$acks['privacy'],
        'needs_ack_cookie' => !$acks['cookie'],
        'acknowledged' => $acks
    ]);

    // Mark shown in this login session if we are asking UI to show it now
    if (!$alreadyShownThisLogin && (!$acks['privacy'] || !$acks['cookie'])) {
        $_SESSION['cnx_legal_notice_shown'] = 1;
    }
} catch (Throwable $e) {
    // Fail-open: do not block UX if something goes wrong
    api_success([
        'policy_version' => $policyVersion,
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'tenant_sector' => $sector,
        'storage_available' => true,
        'show_notice' => false,
        'needs_ack_privacy' => false,
        'needs_ack_cookie' => false,
        'acknowledged' => [
            'privacy' => false,
            'cookie' => false
        ],
        'warning' => 'status_check_failed'
    ]);
}


