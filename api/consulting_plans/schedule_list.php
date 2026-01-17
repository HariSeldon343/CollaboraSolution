<?php
// Consulting Planning: list schedule draft rows for a plan
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $has = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_schedule_drafts'");
    if (!$has) {
        api_error(
            'Modulo calendario proposto non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }
} catch (Exception $e) {
    api_error('Database non disponibile per il calendario proposto', 503);
}

try {
    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $rows = $db->fetchAll(
        "SELECT d.*,
                u.name AS assigned_user_name
         FROM consulting_plan_schedule_drafts d
         LEFT JOIN users u ON u.id = d.assigned_user_id
         WHERE d.plan_id = ?
           AND d.deleted_at IS NULL
         ORDER BY d.start_datetime ASC",
        [$planId]
    ) ?: [];

    api_success([
        'plan_id' => $planId,
        'client_tenant_id' => $clientTenantId,
        'drafts' => array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'kind' => (string)$r['kind'],
            'title' => (string)$r['title'],
            'start_datetime' => (string)$r['start_datetime'],
            'end_datetime' => (string)$r['end_datetime'],
            'assigned_user_id' => $r['assigned_user_id'] !== null ? (int)$r['assigned_user_id'] : null,
            'assigned_user_name' => (string)($r['assigned_user_name'] ?? ''),
            'status' => (string)$r['status'],
            'confirmed_event_id' => $r['confirmed_event_id'] !== null ? (int)$r['confirmed_event_id'] : null,
            'location_city' => isset($r['location_city']) && $r['location_city'] !== null ? (string)$r['location_city'] : null,
            'explain_json' => isset($r['explain_json']) && $r['explain_json'] !== null ? (string)$r['explain_json'] : null,
        ], $rows),
    ]);
} catch (Exception $e) {
    error_log('[CONSULTING_SCHEDULE_LIST] ' . $e->getMessage());
    api_error('Errore caricamento calendario proposto', 500);
}


