<?php
// GET: latest compliance blueprint for a plan (schema drift safe)
require_once __DIR__ . '/_common.php';

try {
    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) {
        api_error('Parametro plan_id mancante o non valido', 400);
    }

    $plan = $db->fetchOne("SELECT id, client_tenant_id FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) {
        api_error('Piano non trovato', 404);
    }

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0 || !cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato all’azienda cliente del piano', 403);
    }

    $storageAvailable = false;
    try {
        $storageAvailable = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_blueprints'");
    } catch (Throwable $e) {
        $storageAvailable = false;
    }

    if (!$storageAvailable) {
        api_success([
            'storage_available' => false,
            'stored' => false,
            'plan_id' => $planId,
            'client_tenant_id' => $clientTenantId,
            'compliance_blueprint' => null,
            'meta' => [],
        ]);
    }

    $row = $db->fetchOne(
        "SELECT blueprint_json, standards_json, created_at, created_by
         FROM consulting_plan_blueprints
         WHERE plan_id = ?
         ORDER BY id DESC
         LIMIT 1",
        [$planId]
    );

    if (!$row) {
        api_success([
            'storage_available' => true,
            'stored' => false,
            'plan_id' => $planId,
            'client_tenant_id' => $clientTenantId,
            'compliance_blueprint' => null,
            'meta' => [],
        ]);
    }

    $bpRaw = $row['blueprint_json'] ?? null;
    $stdRaw = $row['standards_json'] ?? null;

    $bpDecoded = null;
    if (is_string($bpRaw) && $bpRaw !== '') {
        $bpDecoded = json_decode($bpRaw, true);
    } elseif (is_array($bpRaw)) {
        $bpDecoded = $bpRaw;
    }
    if (!is_array($bpDecoded)) $bpDecoded = [];

    $stdDecoded = null;
    if (is_string($stdRaw) && $stdRaw !== '') {
        $stdDecoded = json_decode($stdRaw, true);
    } elseif (is_array($stdRaw)) {
        $stdDecoded = $stdRaw;
    }
    if (!is_array($stdDecoded)) $stdDecoded = [];

    $compliance = isset($bpDecoded['compliance_blueprint']) && is_array($bpDecoded['compliance_blueprint'])
        ? $bpDecoded['compliance_blueprint']
        : null;

    $metaStored = isset($bpDecoded['meta']) && is_array($bpDecoded['meta']) ? $bpDecoded['meta'] : [];
    $generatedAt = (string)($bpDecoded['generated_at'] ?? $row['created_at'] ?? '');

    api_success([
        'storage_available' => true,
        'stored' => true,
        'plan_id' => $planId,
        'client_tenant_id' => $clientTenantId,
        'compliance_blueprint' => $compliance,
        'meta' => [
            'generated_at' => $generatedAt,
            'confidence' => $metaStored['confidence'] ?? null,
            'risks' => is_array($metaStored['risks'] ?? null) ? $metaStored['risks'] : [],
            'standards' => $stdDecoded,
        ],
    ]);
} catch (Exception $e) {
    error_log('[CONSULTING_BLUEPRINT_LATEST] ' . $e->getMessage());
    api_error('Errore caricamento blueprint', 500);
}

