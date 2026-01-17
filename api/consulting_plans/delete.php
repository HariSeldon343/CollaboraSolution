<?php
// POST: soft-delete consulting plan
require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) {
        api_error('Dati non validi', 400);
    }

    $planId = (int)($data['id'] ?? 0);
    if ($planId <= 0) {
        api_error('ID piano non valido', 400);
    }

    $plan = $db->fetchOne(
        "SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL",
        [$planId]
    );
    if (!$plan) {
        api_error('Piano non trovato', 404);
    }

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato', 403);
    }

    $ok = $db->update('consulting_plans', [
        'deleted_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $planId]);

    if (!$ok) {
        api_error('Eliminazione fallita', 500);
    }

    api_success(['id' => $planId], 'Piano eliminato');
} catch (Exception $e) {
    error_log('[CONSULTING_DELETE] ' . $e->getMessage());
    api_error('Errore eliminazione piano', 500);
}


