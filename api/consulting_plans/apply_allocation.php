<?php
// Consulting Planning: apply service->consultant allocation to plan items (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

/**
 * @return bool
 */
function cnx_col_exists(Database $db, string $table, string $col): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
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

function cnx_capacity_mobility_score(string $activityType): int {
    // Lower is more movable
    switch ($activityType) {
        case 'communication': return 1;
        case 'call': return 2;
        case 'remote': return 3;
        case 'travel': return 4;
        case 'onsite': return 5;
        default: return 3;
    }
}

try {
    // Requires migration 35 consultants table
    $hasPlanConsultants = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_consultants'");
    if (!$hasPlanConsultants) {
        api_error(
            'Modulo consulenti non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $allocation = $payload['allocation'] ?? null;
    $itemAssignmentsRaw = $payload['item_assignments'] ?? null; // optional: item_id => user_id
    $hasItemAssignments = (is_array($itemAssignmentsRaw) && !empty($itemAssignmentsRaw));
    if ((!is_array($allocation) || empty($allocation)) && !$hasItemAssignments) {
        api_error('allocation o item_assignments obbligatorio', 400);
    }

    $hasAssignee = cnx_col_exists($db, 'consulting_plan_items', 'assignee_user_id');
    if (!$hasAssignee) {
        api_error(
            'Allocazione non disponibile: applica la migrazione database 50 (assignee_user_id)',
            503,
            ['migration' => 'database/migrations/50_consulting_service_catalog_extensions.sql']
        );
    }

    $hasDomain = cnx_col_exists($db, 'consulting_plan_items', 'domain_activity_type_id');
    if (!$hasDomain) {
        api_error(
            'Allocazione non disponibile: applica la migrazione database 35 (domain_activity_type_id)',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }

    // Load selected consultants for plan
    $rows = $db->fetchAll(
        "SELECT user_id FROM consulting_plan_consultants WHERE plan_id = ?",
        [$planId]
    ) ?: [];
    $selectedConsultants = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['user_id'] ?? 0), $rows), static fn($v) => $v > 0)));
    if (empty($selectedConsultants)) api_error('Seleziona prima i consulenti del piano', 400);

    // Normalize allocation matrix: service_id => user_id => days (optional when item_assignments provided)
    $alloc = [];
    if (is_array($allocation) && !empty($allocation)) {
        foreach ($allocation as $serviceIdRaw => $byUser) {
            $sid = (int)$serviceIdRaw;
            if ($sid <= 0 || !is_array($byUser)) continue;
            foreach ($byUser as $userIdRaw => $daysRaw) {
                $uid = (int)$userIdRaw;
                if ($uid <= 0) continue;
                if (!in_array($uid, $selectedConsultants, true)) {
                    api_error('Allocazione non valida: include consulenti non selezionati', 400);
                }
                $days = (float)$daysRaw;
                if (!is_finite($days) || $days < 0) $days = 0.0;
                $alloc[$sid][$uid] = $days;
            }
        }
    }

    // Load plan items
    $items = $db->fetchAll(
        "SELECT id, activity_type, days, domain_activity_type_id
         FROM consulting_plan_items
         WHERE plan_id = ? AND deleted_at IS NULL
         ORDER BY id ASC",
        [$planId]
    ) ?: [];
    if (empty($items)) api_error('Nessuna attività nel piano', 400);

    // Index items by id + group by service (domain_activity_type_id)
    $itemsById = [];
    $itemsByService = [];
    foreach ($items as $it) {
        $iid = (int)($it['id'] ?? 0);
        if ($iid <= 0) continue;
        $itemsById[$iid] = $it;
        $sid = (int)($it['domain_activity_type_id'] ?? 0);
        if ($sid > 0) $itemsByService[$sid][] = $it;
    }

    $warnings = [];
    $assignments = []; // item_id => assignee_user_id

    // Direct item assignments (preferred when provided) - item_id => user_id
    if ($hasItemAssignments) {
        foreach ($itemAssignmentsRaw as $itemIdRaw => $userIdRaw) {
            $iid = (int)$itemIdRaw;
            $uid = (int)$userIdRaw;
            if ($iid <= 0) continue;
            if (!isset($itemsById[$iid])) {
                $warnings[] = 'Attività non trovata (id=' . $iid . ')';
                continue;
            }
            if ($uid <= 0) {
                $warnings[] = 'Attività #' . $iid . ' senza consulente assegnato';
                continue;
            }
            if (!in_array($uid, $selectedConsultants, true)) {
                api_error('Allocazione non valida: include consulenti non selezionati', 400);
            }
            $assignments[$iid] = $uid;
        }

        // Best-effort: warn if some items are missing assignment
        foreach (array_keys($itemsById) as $iid) {
            if (!isset($assignments[$iid])) {
                $warnings[] = 'Attività #' . (int)$iid . ' non assegnata (verrà lasciata invariata)';
            }
        }

        // Derive alloc matrix from item assignments (for persistence)
        $alloc = [];
        foreach ($assignments as $iid => $uid) {
            $it = $itemsById[$iid] ?? null;
            if (!$it) continue;
            $sid = (int)($it['domain_activity_type_id'] ?? 0);
            if ($sid <= 0) continue;
            $d = (float)($it['days'] ?? 0.0);
            if (!is_finite($d) || $d < 0) $d = 0.0;
            if ($d <= 0) continue;
            if (!isset($alloc[$sid])) $alloc[$sid] = [];
            $alloc[$sid][$uid] = (float)($alloc[$sid][$uid] ?? 0.0) + $d;
        }
    }

    // If no item assignments were provided, compute assignments from allocation matrix (legacy behavior)
    if (!$hasItemAssignments) foreach ($alloc as $serviceId => $byUserDays) {
        $serviceId = (int)$serviceId;
        $svcItems = $itemsByService[$serviceId] ?? [];
        if (empty($svcItems)) {
            $warnings[] = 'Nessuna attività trovata per il servizio ID ' . $serviceId;
            continue;
        }

        // Remaining days per consultant for this service
        $remaining = [];
        foreach ($selectedConsultants as $uid) {
            $remaining[$uid] = (float)($byUserDays[$uid] ?? 0.0);
        }

        usort($svcItems, static function ($a, $b) {
            $am = cnx_capacity_mobility_score((string)($a['activity_type'] ?? 'remote'));
            $bm = cnx_capacity_mobility_score((string)($b['activity_type'] ?? 'remote'));
            if ($am !== $bm) return $am <=> $bm;
            return ((float)($b['days'] ?? 0.0) <=> (float)($a['days'] ?? 0.0));
        });

        $pick = static function (float $need) use (&$remaining, $selectedConsultants): int {
            $bestUid = 0;
            $bestRem = -INF;
            foreach ($selectedConsultants as $uid) {
                $rem = (float)($remaining[$uid] ?? 0.0);
                if ($rem + 1e-6 >= $need && $rem > $bestRem) {
                    $bestRem = $rem;
                    $bestUid = (int)$uid;
                }
            }
            if ($bestUid > 0) return $bestUid;
            // fallback: max remaining even if insufficient
            foreach ($selectedConsultants as $uid) {
                $rem = (float)($remaining[$uid] ?? 0.0);
                if ($rem > $bestRem) {
                    $bestRem = $rem;
                    $bestUid = (int)$uid;
                }
            }
            return $bestUid > 0 ? $bestUid : (int)($selectedConsultants[0] ?? 0);
        };

        foreach ($svcItems as $it) {
            $itemId = (int)($it['id'] ?? 0);
            if ($itemId <= 0) continue;
            $d = (float)($it['days'] ?? 0.0);
            if (!is_finite($d) || $d < 0) $d = 0.0;
            if ($d <= 0) continue;

            $uid = $pick($d);
            $assignments[$itemId] = $uid;
            $remaining[$uid] = ((float)($remaining[$uid] ?? 0.0)) - $d;
        }

        foreach ($remaining as $uid => $rem) {
            if ($rem < -0.01) {
                $warnings[] = sprintf('Allocazione insufficiente per servizio %d: consulente %d sfora di %.2fg', $serviceId, (int)$uid, abs($rem));
            }
        }
    }

    if (empty($assignments)) {
        api_error('Nessuna assegnazione applicabile (verifica che le righe abbiano service_id)', 400);
    }

    // Best-effort capacity check vs default consultant capacities (migration 37)
    $capacityWarnings = [];
    try {
        $hasCap = (bool)$db->fetchOne("SHOW TABLES LIKE 'consultant_capacities'");
        if ($hasCap) {
            $capRows = $db->fetchAll(
                "SELECT user_id, available_days FROM consultant_capacities WHERE user_id IN (" . implode(',', array_fill(0, count($selectedConsultants), '?')) . ")",
                $selectedConsultants
            ) ?: [];
            $available = [];
            foreach ($capRows as $r) {
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid > 0) $available[$uid] = (float)($r['available_days'] ?? 0.0);
            }

            $used = [];
            foreach ($items as $it) {
                $iid = (int)($it['id'] ?? 0);
                $uid = (int)($assignments[$iid] ?? 0);
                if ($uid <= 0) continue;
                $d = (float)($it['days'] ?? 0.0);
                if (!is_finite($d) || $d < 0) $d = 0.0;
                $used[$uid] = ($used[$uid] ?? 0.0) + $d;
            }

            foreach ($used as $uid => $u) {
                $a = (float)($available[$uid] ?? 0.0);
                if ($a > 0 && $u - $a > 1e-6) {
                    $capacityWarnings[] = sprintf('Disponibilità insufficiente: consulente %d richiesti %.2fg, disponibili %.2fg', (int)$uid, $u, $a);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Persist allocation into consulting_plans.estimate_json if available (migration 50)
    $estimatePersisted = false;
    $hasEstimateCol = cnx_col_exists($db, 'consulting_plans', 'estimate_json');
    if ($hasEstimateCol) {
        try {
            $cur = $db->fetchOne("SELECT estimate_json FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
            $curObj = [];
            if ($cur && !empty($cur['estimate_json'])) {
                $tmp = json_decode((string)$cur['estimate_json'], true);
                if (is_array($tmp)) $curObj = $tmp;
            }
            $curObj['allocation'] = $alloc;
            $curObj['allocation_applied_at'] = date('c');
            $curObj['allocation_applied_by'] = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
            $db->update('consulting_plans', [
                'estimate_json' => json_encode($curObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $planId]);
            $estimatePersisted = true;
        } catch (Throwable $e) {
            $estimatePersisted = false;
        }
    }

    // Apply assignments in DB
    $db->beginTransaction();
    try {
        foreach ($assignments as $itemId => $uid) {
            $db->update('consulting_plan_items', [
                'assignee_user_id' => (int)$uid,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int)$itemId]);
        }
        $db->commit();
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }

    api_success([
        'plan_id' => $planId,
        'assigned_count' => count($assignments),
        'estimate_persisted' => $estimatePersisted,
        'warnings' => array_values(array_filter(array_merge($warnings, $capacityWarnings))),
    ], 'Allocazione applicata');
} catch (Throwable $e) {
    error_log('[CONSULTING_APPLY_ALLOCATION] ' . $e->getMessage());
    api_error('Errore allocazione', 500);
}

