<?php
// Consulting Planning: suggest a new free slot for a draft row (one-click)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/calendar.php';

verifyApiCsrfToken();

try {
    $hasDrafts = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plan_schedule_drafts' LIMIT 1");
    $hasConsultants = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plan_consultants' LIMIT 1");
    if (!$hasDrafts || !$hasConsultants) {
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
    // Optional column (migration 53)
    $hasExplainCol = false;
    try {
        $hasExplainCol = (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'consulting_plan_schedule_drafts'
               AND COLUMN_NAME = 'explain_json'
             LIMIT 1"
        );
    } catch (Throwable $e) {
        $hasExplainCol = false;
    }

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $draftId = (int)($payload['draft_id'] ?? 0);
    if ($draftId <= 0) api_error('draft_id obbligatorio', 400);

    $draft = $db->fetchOne("SELECT * FROM consulting_plan_schedule_drafts WHERE id = ? AND deleted_at IS NULL", [$draftId]);
    if (!$draft) api_error('Slot non trovato', 404);
    if ((string)($draft['status'] ?? '') !== 'draft') api_error('Slot non modificabile', 409);

    $planId = (int)($draft['plan_id'] ?? 0);
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $consultants = $db->fetchAll("SELECT user_id FROM consulting_plan_consultants WHERE plan_id = ?", [$planId]) ?: [];
    $consultantIds = array_values(array_filter(array_map(static fn($r) => (int)($r['user_id'] ?? 0), $consultants)));
    if (empty($consultantIds)) api_error('Nessun consulente selezionato', 400);

    $start = new DateTime((string)$draft['start_datetime']);
    $end = new DateTime((string)$draft['end_datetime']);
    $durationMin = max(15, (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    $kind = strtolower(trim((string)($draft['kind'] ?? 'other')));

    // client_blocking (best-effort): if false, allow overlap with other NON-blocking plan drafts
    $draftBlocking = true;
    try {
        if ($hasExplainCol) {
            $expRaw = (string)($draft['explain_json'] ?? '');
            if ($expRaw !== '') {
                $exp = json_decode($expRaw, true);
                if (is_array($exp)) {
                    if (array_key_exists('client_blocking', $exp)) {
                        $draftBlocking = (bool)$exp['client_blocking'];
                    } elseif (isset($exp['rules']['no_overlap']['plan'])) {
                        $draftBlocking = (bool)$exp['rules']['no_overlap']['plan'];
                    }
                }
            }
        } else {
            $draftBlocking = true;
        }
    } catch (Throwable $e) {
        $draftBlocking = true;
    }

    // Search window: next 14 days from current draft start (or today)
    $from = new DateTime('today');
    if ($start > $from) $from = clone $start;
    $to = (clone $from)->add(new DateInterval('P14D'));

    $pdo = Database::getInstance()->getConnection();
    $apiUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $calendar = new Calendar($pdo, CNX_VENDOR_TENANT_ID, $apiUserId);

    $assignee = (int)($draft['assigned_user_id'] ?? 0);
    if ($assignee <= 0) {
        $assignee = (int)($consultantIds[0] ?? 0);
    }
    if ($assignee <= 0) api_error('Nessun consulente assegnato', 400);

    // Avoid overlap with other active drafts for this assignee (all plans)
    $busy = $db->fetchAll(
        "SELECT id, start_datetime, end_datetime
         FROM consulting_plan_schedule_drafts
         WHERE deleted_at IS NULL
           AND id <> ?
           AND assigned_user_id = ?
           AND status IN ('draft','confirmed')
           AND start_datetime < ?
           AND end_datetime > ?",
        [$draftId, $assignee, $to->format('Y-m-d 23:59:59'), $from->format('Y-m-d 00:00:00')]
    ) ?: [];

    $busyIntervals = [];
    foreach ($busy as $b) {
        try {
            $bs = new DateTime((string)($b['start_datetime'] ?? ''));
            $be = new DateTime((string)($b['end_datetime'] ?? ''));
            if ($be <= $bs) continue;
            $busyIntervals[] = ['start' => $bs, 'end' => $be, 'id' => (int)($b['id'] ?? 0)];
        } catch (Throwable $e) {
            continue;
        }
    }

    // Avoid overlap within THIS plan:
    // - if current draft is client_blocking: avoid overlap with ANY plan slot
    // - else: avoid overlap only with other client_blocking slots (best-effort via explain_json)
    $busyPlanSelect = "SELECT id, start_datetime, end_datetime, status";
    if ($hasExplainCol) $busyPlanSelect .= ", explain_json";
    $busyPlanSelect .= "
        FROM consulting_plan_schedule_drafts
        WHERE deleted_at IS NULL
          AND id <> ?
          AND plan_id = ?
          AND status IN ('draft','confirmed')
          AND start_datetime < ?
          AND end_datetime > ?";
    $busyPlan = $db->fetchAll(
        $busyPlanSelect,
        [$draftId, $planId, $to->format('Y-m-d 23:59:59'), $from->format('Y-m-d 00:00:00')]
    ) ?: [];

    $busyPlanIntervals = [];
    foreach ($busyPlan as $b) {
        try {
            $bs = new DateTime((string)($b['start_datetime'] ?? ''));
            $be = new DateTime((string)($b['end_datetime'] ?? ''));
            if ($be <= $bs) continue;
            $otherBlocking = true;
            if (!$draftBlocking) {
                // If current draft is NOT blocking, ignore overlaps with other non-blocking drafts
                $otherBlocking = true;
                try {
                    $er = (string)($b['explain_json'] ?? '');
                    if ($er !== '') {
                        $ex = json_decode($er, true);
                        if (is_array($ex) && array_key_exists('client_blocking', $ex)) {
                            $otherBlocking = (bool)$ex['client_blocking'];
                        } elseif (is_array($ex) && isset($ex['rules']['no_overlap']['plan'])) {
                            $otherBlocking = (bool)$ex['rules']['no_overlap']['plan'];
                        }
                    } elseif ((string)($b['status'] ?? '') !== 'draft') {
                        // confirmed with no explain => assume blocking
                        $otherBlocking = true;
                    }
                } catch (Throwable $e) {
                    $otherBlocking = true;
                }
            }
            if ($draftBlocking || $otherBlocking) {
                $busyPlanIntervals[] = ['start' => $bs, 'end' => $be, 'id' => (int)($b['id'] ?? 0)];
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    // Avoid overlap with CONFIRMED slots for the same client (across plans) (assume blocking)
    $busyClient = $db->fetchAll(
        "SELECT id, start_datetime, end_datetime
         FROM consulting_plan_schedule_drafts
         WHERE deleted_at IS NULL
           AND id <> ?
           AND client_tenant_id = ?
           AND status = 'confirmed'
           AND start_datetime < ?
           AND end_datetime > ?",
        [$draftId, $clientTenantId, $to->format('Y-m-d 23:59:59'), $from->format('Y-m-d 00:00:00')]
    ) ?: [];

    $busyClientIntervals = [];
    foreach ($busyClient as $b) {
        try {
            $bs = new DateTime((string)($b['start_datetime'] ?? ''));
            $be = new DateTime((string)($b['end_datetime'] ?? ''));
            if ($be <= $bs) continue;
            $busyClientIntervals[] = ['start' => $bs, 'end' => $be, 'id' => (int)($b['id'] ?? 0)];
        } catch (Throwable $e) {
            continue;
        }
    }

    // Prefer start times consistent with Planning 2026 rules:
    // - non-call blocks: 09:00 / 14:00 (0.5 day)
    // - calls: more granular times to avoid collisions with 0.5-day blocks
    $preferredTimes = ['09:00', '14:00'];
    if (in_array($kind, ['call', 'communication'], true)) {
        $preferredTimes = ['09:00','09:30','10:00','10:30','11:00','14:00','14:30','15:00','15:30','16:00'];
    }

    $suggestions = $calendar->suggestFreeSlots(
        $durationMin,
        [$assignee],
        ['start' => $from->format('Y-m-d'), 'end' => $to->format('Y-m-d')],
        [
            'preferred_times' => $preferredTimes,
            'avoid_lunch' => true,
            'skip_weekends' => true,
            'max_suggestions' => 20,
        ]
    );

    $newStart = null;
    $newEnd = null;
    foreach ($suggestions as $s) {
        try {
            $candStart = new DateTime((string)($s['start'] ?? ''));
            $candEnd = new DateTime((string)($s['end'] ?? ''));
        } catch (Throwable $e) {
            continue;
        }
        if ($candEnd <= $candStart) continue;

        $overlap = false;
        foreach ($busyIntervals as $bi) {
            /** @var DateTime $bs */
            $bs = $bi['start'];
            /** @var DateTime $be */
            $be = $bi['end'];
            if ($candStart < $be && $candEnd > $bs) { $overlap = true; break; }
        }
        if ($overlap) continue;

        foreach ($busyPlanIntervals as $bi) {
            /** @var DateTime $bs */
            $bs = $bi['start'];
            /** @var DateTime $be */
            $be = $bi['end'];
            if ($candStart < $be && $candEnd > $bs) { $overlap = true; break; }
        }
        if ($overlap) continue;

        foreach ($busyClientIntervals as $bi) {
            /** @var DateTime $bs */
            $bs = $bi['start'];
            /** @var DateTime $be */
            $be = $bi['end'];
            if ($candStart < $be && $candEnd > $bs) { $overlap = true; break; }
        }
        if ($overlap) continue;

        $newStart = $candStart;
        $newEnd = $candEnd;
        break;
    }

    if (!$newStart || !$newEnd) {
        api_error('Nessuno slot disponibile trovato nel periodo (evitando sovrapposizioni con altre bozze)', 409);
    }

    $ok = $db->update('consulting_plan_schedule_drafts', [
        'start_datetime' => $newStart->format('Y-m-d H:i:s'),
        'end_datetime' => $newEnd->format('Y-m-d H:i:s'),
        'assigned_user_id' => $assignee,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $draftId]);
    if (!$ok) api_error('Aggiornamento slot fallito', 500);

    api_success([
        'draft_id' => $draftId,
        'assigned_user_id' => $assignee,
        'start_datetime' => $newStart->format('Y-m-d H:i:s'),
        'end_datetime' => $newEnd->format('Y-m-d H:i:s'),
    ], 'Slot suggerito applicato');
} catch (Throwable $e) {
    error_log('[CONSULTING_SCHEDULE_SUGGEST] ' . $e->getMessage());
    api_error('Errore suggerimento slot', 500);
}


