<?php
// POST: create consulting plan
require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

try {
    $raw = cnx_get_raw_request_body();
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Dati non validi', 400);
    }

    $clientTenantId = (int)($data['client_tenant_id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    $notes = isset($data['notes']) ? trim((string)$data['notes']) : null;
    $status = trim((string)($data['status'] ?? 'draft'));
    $periodStart = isset($data['period_start']) && $data['period_start'] !== '' ? (string)$data['period_start'] : null;
    $periodEnd = isset($data['period_end']) && $data['period_end'] !== '' ? (string)$data['period_end'] : null;
    $consultantUserIds = isset($data['consultant_user_ids']) && is_array($data['consultant_user_ids'])
        ? $data['consultant_user_ids']
        : [];

    // Optional: plan scopes (migration 56)
    $scopesPayload = (isset($data['scopes']) && is_array($data['scopes'])) ? $data['scopes'] : null;

    // Optional: estimation persistence (migration 50)
    $estimateJsonCandidate = null;
    if (array_key_exists('estimate_json', $data)) {
        $v = $data['estimate_json'];
        if (is_string($v)) {
            $s = trim($v);
            $estimateJsonCandidate = ($s !== '') ? $s : null;
        } elseif ($v !== null) {
            $estimateJsonCandidate = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($estimateJsonCandidate !== null && strlen($estimateJsonCandidate) > 65000) {
            $estimateJsonCandidate = substr($estimateJsonCandidate, 0, 65000);
        }
    }

    // Optional: client profile + blueprint defaults (migration 43)
    $clientProfile = isset($data['client_profile']) && is_array($data['client_profile']) ? $data['client_profile'] : [];
    $clientBusinessDescription = trim((string)($clientProfile['business_description'] ?? ''));
    $clientDesiredScopeHint = trim((string)($clientProfile['desired_scope_hint'] ?? ''));
    $clientEmployeeCount = isset($clientProfile['employee_count']) && $clientProfile['employee_count'] !== '' ? (int)$clientProfile['employee_count'] : null;
    $clientSitesCount = isset($clientProfile['sites_count']) && $clientProfile['sites_count'] !== '' ? (int)$clientProfile['sites_count'] : null;
    if ($clientEmployeeCount !== null && $clientEmployeeCount < 0) $clientEmployeeCount = null;
    if ($clientSitesCount !== null && $clientSitesCount < 0) $clientSitesCount = null;

    $blueprintStandards = isset($data['blueprint_standards']) && is_array($data['blueprint_standards']) ? $data['blueprint_standards'] : null;
    $blueprintStandardsJson = $blueprintStandards ? json_encode(array_values(array_unique(array_filter(array_map('strval', $blueprintStandards)))), JSON_UNESCAPED_UNICODE) : null;

    if ($clientTenantId <= 0 || $clientTenantId === CNX_VENDOR_TENANT_ID) {
        api_error('Azienda cliente non valida', 400);
    }
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato all’azienda cliente', 403);
    }
    if ($title === '' || mb_strlen($title) < 3) {
        api_error('Titolo obbligatorio (min 3 caratteri)', 400);
    }
    if (mb_strlen($title) > 255) {
        api_error('Titolo troppo lungo', 400);
    }

    $validStatuses = ['draft','proposed','approved','scheduled','done','cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'draft';
    }

    $createdBy = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($createdBy <= 0) {
        api_error('Utente non valido', 400);
    }

    $db->beginTransaction();
    try {
        // Schema-aware insert (migration 43 optional)
        $cols = [];
        try {
            $colRows = $db->fetchAll("SHOW COLUMNS FROM consulting_plans") ?: [];
            foreach ($colRows as $r) {
                if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
            }
        } catch (Exception $e) {
            $cols = [];
        }

        $insert = [
            'client_tenant_id' => $clientTenantId,
            'title' => $title,
            'status' => $status,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'notes' => $notes,
            // Migration 50 (optional)
            'estimate_json' => $estimateJsonCandidate,
            'client_business_description' => ($clientBusinessDescription !== '' ? $clientBusinessDescription : null),
            'client_desired_scope_hint' => ($clientDesiredScopeHint !== '' ? $clientDesiredScopeHint : null),
            'client_employee_count' => $clientEmployeeCount,
            'client_sites_count' => $clientSitesCount,
            'blueprint_standards_json' => $blueprintStandardsJson,
            'created_by_user_id' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($cols)) {
            $insert = array_filter($insert, static fn($v, $k) => isset($cols[$k]), ARRAY_FILTER_USE_BOTH);
        }

        $planId = $db->insert('consulting_plans', $insert);

        if (!$planId) {
            throw new Exception('Insert fallito');
        }

        // Optional: store selected consultants (migration 35)
        try {
            $hasConsultants = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
            if ($hasConsultants && !empty($consultantUserIds)) {
                $ids = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $consultantUserIds), static fn($v) => $v > 0)));
                foreach ($ids as $uid) {
                    $db->insert('consulting_plan_consultants', [
                        'plan_id' => (int)$planId,
                        'user_id' => (int)$uid,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (Exception $ignore) {
            // non-blocking
        }

        // Optional: store plan scopes (migration 56) - best-effort, non-blocking
        $scopesStorageAvailable = false;
        $scopesStored = false;
        $scopesInserted = 0;
        try {
            $hasScopes = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_scopes'");
            $scopesStorageAvailable = (bool)$hasScopes;
            if ($hasScopes && is_array($scopesPayload) && !empty($scopesPayload)) {
                $norm = [];
                $seen = [];
                $i = 0;
                foreach ($scopesPayload as $s) {
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

                if (!empty($norm)) {
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

                    foreach ($norm as $r) {
                        $sid = (int)$r['activity_type_id'];
                        if (empty($found[$sid])) continue;
                        $ok = $db->insert('consulting_plan_scopes', [
                            'plan_id' => (int)$planId,
                            'activity_type_id' => $sid,
                            'estimated_days' => $r['estimated_days'],
                            'planned_days_override' => $r['planned_days_override'],
                            'notes' => $r['notes'],
                            'sort_order' => (int)$r['sort_order'],
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                        if ($ok) $scopesInserted++;
                    }
                    $scopesStored = ($scopesInserted > 0);
                }
            }
        } catch (Exception $ignore) {
            $scopesStorageAvailable = false;
            $scopesStored = false;
            $scopesInserted = 0;
            // non-blocking
        }

        $db->commit();

        $estimateStorageAvailable = false;
        $estimateStored = false;
        try {
            $estimateStorageAvailable = (bool)$db->fetchOne(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'consulting_plans'
                   AND COLUMN_NAME = 'estimate_json'
                 LIMIT 1"
            );
            $estimateStored = $estimateStorageAvailable && ($estimateJsonCandidate !== null);
        } catch (Throwable $e) {
            $estimateStorageAvailable = false;
            $estimateStored = false;
        }

        api_success([
            'id' => (int)$planId,
            'estimate_storage_available' => $estimateStorageAvailable,
            'estimate_stored' => $estimateStored,
            'scopes_storage_available' => $scopesStorageAvailable,
            'scopes_stored' => $scopesStored,
            'scopes_inserted' => $scopesInserted,
        ], 'Piano creato');
    } catch (Exception $tx) {
        $db->rollBack();
        throw $tx;
    }
} catch (Exception $e) {
    error_log('[CONSULTING_CREATE] ' . $e->getMessage());
    api_error('Errore creazione piano', 500);
}


