<?php
/**
 * Planning Estimate Wizard sessions (Tenant 28 internal tools).
 *
 * Storage: consulting_plan_estimates (migration 57) — OPTIONAL.
 * Feature-detection:
 * - If table missing → no 500; return storage_available=false and let UI fallback to localStorage.
 *
 * Endpoints:
 * - GET  /api/consulting_plans/estimate_session.php?action=get&client_id=...&session_id=...
 * - POST /api/consulting_plans/estimate_session.php?action=save_profile
 * - POST /api/consulting_plans/estimate_session.php?action=save_estimate
 * - POST /api/consulting_plans/estimate_session.php?action=link_plan
 *
 * Security:
 * - Tenant 28 gate enforced by _common.php
 * - CSRF required for POST (verifyApiCsrfToken)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$action = strtolower(trim((string)($_GET['action'] ?? ($_POST['action'] ?? 'get'))));

/**
 * Drift-safe table detection.
 */
function cnx_consulting_has_estimate_sessions(Database $db): bool {
    try {
        return (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_estimates'");
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return mixed|null
 */
function cnx_consulting_json_decode_or_null($raw) {
    if (!is_string($raw)) return null;
    $s = trim($raw);
    if ($s === '') return null;
    try {
        $v = json_decode($s, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        return $v;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return string|null
 */
function cnx_consulting_json_encode_or_null($v, int $maxBytes = 800000) {
    if ($v === null) return null;
    $json = null;
    try {
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $json = null;
    }
    if (!is_string($json) || $json === '') return null;
    if ($maxBytes > 0 && strlen($json) > $maxBytes) {
        // prevent oversized payloads; keep non-blocking for UX
        return null;
    }
    return $json;
}

try {
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($userId <= 0) api_error('Utente non valido', 401);

    $hasTable = cnx_consulting_has_estimate_sessions($db);
    if (!$hasTable) {
        api_success([
            'storage_available' => false,
            'action' => $action,
            'session' => null,
            'note' => 'Server storage non disponibile: applica migrazione 57 (consulting_plan_estimates).',
        ]);
    }

    if ($action === 'get') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($clientId <= 0) api_error('client_id non valido', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientId)) api_error('Accesso negato all’azienda cliente', 403);

        $sessionId = (int)($_GET['session_id'] ?? 0);

        $row = null;
        if ($sessionId > 0) {
            $row = $db->fetchOne(
                "SELECT id, tenant_id, client_id, created_by_user_id, services_json, profile_json, estimate_json, linked_plan_id, updated_at
                 FROM consulting_plan_estimates
                 WHERE id = ?
                   AND tenant_id = ?
                   AND client_id = ?
                   AND created_by_user_id = ?
                 LIMIT 1",
                [$sessionId, CNX_VENDOR_TENANT_ID, $clientId, $userId]
            );
        } else {
            // Prefer unlinked (active draft) first, then most recent update
            $row = $db->fetchOne(
                "SELECT id, tenant_id, client_id, created_by_user_id, services_json, profile_json, estimate_json, linked_plan_id, updated_at
                 FROM consulting_plan_estimates
                 WHERE tenant_id = ?
                   AND client_id = ?
                   AND created_by_user_id = ?
                 ORDER BY (linked_plan_id IS NULL) DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [CNX_VENDOR_TENANT_ID, $clientId, $userId]
            );
        }

        $session = null;
        if ($row && !empty($row['id'])) {
            $session = [
                'id' => (int)$row['id'],
                'client_id' => (int)$row['client_id'],
                'services' => cnx_consulting_json_decode_or_null($row['services_json'] ?? null),
                'profile' => cnx_consulting_json_decode_or_null($row['profile_json'] ?? null),
                'estimate' => cnx_consulting_json_decode_or_null($row['estimate_json'] ?? null),
                'linked_plan_id' => ($row['linked_plan_id'] === null ? null : (int)$row['linked_plan_id']),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        api_success([
            'storage_available' => true,
            'session' => $session,
        ]);
    }

    // POST actions
    verifyApiCsrfToken();
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    if ($action === 'save_profile') {
        $clientId = (int)($payload['client_id'] ?? 0);
        if ($clientId <= 0) api_error('client_id non valido', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientId)) api_error('Accesso negato all’azienda cliente', 403);

        $sessionId = (int)($payload['session_id'] ?? 0);
        $services = $payload['services'] ?? null;
        $profile = $payload['profile'] ?? null;

        $servicesJson = cnx_consulting_json_encode_or_null($services);
        $profileJson = cnx_consulting_json_encode_or_null($profile);

        // Create new session if missing/invalid session_id
        $id = 0;
        if ($sessionId > 0) {
            $exists = $db->fetchOne(
                "SELECT id FROM consulting_plan_estimates
                 WHERE id = ? AND tenant_id = ? AND client_id = ? AND created_by_user_id = ?
                 LIMIT 1",
                [$sessionId, CNX_VENDOR_TENANT_ID, $clientId, $userId]
            );
            if ($exists && !empty($exists['id'])) {
                $db->query(
                    "UPDATE consulting_plan_estimates
                     SET services_json = ?, profile_json = ?, updated_at = NOW()
                     WHERE id = ?
                       AND tenant_id = ?
                       AND client_id = ?
                       AND created_by_user_id = ?
                     LIMIT 1",
                    [$servicesJson, $profileJson, $sessionId, CNX_VENDOR_TENANT_ID, $clientId, $userId]
                );
                $id = $sessionId;
            }
        }
        if ($id <= 0) {
            $id = (int)$db->insert('consulting_plan_estimates', [
                'tenant_id' => CNX_VENDOR_TENANT_ID,
                'client_id' => $clientId,
                'created_by_user_id' => $userId,
                'services_json' => $servicesJson,
                'profile_json' => $profileJson,
                'estimate_json' => null,
                'linked_plan_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        api_success([
            'storage_available' => true,
            'session_id' => $id,
        ], 'Bozza profilo salvata');
    }

    if ($action === 'save_estimate') {
        $clientId = (int)($payload['client_id'] ?? 0);
        if ($clientId <= 0) api_error('client_id non valido', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientId)) api_error('Accesso negato all’azienda cliente', 403);

        $sessionId = (int)($payload['session_id'] ?? 0);
        if ($sessionId <= 0) api_error('session_id non valido', 400);
        $estimate = $payload['estimate'] ?? null;
        $estimateJson = cnx_consulting_json_encode_or_null($estimate, 1200000);

        $db->query(
            "UPDATE consulting_plan_estimates
             SET estimate_json = ?, updated_at = NOW()
             WHERE id = ?
               AND tenant_id = ?
               AND client_id = ?
               AND created_by_user_id = ?
             LIMIT 1",
            [$estimateJson, $sessionId, CNX_VENDOR_TENANT_ID, $clientId, $userId]
        );

        api_success([
            'storage_available' => true,
            'session_id' => $sessionId,
        ], 'Stima salvata');
    }

    if ($action === 'link_plan') {
        $clientId = (int)($payload['client_id'] ?? 0);
        if ($clientId <= 0) api_error('client_id non valido', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientId)) api_error('Accesso negato all’azienda cliente', 403);

        $sessionId = (int)($payload['session_id'] ?? 0);
        if ($sessionId <= 0) api_error('session_id non valido', 400);
        $planId = (int)($payload['linked_plan_id'] ?? 0);
        if ($planId <= 0) api_error('linked_plan_id non valido', 400);

        $db->query(
            "UPDATE consulting_plan_estimates
             SET linked_plan_id = ?, updated_at = NOW()
             WHERE id = ?
               AND tenant_id = ?
               AND client_id = ?
               AND created_by_user_id = ?
             LIMIT 1",
            [$planId, $sessionId, CNX_VENDOR_TENANT_ID, $clientId, $userId]
        );

        api_success([
            'storage_available' => true,
            'session_id' => $sessionId,
            'linked_plan_id' => $planId,
        ], 'Sessione collegata al piano');
    }

    api_error('Azione non supportata', 400, ['action' => $action]);
} catch (Throwable $e) {
    error_log('[CONSULTING_ESTIMATE_SESSION] ' . $e->getMessage());
    api_error('Errore gestione sessione stima', 500);
}

