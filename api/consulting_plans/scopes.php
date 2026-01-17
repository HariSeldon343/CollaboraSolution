<?php
// Consulting Planning: plan scopes (multi-service / multi-norma)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    verifyApiCsrfToken();
}

/**
 * Feature detect consulting_plan_scopes table.
 */
function cnx_consulting_has_plan_scopes(Database $db): bool {
    try {
        return (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_scopes'");
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Derive scope rows from consulting_plans.estimate_json (legacy fallback).
 *
 * @return array<int, array{activity_type_id:int, estimated_days:?float, planned_days_override:?float, notes:?string, sort_order:int}>
 */
function cnx_consulting_derive_scopes_from_estimate_json($estimateJson): array {
    $est = null;
    if (is_string($estimateJson)) {
        $s = trim($estimateJson);
        if ($s !== '') {
            try { $est = json_decode($s, true); } catch (Throwable $e) { $est = null; }
        }
    } elseif (is_array($estimateJson)) {
        $est = $estimateJson;
    }
    if (!is_array($est)) return [];

    $estimates = isset($est['estimates']) && is_array($est['estimates']) ? $est['estimates'] : [];
    if (empty($estimates)) return [];

    $out = [];
    $seen = [];
    $idx = 0;
    foreach ($estimates as $e) {
        if (!is_array($e)) continue;
        $sid = (int)($e['service_type_id'] ?? 0);
        if ($sid <= 0) continue;
        if (isset($seen[$sid])) continue;
        $seen[$sid] = true;

        $suggested = $e['suggested_days'] ?? null;
        $estimated = ($suggested === null || $suggested === '') ? null : (float)$suggested;
        if ($estimated !== null && $estimated < 0) $estimated = 0.0;

        $out[] = [
            'activity_type_id' => $sid,
            'estimated_days' => $estimated,
            'planned_days_override' => null,
            'notes' => null,
            'sort_order' => $idx,
        ];
        $idx++;
    }
    return $out;
}

try {
    if ($method === 'GET') {
        $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
        if ($planId <= 0) api_error('plan_id obbligatorio', 400);

        $plan = $db->fetchOne(
            "SELECT id, client_tenant_id, estimate_json
             FROM consulting_plans
             WHERE id = ?
               AND deleted_at IS NULL",
            [$planId]
        );
        if (!$plan) api_error('Piano non trovato', 404);

        $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

        $storageAvailable = cnx_consulting_has_plan_scopes($db);
        $derived = false;
        $scopes = [];

        if ($storageAvailable) {
            $rows = $db->fetchAll(
                "SELECT activity_type_id, estimated_days, planned_days_override, notes, sort_order
                 FROM consulting_plan_scopes
                 WHERE plan_id = ?
                 ORDER BY (sort_order IS NULL) ASC, sort_order ASC, id ASC",
                [$planId]
            ) ?: [];

            foreach ($rows as $r) {
                $sid = (int)($r['activity_type_id'] ?? 0);
                if ($sid <= 0) continue;
                $scopes[] = [
                    'activity_type_id' => $sid,
                    'estimated_days' => ($r['estimated_days'] === null) ? null : (float)$r['estimated_days'],
                    'planned_days_override' => ($r['planned_days_override'] === null) ? null : (float)$r['planned_days_override'],
                    'notes' => isset($r['notes']) && $r['notes'] !== null ? (string)$r['notes'] : null,
                    'sort_order' => isset($r['sort_order']) && $r['sort_order'] !== null ? (int)$r['sort_order'] : 0,
                ];
            }

            // If table exists but plan has no scopes yet, fallback to estimate_json for legacy compatibility.
            if (empty($scopes)) {
                $derived = true;
                $scopes = cnx_consulting_derive_scopes_from_estimate_json($plan['estimate_json'] ?? null);
            }
        } else {
            $derived = true;
            $scopes = cnx_consulting_derive_scopes_from_estimate_json($plan['estimate_json'] ?? null);
        }

        api_success([
            'plan_id' => $planId,
            'client_tenant_id' => $clientTenantId,
            'storage_available' => $storageAvailable,
            'derived_from_estimate' => $derived,
            'scopes' => $scopes,
        ]);
    }

    // POST: replace scopes set for plan
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);
    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);
    $scopesIn = $payload['scopes'] ?? null;
    if (!is_array($scopesIn)) api_error('scopes non valido', 400);

    $plan = $db->fetchOne(
        "SELECT id, client_tenant_id
         FROM consulting_plans
         WHERE id = ?
           AND deleted_at IS NULL",
        [$planId]
    );
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    if (!cnx_consulting_has_plan_scopes($db)) {
        api_error(
            'Modulo scope piano non inizializzato: applica la migrazione database 56',
            503,
            ['migration' => 'database/migrations/56_consulting_plan_scopes.sql']
        );
    }

    // Normalize payload
    $norm = [];
    $seen = [];
    $i = 0;
    foreach ($scopesIn as $s) {
        if (!is_array($s)) continue;
        $sid = (int)($s['activity_type_id'] ?? ($s['service_type_id'] ?? 0));
        if ($sid <= 0) continue;
        if (isset($seen[$sid])) continue;
        $seen[$sid] = true;

        $estimated = $s['estimated_days'] ?? ($s['suggested_days'] ?? null);
        $estimatedDays = ($estimated === null || $estimated === '') ? null : (float)$estimated;
        if ($estimatedDays !== null && $estimatedDays < 0) $estimatedDays = 0.0;

        $override = $s['planned_days_override'] ?? ($s['planned_days'] ?? null);
        $overrideDays = ($override === null || $override === '') ? null : (float)$override;
        if ($overrideDays !== null && $overrideDays < 0) $overrideDays = 0.0;

        $notes = isset($s['notes']) ? trim((string)$s['notes']) : null;
        if ($notes === '') $notes = null;
        if ($notes !== null && strlen($notes) > 2000) $notes = substr($notes, 0, 2000);

        $sort = isset($s['sort_order']) ? (int)$s['sort_order'] : $i;

        $norm[] = [
            'activity_type_id' => $sid,
            'estimated_days' => $estimatedDays,
            'planned_days_override' => $overrideDays,
            'notes' => $notes,
            'sort_order' => $sort,
        ];
        $i++;
    }
    if (empty($norm)) api_error('Seleziona almeno 1 servizio/norma', 400);

    // Validate activity type ids exist in vendor catalog
    $ids = array_values(array_map(static fn($r) => (int)$r['activity_type_id'], $norm));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT id
         FROM consulting_activity_types
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND id IN ($placeholders)",
        array_merge([CNX_VENDOR_TENANT_ID], $ids)
    ) ?: [];
    $found = [];
    foreach ($rows as $r) {
        $fid = (int)($r['id'] ?? 0);
        if ($fid > 0) $found[$fid] = true;
    }
    $missing = array_values(array_filter($ids, static fn($id) => empty($found[$id])));
    if (!empty($missing)) {
        api_error('Servizi/norme non validi', 400, ['missing_activity_type_ids' => $missing]);
    }

    $db->beginTransaction();
    try {
        $db->query("DELETE FROM consulting_plan_scopes WHERE plan_id = ?", [$planId]);

        $inserted = 0;
        foreach ($norm as $r) {
            $ok = $db->insert('consulting_plan_scopes', [
                'plan_id' => $planId,
                'activity_type_id' => (int)$r['activity_type_id'],
                'estimated_days' => $r['estimated_days'],
                'planned_days_override' => $r['planned_days_override'],
                'notes' => $r['notes'],
                'sort_order' => (int)$r['sort_order'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ($ok) $inserted++;
        }

        $db->commit();
        api_success([
            'plan_id' => $planId,
            'inserted' => $inserted,
        ], 'Scope salvato');
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('[CONSULTING_SCOPES] ' . $e->getMessage());
    api_error('Errore gestione scope piano', 500);
}

