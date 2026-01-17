<?php
// Consulting Planning: consultants selection (S.CO users for schedule)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/locations_municipalities.php';

// Require migration 35 table
try {
    $has = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
    if (!$has) {
        api_error(
            'Modulo consulenti non inizializzato: applica la migrazione database 35 (activity catalog)',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }
} catch (Exception $e) {
    api_error('Database non disponibile per il modulo consulenti', 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    verifyApiCsrfToken();
}

try {
    if ($method === 'GET') {
        $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
        $selected = [];
        $selectedConsultants = [];
        $clientTenantId = 0;
        $cpcCols = [];
        try {
            $colRows = $db->fetchAll("SHOW COLUMNS FROM consulting_plan_consultants") ?: [];
            foreach ($colRows as $r) {
                if (!empty($r['Field'])) $cpcCols[(string)$r['Field']] = true;
            }
        } catch (Throwable $e) {
            $cpcCols = [];
        }
        $hasHomeCity = !empty($cpcCols['home_city']);
        $hasHomeLat = !empty($cpcCols['home_lat']);
        $hasHomeLng = !empty($cpcCols['home_lng']);

        if ($planId > 0) {
            $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
            if (!$plan) api_error('Piano non trovato', 404);
            $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
            if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

            $selFields = "user_id";
            if ($hasHomeCity) $selFields .= ", home_city";
            if ($hasHomeLat) $selFields .= ", home_lat";
            if ($hasHomeLng) $selFields .= ", home_lng";
            $rows = $db->fetchAll("SELECT $selFields FROM consulting_plan_consultants WHERE plan_id = ?", [$planId]) ?: [];

            foreach ($rows as $r) {
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $selected[] = $uid;
                $selectedConsultants[] = [
                    'user_id' => $uid,
                    'home_city' => $hasHomeCity ? (isset($r['home_city']) && trim((string)$r['home_city']) !== '' ? (string)$r['home_city'] : null) : null,
                    'home_lat' => $hasHomeLat ? (isset($r['home_lat']) && $r['home_lat'] !== null ? (float)$r['home_lat'] : null) : null,
                    'home_lng' => $hasHomeLng ? (isset($r['home_lng']) && $r['home_lng'] !== null ? (float)$r['home_lng'] : null) : null,
                ];
            }
        }

        // Users available to pick: all active users with access to tenant 28
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";

        // Optional users.home_city (migration 54) as default for planning home city per consultant
        $uHasHomeCity = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'home_city'
                 LIMIT 1"
            );
            $uHasHomeCity = ((int)($col['ok'] ?? 0) === 1);
        } catch (Throwable $e) {
            $uHasHomeCity = false;
        }
        $homeCitySelect = $uHasHomeCity ? ", u.home_city" : "";

        $users = $db->fetchAll(
            "SELECT DISTINCT u.id, u.name, u.email, u.role{$homeCitySelect}
             FROM users u
             LEFT JOIN user_tenant_access uta
               ON uta.user_id = u.id
              AND uta.tenant_id = ?" . $utaWhere . "
             WHERE u.deleted_at IS NULL
               AND u.is_active = 1
               AND (
                    u.tenant_id = ?
                    OR uta.user_id IS NOT NULL
               )
             ORDER BY u.name ASC",
            [CNX_VENDOR_TENANT_ID, CNX_VENDOR_TENANT_ID]
        ) ?: [];

        api_success([
            'plan_id' => $planId ?: null,
            'client_tenant_id' => $clientTenantId ?: null,
            'consultants' => array_map(static fn($u) => [
                'id' => (int)$u['id'],
                'name' => (string)($u['name'] ?? ''),
                'email' => (string)($u['email'] ?? ''),
                'role' => (string)($u['role'] ?? ''),
                'home_city' => (isset($u['home_city']) && trim((string)$u['home_city']) !== '') ? (string)$u['home_city'] : null,
            ], $users),
            'selected_user_ids' => $selected,
            'selected_consultants' => $selectedConsultants,
            'home_city_storage_available' => $hasHomeCity,
            'user_home_city_available' => $uHasHomeCity,
        ]);
    }

    // POST: set consultants for plan
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);
    $planId = (int)($payload['plan_id'] ?? 0);
    $userIds = $payload['user_ids'] ?? [];
    $consultantsPayload = $payload['consultants'] ?? null;
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);
    if ($consultantsPayload !== null && !is_array($consultantsPayload)) api_error('consultants non valido', 400);
    if ($consultantsPayload === null && !is_array($userIds)) api_error('user_ids non valido', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $cpcCols = [];
    try {
        $colRows = $db->fetchAll("SHOW COLUMNS FROM consulting_plan_consultants") ?: [];
        foreach ($colRows as $r) {
            if (!empty($r['Field'])) $cpcCols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $cpcCols = [];
    }
    $hasHomeCity = !empty($cpcCols['home_city']);
    $hasHomeLat = !empty($cpcCols['home_lat']);
    $hasHomeLng = !empty($cpcCols['home_lng']);

    $consultants = []; // array of ['user_id'=>int,'home_city'=>?string,'home_lat'=>?float,'home_lng'=>?float]
    if (is_array($consultantsPayload)) {
        foreach ($consultantsPayload as $c) {
            if (!is_array($c)) continue;
            $uid = (int)($c['user_id'] ?? $c['id'] ?? 0);
            if ($uid <= 0) continue;
            $homeCity = isset($c['home_city']) ? trim((string)$c['home_city']) : '';
            $homeLat = isset($c['home_lat']) && $c['home_lat'] !== '' ? (float)$c['home_lat'] : null;
            $homeLng = isset($c['home_lng']) && $c['home_lng'] !== '' ? (float)$c['home_lng'] : null;
            $consultants[$uid] = [
                'user_id' => $uid,
                'home_city' => ($homeCity !== '' ? $homeCity : null),
                'home_lat' => $homeLat,
                'home_lng' => $homeLng,
            ];
        }
    } else {
        $ids = array_values(array_unique(array_filter(array_map(static function($v) {
            $n = (int)$v;
            return $n > 0 ? $n : null;
        }, $userIds))));
        foreach ($ids as $uid) {
            $consultants[$uid] = [
                'user_id' => (int)$uid,
                'home_city' => null,
                'home_lat' => null,
                'home_lng' => null,
            ];
        }
    }

    // Validate home_city values against the Italian municipalities dataset (if available).
    // This prevents unusable travel optimization data (unknown cities).
    if ($hasHomeCity && !empty($consultants)) {
        $invalid = [];
        foreach ($consultants as $c) {
            $hc = $c['home_city'] ?? null;
            if ($hc === null) continue;
            $hcNorm = cnx_locations_normalize_city_input((string)$hc);
            if ($hcNorm === '') continue;
            if (!cnx_locations_city_exists($db, $hcNorm)) {
                $invalid[] = [
                    'user_id' => (int)($c['user_id'] ?? 0),
                    'home_city' => $hcNorm,
                    'suggestions' => cnx_locations_city_suggestions($db, $hcNorm, 5),
                ];
            }
        }
        if (!empty($invalid)) {
            api_error(
                'Città di partenza non riconosciuta per uno o più consulenti. Seleziona un comune italiano valido.',
                400,
                ['invalid' => $invalid]
            );
        }
    }

    $ids = array_values(array_map(static fn($c) => (int)($c['user_id'] ?? 0), $consultants));

    // Replace set (simple + safe)
    $db->beginTransaction();
    try {
        // Database wrapper uses query()/rollback() (not execute()/rollBack())
        $db->query("DELETE FROM consulting_plan_consultants WHERE plan_id = ?", [$planId]);
        foreach ($ids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            $c = $consultants[$uid] ?? ['user_id' => $uid];
            $ins = [
                'plan_id' => $planId,
                'user_id' => $uid,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasHomeCity) $ins['home_city'] = $c['home_city'] ?? null;
            if ($hasHomeLat) $ins['home_lat'] = $c['home_lat'] ?? null;
            if ($hasHomeLng) $ins['home_lng'] = $c['home_lng'] ?? null;
            $db->insert('consulting_plan_consultants', $ins);
        }
        $db->commit();
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }

    api_success([
        'plan_id' => $planId,
        'user_ids' => $ids,
        'home_city_storage_available' => $hasHomeCity,
    ], 'Consulenti aggiornati');
} catch (Throwable $e) {
    $errId = 'cpc_' . substr(str_replace('.', '', uniqid('', true)), -10);
    try { $errId = 'cpc_' . bin2hex(random_bytes(5)); } catch (Throwable $ignored) {}
    error_log('[CONSULTING_CONSULTANTS][' . $errId . '] ' . $e->getMessage());

    $role = (string)($userInfo['role'] ?? 'user');
    $extra = ['error_id' => $errId];
    if ($role === 'super_admin') {
        $extra['debug_message'] = $e->getMessage();
    }
    api_error('Errore gestione consulenti', 500, $extra);
}


