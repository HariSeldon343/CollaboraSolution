<?php
// Consulting Planning: lightweight count of plan items (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

/**
 * Schema-drift safe: check whether a column exists.
 */
function cnx_has_col(Database $db, string $table, string $col): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $planHasDeletedAt = cnx_has_col($db, 'consulting_plans', 'deleted_at');
    $plan = $db->fetchOne(
        "SELECT id, client_tenant_id
         FROM consulting_plans
         WHERE id = ?" . ($planHasDeletedAt ? " AND deleted_at IS NULL" : "") . "
         LIMIT 1",
        [$planId]
    );
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $itemsHasDeletedAt = cnx_has_col($db, 'consulting_plan_items', 'deleted_at');
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS c
         FROM consulting_plan_items
         WHERE plan_id = ?" . ($itemsHasDeletedAt ? " AND deleted_at IS NULL" : ""),
        [$planId]
    );
    $count = (int)($row['c'] ?? 0);
    if ($count < 0) $count = 0;

    api_success([
        'plan_id' => $planId,
        'client_tenant_id' => $clientTenantId,
        'items_count' => $count,
        'has_items' => ($count > 0),
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_ITEMS_COUNT] ' . $e->getMessage());
    api_error('Errore conteggio attività', 500);
}

