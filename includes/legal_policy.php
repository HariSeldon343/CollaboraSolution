<?php
/**
 * Legal policy helpers (Privacy/Cookie) - tenant-aware
 *
 * Model: each tenant/company is Data Controller; Nexio is Data Processor.
 * We keep policy version server-side to avoid tampering and to allow re-ack when texts change.
 */

declare(strict_types=1);

/**
 * Compute a stable policy version string based on file mtimes (deploy-aware).
 * If files do not exist yet, falls back to a constant.
 */
function cnx_get_legal_policy_version(): string {
    $root = dirname(__DIR__);
    $privacy = $root . DIRECTORY_SEPARATOR . 'privacy.php';
    $cookie = $root . DIRECTORY_SEPARATOR . 'cookie-policy.php';

    $privacyV = is_file($privacy) ? (string)@filemtime($privacy) : 'na';
    $cookieV = is_file($cookie) ? (string)@filemtime($cookie) : 'na';

    return 'privacy@' . $privacyV . '|cookie@' . $cookieV;
}

/**
 * Best-effort tenant sector lookup (aziende.php uses tenants.settore_merceologico).
 */
function cnx_get_tenant_sector(Database $db, int $tenantId): ?string {
    if ($tenantId <= 0) return null;
    try {
        $row = $db->fetchOne("SELECT settore_merceologico FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
        $sector = isset($row['settore_merceologico']) ? (string)$row['settore_merceologico'] : '';
        return $sector !== '' ? $sector : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Best-effort tenant display name for legal pages.
 */
function cnx_get_tenant_display_name(Database $db, int $tenantId): ?string {
    if ($tenantId <= 0) return null;
    try {
        $row = $db->fetchOne("SELECT COALESCE(denominazione, name) AS display_name FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
        $name = isset($row['display_name']) ? (string)$row['display_name'] : '';
        return $name !== '' ? $name : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Schema-drift safe: check if a table exists in current DB.
 */
function cnx_table_exists(Database $db, string $tableName): bool {
    try {
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


