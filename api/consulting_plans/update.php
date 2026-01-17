<?php
// POST: update consulting plan
require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

try {
    $raw = cnx_get_raw_request_body();
    $data = json_decode($raw, true);
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
        api_error('Accesso negato all’azienda cliente', 403);
    }

    $updates = [];

    if (array_key_exists('title', $data)) {
        $title = trim((string)$data['title']);
        if ($title === '' || mb_strlen($title) < 3) api_error('Titolo non valido', 400);
        if (mb_strlen($title) > 255) api_error('Titolo troppo lungo', 400);
        $updates['title'] = $title;
    }

    if (array_key_exists('notes', $data)) {
        $updates['notes'] = $data['notes'] === null ? null : trim((string)$data['notes']);
    }

    if (array_key_exists('status', $data)) {
        $status = trim((string)$data['status']);
        $validStatuses = ['draft','proposed','approved','scheduled','done','cancelled'];
        if (!in_array($status, $validStatuses, true)) api_error('Status non valido', 400);
        $updates['status'] = $status;
    }

    if (array_key_exists('period_start', $data)) {
        $updates['period_start'] = $data['period_start'] === '' ? null : (string)$data['period_start'];
    }
    if (array_key_exists('period_end', $data)) {
        $updates['period_end'] = $data['period_end'] === '' ? null : (string)$data['period_end'];
    }

    // Optional: estimate_json (migration 50) - schema drift safe
    try {
        $hasEstimate = (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'consulting_plans'
               AND COLUMN_NAME = 'estimate_json'
             LIMIT 1"
        );
        if ($hasEstimate && array_key_exists('estimate_json', $data)) {
            $v = $data['estimate_json'];
            $estimateJson = null;
            if (is_string($v)) {
                $s = trim($v);
                $estimateJson = ($s !== '') ? $s : null;
            } elseif ($v !== null) {
                $estimateJson = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($estimateJson !== null && strlen($estimateJson) > 65000) {
                $estimateJson = substr($estimateJson, 0, 65000);
            }
            $updates['estimate_json'] = $estimateJson;
        }
    } catch (Throwable $e) {
        // non-blocking
    }

    // Optional: client profile + blueprint defaults (migration 43) - schema drift safe
    try {
        $cols = [];
        $colRows = $db->fetchAll("SHOW COLUMNS FROM consulting_plans") ?: [];
        foreach ($colRows as $r) {
            if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
        }

        if (isset($cols['client_business_description']) && array_key_exists('client_business_description', $data)) {
            $updates['client_business_description'] = ($data['client_business_description'] === null || $data['client_business_description'] === '') ? null : trim((string)$data['client_business_description']);
        }
        if (isset($cols['client_desired_scope_hint']) && array_key_exists('client_desired_scope_hint', $data)) {
            $updates['client_desired_scope_hint'] = ($data['client_desired_scope_hint'] === null || $data['client_desired_scope_hint'] === '') ? null : trim((string)$data['client_desired_scope_hint']);
        }
        if (isset($cols['client_employee_count']) && array_key_exists('client_employee_count', $data)) {
            $v = $data['client_employee_count'];
            $updates['client_employee_count'] = ($v === null || $v === '') ? null : (int)$v;
        }
        if (isset($cols['client_sites_count']) && array_key_exists('client_sites_count', $data)) {
            $v = $data['client_sites_count'];
            $updates['client_sites_count'] = ($v === null || $v === '') ? null : (int)$v;
        }
        if (isset($cols['blueprint_standards_json']) && array_key_exists('blueprint_standards_json', $data)) {
            $updates['blueprint_standards_json'] = ($data['blueprint_standards_json'] === null || $data['blueprint_standards_json'] === '') ? null : (string)$data['blueprint_standards_json'];
        }
    } catch (Exception $e) {
        // non-blocking
    }

    if (empty($updates)) {
        api_success(['id' => $planId], 'Nessuna modifica');
    }

    $updates['updated_at'] = date('Y-m-d H:i:s');

    $ok = $db->update('consulting_plans', $updates, ['id' => $planId]);
    if (!$ok) {
        api_error('Aggiornamento fallito', 500);
    }

    $estimateStorageAvailable = false;
    try {
        $estimateStorageAvailable = (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'consulting_plans'
               AND COLUMN_NAME = 'estimate_json'
             LIMIT 1"
        );
    } catch (Throwable $e) {
        $estimateStorageAvailable = false;
    }

    api_success([
        'id' => $planId,
        'estimate_storage_available' => $estimateStorageAvailable,
    ], 'Piano aggiornato');
} catch (Exception $e) {
    error_log('[CONSULTING_UPDATE] ' . $e->getMessage());
    api_error('Errore aggiornamento piano', 500);
}


