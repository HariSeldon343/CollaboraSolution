<?php
/**
 * Record acknowledgement for current policy version (privacy/cookie) - tenant-aware
 *
 * POST JSON:
 *  - ack_types: ['privacy','cookie'] (optional; default both)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/legal_policy.php';

initializeApiEnvironment();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();
verifyApiCsrfToken();

$db = Database::getInstance();
$userInfo = getApiUserInfo();
$userId = (int)($userInfo['id'] ?? $userInfo['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$role = (string)($userInfo['role'] ?? ($_SESSION['role'] ?? 'user'));

// Resolve tenant context (same rules as status.php)
$tenantId = (int)($_SESSION['tenant_id'] ?? ($userInfo['tenant_id'] ?? 0));
if (in_array($role, ['admin', 'super_admin'], true)) {
    $cfId = (int)($_SESSION['company_filter_id'] ?? 0);
    if ($cfId > 0) {
        $tenantId = $cfId;
    } else {
        $cfIds = $_SESSION['company_filter_ids'] ?? [];
        if (is_array($cfIds) && !empty($cfIds)) {
            $first = (int)($cfIds[0] ?? 0);
            if ($first > 0) $tenantId = $first;
        }
    }
}

if ($userId <= 0) {
    api_error('Utente non valido', 400);
}

// Schema drift safety
$storageAvailable = cnx_table_exists($db, 'privacy_acknowledgements');
if (!$storageAvailable) {
    api_success([
        'storage_available' => false,
        'recorded' => []
    ], 'OK (storage non disponibile)');
}

if ($tenantId <= 0) {
    // Fail-open: avoid blocking on missing tenant context
    api_success([
        'storage_available' => true,
        'recorded' => []
    ], 'OK (tenant non determinato)');
}

$raw = cnx_get_raw_request_body();
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload)) $payload = [];

$ackTypes = $payload['ack_types'] ?? null;
if (!is_array($ackTypes) || empty($ackTypes)) {
    $ackTypes = ['privacy', 'cookie'];
}
$ackTypes = array_values(array_unique(array_filter(array_map('strval', $ackTypes))));
$allowed = ['privacy', 'cookie'];
$ackTypes = array_values(array_filter($ackTypes, fn($t) => in_array($t, $allowed, true)));
if (empty($ackTypes)) {
    api_error('ack_types non valido', 400);
}

$policyVersion = cnx_get_legal_policy_version(); // server-side version
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

try {
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO privacy_acknowledgements
            (tenant_id, user_id, ack_type, policy_version, ack_at, ip_address, user_agent)
         VALUES
            (?, ?, ?, ?, NOW(), ?, ?)"
    );

    $recorded = [];
    foreach ($ackTypes as $t) {
        $stmt->execute([$tenantId, $userId, $t, $policyVersion, $ip ?: null, $ua ? mb_substr($ua, 0, 255) : null]);
        $recorded[] = $t;
    }

    api_success([
        'storage_available' => true,
        'policy_version' => $policyVersion,
        'tenant_id' => $tenantId,
        'recorded' => $recorded
    ], 'Presa visione registrata');
} catch (Throwable $e) {
    api_error('Errore registrazione presa visione', 500, ['detail' => $e->getMessage()]);
}


