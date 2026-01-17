<?php
/**
 * Tenant 28 (S.CO Srls) Access Gate
 *
 * Purpose:
 * - Restrict a set of internal/consulting planning features to the vendor tenant (ID 28).
 * - Super Admin: always allowed.
 * - Admin: allowed only if they have access to tenant 28 (primary tenant_id=28 OR user_tenant_access includes 28).
 * - Others: denied.
 *
 * This file intentionally does NOT depend on api_auth.php helpers (api_error, etc.).
 * Use `cnxCheckTenant28Access()` and handle errors in the caller context (page redirect vs JSON API error).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CNX_VENDOR_TENANT_ID = 28;

/**
 * Check whether a user has access to tenant 28 consulting tools.
 *
 * @param array $userInfo Current user info (from Auth::getCurrentUser() or getApiUserInfo()).
 * @return array{ok:bool, reason:string}
 */
function cnxCheckTenant28Access(array $userInfo): array {
    $role = (string)($userInfo['role'] ?? 'user');
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);

    if ($role === 'super_admin') {
        return ['ok' => true, 'reason' => 'Accesso consentito: super_admin'];
    }

    if ($role !== 'admin') {
        return ['ok' => false, 'reason' => 'Accesso negato: ruolo non autorizzato'];
    }

    if ($primaryTenantId === CNX_VENDOR_TENANT_ID) {
        return ['ok' => true, 'reason' => 'Accesso consentito: tenant primario 28'];
    }

    if ($userId <= 0) {
        return ['ok' => false, 'reason' => 'Accesso negato: utente non valido'];
    }

    // Check user_tenant_access (schema-drift safe for deleted_at)
    $db = Database::getInstance();
    $utaHasDeletedAt = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_tenant_access'
           AND COLUMN_NAME = 'deleted_at'
         LIMIT 1"
    );
    $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";

    $hasAccess = $db->fetchOne(
        "SELECT 1
         FROM user_tenant_access
         WHERE user_id = ?
           AND tenant_id = ?" . $utaWhere . "
         LIMIT 1",
        [$userId, CNX_VENDOR_TENANT_ID]
    );

    if ($hasAccess) {
        return ['ok' => true, 'reason' => 'Accesso consentito: tenant 28 associato'];
    }

    return ['ok' => false, 'reason' => 'Accesso negato: tenant 28 non associato'];
}

/**
 * Enforce tenant 28 access for a PAGE (redirect on deny).
 *
 * @param array|null $userInfo Optional user object (defaults to session/global $currentUser)
 */
function requireTenant28AccessPage(?array $userInfo = null): void {
    global $currentUser;
    $u = $userInfo ?? ($_SESSION['user'] ?? $currentUser ?? null);
    if (!$u || !is_array($u)) {
        header('Location: index.php');
        exit;
    }

    $check = cnxCheckTenant28Access($u);
    if ($check['ok']) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_error'] = $check['reason'];
    header('Location: dashboard.php');
    exit;
}


