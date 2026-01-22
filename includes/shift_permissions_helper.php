<?php
/**
 * Shift permissions helper (per-tenant)
 *
 * Stores JSON in tenants.shift_permissions:
 * - can_manage_shifts_roles: ["admin","manager","user"]
 * - can_approve_shift_requests_roles: ["admin","manager","user"]
 *
 * super_admin is always allowed (implicit, not stored).
 */

declare(strict_types=1);

/**
 * Default permissions (if not configured).
 */
function cnx_default_shift_permissions(): array {
    return [
        'can_manage_shifts_roles' => ['admin', 'manager'],
        'can_approve_shift_requests_roles' => ['admin', 'manager'],
    ];
}

/**
 * Normalize an array of system roles (admin/manager/user).
 */
function cnx_normalize_shift_roles(array $roles): array {
    $allowed = ['admin', 'manager', 'user'];
    $out = [];
    foreach ($roles as $r) {
        $r = trim((string)$r);
        if ($r === '') continue;
        if (!in_array($r, $allowed, true)) continue;
        $out[] = $r;
    }
    return array_values(array_unique($out));
}

/**
 * Parse stored JSON and return a normalized permissions array.
 */
function cnx_parse_shift_permissions(?string $raw): array {
    $defaults = cnx_default_shift_permissions();
    if (!is_string($raw) || $raw === '') return $defaults;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $defaults;

    $manage = $decoded['can_manage_shifts_roles'] ?? $defaults['can_manage_shifts_roles'];
    $approve = $decoded['can_approve_shift_requests_roles'] ?? $defaults['can_approve_shift_requests_roles'];

    $manage = is_array($manage) ? cnx_normalize_shift_roles($manage) : $defaults['can_manage_shifts_roles'];
    $approve = is_array($approve) ? cnx_normalize_shift_roles($approve) : $defaults['can_approve_shift_requests_roles'];

    return [
        'can_manage_shifts_roles' => $manage,
        'can_approve_shift_requests_roles' => $approve,
    ];
}

/**
 * Load permissions for a tenant (feature-detect column existence).
 */
function cnx_get_shift_permissions_for_tenant(Database $db, int $tenantId): array {
    $defaults = cnx_default_shift_permissions();

    $hasCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'shift_permissions'"
    );
    if (!$hasCol) return $defaults;

    $row = $db->fetchOne(
        "SELECT shift_permissions
         FROM tenants
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1",
        [$tenantId]
    );
    if (!$row) return $defaults;

    return cnx_parse_shift_permissions($row['shift_permissions'] ?? null);
}

