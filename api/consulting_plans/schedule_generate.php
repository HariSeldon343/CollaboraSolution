<?php
// Consulting Planning: generate schedule draft proposal (activities + periodic calls)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/calendar.php';
require_once __DIR__ . '/../../includes/locations_municipalities.php';

verifyApiCsrfToken();

try {
    $hasDrafts = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plan_schedule_drafts' LIMIT 1");
    $hasConsultants = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plan_consultants' LIMIT 1");
    $hasTypes = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_activity_types' LIMIT 1");
    $hasOverrides = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_activity_type_overrides' LIMIT 1");
    if (!$hasDrafts || !$hasConsultants || !$hasTypes || !$hasOverrides) {
        api_error(
            'Modulo calendario/catalago non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }
} catch (Exception $e) {
    api_error('Database non disponibile per il calendario proposto', 503);
}

/**
 * Resolve activity type effective settings for a client.
 * @return array{weight_factor:float,call_every_days:int,call_duration_minutes:int,is_active:bool,name:string}|null
 */
function cnx_resolve_effective_activity_type(Database $db, int $typeId, int $clientTenantId): ?array {
    $t = $db->fetchOne(
        "SELECT id, name, weight_factor, call_every_days_default, call_duration_minutes_default, is_active
         FROM consulting_activity_types
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
         LIMIT 1",
        [$typeId, CNX_VENDOR_TENANT_ID]
    );
    if (!$t) return null;

    $eff = [
        'name' => (string)$t['name'],
        'weight_factor' => (float)$t['weight_factor'],
        'call_every_days' => (int)$t['call_every_days_default'],
        'call_duration_minutes' => (int)$t['call_duration_minutes_default'],
        'is_active' => (bool)((int)$t['is_active']),
    ];

    if ($clientTenantId > 0) {
        $ov = $db->fetchOne(
            "SELECT weight_factor_override, call_every_days_override, call_duration_minutes_override, is_active_override
             FROM consulting_activity_type_overrides
             WHERE client_tenant_id = ? AND activity_type_id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$clientTenantId, $typeId]
        );
        if ($ov) {
            if ($ov['weight_factor_override'] !== null && $ov['weight_factor_override'] !== '') {
                $eff['weight_factor'] = (float)$ov['weight_factor_override'];
            }
            if ($ov['call_every_days_override'] !== null && $ov['call_every_days_override'] !== '') {
                $eff['call_every_days'] = (int)$ov['call_every_days_override'];
            }
            if ($ov['call_duration_minutes_override'] !== null && $ov['call_duration_minutes_override'] !== '') {
                $eff['call_duration_minutes'] = (int)$ov['call_duration_minutes_override'];
            }
            if ($ov['is_active_override'] !== null && $ov['is_active_override'] !== '') {
                $eff['is_active'] = (bool)((int)$ov['is_active_override']);
            }
        }
    }

    return $eff;
}

function cnx_norm_city(string $s): string {
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}

/**
 * Best-effort: resolve client's primary municipality (comune) from tenant_locations.
 */
function cnx_consulting_get_client_city(Database $db, int $clientTenantId): ?string {
    if ($clientTenantId <= 0) return null;
    try {
        $hasLoc = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_locations' LIMIT 1");
        if (!$hasLoc) return null;
        $row = $db->fetchOne(
            "SELECT comune
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
        $city = $row ? trim((string)($row['comune'] ?? '')) : '';
        return $city !== '' ? $city : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}> $busy
 * @return array|null conflict metadata
 */
function cnx_busy_find_overlap(array $busy, DateTime $start, DateTime $end): ?array {
    $s = $start->getTimestamp();
    $e = $end->getTimestamp();
    foreach ($busy as $b) {
        $bs = (int)($b['start_ts'] ?? 0);
        $be = (int)($b['end_ts'] ?? 0);
        if ($bs <= 0 || $be <= 0) continue;
        if ($s < $be && $e > $bs) return $b;
    }
    return null;
}

/**
 * @param array<int,array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}>> $busyByUser
 */
function cnx_busy_add(array &$busyByUser, int $userId, DateTime $start, DateTime $end, array $meta): void {
    if ($userId <= 0) return;
    $busyByUser[$userId] = $busyByUser[$userId] ?? [];
    $busyByUser[$userId][] = array_merge($meta, [
        'start_ts' => $start->getTimestamp(),
        'end_ts' => $end->getTimestamp(),
    ]);
}

/**
 * Find a free slot for a single assignee:
 * - respects Calendar free slots (real events)
 * - respects busyByUser intervals (other drafts + already created drafts in this run)
 *
 * @return array{start:DateTime,end:DateTime,explain:array<string,mixed>}|null
 */
function cnx_pick_slot(
    Calendar $calendar,
    int $minutes,
    int $assignee,
    DateTime $rangeStart,
    DateTime $rangeEnd,
    array $preferences,
    array $busyIntervals,
    DateTime $windowStart,
    DateTime $windowEnd
): ?array {
    $minutes = max(15, $minutes);
    $attempts = [];

    $tryRanges = [
        ['start' => $rangeStart->format('Y-m-d'), 'end' => $rangeEnd->format('Y-m-d'), 'label' => 'segment'],
        ['start' => $windowStart->format('Y-m-d'), 'end' => $windowEnd->format('Y-m-d'), 'label' => 'window_fallback'],
    ];

    foreach ($tryRanges as $ri => $r) {
        $suggestions = [];
        try {
            $suggestions = $calendar->suggestFreeSlots(
                $minutes,
                [$assignee],
                ['start' => (string)$r['start'], 'end' => (string)$r['end']],
                array_merge($preferences, ['max_suggestions' => ($ri === 0 ? 12 : 30)])
            );
        } catch (Throwable $e) {
            $suggestions = [];
        }

        foreach ($suggestions as $s) {
            try {
                $st = new DateTime((string)($s['start'] ?? ''));
                $en = new DateTime((string)($s['end'] ?? ''));
            } catch (Throwable $e) {
                continue;
            }
            if ($en <= $st) continue;
            if ($st < $windowStart || $en > $windowEnd) {
                $attempts[] = ['range' => $r['label'], 'start' => $st->format('Y-m-d H:i:s'), 'end' => $en->format('Y-m-d H:i:s'), 'ok' => false, 'reason' => 'outside_window'];
                continue;
            }
            $conf = cnx_busy_find_overlap($busyIntervals, $st, $en);
            if ($conf) {
                $attempts[] = [
                    'range' => $r['label'],
                    'start' => $st->format('Y-m-d H:i:s'),
                    'end' => $en->format('Y-m-d H:i:s'),
                    'ok' => false,
                    'reason' => 'overlap_draft',
                    'conflict' => $conf,
                ];
                continue;
            }
            return [
                'start' => $st,
                'end' => $en,
                'explain' => [
                    'strategy' => 'calendar_suggestions',
                    'range' => $r['label'],
                    'selected_score' => $s['score'] ?? null,
                    'selected_reasons' => $s['reasons'] ?? null,
                    'attempts' => array_slice($attempts, -10),
                ],
            ];
        }
    }

    // Fallback strategy (deterministic):
    // If suggestFreeSlots() returns no suitable slot (or throws), try preferred_times directly
    // while checking user availability + draft overlaps.
    try {
        $preferredTimes = $preferences['preferred_times'] ?? ['09:00', '14:00'];
        if (!is_array($preferredTimes) || empty($preferredTimes)) $preferredTimes = ['09:00', '14:00'];
        $avoidLunch = (bool)($preferences['avoid_lunch'] ?? true);
        $skipWeekends = (bool)($preferences['skip_weekends'] ?? true);

        $dateCursor = clone $rangeStart;
        $dateCursor->setTime(0, 0, 0);
        $dateEnd = clone $rangeEnd;
        $dateEnd->setTime(0, 0, 0);

        $guardDays = 0;
        while ($dateCursor <= $dateEnd && $guardDays < 180) {
            if ($skipWeekends) {
                $n = (int)$dateCursor->format('N');
                if ($n === 6 || $n === 7) {
                    $dateCursor->add(new DateInterval('P1D'));
                    $guardDays++;
                    continue;
                }
            }

            $avail = $calendar->getUserAvailability($assignee, $dateCursor);
            $workStart = $avail['work_hours']['start'] ?? null;
            $workEnd = $avail['work_hours']['end'] ?? null;
            $busySlots = (isset($avail['busy_slots']) && is_array($avail['busy_slots'])) ? $avail['busy_slots'] : [];

            foreach ($preferredTimes as $pt) {
                if (!is_string($pt)) continue;
                $pt = trim($pt);
                if (!preg_match('/^\d{1,2}:\d{2}$/', $pt)) continue;
                [$hh, $mm] = array_map('intval', explode(':', $pt));
                if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) continue;

                $st = clone $dateCursor;
                $st->setTime($hh, $mm, 0);
                $en = (clone $st)->add(new DateInterval('PT' . $minutes . 'M'));
                if ($en <= $st) continue;

                // Window clamp
                if ($st < $windowStart || $en > $windowEnd) continue;

                // Within working hours (if present)
                if ($workStart instanceof DateTime && $st < $workStart) continue;
                if ($workEnd instanceof DateTime && $en > $workEnd) continue;

                // Lunch avoidance (12:30-14:00)
                if ($avoidLunch) {
                    $sm = ((int)$st->format('H')) * 60 + (int)$st->format('i');
                    $em = ((int)$en->format('H')) * 60 + (int)$en->format('i');
                    $lStart = 12 * 60 + 30;
                    $lEnd = 14 * 60;
                    if ($sm < $lEnd && $em > $lStart) continue;
                }

                // Overlap with other drafts (cross-plan) or already-picked slots in this run
                $conf = cnx_busy_find_overlap($busyIntervals, $st, $en);
                if ($conf) continue;

                // Overlap with real events (busy slots)
                $eventOverlap = false;
                foreach ($busySlots as $b) {
                    if (!is_array($b)) continue;
                    $bs = $b['start'] ?? null;
                    $be = $b['end'] ?? null;
                    if (!($bs instanceof DateTime) || !($be instanceof DateTime) || $be <= $bs) continue;
                    if ($st < $be && $en > $bs) { $eventOverlap = true; break; }
                }
                if ($eventOverlap) continue;

                return [
                    'start' => $st,
                    'end' => $en,
                    'explain' => [
                        'strategy' => 'fallback_preferred_times',
                        'preferred_time' => $pt,
                        'range' => 'fallback',
                    ],
                ];
            }

            $dateCursor->add(new DateInterval('P1D'));
            $guardDays++;
        }
    } catch (Throwable $e) {
        // ignore and fail below
    }

    return null;
}

/**
 * Forced slot pick (best-effort):
 * - Does NOT check real calendar events
 * - Optionally skips checking draft overlaps (allowOverlap=true)
 *
 * @return array{start:DateTime,end:DateTime,explain:array<string,mixed>}|null
 */
function cnx_force_slot(
    int $minutes,
    int $assignee,
    DateTime $rangeStart,
    DateTime $rangeEnd,
    array $preferences,
    array $busyIntervals,
    DateTime $windowStart,
    DateTime $windowEnd,
    bool $allowOverlap = false
): ?array {
    $minutes = max(15, (int)$minutes);
    $skipWeekends = (bool)($preferences['skip_weekends'] ?? true);
    $preferredTimes = $preferences['preferred_times'] ?? ['09:00', '14:00'];
    if (!is_array($preferredTimes) || empty($preferredTimes)) $preferredTimes = ['09:00', '14:00'];

    // Candidate times: preferred first, then every 30 minutes 09:00–17:00 (inclusive)
    $cand = [];
    $seen = [];
    foreach ($preferredTimes as $pt) {
        if (!is_string($pt)) continue;
        $pt = trim($pt);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $pt)) continue;
        [$hh, $mm] = array_map('intval', explode(':', $pt));
        if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) continue;
        $key = sprintf('%02d:%02d', $hh, $mm);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $cand[] = [$hh, $mm, $key];
    }
    for ($hh = 9; $hh <= 17; $hh++) {
        for ($mm = 0; $mm < 60; $mm += 30) {
            $key = sprintf('%02d:%02d', $hh, $mm);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $cand[] = [$hh, $mm, $key];
        }
    }

    $dateCursor = clone $rangeStart;
    $dateCursor->setTime(0, 0, 0);
    $dateEnd = clone $rangeEnd;
    $dateEnd->setTime(0, 0, 0);

    $guard = 0;
    while ($dateCursor <= $dateEnd && $guard < 730) { // 2 years safety
        if ($skipWeekends) {
            $n = (int)$dateCursor->format('N');
            if ($n === 6 || $n === 7) {
                $dateCursor->add(new DateInterval('P1D'));
                $guard++;
                continue;
            }
        }

        foreach ($cand as $c) {
            $hh = (int)$c[0];
            $mm = (int)$c[1];
            $label = (string)$c[2];
            $st = clone $dateCursor;
            $st->setTime($hh, $mm, 0);
            $en = (clone $st)->add(new DateInterval('PT' . $minutes . 'M'));
            if ($en <= $st) continue;
            if ($st < $windowStart || $en > $windowEnd) continue;

            if (!$allowOverlap) {
                $conf = cnx_busy_find_overlap($busyIntervals, $st, $en);
                if ($conf) continue;
            }

            return [
                'start' => $st,
                'end' => $en,
                'explain' => [
                    'strategy' => 'forced_slot',
                    'forced' => true,
                    'allow_overlap' => $allowOverlap,
                    'preferred_time' => $label,
                ],
            ];
        }

        $dateCursor->add(new DateInterval('P1D'));
        $guard++;
    }

    return null;
}

function cnx_sched_is_weekend(DateTime $d): bool {
    $n = (int)$d->format('N');
    return ($n === 6 || $n === 7);
}

function cnx_sched_apply_phase_gap(DateTime $cursor, int $gapDays): DateTime {
    $gapDays = max(0, (int)$gapDays);
    $d = clone $cursor;
    if ($gapDays > 0) {
        $d->add(new DateInterval('P' . $gapDays . 'D'));
    }
    // Keep time-of-day to respect "previous end + gap days".
    // Normalize into working hours (09:00–18:00).
    $h = (int)$d->format('H');
    $m = (int)$d->format('i');
    if ($h < 9) {
        $d->setTime(9, 0, 0);
    } elseif ($h > 18 || ($h === 18 && $m > 0)) {
        $d->add(new DateInterval('P1D'));
        $d->setTime(9, 0, 0);
    }

    $guard = 0;
    while (cnx_sched_is_weekend($d) && $guard < 10) {
        $d->add(new DateInterval('P1D'));
        $guard++;
    }
    return $d;
}

function cnx_sched_initial_cursor(DateTime $windowStart): DateTime {
    $today = new DateTime('today 00:00:00');
    $d = clone $windowStart;
    if ($d < $today) $d = $today;
    $d->setTime(9, 0, 0);
    // If we're past working hours, start next day.
    $now = new DateTime('now');
    if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
        $hour = (int)$now->format('H');
        if ($hour >= 18) {
            $d->add(new DateInterval('P1D'));
            $d->setTime(9, 0, 0);
        }
    }
    $guard = 0;
    while (cnx_sched_is_weekend($d) && $guard < 10) {
        $d->add(new DateInterval('P1D'));
        $d->setTime(9, 0, 0);
        $guard++;
    }
    return $d;
}

/**
 * Load phase library from configs/ (schema drift safe).
 * @return array<string,array{order:int,label:string}>
 */
function cnx_sched_phase_library(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = [];
    $p = __DIR__ . '/../../configs/scheduling/phase_library.php';
    if (is_file($p)) {
        $v = require $p;
        if (is_array($v)) $cache = $v;
    }
    if (empty($cache)) {
        $cache = [
            'kickoff' => ['order' => 10, 'label' => 'Kickoff / Pianificazione'],
            'data_collection' => ['order' => 15, 'label' => 'Raccolta dati / Input'],
            'context_scope' => ['order' => 20, 'label' => 'Analisi contesto / Scopo'],
            'process_mapping' => ['order' => 25, 'label' => 'Mappatura processi'],
            'risk_assessment' => ['order' => 30, 'label' => 'Valutazione rischi'],
            'documentation' => ['order' => 50, 'label' => 'Documentazione'],
            'implementation' => ['order' => 60, 'label' => 'Implementazione'],
            'internal_audit' => ['order' => 80, 'label' => 'Audit interno'],
            'management_review' => ['order' => 85, 'label' => 'Riesame di direzione'],
            'external_audit_support' => ['order' => 90, 'label' => 'Supporto audit esterno / certificazione'],
            'nc_closure' => ['order' => 95, 'label' => 'Chiusura NC'],
            'ongoing' => ['order' => 99, 'label' => 'Ongoing'],
            'gap_analysis' => ['order' => 20, 'label' => 'Analisi gap / contesto'],
            'cert_support' => ['order' => 90, 'label' => 'Supporto verifica / certificazione'],
        ];
    }
    return $cache;
}

/**
 * @return array{phase_key:string,phase_label:string,phase_order:int}
 */
function cnx_sched_phase_meta(string $title, string $desc): array {
    $label = '';
    $m = null;
    if (preg_match('/Fase:\s*(.+)$/iu', $desc, $m) && !empty($m[1])) {
        $label = trim((string)$m[1]);
    } elseif (preg_match('/Fase:\s*(.+)$/iu', $title, $m) && !empty($m[1])) {
        $label = trim((string)$m[1]);
    }
    $hay = mb_strtolower(trim($label !== '' ? $label : ($desc !== '' ? $desc : $title)));

    $key = 'ongoing';
    if (strpos($hay, 'kickoff') !== false || strpos($hay, 'pianific') !== false) $key = 'kickoff';
    elseif (strpos($hay, 'raccolta') !== false || strpos($hay, 'input') !== false) $key = 'data_collection';
    elseif (strpos($hay, 'contesto') !== false || strpos($hay, 'scopo') !== false || strpos($hay, 'campo') !== false || strpos($hay, 'gap') !== false) $key = 'context_scope';
    elseif (strpos($hay, 'mappatura') !== false || strpos($hay, 'process') !== false) $key = 'process_mapping';
    elseif (strpos($hay, 'risch') !== false) $key = 'risk_assessment';
    elseif (strpos($hay, 'obbligh') !== false || strpos($hay, 'conformit') !== false) $key = 'compliance_obligations';
    elseif (strpos($hay, 'progettaz') !== false || strpos($hay, 'design') !== false) $key = 'system_design';
    elseif (strpos($hay, 'documentaz') !== false || strpos($hay, 'manuale') !== false || strpos($hay, 'procedure') !== false) $key = 'documentation';
    elseif (strpos($hay, 'implementaz') !== false || strpos($hay, 'affianc') !== false || strpos($hay, 'formaz') !== false) $key = 'implementation';
    elseif (strpos($hay, 'monitor') !== false || strpos($hay, 'kpi') !== false) $key = 'monitoring';
    elseif (strpos($hay, 'audit interno') !== false) $key = 'internal_audit';
    elseif (strpos($hay, 'riesame') !== false) $key = 'management_review';
    elseif (strpos($hay, 'certific') !== false || strpos($hay, 'audit esterno') !== false || strpos($hay, 'supporto verifica') !== false) $key = 'external_audit_support';
    elseif (strpos($hay, 'chiusura') !== false && strpos($hay, 'nc') !== false) $key = 'nc_closure';

    $lib = cnx_sched_phase_library();
    $meta = $lib[$key] ?? null;
    $order = is_array($meta) && isset($meta['order']) ? (int)$meta['order'] : 99;
    $labelOut = $label !== '' ? $label : (is_array($meta) && isset($meta['label']) ? (string)$meta['label'] : $key);
    return ['phase_key' => $key, 'phase_label' => $labelOut, 'phase_order' => $order];
}

function cnx_sched_normalize_kind(string $activityType): string {
    $t = strtolower(trim($activityType));
    $valid = ['onsite','remote','call','communication','travel','other'];
    return in_array($t, $valid, true) ? $t : 'other';
}

function cnx_sched_minutes_for_item(string $kind, float $days, float $hours, ?int $callDurationOverrideMin, int $callDurationDefaultMin): int {
    $kind = cnx_sched_normalize_kind($kind);
    if ($kind === 'call' || $kind === 'communication') {
        $min = $callDurationOverrideMin !== null && $callDurationOverrideMin > 0 ? (int)$callDurationOverrideMin : 0;
        if ($min <= 0) {
            $planned = 0;
            if ($hours > 0) $planned = (int)round($hours * 60);
            elseif ($days > 0) $planned = (int)round($days * 240); // call-day = 4h => 0.125 = 30m, 0.25 = 60m
            $min = $planned > 0 ? $planned : $callDurationDefaultMin;
        }
        // Clamp 30–60 minutes as requested
        $min = max(30, min(60, (int)$min));
        return $min;
    }

    $min = 0;
    if ($hours > 0) {
        // For non-call kinds, hours are treated as "effort" but still scheduled in 0.5-day slots.
        $min = (int)round($hours * 60);
    } elseif ($days > 0) {
        // Enforce half-day granularity (0.5d) to avoid 0.25/0.3 artifacts.
        $d = (float)(round($days * 2.0) / 2.0);
        if ($d < 0.5) $d = 0.5;
        $min = (int)round($d * 8 * 60);
    }

    // Planning 2026: non-call work is always scheduled in 0.5-day (240m) blocks.
    if ($min < 240) $min = 240;
    $min = (int)(ceil($min / 240) * 240);
    return $min;
}

/**
 * Split planned minutes into standard session blocks:
 * - onsite/remote: 4h blocks (240m) + 2h blocks (120m)
 * - call/communication: single 30–60m
 *
 * @return array<int,int> list of session minutes
 */
function cnx_sched_split_sessions(string $kind, int $minutes): array {
    $kind = cnx_sched_normalize_kind($kind);
    $minutes = max(0, (int)$minutes);
    if ($kind === 'call' || $kind === 'communication') {
        return [$minutes];
    }
    if ($minutes <= 0) return [];

    // Planning 2026: all non-call sessions are 0.5-day blocks (240m).
    $blocks = (int)ceil($minutes / 240);
    if ($blocks < 1) $blocks = 1;
    return array_fill(0, $blocks, 240);
}

/**
 * @return array<int,string> allowed start times (HH:MM)
 */
function cnx_sched_allowed_start_times(string $kind, int $minutes): array {
    $kind = cnx_sched_normalize_kind($kind);
    $minutes = max(15, (int)$minutes);
    if ($kind === 'call' || $kind === 'communication') {
        // 30–60m: keep dense options
        return ['09:00','09:30','10:00','10:30','11:00','14:00','14:30','15:00','15:30','16:00'];
    }
    if ($minutes >= 240) return ['09:00','14:00'];
    if ($minutes >= 120) return ['09:00','11:00','14:00','16:00'];
    return ['09:00','10:00','11:00','14:00','15:00','16:00'];
}

/**
 * Check if [start,end] is fully contained in at least one free slot.
 * @param array<int,array{start:DateTime,end:DateTime}> $freeSlots
 */
function cnx_sched_is_within_free_slots(DateTime $start, DateTime $end, array $freeSlots): bool {
    foreach ($freeSlots as $s) {
        if (!is_array($s)) continue;
        $fs = $s['start'] ?? null;
        $fe = $s['end'] ?? null;
        if (!($fs instanceof DateTime) || !($fe instanceof DateTime) || $fe <= $fs) continue;
        if ($start >= $fs && $end <= $fe) return true;
    }
    return false;
}

/**
 * Deterministic next-slot finder (sequential scheduling):
 * - checks consultant calendar availability (real events)
 * - checks busy intervals for assignee + plan + client (no overlaps)
 *
 * @param array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}> $busyUser
 * @param array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}> $busyPlan
 * @param array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}> $busyClient
 * @return array{start:DateTime,end:DateTime,explain:array<string,mixed>}|null
 */
function cnx_sched_find_next_slot(
    Calendar $calendar,
    int $assignee,
    int $minutes,
    DateTime $cursor,
    DateTime $rangeEnd,
    array $allowedTimes,
    array $busyUser,
    array $busyPlan,
    array $busyClient
): ?array {
    $minutes = max(15, (int)$minutes);
    $allowedTimes = array_values(array_filter($allowedTimes, static fn($v) => is_string($v) && preg_match('/^\d{1,2}:\d{2}$/', trim($v))));
    if (empty($allowedTimes)) $allowedTimes = ['09:00','14:00'];

    $day = clone $cursor;
    $day->setTime(0, 0, 0);
    $endDay = clone $rangeEnd;
    $endDay->setTime(0, 0, 0);

    $guard = 0;
    while ($day <= $endDay && $guard < 400) {
        if (cnx_sched_is_weekend($day)) {
            $day->add(new DateInterval('P1D'));
            $guard++;
            continue;
        }

        $avail = $calendar->getUserAvailability($assignee, $day);
        $freeSlots = (isset($avail['free_slots']) && is_array($avail['free_slots'])) ? $avail['free_slots'] : [];

        foreach ($allowedTimes as $pt) {
            $pt = trim($pt);
            [$hh, $mm] = array_map('intval', explode(':', $pt));
            if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) continue;

            $st = clone $day;
            $st->setTime($hh, $mm, 0);
            if ($st < $cursor) continue;
            $en = (clone $st)->add(new DateInterval('PT' . $minutes . 'M'));
            if ($en <= $st) continue;
            if ($en > $rangeEnd) continue;

            if (!cnx_sched_is_within_free_slots($st, $en, $freeSlots)) continue;

            if (cnx_busy_find_overlap($busyUser, $st, $en)) continue;
            if (cnx_busy_find_overlap($busyPlan, $st, $en)) continue;
            if (cnx_busy_find_overlap($busyClient, $st, $en)) continue;

            return [
                'start' => $st,
                'end' => $en,
                'explain' => [
                    'strategy' => 'sequential_blocks',
                    'candidate_start' => $pt,
                ],
            ];
        }

        $day->add(new DateInterval('P1D'));
        $guard++;
    }

    return null;
}

/**
 * Add a busy interval to a flat list (plan/client).
 * @param array<int,array{start_ts:int,end_ts:int,source:string,draft_id?:int,plan_id?:int,status?:string}> $busy
 */
function cnx_busy_add_list(array &$busy, DateTime $start, DateTime $end, array $meta): void {
    if ($end <= $start) return;
    $busy[] = array_merge($meta, [
        'start_ts' => $start->getTimestamp(),
        'end_ts' => $end->getTimestamp(),
    ]);
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    // New flow gating: only enforce assignee requirement when estimate_json exists (migration 50),
    // to avoid breaking legacy plans created before allocation support.
    $isNewFlow = false;
    try {
        $isNewFlow = isset($plan['estimate_json']) && is_string($plan['estimate_json']) && trim($plan['estimate_json']) !== '';
    } catch (Throwable $e) {
        $isNewFlow = false;
    }

    // Load selected consultants
    $cpcHasHomeCity = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_consultants'
           AND COLUMN_NAME = 'home_city'
         LIMIT 1"
    );
    $cpcHasHomeLat = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_consultants'
           AND COLUMN_NAME = 'home_lat'
         LIMIT 1"
    );
    $cpcHasHomeLng = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_consultants'
           AND COLUMN_NAME = 'home_lng'
         LIMIT 1"
    );

    $selFields = "user_id";
    if ($cpcHasHomeCity) $selFields .= ", home_city";
    if ($cpcHasHomeLat) $selFields .= ", home_lat";
    if ($cpcHasHomeLng) $selFields .= ", home_lng";
    $consultants = $db->fetchAll("SELECT $selFields FROM consulting_plan_consultants WHERE plan_id = ?", [$planId]) ?: [];
    $consultantIds = [];
    $consultantHomeCityById = [];
    foreach ($consultants as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0) continue;
        $consultantIds[] = $uid;
        if ($cpcHasHomeCity) {
            $consultantHomeCityById[$uid] = trim((string)($r['home_city'] ?? ''));
        }
    }
    if (empty($consultantIds)) {
        api_error(
            'Seleziona e salva almeno un consulente S.CO (tab Consulenti → “Seleziona consulenti” → Salva) prima di generare la proposta',
            400,
            ['hint' => 'missing_plan_consultants']
        );
    }

    // Determine proposal window
    $startStr = (string)($plan['period_start'] ?? '');
    $endStr = (string)($plan['period_end'] ?? '');
    $windowStart = $startStr !== '' ? new DateTime($startStr . ' 00:00:00') : new DateTime('today');
    if ($endStr !== '') {
        $windowEnd = new DateTime($endStr . ' 23:59:59');
    } else {
        // Default: compact window (Planning 2026): next 30 days
        $windowEnd = (clone $windowStart)->add(new DateInterval('P30D'));
        $windowEnd->setTime(23, 59, 59);
    }
    if ($windowEnd < $windowStart) {
        // swap
        $tmp = $windowStart;
        $windowStart = $windowEnd;
        $windowEnd = $tmp;
    }

    // Preferences
    $durationDefault = (int)($payload['call_duration_minutes_default'] ?? 30);
    if ($durationDefault <= 0) $durationDefault = 30;
    $preferredTimes = $payload['preferred_times'] ?? ['09:00', '14:00'];
    if (!is_array($preferredTimes) || empty($preferredTimes)) $preferredTimes = ['09:00', '14:00'];

    $preferences = [
        'preferred_times' => $preferredTimes,
        'avoid_lunch' => true,
        'skip_weekends' => true,
        'max_suggestions' => 3,
    ];

    // Calendar context: tenant 28 (S.CO), for conflict checking
    $pdo = Database::getInstance()->getConnection();
    $apiUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $calendar = new Calendar($pdo, CNX_VENDOR_TENANT_ID, $apiUserId);

    // Soft-delete previous draft rows (keep confirmed)
    $db->update(
        'consulting_plan_schedule_drafts',
        ['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ['plan_id' => $planId, 'status' => 'draft']
    );

    // Load items
    $items = $db->fetchAll(
        "SELECT *
         FROM consulting_plan_items
         WHERE plan_id = ? AND deleted_at IS NULL
         ORDER BY id ASC",
        [$planId]
    ) ?: [];

    // Compact timeline (best-effort):
    // If there is no explicit deadline/target date, don't spread activities across the whole year.
    // Use 60–120 days horizon depending on total planned effort.
    $totalPlannedMinutes = 0;
    foreach ($items as $it) {
        $d = (float)($it['days'] ?? 0);
        $h = (float)($it['hours'] ?? 0);
        if ($h > 0) $totalPlannedMinutes += (int)round($h * 60);
        elseif ($d > 0) $totalPlannedMinutes += (int)round($d * 8 * 60);
    }
    $totalPlannedDays = $totalPlannedMinutes > 0 ? ($totalPlannedMinutes / (8 * 60)) : 0.0;

    $deadlineDate = null;
    try {
        $rawEst = isset($plan['estimate_json']) ? (string)$plan['estimate_json'] : '';
        if (trim($rawEst) !== '') {
            $est = json_decode($rawEst, true);
            $dl =
                $est['input_company_profile']['preferred_delivery_deadline'] ??
                $est['company_profile_inferred']['preferred_delivery_deadline'] ??
                null;
            if (is_string($dl)) {
                $dl = trim($dl);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) {
                    $deadlineDate = $dl;
                }
            }
        }
    } catch (Throwable $e) {
        $deadlineDate = null;
    }

    // Treat year-end as "non explicit" when it matches the default window (avoid full-year spreading).
    $yearEndStr = $windowStart->format('Y') . '-12-31';
    if (is_string($deadlineDate) && trim($deadlineDate) === $yearEndStr) {
        $endLooksDefault = ($endStr === '' || trim($endStr) === $yearEndStr);
        if ($endLooksDefault) {
            $deadlineDate = null;
        }
    }

    if (is_string($deadlineDate) && $deadlineDate !== '') {
        try {
            $deadlineEnd = new DateTime($deadlineDate . ' 23:59:59');
            if ($deadlineEnd < $windowEnd) $windowEnd = $deadlineEnd;
        } catch (Throwable $e) {
            // ignore invalid deadline
        }
    } else {
        // Treat end-of-year as "no target" (wizard default)
        $endLooksDefault = ($endStr === '' || trim($endStr) === $yearEndStr);
        if ($endLooksDefault) {
            $spanDays = (int)round($totalPlannedDays * 12); // 10 days ~ 120 days
            $spanDays = max(60, min(120, $spanDays));
            $compactEnd = (clone $windowStart)->add(new DateInterval('P' . $spanDays . 'D'));
            $compactEnd->setTime(23, 59, 59);
            if ($compactEnd < $windowEnd) {
                $windowEnd = $compactEnd;
            }
        }
    }
    if ($windowEnd < $windowStart) {
        $windowEnd = clone $windowStart;
        $windowEnd->setTime(23, 59, 59);
    }

    // Intervention type (from estimate_json) - used to place "supporto audit esterno" near end of period for RECERT.
    $planInterventionType = null;
    try {
        $rawEst = isset($plan['estimate_json']) ? (string)$plan['estimate_json'] : '';
        if (trim($rawEst) !== '') {
            $est = json_decode($rawEst, true);
            $it =
                $est['company_profile_inferred']['intervention_type'] ??
                $est['input_company_profile']['intervention_type'] ??
                null;
            if (is_string($it)) {
                $it = strtolower(trim($it));
                if ($it !== '') $planInterventionType = $it;
            }
        }
    } catch (Throwable $e) {
        $planInterventionType = null;
    }
    $isRecert = ($planInterventionType === 'recertification');
    $recertTailStart = null;      // e.g. internal audit + management review window
    $recertExternalStart = null;  // e.g. external audit support window
    if ($isRecert) {
        try {
            $recertTailStart = (clone $windowEnd)->sub(new DateInterval('P45D'));
            if ($recertTailStart < $windowStart) $recertTailStart = clone $windowStart;
            $recertTailStart->setTime(9, 0, 0);
        } catch (Throwable $e) { $recertTailStart = null; }
        try {
            $recertExternalStart = (clone $windowEnd)->sub(new DateInterval('P30D'));
            if ($recertExternalStart < $windowStart) $recertExternalStart = clone $windowStart;
            $recertExternalStart->setTime(9, 0, 0);
        } catch (Throwable $e) { $recertExternalStart = null; }
    }

    // Collect non-blocking warnings for the response (not "forced slot" warnings).
    $metaWarnings = [];

    // Require home city when onsite activities exist (travel optimization + realistic clustering)
    $planHasOnsite = false;
    foreach ($items as $it) {
        if (strtolower((string)($it['activity_type'] ?? '')) === 'onsite') {
            $planHasOnsite = true;
            break;
        }
    }
    if ($planHasOnsite) {
        if (!$cpcHasHomeCity) {
            // Backward-compatible: travel optimization is best-effort; don't block schedule generation.
            $metaWarnings[] = 'Home city consulenti non disponibile (migrazione 52 non applicata): ottimizzazione trasferte disattivata.';
        } else {
            $missing = [];
            foreach ($consultantIds as $uid) {
                $v = trim((string)($consultantHomeCityById[$uid] ?? ''));
                if ($v === '') $missing[] = (int)$uid;
            }
            if (!empty($missing)) {
                $metaWarnings[] = 'Città di partenza mancante per uno o più consulenti: ottimizzazione trasferte parziale.';
            }

            // Validate that provided cities actually exist (so travel optimization can work).
            // Backward-compatible: invalid values degrade to "unknown" instead of blocking.
            foreach ($consultantIds as $uid) {
                $v = trim((string)($consultantHomeCityById[$uid] ?? ''));
                if ($v === '') continue;
                $norm = cnx_locations_normalize_city_input($v);
                if ($norm === '' || !cnx_locations_city_exists($db, $norm)) {
                    $consultantHomeCityById[(int)$uid] = '';
                } else {
                    $consultantHomeCityById[(int)$uid] = $norm;
                }
            }
        }
    }

    // Draft schema (migration 53 optional)
    $draftCols = [];
    try {
        $colRows = $db->fetchAll("SHOW COLUMNS FROM consulting_plan_schedule_drafts") ?: [];
        foreach ($colRows as $r) {
            if (!empty($r['Field'])) $draftCols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $draftCols = [];
    }
    $hasLocationCityCol = !empty($draftCols['location_city']);
    $hasExplainCol = !empty($draftCols['explain_json']);

    // Default client city for onsite/travel (best-effort)
    // Prefer wizard-selected locations stored in consulting_plans.estimate_json (if present),
    // otherwise fallback to tenant primary location.
    $selectedSiteCities = [];
    if ($planHasOnsite) {
        try {
            $rawEst = isset($plan['estimate_json']) ? (string)$plan['estimate_json'] : '';
            if ($rawEst !== '') {
                $est = json_decode($rawEst, true);
                $sel = $est['meta']['client_locations']['selected'] ?? null;
                if (is_array($sel)) {
                    foreach ($sel as $loc) {
                        if (!is_array($loc)) continue;
                        $c = trim((string)($loc['comune'] ?? ''));
                        if ($c !== '') $selectedSiteCities[] = $c;
                    }
                }
            }
        } catch (Throwable $e) {
            $selectedSiteCities = [];
        }
        $selectedSiteCities = array_values(array_unique(array_filter($selectedSiteCities, static fn($v) => $v !== '')));
    }
    $clientCity = $planHasOnsite
        ? (($selectedSiteCities[0] ?? '') !== '' ? $selectedSiteCities[0] : cnx_consulting_get_client_city($db, $clientTenantId))
        : null;
    $clientCityNorm = $clientCity ? cnx_norm_city($clientCity) : '';

    // If clientCity is unknown (non valid municipality), fallback to tenant primary city, otherwise keep null.
    if ($planHasOnsite && $clientCity !== null) {
        $cc = trim((string)$clientCity);
        if ($cc !== '' && !cnx_locations_city_exists($db, $cc)) {
            $fallback = cnx_consulting_get_client_city($db, $clientTenantId);
            if ($fallback && cnx_locations_city_exists($db, (string)$fallback)) {
                $clientCity = $fallback;
                $clientCityNorm = cnx_norm_city($clientCity);
            } else {
                $clientCity = null;
                $clientCityNorm = '';
            }
        }
    }

    // Preload busy intervals from OTHER active drafts (draft+confirmed) so we never overlap across plans
    $busyByUser = [];
    try {
        if (!empty($consultantIds)) {
            $ph = implode(',', array_fill(0, count($consultantIds), '?'));
            $busyRows = $db->fetchAll(
                "SELECT id, plan_id, assigned_user_id, start_datetime, end_datetime, status
                 FROM consulting_plan_schedule_drafts
                 WHERE deleted_at IS NULL
                   AND status IN ('draft','confirmed')
                   AND assigned_user_id IN ($ph)
                   AND start_datetime < ?
                   AND end_datetime > ?",
                array_merge(
                    $consultantIds,
                    [$windowEnd->format('Y-m-d H:i:s'), $windowStart->format('Y-m-d H:i:s')]
                )
            ) ?: [];
            foreach ($busyRows as $br) {
                $uid = (int)($br['assigned_user_id'] ?? 0);
                if ($uid <= 0) continue;
                try {
                    $bs = new DateTime((string)($br['start_datetime'] ?? ''));
                    $be = new DateTime((string)($br['end_datetime'] ?? ''));
                } catch (Throwable $e) {
                    continue;
                }
                if ($be <= $bs) continue;
                cnx_busy_add($busyByUser, $uid, $bs, $be, [
                    'source' => 'draft',
                    'draft_id' => (int)($br['id'] ?? 0),
                    'plan_id' => (int)($br['plan_id'] ?? 0),
                    'status' => (string)($br['status'] ?? ''),
                ]);
            }
        }
    } catch (Throwable $e) {
        // non-blocking
        $busyByUser = [];
    }

    // Optional assignee support (migration 50)
    $hasAssigneeCol = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'assignee_user_id'
         LIMIT 1"
    );
    if ($hasAssigneeCol && $isNewFlow) {
        $anyAssigned = false;
        foreach ($items as $it) {
            $uid = (int)($it['assignee_user_id'] ?? 0);
            if ($uid > 0) { $anyAssigned = true; break; }
        }
        if (!$anyAssigned) {
            api_error('Assegna prima i consulenti alle attività (allocazione) prima di generare la bozza calendario', 400);
        }
    }

    $created = [];
    $errors = [];
    // Forced-slot warnings (used to count "FORZATO" proposals)
    $warnings = [];
    $rr = 0;
    $onsiteLoadMinutesByUser = [];
    $fatalNoSlot = false;

    // =============================================================
    // NEW (2026-01): Phase-ordered, sequential scheduling (best-effort)
    // Goals:
    // - Respect ISO9001 phase order (Kickoff → Analisi → Documentazione → Implementazione → Audit → Riesame → Certificazione)
    // - No overlap within the same plan/client, even with different consultants
    // - Convert planned days into realistic blocks (onsite/remote) and 30–60 min calls
    // =============================================================

    $minGapDays = isset($payload['min_gap_days']) ? (int)($payload['min_gap_days']) : 3;
    if ($minGapDays < 0) $minGapDays = 3;

    $hasCallDurOverrideCol = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'consulting_plan_items'
           AND COLUMN_NAME = 'call_duration_minutes_override'
         LIMIT 1"
    );

    // Busy intervals: existing CONFIRMED slots for this plan/client
    $busyPlan = [];
    $busyClient = [];
    try {
        $planBusyRows = $db->fetchAll(
            "SELECT id, plan_id, client_tenant_id, start_datetime, end_datetime, status
             FROM consulting_plan_schedule_drafts
             WHERE deleted_at IS NULL
               AND status = 'confirmed'
               AND plan_id = ?
               AND start_datetime < ?
               AND end_datetime > ?",
            [$planId, $windowEnd->format('Y-m-d H:i:s'), $windowStart->format('Y-m-d H:i:s')]
        ) ?: [];
        foreach ($planBusyRows as $br) {
            try {
                $bs = new DateTime((string)($br['start_datetime'] ?? ''));
                $be = new DateTime((string)($br['end_datetime'] ?? ''));
            } catch (Throwable $e) {
                continue;
            }
            if ($be <= $bs) continue;
            cnx_busy_add_list($busyPlan, $bs, $be, [
                'source' => 'confirmed_plan',
                'draft_id' => (int)($br['id'] ?? 0),
                'plan_id' => (int)($br['plan_id'] ?? 0),
                'status' => (string)($br['status'] ?? ''),
            ]);
            cnx_busy_add_list($busyClient, $bs, $be, [
                'source' => 'confirmed_plan',
                'draft_id' => (int)($br['id'] ?? 0),
                'plan_id' => (int)($br['plan_id'] ?? 0),
                'status' => (string)($br['status'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        // non-blocking
        $busyPlan = [];
        $busyClient = [];
    }
    try {
        if ($clientTenantId > 0) {
            $clientBusyRows = $db->fetchAll(
                "SELECT id, plan_id, start_datetime, end_datetime, status
                 FROM consulting_plan_schedule_drafts
                 WHERE deleted_at IS NULL
                   AND status = 'confirmed'
                   AND client_tenant_id = ?
                   AND plan_id <> ?
                   AND start_datetime < ?
                   AND end_datetime > ?",
                [$clientTenantId, $planId, $windowEnd->format('Y-m-d H:i:s'), $windowStart->format('Y-m-d H:i:s')]
            ) ?: [];
            foreach ($clientBusyRows as $br) {
                try {
                    $bs = new DateTime((string)($br['start_datetime'] ?? ''));
                    $be = new DateTime((string)($br['end_datetime'] ?? ''));
                } catch (Throwable $e) {
                    continue;
                }
                if ($be <= $bs) continue;
                cnx_busy_add_list($busyClient, $bs, $be, [
                    'source' => 'confirmed_client',
                    'draft_id' => (int)($br['id'] ?? 0),
                    'plan_id' => (int)($br['plan_id'] ?? 0),
                    'status' => (string)($br['status'] ?? ''),
                ]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Build phase-aware worklist from plan items
    $phaseLib = cnx_sched_phase_library();
    $workItems = [];
    foreach ($items as $it) {
        $days = (float)($it['days'] ?? 0);
        $hours = (float)($it['hours'] ?? 0);
        $activityType = (string)($it['activity_type'] ?? 'remote');
        $kind = cnx_sched_normalize_kind($activityType);
        $activityDate = trim((string)($it['activity_date'] ?? ''));
        $desc = (string)($it['description'] ?? '');

        $hasEffort = ($days > 0 || $hours > 0);
        if (!$hasEffort && !in_array($kind, ['call','communication'], true)) {
            continue;
        }

        $callDurOv = null;
        if ($hasCallDurOverrideCol) {
            $v = (int)($it['call_duration_minutes_override'] ?? 0);
            if ($v > 0) $callDurOv = $v;
        }

        $plannedMin = cnx_sched_minutes_for_item($kind, $days, $hours, $callDurOv, $durationDefault);
        if ($plannedMin <= 0) continue;

        $titleBase = 'Piano #' . $planId . ' - ' . strtoupper($kind) . ' - ' . ($desc !== '' ? mb_substr($desc, 0, 120) : 'Attività');

        // Prefer explicit phase fields from consulting_plan_items (migration 62); fallback to heuristics
        $phaseKeyDb = array_key_exists('phase_key', $it) ? trim((string)($it['phase_key'] ?? '')) : '';
        $phaseOrderDb = array_key_exists('phase_order', $it) ? (int)($it['phase_order'] ?? 0) : 0;
        $phaseLabelDb = '';
        if ($phaseKeyDb !== '') {
            $meta = $phaseLib[$phaseKeyDb] ?? $phaseLib[strtolower($phaseKeyDb)] ?? null;
            if ($phaseOrderDb <= 0 && is_array($meta) && isset($meta['order'])) $phaseOrderDb = (int)$meta['order'];
            if (is_array($meta) && isset($meta['label'])) $phaseLabelDb = (string)$meta['label'];
        }
        $phase = null;
        if ($phaseKeyDb !== '' && $phaseOrderDb > 0) {
            $phase = ['phase_key' => $phaseKeyDb, 'phase_label' => ($phaseLabelDb !== '' ? $phaseLabelDb : $phaseKeyDb), 'phase_order' => $phaseOrderDb];
        } else {
            $phase = cnx_sched_phase_meta($titleBase, $desc);
        }

        // Preferred assignee from item when available; fallback to round-robin / travel heuristic
        $preferredAssignee = $hasAssigneeCol ? (int)($it['assignee_user_id'] ?? 0) : 0;
        if ($preferredAssignee > 0 && !in_array($preferredAssignee, $consultantIds, true)) {
            $preferredAssignee = 0;
        }

        $mergeKey = array_key_exists('merge_key', $it) ? trim((string)($it['merge_key'] ?? '')) : '';
        $clientBlocking = true;
        if (array_key_exists('client_blocking', $it) && $it['client_blocking'] !== null && $it['client_blocking'] !== '') {
            $clientBlocking = ((int)$it['client_blocking']) ? true : false;
        }

        $workItems[] = [
            'it' => $it,
            'id' => (int)($it['id'] ?? 0),
            'kind' => $kind,
            'activity_date' => $activityDate,
            'desc' => $desc,
            'planned_minutes' => $plannedMin,
            'phase' => $phase,
            'preferred_assignee' => $preferredAssignee,
            'merge_key' => $mergeKey,
            'client_blocking' => $clientBlocking,
        ];
    }

    usort($workItems, static function($a, $b) {
        $ao = (int)($a['phase']['phase_order'] ?? 99);
        $bo = (int)($b['phase']['phase_order'] ?? 99);
        if ($ao !== $bo) return $ao <=> $bo;
        $aid = (int)($a['id'] ?? 0);
        $bid = (int)($b['id'] ?? 0);
        return $aid <=> $bid;
    });

    // Best-effort merging across services:
    // If multiple plan items share the same merge_key within the same phase_order and same fixed date,
    // merge them into a single scheduled block (sum duration).
    $mergedOut = [];
    $agg = [];
    $emitted = [];
    foreach ($workItems as $wi) {
        $mk = trim((string)($wi['merge_key'] ?? ''));
        $phaseOrder = (int)($wi['phase']['phase_order'] ?? 99);
        $ad = (string)($wi['activity_date'] ?? '');
        if ($mk === '') {
            $mergedOut[] = $wi;
            continue;
        }
        $k = $phaseOrder . '|' . $ad . '|' . $mk;
        if (!isset($agg[$k])) {
            $agg[$k] = $wi;
            $agg[$k]['merged_ids'] = [(int)($wi['id'] ?? 0)];
            $agg[$k]['merged_descs'] = [trim((string)($wi['desc'] ?? ''))];
        } else {
            $agg[$k]['planned_minutes'] = (int)($agg[$k]['planned_minutes'] ?? 0) + (int)($wi['planned_minutes'] ?? 0);
            $agg[$k]['client_blocking'] = !empty($agg[$k]['client_blocking']) || !empty($wi['client_blocking']);
            $agg[$k]['merged_ids'][] = (int)($wi['id'] ?? 0);
            $d = trim((string)($wi['desc'] ?? ''));
            if ($d !== '') $agg[$k]['merged_descs'][] = $d;

            // Kind: prefer onsite > remote > call > other
            $rank = ['onsite' => 4, 'travel' => 4, 'remote' => 3, 'call' => 2, 'communication' => 2, 'other' => 1];
            $cur = (string)($agg[$k]['kind'] ?? 'other');
            $new = (string)($wi['kind'] ?? 'other');
            $rc = (int)($rank[$cur] ?? 1);
            $rn = (int)($rank[$new] ?? 1);
            if ($rn > $rc) {
                $agg[$k]['kind'] = $new;
            }

            // Prefer a specific assignee if already set
            $pa = (int)($agg[$k]['preferred_assignee'] ?? 0);
            $pb = (int)($wi['preferred_assignee'] ?? 0);
            if ($pa <= 0 && $pb > 0) $agg[$k]['preferred_assignee'] = $pb;
        }
    }

    foreach ($workItems as $wi) {
        $mk = trim((string)($wi['merge_key'] ?? ''));
        if ($mk === '') continue;
        $phaseOrder = (int)($wi['phase']['phase_order'] ?? 99);
        $ad = (string)($wi['activity_date'] ?? '');
        $k = $phaseOrder . '|' . $ad . '|' . $mk;
        if (isset($emitted[$k])) continue;
        $emitted[$k] = true;

        $m = $agg[$k] ?? null;
        if (!is_array($m)) continue;
        $descs = is_array($m['merged_descs'] ?? null) ? $m['merged_descs'] : [];
        $descs = array_values(array_unique(array_filter(array_map(static fn($s) => trim((string)$s), $descs), static fn($s) => $s !== '')));
        if (count($descs) > 1) {
            $m['desc'] = 'Multi-servizio: ' . implode(' | ', array_slice($descs, 0, 3));
        }
        $mergedOut[] = $m;
    }

    // Re-sort merged list by phase_order, then by id (stable)
    usort($mergedOut, static function($a, $b) {
        $ao = (int)($a['phase']['phase_order'] ?? 99);
        $bo = (int)($b['phase']['phase_order'] ?? 99);
        if ($ao !== $bo) return $ao <=> $bo;
        $aid = (int)($a['id'] ?? 0);
        $bid = (int)($b['id'] ?? 0);
        return $aid <=> $bid;
    });
    $workItems = $mergedOut;

    $cursor = cnx_sched_initial_cursor($windowStart);
    $prevPhaseOrder = null;

    foreach ($workItems as $wi) {
        $it = $wi['it'];
        $kind = (string)$wi['kind'];
        $desc = (string)$wi['desc'];
        $activityDate = (string)$wi['activity_date'];
        $plannedMin = (int)$wi['planned_minutes'];
        $phase = is_array($wi['phase'] ?? null) ? $wi['phase'] : ['phase_key' => 'other', 'phase_label' => '', 'phase_order' => 50];
        $phaseOrder = (int)($phase['phase_order'] ?? 50);
        $phaseKey = (string)($phase['phase_key'] ?? 'other');
        $phaseLabel = (string)($phase['phase_label'] ?? '');
        $mergeKey = trim((string)($wi['merge_key'] ?? ''));
        $clientBlocking = array_key_exists('client_blocking', $wi) ? (bool)($wi['client_blocking'] ?? true) : true;

        $gapApplied = 0;
        if ($prevPhaseOrder !== null && $phaseOrder !== $prevPhaseOrder) {
            $gapApplied = $minGapDays;
            $cursor = cnx_sched_apply_phase_gap($cursor, $gapApplied);
        }
        $prevPhaseOrder = $phaseOrder;

        // RECERT: keep the final phases closer to the end of period (best-effort),
        // unless the item has a fixed activity_date.
        if ($isRecert && $activityDate === '') {
            if (in_array($phaseKey, ['internal_audit', 'management_review'], true)) {
                if (($recertTailStart instanceof DateTime) && $cursor < $recertTailStart) {
                    $cursor = clone $recertTailStart;
                }
            }
            if (in_array($phaseKey, ['external_audit_support', 'cert_support'], true)) {
                if (($recertExternalStart instanceof DateTime) && $cursor < $recertExternalStart) {
                    $cursor = clone $recertExternalStart;
                }
            }
        }

        // Determine assignee
        $assignee = (int)($wi['preferred_assignee'] ?? 0);
        $isTravelRelevant = in_array($kind, ['onsite','travel'], true);
        if ($assignee <= 0) {
            if ($isTravelRelevant && $planHasOnsite && $clientCityNorm !== '') {
                $best = 0;
                $bestLoad = null;
                foreach ($consultantIds as $cid) {
                    $hc = trim((string)($consultantHomeCityById[(int)$cid] ?? ''));
                    if ($hc === '') continue;
                    if (cnx_norm_city($hc) !== $clientCityNorm) continue;
                    $load = (int)($onsiteLoadMinutesByUser[(int)$cid] ?? 0);
                    if ($best === 0 || $bestLoad === null || $load < (int)$bestLoad) {
                        $best = (int)$cid;
                        $bestLoad = $load;
                    }
                }
                if ($best > 0) $assignee = $best;
            }
            if ($assignee <= 0) {
                $assignee = (int)$consultantIds[$rr % count($consultantIds)];
                $rr++;
            }
        }

        // Fixed-date constraint (optional)
        $searchEnd = clone $windowEnd;
        if ($activityDate !== '') {
            try {
                $dayStart = new DateTime($activityDate . ' 00:00:00');
                $dayEnd = new DateTime($activityDate . ' 23:59:59');
                if ($cursor < $dayStart) {
                    $cursor = clone $dayStart;
                    $cursor->setTime(9, 0, 0);
                }
                if ($dayEnd < $searchEnd) $searchEnd = $dayEnd;
            } catch (Throwable $e) {
                // ignore invalid fixed date
            }
        }

        $sessions = cnx_sched_split_sessions($kind, $plannedMin);
        $totalSessions = count($sessions);
        foreach ($sessions as $si => $sessMin) {
            $sessMin = max(15, (int)$sessMin);
            $allowedTimes = cnx_sched_allowed_start_times($kind, $sessMin);

            $pick = cnx_sched_find_next_slot(
                $calendar,
                $assignee,
                $sessMin,
                $cursor,
                $searchEnd,
                $allowedTimes,
                $busyByUser[$assignee] ?? [],
                ($clientBlocking ? $busyPlan : []),
                ($clientBlocking ? $busyClient : [])
            );

            if (!$pick) {
                // Hard constraint: never overlap real calendar events or other drafts.
                // If no slot is available in the window, abort and return 409 (caller can widen the window).
                $errors[] = [
                    'kind' => $kind,
                    'assignee_user_id' => $assignee,
                    'phase_order' => $phaseOrder,
                    'phase_key' => $phaseKey,
                    'reason' => 'no_free_slot_in_window',
                    'minutes' => $sessMin,
                    'cursor' => $cursor->format('Y-m-d H:i:s'),
                    'search_end' => $searchEnd->format('Y-m-d H:i:s'),
                    'allowed_times' => $allowedTimes,
                    'client_blocking' => $clientBlocking,
                ];
                $fatalNoSlot = true;
                break 2;
            }

            $slotStart = $pick['start'];
            $slotEnd = $pick['end'];

            $titleCore = $desc !== '' ? mb_substr($desc, 0, 120) : ($phaseLabel !== '' ? $phaseLabel : 'Attività');
            $title = 'Piano #' . $planId . ' - ' . strtoupper($kind) . ' - ' . $titleCore;
            if ($totalSessions > 1) {
                $title .= ' (' . ($si + 1) . '/' . $totalSessions . ')';
            }

            $ins = [
                'plan_id' => $planId,
                'kind' => $kind,
                'title' => $title,
                'start_datetime' => $slotStart->format('Y-m-d H:i:s'),
                'end_datetime' => $slotEnd->format('Y-m-d H:i:s'),
                'assigned_user_id' => $assignee,
                'client_tenant_id' => $clientTenantId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasLocationCityCol && $isTravelRelevant) $ins['location_city'] = $clientCity;
            if ($hasExplainCol) {
                $reasonTags = [];
                $reasonTags[] = 'fase ' . $phaseOrder;
                if ($gapApplied > 0 && $si === 0) $reasonTags[] = 'buffer ' . $gapApplied . 'gg';
                $strategy = '';
                $rangeLbl = '';
                if (isset($pick['explain']) && is_array($pick['explain'])) {
                    $strategy = trim((string)($pick['explain']['strategy'] ?? ''));
                    $rangeLbl = trim((string)($pick['explain']['range'] ?? ''));
                }
                if ($strategy !== '') $reasonTags[] = 'strategy ' . $strategy;
                if ($rangeLbl !== '') $reasonTags[] = 'range ' . $rangeLbl;
                $reasonTags[] = 'no overlap consulente';
                if ($clientBlocking) {
                    $reasonTags[] = 'no overlap piano';
                    $reasonTags[] = 'no overlap cliente';
                } else {
                    $reasonTags[] = 'non bloccante cliente';
                }
                $reasonTags[] = 'slot ' . $slotStart->format('H:i') . '-' . $slotEnd->format('H:i');

                $explain = [
                    'phase' => [
                        'key' => $phaseKey,
                        'order' => $phaseOrder,
                        'label' => $phaseLabel,
                    ],
                    'client_blocking' => $clientBlocking,
                    'merge_key' => ($mergeKey !== '' ? $mergeKey : null),
                    'duration' => [
                        'planned_minutes' => $plannedMin,
                        'session_minutes' => $sessMin,
                        'kind' => $kind,
                        'sessions_total' => $totalSessions,
                        'session_idx' => $si + 1,
                    ],
                    'rules' => [
                        'min_gap_days' => $minGapDays,
                        'gap_applied_days' => ($gapApplied > 0 && $si === 0) ? $gapApplied : 0,
                        'no_overlap' => [
                            'assignee' => true,
                            'plan' => $clientBlocking,
                            'client' => $clientBlocking,
                        ],
                    ],
                    'reason' => [
                        'tags' => $reasonTags,
                    ],
                    'slot' => $pick['explain'] ?? null,
                ];
                $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (!empty($draftCols)) {
                // Schema-drift safety: only insert known columns.
                $ins = array_intersect_key($ins, $draftCols);
            }
            $id = $db->insert('consulting_plan_schedule_drafts', $ins);
            if ($id) {
                $created[] = (int)$id;
                cnx_busy_add($busyByUser, $assignee, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                cnx_busy_add_list($busyPlan, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                cnx_busy_add_list($busyClient, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                if ($isTravelRelevant) {
                    $onsiteLoadMinutesByUser[$assignee] = ($onsiteLoadMinutesByUser[$assignee] ?? 0) + $sessMin;
                }
            }

            $cursor = clone $slotEnd;
        }
    }

    // If we couldn't schedule at least one mandatory session, cleanup and return 409.
    if ($fatalNoSlot) {
        try {
            if (!empty($created)) {
                $ph = implode(',', array_fill(0, count($created), '?'));
                $now = date('Y-m-d H:i:s');
                if (!empty($draftCols['deleted_at'])) {
                    $setParts = ["deleted_at = ?"];
                    $params = [$now];
                    if (!empty($draftCols['updated_at'])) {
                        $setParts[] = "updated_at = ?";
                        $params[] = $now;
                    }
                    $sql = "UPDATE consulting_plan_schedule_drafts SET " . implode(', ', $setParts) . " WHERE id IN ($ph)";
                    $db->query($sql, array_merge($params, $created));
                } else {
                    $db->query("DELETE FROM consulting_plan_schedule_drafts WHERE id IN ($ph)", $created);
                }
            }
        } catch (Throwable $e) {
            // non-blocking: cleanup best-effort
        }

        api_error(
            'Impossibile trovare slot liberi nella finestra selezionata. Suggerimento: amplia la finestra (Scadenza/fine periodo), cambia consulente o rimuovi conflitti calendario.',
            409,
            [
                'plan_id' => $planId,
                'errors' => $errors,
                'meta_warnings' => $metaWarnings,
                'window' => [
                    'start' => $windowStart->format('Y-m-d'),
                    'end' => $windowEnd->format('Y-m-d'),
                ],
            ]
        );
    }

    // Calls/feedback (best-effort): schedule AFTER phase-based work, spaced by catalog call_every_days.
    $callDraftsToCreate = [];
    $daysByService = []; // serviceId => totalDays
    $assigneeByService = []; // serviceId => userId (primary)
    if (!empty($items)) {
        $perServicePerAssignee = [];
        foreach ($items as $it) {
            $domainTypeId = (int)($it['domain_activity_type_id'] ?? 0);
            if ($domainTypeId <= 0) continue;
            $days = (float)($it['days'] ?? 0);
            if ($days <= 0) continue;
            $daysByService[$domainTypeId] = ($daysByService[$domainTypeId] ?? 0.0) + $days;
            $uid = $hasAssigneeCol ? (int)($it['assignee_user_id'] ?? 0) : 0;
            if ($uid > 0) {
                $perServicePerAssignee[$domainTypeId][$uid] = ($perServicePerAssignee[$domainTypeId][$uid] ?? 0.0) + $days;
            }
        }
        foreach ($perServicePerAssignee as $sid => $map) {
            arsort($map);
            $topUid = (int)array_key_first($map);
            if ($topUid > 0 && in_array($topUid, $consultantIds, true)) {
                $assigneeByService[(int)$sid] = $topUid;
            }
        }
    }

    foreach ($daysByService as $domainTypeId => $totalDays) {
        $domainTypeId = (int)$domainTypeId;
        $totalDays = (float)$totalDays;
        if ($domainTypeId <= 0 || $totalDays <= 0) continue;

        $eff = cnx_resolve_effective_activity_type($db, $domainTypeId, $clientTenantId);
        if (!$eff) continue;

        $callEvery = (int)$eff['call_every_days'];
        if ($callEvery <= 0) $callEvery = 7;

        $callDur = (int)$eff['call_duration_minutes'];
        if ($callDur <= 0) $callDur = $durationDefault;
        $callDur = max(30, min(60, $callDur)); // clamp 30–60

        $numCalls = (int)ceil($totalDays / $callEvery);
        if ($numCalls <= 0) $numCalls = 1;

        $assignee = (int)($assigneeByService[$domainTypeId] ?? 0);
        if ($assignee <= 0) {
            $assignee = (int)$consultantIds[$rr % count($consultantIds)];
            $rr++;
        }

        for ($i = 0; $i < $numCalls; $i++) {
            $callDraftsToCreate[] = [
                'service_id' => $domainTypeId,
                'type_name' => $eff['name'],
                'duration' => $callDur,
                'idx' => $i,
                'total' => $numCalls,
                'assignee' => $assignee,
                'call_every_days' => $callEvery,
            ];
        }
    }

    if (!empty($callDraftsToCreate)) {
        // Start calls after the last scheduled slot (if any), otherwise from initial cursor
        $callCursorByService = [];
        foreach ($callDraftsToCreate as $cd) {
            $serviceId = (int)($cd['service_id'] ?? 0);
            $assignee = (int)($cd['assignee'] ?? 0);
            if ($assignee <= 0 || !in_array($assignee, $consultantIds, true)) {
                $assignee = (int)$consultantIds[$rr % count($consultantIds)];
                $rr++;
            }

            $serviceCursor = $callCursorByService[$serviceId] ?? clone $cursor;
            if (!($serviceCursor instanceof DateTime)) $serviceCursor = clone $cursor;

            $callEvery = (int)($cd['call_every_days'] ?? 7);
            $idx = (int)($cd['idx'] ?? 0);
            if ($idx > 0) {
                $serviceCursor = cnx_sched_apply_phase_gap($serviceCursor, max(7, $callEvery));
            }

            $dur = max(30, min(60, (int)($cd['duration'] ?? 30)));
            $allowedTimes = cnx_sched_allowed_start_times('call', $dur);
            $pick = cnx_sched_find_next_slot(
                $calendar,
                $assignee,
                $dur,
                $serviceCursor,
                $windowEnd,
                $allowedTimes,
                $busyByUser[$assignee] ?? [],
                $busyPlan,
                $busyClient
            );
            if (!$pick) {
                $errors[] = [
                    'kind' => 'call',
                    'assignee_user_id' => $assignee,
                    'reason' => 'no_free_slot_for_call',
                ];
                continue;
            }

            $slotStart = $pick['start'];
            $slotEnd = $pick['end'];
            $title = 'Piano #' . $planId . ' - Call/Feedback (' . $cd['type_name'] . ') ' . ($cd['idx'] + 1) . '/' . $cd['total'];

            $ins = [
                'plan_id' => $planId,
                'kind' => 'call',
                'title' => $title,
                'start_datetime' => $slotStart->format('Y-m-d H:i:s'),
                'end_datetime' => $slotEnd->format('Y-m-d H:i:s'),
                'assigned_user_id' => $assignee,
                'client_tenant_id' => $clientTenantId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasExplainCol) {
                $explain = [
                    'phase' => [
                        'key' => 'call_feedback',
                        'order' => 999,
                        'label' => 'Call/Feedback',
                    ],
                    'reason' => [
                        'tags' => [
                            'fase 999',
                            'strategy ' . trim((string)($pick['explain']['strategy'] ?? '')),
                            'range ' . trim((string)($pick['explain']['range'] ?? '')),
                            'no overlap consulente',
                            'no overlap piano',
                            'no overlap cliente',
                            'slot ' . $slotStart->format('H:i') . '-' . $slotEnd->format('H:i'),
                        ],
                    ],
                    'service_id' => $cd['service_id'] ?? null,
                    'slot' => $pick['explain'] ?? null,
                ];
                // Remove empty tags (best-effort)
                if (isset($explain['reason']['tags']) && is_array($explain['reason']['tags'])) {
                    $explain['reason']['tags'] = array_values(array_filter(array_map(static fn($s) => trim((string)$s), $explain['reason']['tags']), static fn($s) => $s !== '' && $s !== 'strategy' && $s !== 'range'));
                }
                $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (!empty($draftCols)) {
                $ins = array_intersect_key($ins, $draftCols);
            }
            $id = $db->insert('consulting_plan_schedule_drafts', $ins);
            if ($id) {
                $created[] = (int)$id;
                cnx_busy_add($busyByUser, $assignee, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                cnx_busy_add_list($busyPlan, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                cnx_busy_add_list($busyClient, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                $callCursorByService[(int)($cd['service_id'] ?? 0)] = clone $slotEnd;
            }
        }
    }

    api_success([
        'plan_id' => $planId,
        'created_count' => count($created),
        'created_ids' => $created,
        'errors' => $errors,
        'meta_warnings' => $metaWarnings,
        'warnings' => $warnings,
        'forced_count' => count($warnings),
        'window' => [
            'start' => $windowStart->format('Y-m-d'),
            'end' => $windowEnd->format('Y-m-d'),
        ],
    ], 'Proposta generata');
    return;

    // 1) Draft for dated items (basic calendar of activities)
    foreach ($items as $it) {
        $activityType = (string)($it['activity_type'] ?? 'remote');
        $activityDate = (string)($it['activity_date'] ?? '');
        $days = (float)($it['days'] ?? 0);
        $hours = (float)($it['hours'] ?? 0);
        $desc = (string)($it['description'] ?? '');

        // Prefer assignee from item when available; fallback to round-robin
        $preferredAssignee = $hasAssigneeCol ? (int)($it['assignee_user_id'] ?? 0) : 0;
        if ($preferredAssignee > 0 && !in_array($preferredAssignee, $consultantIds, true)) {
            $preferredAssignee = 0;
        }

        // Determine work minutes
        $minutes = 0;
        if ($hours > 0) {
            $minutes = (int)round($hours * 60);
        } elseif ($days > 0) {
            $minutes = (int)round($days * 8 * 60); // 8h per day heuristic
        } else {
            $minutes = 60;
        }
        if ($minutes < 15) $minutes = 15;

        // Dated item: schedule on that day; Undated item: schedule later (best-effort distribution)
        if ($activityDate !== '') {
            $kind = in_array($activityType, ['onsite','remote','travel','communication'], true) ? $activityType : 'other';
            $isTravelRelevant = in_array($kind, ['onsite','travel'], true);

            $assignee = $preferredAssignee > 0 ? $preferredAssignee : 0;
            if ($assignee <= 0) {
                // Travel heuristic: prefer consultant whose home_city matches client city (best-effort)
                if ($isTravelRelevant && $planHasOnsite && $clientCityNorm !== '') {
                    $best = 0;
                    $bestLoad = null;
                    foreach ($consultantIds as $cid) {
                        $hc = trim((string)($consultantHomeCityById[(int)$cid] ?? ''));
                        if ($hc === '') continue;
                        if (cnx_norm_city($hc) !== $clientCityNorm) continue;
                        $load = (int)($onsiteLoadMinutesByUser[(int)$cid] ?? 0);
                        if ($best === 0 || $bestLoad === null || $load < (int)$bestLoad) {
                            $best = (int)$cid;
                            $bestLoad = $load;
                        }
                    }
                    if ($best > 0) $assignee = $best;
                }
                if ($assignee <= 0) {
                    $assignee = $consultantIds[$rr % count($consultantIds)];
                    $rr++;
                }
            }

            $dayStart = new DateTime($activityDate . ' 00:00:00');
            $dayEnd = new DateTime($activityDate . ' 23:59:59');
            $pick = cnx_pick_slot(
                $calendar,
                $minutes,
                $assignee,
                $dayStart,
                $dayEnd,
                $preferences,
                $busyByUser[$assignee] ?? [],
                $dayStart,
                $dayEnd
            );
            if (!$pick) {
                // Forced fallback: create a slot anyway (may conflict with real calendar)
                $forced = cnx_force_slot($minutes, (int)$assignee, $dayStart, $dayEnd, $preferences, $busyByUser[$assignee] ?? [], $dayStart, $dayEnd, false);
                if (!$forced) $forced = cnx_force_slot($minutes, (int)$assignee, $dayStart, $dayEnd, $preferences, $busyByUser[$assignee] ?? [], $dayStart, $dayEnd, true);
                if ($forced) {
                    $pick = $forced;
                    $warnings[] = [
                        'kind' => $kind,
                        'activity_date' => $activityDate,
                        'assignee_user_id' => (int)$assignee,
                        'reason' => 'forced_slot_used',
                    ];
                } else {
                    $errors[] = [
                        'kind' => $kind,
                        'activity_date' => $activityDate,
                        'assignee_user_id' => (int)$assignee,
                        'reason' => 'no_free_slot_on_fixed_date',
                    ];
                    continue;
                }
            }

            $start = $pick['start'];
            $end = $pick['end'];
            if ($isTravelRelevant) {
                $onsiteLoadMinutesByUser[$assignee] = ($onsiteLoadMinutesByUser[$assignee] ?? 0) + $minutes;
            }

            $title = 'Piano #' . $planId . ' - ' . strtoupper($activityType) . ' - ' . ($desc !== '' ? mb_substr($desc, 0, 60) : 'Attività');
            $ins = [
                'plan_id' => $planId,
                'kind' => $kind,
                'title' => $title,
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $end->format('Y-m-d H:i:s'),
                'assigned_user_id' => $assignee,
                'client_tenant_id' => $clientTenantId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasLocationCityCol && $isTravelRelevant) $ins['location_city'] = $clientCity;
            if ($hasExplainCol) {
                $explain = [
                    'fixed_date' => true,
                    'activity_date' => $activityDate,
                    'client_city' => $clientCity,
                    'consultant_home_city' => $cpcHasHomeCity ? ($consultantHomeCityById[$assignee] ?? null) : null,
                    'slot' => $pick['explain'] ?? null,
                ];
                $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $id = $db->insert('consulting_plan_schedule_drafts', $ins);
            if ($id) {
                $created[] = (int)$id;
                cnx_busy_add($busyByUser, (int)$assignee, $start, $end, ['source' => 'new_draft', 'plan_id' => $planId]);
            }
        }
    }

    // 2) Draft work items without dates (best-effort distribution across window)
    $undatedChunks = [];
    $chunkCap = 60;
    foreach ($items as $it) {
        $activityDate = (string)($it['activity_date'] ?? '');
        if ($activityDate !== '') continue;

        $days = (float)($it['days'] ?? 0);
        $hours = (float)($it['hours'] ?? 0);
        if ($days <= 0 && $hours <= 0) continue;

        $activityType = (string)($it['activity_type'] ?? 'remote');
        $desc = (string)($it['description'] ?? '');
        $preferredAssignee = $hasAssigneeCol ? (int)($it['assignee_user_id'] ?? 0) : 0;
        if ($preferredAssignee > 0 && !in_array($preferredAssignee, $consultantIds, true)) {
            $preferredAssignee = 0;
        }
        $kind = in_array($activityType, ['onsite','remote','travel','communication'], true) ? $activityType : 'other';
        $isTravelRelevant = in_array($kind, ['onsite','travel'], true);

        $assignee = $preferredAssignee > 0 ? $preferredAssignee : 0;
        if ($assignee <= 0) {
            if ($isTravelRelevant && $planHasOnsite && $clientCityNorm !== '') {
                $best = 0;
                $bestLoad = null;
                foreach ($consultantIds as $cid) {
                    $hc = trim((string)($consultantHomeCityById[(int)$cid] ?? ''));
                    if ($hc === '') continue;
                    if (cnx_norm_city($hc) !== $clientCityNorm) continue;
                    $load = (int)($onsiteLoadMinutesByUser[(int)$cid] ?? 0);
                    if ($best === 0 || $bestLoad === null || $load < (int)$bestLoad) {
                        $best = (int)$cid;
                        $bestLoad = $load;
                    }
                }
                if ($best > 0) $assignee = $best;
            }
            if ($assignee <= 0) {
                $assignee = $consultantIds[$rr % count($consultantIds)];
                $rr++;
            }
        }

        $totalMinutes = 0;
        if ($hours > 0) $totalMinutes = (int)round($hours * 60);
        else $totalMinutes = (int)round($days * 8 * 60);
        if ($totalMinutes < 15) $totalMinutes = 15;

        // Split into day-sized chunks (max 8h) to avoid absurdly long single slots
        $chunkIdx = 1;
        $chunkTotal = (int)ceil($totalMinutes / (8 * 60));
        while ($totalMinutes > 0 && count($undatedChunks) < $chunkCap) {
            $m = min($totalMinutes, 8 * 60);
            if ($isTravelRelevant) {
                $onsiteLoadMinutesByUser[$assignee] = ($onsiteLoadMinutesByUser[$assignee] ?? 0) + (int)$m;
            }
            $undatedChunks[] = [
                'assignee' => $assignee,
                'minutes' => $m,
                'kind' => $kind,
                'title' => 'Piano #' . $planId . ' - ' . strtoupper($activityType) . ' - ' . ($desc !== '' ? mb_substr($desc, 0, 60) : 'Attività') . ($chunkTotal > 1 ? (' (' . $chunkIdx . '/' . $chunkTotal . ')') : ''),
                'location_city' => ($isTravelRelevant ? $clientCity : null),
            ];
            $totalMinutes -= $m;
            $chunkIdx++;
        }
    }

    if (!empty($undatedChunks)) {
        // Travel optimization: schedule onsite/travel chunks in blocks per consultant (reduce back-and-forth trips)
        $onsiteChunks = [];
        $otherChunks = [];
        foreach ($undatedChunks as $c) {
            $k = (string)($c['kind'] ?? 'other');
            if (in_array($k, ['onsite','travel'], true)) $onsiteChunks[] = $c;
            else $otherChunks[] = $c;
        }

        usort($onsiteChunks, static function ($a, $b) {
            $au = (int)($a['assignee'] ?? 0);
            $bu = (int)($b['assignee'] ?? 0);
            if ($au !== $bu) return $au <=> $bu;
            return ((int)($b['minutes'] ?? 0)) <=> ((int)($a['minutes'] ?? 0)); // bigger first
        });

        $onsiteCursorByUser = [];
        foreach ($onsiteChunks as $c) {
            $assignee = (int)($c['assignee'] ?? 0);
            if ($assignee <= 0 || !in_array($assignee, $consultantIds, true)) {
                $assignee = $consultantIds[0];
            }
            $minutes = (int)($c['minutes'] ?? 60);
            if ($minutes < 15) $minutes = 15;

            $cursor = $onsiteCursorByUser[$assignee] ?? (clone $windowStart);
            if ($cursor < $windowStart) $cursor = clone $windowStart;
            $segStart = (clone $cursor);
            $segStart->setTime(0, 0, 0);
            $segEnd = (clone $segStart)->add(new DateInterval('P7D'));
            if ($segEnd > $windowEnd) $segEnd = clone $windowEnd;

            $pick = cnx_pick_slot(
                $calendar,
                $minutes,
                $assignee,
                $segStart,
                $segEnd,
                $preferences,
                $busyByUser[$assignee] ?? [],
                $windowStart,
                $windowEnd
            );
            if (!$pick) {
                // Forced fallback: create a slot anyway (may conflict with real calendar)
                $forced = cnx_force_slot($minutes, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, false);
                if (!$forced) $forced = cnx_force_slot($minutes, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, true);
                if ($forced) {
                    $pick = $forced;
                    $warnings[] = [
                        'kind' => (string)($c['kind'] ?? 'other'),
                        'assignee_user_id' => $assignee,
                        'reason' => 'forced_slot_used',
                    ];
                } else {
                    $errors[] = [
                        'kind' => (string)($c['kind'] ?? 'other'),
                        'assignee_user_id' => $assignee,
                        'reason' => 'no_free_slot_for_chunk',
                    ];
                    continue;
                }
            }

            $slotStart = $pick['start'];
            $slotEnd = $pick['end'];

            // Cursor advancement: keep same day for short chunks; next day for near-full-day chunks
            $nextCursor = clone $slotStart;
            $nextCursor->setTime(0, 0, 0);
            if ($minutes >= (6 * 60)) $nextCursor->add(new DateInterval('P1D'));
            $onsiteCursorByUser[$assignee] = $nextCursor;

            $ins = [
                'plan_id' => $planId,
                'kind' => (string)($c['kind'] ?? 'other'),
                'title' => (string)($c['title'] ?? ('Piano #' . $planId . ' - Attività')),
                'start_datetime' => $slotStart->format('Y-m-d H:i:s'),
                'end_datetime' => $slotEnd->format('Y-m-d H:i:s'),
                'assigned_user_id' => $assignee,
                'client_tenant_id' => $clientTenantId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasLocationCityCol) $ins['location_city'] = $c['location_city'] ?? $clientCity;
            if ($hasExplainCol) {
                $explain = [
                    'fixed_date' => false,
                    'travel_cluster' => true,
                    'client_city' => $clientCity,
                    'consultant_home_city' => $cpcHasHomeCity ? ($consultantHomeCityById[$assignee] ?? null) : null,
                    'slot' => $pick['explain'] ?? null,
                ];
                $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $id = $db->insert('consulting_plan_schedule_drafts', $ins);
            if ($id) {
                $created[] = (int)$id;
                cnx_busy_add($busyByUser, $assignee, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
            }
        }

        // Remaining chunks: distribute across the window (avoid conflicts with real events + drafts)
        if (!empty($otherChunks)) {
            $totalChunks = count($otherChunks);
            $totalSeconds = max(1, $windowEnd->getTimestamp() - $windowStart->getTimestamp());
            foreach ($otherChunks as $i => $c) {
                $segStartTs = $windowStart->getTimestamp() + (int)floor(($totalSeconds * $i) / $totalChunks);
                $segEndTs = $windowStart->getTimestamp() + (int)floor(($totalSeconds * ($i + 1)) / $totalChunks);
                $segStart = (new DateTime())->setTimestamp($segStartTs);
                $segEnd = (new DateTime())->setTimestamp(max($segStartTs + 3600, $segEndTs));
                if ($segStart < $windowStart) $segStart = clone $windowStart;
                if ($segEnd > $windowEnd) $segEnd = clone $windowEnd;

                $assignee = (int)($c['assignee'] ?? 0);
                if ($assignee <= 0 || !in_array($assignee, $consultantIds, true)) {
                    $assignee = $consultantIds[0];
                }
                $minutes = (int)($c['minutes'] ?? 60);
                if ($minutes < 15) $minutes = 15;

                $pick = cnx_pick_slot(
                    $calendar,
                    $minutes,
                    $assignee,
                    $segStart,
                    $segEnd,
                    $preferences,
                    $busyByUser[$assignee] ?? [],
                    $windowStart,
                    $windowEnd
                );
                if (!$pick) {
                    // Forced fallback: create a slot anyway (may conflict with real calendar)
                    $forced = cnx_force_slot($minutes, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, false);
                    if (!$forced) $forced = cnx_force_slot($minutes, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, true);
                    if ($forced) {
                        $pick = $forced;
                        $warnings[] = [
                            'kind' => (string)($c['kind'] ?? 'other'),
                            'assignee_user_id' => $assignee,
                            'reason' => 'forced_slot_used',
                        ];
                    } else {
                        $errors[] = [
                            'kind' => (string)($c['kind'] ?? 'other'),
                            'assignee_user_id' => $assignee,
                            'reason' => 'no_free_slot_for_chunk',
                        ];
                        continue;
                    }
                }

                $slotStart = $pick['start'];
                $slotEnd = $pick['end'];

                $ins = [
                    'plan_id' => $planId,
                    'kind' => (string)($c['kind'] ?? 'other'),
                    'title' => (string)($c['title'] ?? ('Piano #' . $planId . ' - Attività')),
                    'start_datetime' => $slotStart->format('Y-m-d H:i:s'),
                    'end_datetime' => $slotEnd->format('Y-m-d H:i:s'),
                    'assigned_user_id' => $assignee,
                    'client_tenant_id' => $clientTenantId,
                    'status' => 'draft',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($hasLocationCityCol && !empty($c['location_city'])) $ins['location_city'] = $c['location_city'];
                if ($hasExplainCol) {
                    $explain = [
                        'fixed_date' => false,
                        'client_city' => $clientCity,
                        'slot' => $pick['explain'] ?? null,
                    ];
                    $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $id = $db->insert('consulting_plan_schedule_drafts', $ins);
                if ($id) {
                    $created[] = (int)$id;
                    cnx_busy_add($busyByUser, $assignee, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
                }
            }
        }
    }

    // 3) Draft calls/feedback grouped by service (domain_activity_type_id)
    $callDraftsToCreate = [];
    $daysByService = []; // serviceId => totalDays
    $assigneeByService = []; // serviceId => userId (primary)
    if (!empty($items)) {
        $perServicePerAssignee = [];
        foreach ($items as $it) {
            $domainTypeId = (int)($it['domain_activity_type_id'] ?? 0);
            if ($domainTypeId <= 0) continue;
            $days = (float)($it['days'] ?? 0);
            if ($days <= 0) continue;
            $daysByService[$domainTypeId] = ($daysByService[$domainTypeId] ?? 0.0) + $days;
            $uid = $hasAssigneeCol ? (int)($it['assignee_user_id'] ?? 0) : 0;
            if ($uid > 0) {
                $perServicePerAssignee[$domainTypeId][$uid] = ($perServicePerAssignee[$domainTypeId][$uid] ?? 0.0) + $days;
            }
        }
        foreach ($perServicePerAssignee as $sid => $map) {
            arsort($map);
            $topUid = (int)array_key_first($map);
            if ($topUid > 0 && in_array($topUid, $consultantIds, true)) {
                $assigneeByService[(int)$sid] = $topUid;
            }
        }
    }

    foreach ($daysByService as $domainTypeId => $totalDays) {
        $domainTypeId = (int)$domainTypeId;
        $totalDays = (float)$totalDays;
        if ($domainTypeId <= 0 || $totalDays <= 0) continue;

        $eff = cnx_resolve_effective_activity_type($db, $domainTypeId, $clientTenantId);
        // Note: even if the catalog entry is inactive/legacy, the plan may already reference it (historical plans).
        if (!$eff) continue;

        $callEvery = (int)$eff['call_every_days'];
        if ($callEvery <= 0) $callEvery = 7;

        $callDur = (int)$eff['call_duration_minutes'];
        if ($callDur <= 0) $callDur = $durationDefault;

        $numCalls = (int)ceil($totalDays / $callEvery);
        if ($numCalls <= 0) $numCalls = 1;

        $assignee = (int)($assigneeByService[$domainTypeId] ?? 0);
        if ($assignee <= 0) {
            $assignee = $consultantIds[$rr % count($consultantIds)];
            $rr++;
        }

        for ($i = 0; $i < $numCalls; $i++) {
            $callDraftsToCreate[] = [
                'service_id' => $domainTypeId,
                'type_name' => $eff['name'],
                'duration' => $callDur,
                'idx' => $i,
                'total' => $numCalls,
                'assignee' => $assignee,
            ];
        }
    }

    $totalCalls = count($callDraftsToCreate);
    if ($totalCalls > 0) {
        // Split the window into segments, one per call, and ask for free slot in that segment
        $totalSeconds = max(1, $windowEnd->getTimestamp() - $windowStart->getTimestamp());
        foreach ($callDraftsToCreate as $i => $cd) {
            $segStartTs = $windowStart->getTimestamp() + (int)floor(($totalSeconds * $i) / $totalCalls);
            $segEndTs = $windowStart->getTimestamp() + (int)floor(($totalSeconds * ($i + 1)) / $totalCalls);
            $segStart = (new DateTime())->setTimestamp($segStartTs);
            $segEnd = (new DateTime())->setTimestamp(max($segStartTs + 3600, $segEndTs)); // at least 1h window

            // Clamp
            if ($segStart < $windowStart) $segStart = clone $windowStart;
            if ($segEnd > $windowEnd) $segEnd = clone $windowEnd;

            // Assign per-call and find a free slot for THAT consultant (avoid overlaps)
            $assignee = (int)($cd['assignee'] ?? 0);
            if ($assignee <= 0 || !in_array($assignee, $consultantIds, true)) {
                $assignee = $consultantIds[$rr % count($consultantIds)];
                $rr++;
            }

            $dur = max(15, (int)($cd['duration'] ?? 30));
            $pick = cnx_pick_slot(
                $calendar,
                $dur,
                $assignee,
                $segStart,
                $segEnd,
                $preferences,
                $busyByUser[$assignee] ?? [],
                $windowStart,
                $windowEnd
            );
            if (!$pick) {
                // Forced fallback: create a slot anyway (may conflict with real calendar)
                $forced = cnx_force_slot($dur, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, false);
                if (!$forced) $forced = cnx_force_slot($dur, (int)$assignee, $segStart, $segEnd, $preferences, $busyByUser[$assignee] ?? [], $windowStart, $windowEnd, true);
                if ($forced) {
                    $pick = $forced;
                    $warnings[] = [
                        'kind' => 'call',
                        'assignee_user_id' => $assignee,
                        'reason' => 'forced_slot_used',
                    ];
                } else {
                    $errors[] = [
                        'kind' => 'call',
                        'assignee_user_id' => $assignee,
                        'reason' => 'no_free_slot_for_call',
                    ];
                    continue;
                }
            }

            $slotStart = $pick['start'];
            $slotEnd = $pick['end'];

            $title = 'Piano #' . $planId . ' - Call/Feedback (' . $cd['type_name'] . ') ' . ($cd['idx'] + 1) . '/' . $cd['total'];
            $ins = [
                'plan_id' => $planId,
                'kind' => 'call',
                'title' => $title,
                'start_datetime' => $slotStart->format('Y-m-d H:i:s'),
                'end_datetime' => $slotEnd->format('Y-m-d H:i:s'),
                'assigned_user_id' => $assignee,
                'client_tenant_id' => $clientTenantId,
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasExplainCol) {
                $explain = [
                    'fixed_date' => false,
                    'service_id' => $cd['service_id'] ?? null,
                    'slot' => $pick['explain'] ?? null,
                ];
                $ins['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $id = $db->insert('consulting_plan_schedule_drafts', $ins);
            if ($id) {
                $created[] = (int)$id;
                cnx_busy_add($busyByUser, $assignee, $slotStart, $slotEnd, ['source' => 'new_draft', 'plan_id' => $planId]);
            }
        }
    }

    api_success([
        'plan_id' => $planId,
        'created_count' => count($created),
        'created_ids' => $created,
        'errors' => $errors,
        'meta_warnings' => $metaWarnings,
        'warnings' => $warnings,
        'forced_count' => count($warnings),
        'window' => [
            'start' => $windowStart->format('Y-m-d'),
            'end' => $windowEnd->format('Y-m-d'),
        ],
    ], 'Proposta generata');
} catch (Throwable $e) {
    $errId = 'csg_' . substr(str_replace('.', '', uniqid('', true)), -10);
    try { $errId = 'csg_' . bin2hex(random_bytes(5)); } catch (Throwable $ignored) {}
    error_log('[CONSULTING_SCHEDULE_GENERATE][' . $errId . '] ' . $e->getMessage());
    $role = (string)($userInfo['role'] ?? 'user');
    $extra = ['error_id' => $errId];
    if ($role === 'super_admin') {
        $extra['debug_message'] = $e->getMessage();
    }
    api_error('Errore generazione proposta', 500, $extra);
}


