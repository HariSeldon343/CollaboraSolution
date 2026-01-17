<?php
// POST: create or update an item
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/locations_distance.php';

verifyApiCsrfToken();

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $planId = (int)($data['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $id = (int)($data['id'] ?? 0);
    $activityType = (string)($data['activity_type'] ?? 'remote');
    $validTypes = ['onsite','remote','call','communication','travel'];
    if (!in_array($activityType, $validTypes, true)) {
        api_error('Tipo attività non valido', 400);
    }

    $activityDate = isset($data['activity_date']) && $data['activity_date'] !== '' ? (string)$data['activity_date'] : null;
    $days = isset($data['days']) ? (float)$data['days'] : 0.0;
    $hours = isset($data['hours']) ? (float)$data['hours'] : 0.0;
    $km = isset($data['km']) ? (float)$data['km'] : 0.0;
    $dayRate = isset($data['day_rate']) ? (float)$data['day_rate'] : 0.0;
    $fixedAmount = isset($data['fixed_amount']) ? (float)$data['fixed_amount'] : 0.0;
    $kmRate = isset($data['km_rate']) ? (float)$data['km_rate'] : 0.50;
    $extras = isset($data['extras_amount']) ? (float)$data['extras_amount'] : 0.0;
    $desc = isset($data['description']) ? trim((string)$data['description']) : null;
    $assigneeUserId = isset($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : 0;
    if ($assigneeUserId < 0) $assigneeUserId = 0;
    $locationTenantLocationId = isset($data['location_tenant_location_id']) ? (int)$data['location_tenant_location_id'] : 0;
    if ($locationTenantLocationId < 0) $locationTenantLocationId = 0;

    // Optional fields (migration 35) - schema drift safe
    $domainActivityTypeId = isset($data['domain_activity_type_id']) ? (int)$data['domain_activity_type_id'] : 0;
    $callEveryDaysOverride = isset($data['call_every_days_override']) ? (int)$data['call_every_days_override'] : 0;
    $callDurationMinutesOverride = isset($data['call_duration_minutes_override']) ? (int)$data['call_duration_minutes_override'] : 0;

    // Pricing defaults: if day_rate is unset/0, fallback to 360€/day (platform default)
    if ($dayRate <= 0) $dayRate = 360.0;

    // ------------------------------------------------------------
    // Days/Hours normalization (Planning 2026 rules)
    // - Day-based activities: days step 0.5 (no 0.25), min 0.5 when >0
    // - Call/communication: use hours (30–60 min), days forced to 0
    // Backward-compatible: if legacy payload sends days for call, convert to hours (call-day=4h).
    // ------------------------------------------------------------
    $warnings = [];
    $origDays = $days;
    $origHours = $hours;

    if (in_array($activityType, ['call','communication'], true)) {
        // Convert legacy days -> hours (best-effort) if needed
        if ($hours <= 0 && $days > 0) {
            $hours = $days * 4.0; // call-day = 4h
            $days = 0.0;
            $warnings[] = 'Normalizzato: call/communication usa ore, non giornate (days→hours).';
        } else {
            $days = 0.0;
        }

        if ($hours > 0) {
            $min = 30;
            $max = 60;
            $mins = (int)round($hours * 60.0);
            if ($mins < $min) $mins = $min;
            if ($mins > $max) $mins = $max;
            $hours = $mins / 60.0;
            if (abs($origHours - $hours) > 1e-6) {
                $warnings[] = 'Normalizzato: durata call clamp 30–60 minuti.';
            }
        }
    } else {
        // Day-based step enforcement
        if ($days > 0) {
            $norm = round($days * 2.0) / 2.0;
            if ($norm < 0.5) $norm = 0.5;
            if (abs($norm - $days) > 1e-9) {
                $warnings[] = 'Normalizzato: giorni arrotondati a step 0.5 (min 0.5).';
            }
            $days = $norm;
        }
    }

    $hasDomainCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'domain_activity_type_id'
         LIMIT 1"
    );
    $hasCallEveryCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'call_every_days_override'
         LIMIT 1"
    );
    $hasCallDurCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'call_duration_minutes_override'
         LIMIT 1"
    );
    $hasFixedAmountCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'fixed_amount'
         LIMIT 1"
    );
    $hasAssigneeCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'assignee_user_id'
         LIMIT 1"
    );
    $hasLocationCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'location_tenant_location_id'
         LIMIT 1"
    );

    $payload = [
        'plan_id' => $planId,
        'activity_type' => $activityType,
        'activity_date' => $activityDate,
        'days' => max(0, $days),
        'hours' => max(0, $hours),
        'km' => max(0, $km),
        'day_rate' => max(0, $dayRate),
        'km_rate' => max(0, $kmRate),
        'extras_amount' => max(0, $extras),
        'description' => $desc,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($hasFixedAmountCol) {
        $payload['fixed_amount'] = max(0, $fixedAmount);
    }

    if ($hasDomainCol) {
        $payload['domain_activity_type_id'] = $domainActivityTypeId > 0 ? $domainActivityTypeId : null;
    }
    if ($hasCallEveryCol) {
        $payload['call_every_days_override'] = $callEveryDaysOverride > 0 ? $callEveryDaysOverride : null;
    }
    if ($hasCallDurCol) {
        $payload['call_duration_minutes_override'] = $callDurationMinutesOverride > 0 ? $callDurationMinutesOverride : null;
    }

    if ($hasAssigneeCol) {
        // Optional: enforce that assignee belongs to selected plan consultants (migration 35) when available
        $assigneeVal = $assigneeUserId > 0 ? $assigneeUserId : null;
        if ($assigneeVal !== null) {
            try {
                $hasPlanConsultants = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
                if ($hasPlanConsultants) {
                    $ok = $db->fetchOne(
                        "SELECT 1 FROM consulting_plan_consultants WHERE plan_id = ? AND user_id = ? LIMIT 1",
                        [$planId, $assigneeVal]
                    );
                    if (!$ok) {
                        api_error('Consulente non selezionato per questo piano', 400);
                    }
                }
            } catch (Throwable $e) {
                // best-effort: do not block
            }
        }
        $payload['assignee_user_id'] = $assigneeVal;
    }

    if ($hasLocationCol) {
        $locVal = $locationTenantLocationId > 0 ? $locationTenantLocationId : null;
        if ($locVal !== null) {
            // Validate location belongs to this plan's client tenant (best-effort, do not block if tenant_locations missing)
            try {
                $hasLoc = (bool)$db->fetchOne("SHOW TABLES LIKE 'tenant_locations'");
                if ($hasLoc) {
                    $ok = $db->fetchOne(
                        "SELECT id
                         FROM tenant_locations
                         WHERE id = ?
                           AND tenant_id = ?
                           AND deleted_at IS NULL
                         LIMIT 1",
                        [$locVal, $clientTenantId]
                    );
                    if (!$ok) {
                        api_error('Sede non valida per questa azienda', 400);
                    }
                } else {
                    $locVal = null;
                }
            } catch (Throwable $e) {
                // non-blocking: if validation fails, just store null (avoid breaking older deployments)
                $locVal = null;
            }
        }
        $payload['location_tenant_location_id'] = $locVal;
    }

    // Auto-calc KM for travel items when not provided (best-effort).
    // Uses consultant home_city (consulting_plan_consultants) + client location province (tenant_locations)
    // and offline province centroids (no external APIs).
    if ($activityType === 'travel' && $km <= 0 && $hasLocationCol && $assigneeUserId > 0) {
        try {
            $homeCity = '';
            $fromProv = null;
            $toProv = null;

            // Consultant home city
            $hasCpc = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
            if ($hasCpc) {
                $cpcHasHomeCity = (bool)$db->fetchOne(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'consulting_plan_consultants'
                       AND COLUMN_NAME = 'home_city'
                     LIMIT 1"
                );
                if ($cpcHasHomeCity) {
                    $row = $db->fetchOne(
                        "SELECT home_city
                         FROM consulting_plan_consultants
                         WHERE plan_id = ? AND user_id = ?
                         LIMIT 1",
                        [$planId, $assigneeUserId]
                    );
                    $homeCity = $row ? trim((string)($row['home_city'] ?? '')) : '';
                }
            }
            if ($homeCity !== '') {
                $fromProv = cnx_locations_extract_province_code_from_city($homeCity) ?: cnx_locations_infer_province_code($db, $homeCity);
            }

            // Client location province
            $hasLoc = (bool)$db->fetchOne("SHOW TABLES LIKE 'tenant_locations'");
            if ($hasLoc) {
                if ($locationTenantLocationId > 0) {
                    $row = $db->fetchOne(
                        "SELECT provincia
                         FROM tenant_locations
                         WHERE id = ?
                           AND tenant_id = ?
                           AND deleted_at IS NULL
                         LIMIT 1",
                        [$locationTenantLocationId, $clientTenantId]
                    );
                } else {
                    // Auto: use primary location for the client tenant
                    $row = $db->fetchOne(
                        "SELECT provincia
                         FROM tenant_locations
                         WHERE tenant_id = ?
                           AND deleted_at IS NULL
                           AND is_active = 1
                         ORDER BY is_primary DESC,
                                  CASE location_type WHEN 'sede_legale' THEN 0 ELSE 1 END,
                                  created_at ASC
                         LIMIT 1",
                        [$clientTenantId]
                    );
                }
                $toProv = $row ? strtoupper(trim((string)($row['provincia'] ?? ''))) : null;
            }

            if (is_string($fromProv) && is_string($toProv) && strlen($fromProv) === 2 && strlen($toProv) === 2) {
                $kmEst = cnx_locations_estimate_road_km_by_province($db, $fromProv, $toProv, true, 1.25);
                if ($kmEst !== null && $kmEst > 0) {
                    $km = (float)$kmEst;
                    $payload['km'] = max(0, $km);
                }
            }
        } catch (Throwable $e) {
            // non-blocking
        }
    }

    if ($id > 0) {
        $existing = $db->fetchOne(
            "SELECT id FROM consulting_plan_items WHERE id = ? AND plan_id = ? AND deleted_at IS NULL",
            [$id, $planId]
        );
        if (!$existing) api_error('Attività non trovata', 404);

        $ok = $db->update('consulting_plan_items', $payload, ['id' => $id]);
        if (!$ok) api_error('Aggiornamento fallito', 500);
        api_success(['id' => $id, 'warnings' => $warnings], 'Attività aggiornata');
    }

    $payload['created_at'] = date('Y-m-d H:i:s');
    $newId = $db->insert('consulting_plan_items', $payload);
    if (!$newId) api_error('Creazione fallita', 500);
    api_success(['id' => (int)$newId, 'warnings' => $warnings], 'Attività creata');
} catch (Exception $e) {
    error_log('[CONSULTING_ITEM_UPSERT] ' . $e->getMessage());
    api_error('Errore salvataggio attività', 500);
}


