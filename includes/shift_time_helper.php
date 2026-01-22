<?php
/**
 * Shift time helpers (overlaps + weekly hours)
 *
 * Goals:
 * - Prevent assigning overlapping shifts to the same user (even across midnight)
 * - Compute weekly assigned hours (48h alert threshold)
 *
 * Notes:
 * - Multi-tenant safe: all queries require tenant_id
 * - Schema assumptions (Migration 22): shift_types + work_shifts
 */
declare(strict_types=1);

/**
 * Normalize TIME string to HH:MM:SS.
 */
function cnx_shift_norm_time(string $t): string {
    $t = trim($t);
    if ($t === '') return '00:00:00';
    if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
    // Best-effort fallback: take first 8 chars if looks like time
    $t = substr($t, 0, 8);
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
    return '00:00:00';
}

/**
 * Convert HH:MM(:SS) to seconds since midnight.
 */
function cnx_shift_time_to_seconds(string $t): int {
    $t = cnx_shift_norm_time($t);
    $parts = explode(':', $t);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    return ($h * 3600) + ($m * 60) + $s;
}

/**
 * Build a DateTime from date+time (local timezone).
 */
function cnx_shift_build_datetime(string $dateYmd, string $timeHms): DateTime {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateYmd . ' ' . cnx_shift_norm_time($timeHms));
    if ($dt instanceof DateTime) return $dt;
    return new DateTime($dateYmd . ' ' . cnx_shift_norm_time($timeHms));
}

/**
 * Build interval for a shift (handles overnight end <= start).
 *
 * @return array{start:DateTime,end:DateTime}
 */
function cnx_shift_build_interval(string $shiftDateYmd, string $startTime, string $endTime): array {
    $start = cnx_shift_build_datetime($shiftDateYmd, $startTime);
    $end = cnx_shift_build_datetime($shiftDateYmd, $endTime);
    $startSec = cnx_shift_time_to_seconds($startTime);
    $endSec = cnx_shift_time_to_seconds($endTime);
    if ($endSec <= $startSec) {
        $end->modify('+1 day');
    }
    return ['start' => $start, 'end' => $end];
}

function cnx_shift_intervals_overlap(DateTime $aStart, DateTime $aEnd, DateTime $bStart, DateTime $bEnd): bool {
    $as = $aStart->getTimestamp();
    $ae = $aEnd->getTimestamp();
    $bs = $bStart->getTimestamp();
    $be = $bEnd->getTimestamp();
    return ($as < $be) && ($bs < $ae);
}

/**
 * Find a conflicting shift (overlap) for a user within a date window.
 *
 * Returns null if no conflict.
 *
 * @return array<string,mixed>|null
 */
function cnx_shift_find_overlap_conflict(Database $db, int $tenantId, int $userId, DateTime $newStart, DateTime $newEnd, int $excludeShiftId = 0): ?array {
    $minD = min($newStart->format('Y-m-d'), $newEnd->format('Y-m-d'));
    $maxD = max($newStart->format('Y-m-d'), $newEnd->format('Y-m-d'));
    $fromDate = date('Y-m-d', strtotime($minD . ' -1 day'));
    $toDate = date('Y-m-d', strtotime($maxD . ' +1 day'));

    $params = [$tenantId, $userId, $fromDate, $toDate];
    $excludeSql = '';
    if ($excludeShiftId > 0) {
        $excludeSql = ' AND ws.id != ?';
        $params[] = $excludeShiftId;
    }

    $rows = $db->fetchAll(
        "SELECT
            ws.id,
            ws.shift_type_id,
            ws.shift_date,
            COALESCE(ws.start_time_override, st.start_time) AS start_time,
            COALESCE(ws.end_time_override, st.end_time) AS end_time,
            ws.status,
            st.name AS shift_name
         FROM work_shifts ws
         INNER JOIN shift_types st ON ws.shift_type_id = st.id
         WHERE ws.tenant_id = ?
           AND ws.user_id = ?
           AND ws.deleted_at IS NULL
           AND ws.status != 'cancelled'
           AND ws.shift_date >= ?
           AND ws.shift_date <= ?
           {$excludeSql}
         ORDER BY ws.shift_date ASC, start_time ASC, ws.id ASC",
        $params
    ) ?: [];

    foreach ($rows as $r) {
        $sid = (int)($r['id'] ?? 0);
        if ($sid <= 0) continue;
        $interval = cnx_shift_build_interval((string)$r['shift_date'], (string)$r['start_time'], (string)$r['end_time']);
        if (cnx_shift_intervals_overlap($newStart, $newEnd, $interval['start'], $interval['end'])) {
            return [
                'conflict_shift_id' => $sid,
                'conflict_shift_type_id' => (int)($r['shift_type_id'] ?? 0),
                'conflict_shift_name' => (string)($r['shift_name'] ?? ''),
                'conflict_shift_date' => (string)($r['shift_date'] ?? ''),
                'conflict_start_time' => (string)($r['start_time'] ?? ''),
                'conflict_end_time' => (string)($r['end_time'] ?? ''),
                'conflict_start_datetime' => $interval['start']->format('Y-m-d H:i:s'),
                'conflict_end_datetime' => $interval['end']->format('Y-m-d H:i:s'),
                'new_start_datetime' => $newStart->format('Y-m-d H:i:s'),
                'new_end_datetime' => $newEnd->format('Y-m-d H:i:s'),
            ];
        }
    }
    return null;
}

/**
 * Week bounds for a given DateTime (ISO week: Monday 00:00 to next Monday 00:00).
 *
 * @return array{start:DateTime,end:DateTime}
 */
function cnx_shift_week_bounds(DateTime $dt): array {
    $start = clone $dt;
    $start->setTime(0, 0, 0);
    $dow = (int)$start->format('N'); // 1=Mon..7=Sun
    if ($dow > 1) {
        $start->modify('-' . ($dow - 1) . ' days');
    }
    $end = clone $start;
    $end->modify('+7 days');
    return ['start' => $start, 'end' => $end];
}

/**
 * Compute weekly minutes assigned to a user in a given week interval.
 * - Excludes cancelled and deleted shifts
 * - Properly counts overnight shifts and splits at week boundary
 */
function cnx_shift_compute_week_minutes(Database $db, int $tenantId, int $userId, DateTime $weekStart, DateTime $weekEndExclusive, int $excludeShiftId = 0): int {
    $startDate = $weekStart->format('Y-m-d');
    $endDate = $weekEndExclusive->format('Y-m-d');
    $fromDate = date('Y-m-d', strtotime($startDate . ' -1 day'));
    $toDate = date('Y-m-d', strtotime($endDate . ' +1 day'));

    $params = [$tenantId, $userId, $fromDate, $toDate];
    $excludeSql = '';
    if ($excludeShiftId > 0) {
        $excludeSql = ' AND ws.id != ?';
        $params[] = $excludeShiftId;
    }

    $rows = $db->fetchAll(
        "SELECT
            ws.id,
            ws.shift_date,
            COALESCE(ws.start_time_override, st.start_time) AS start_time,
            COALESCE(ws.end_time_override, st.end_time) AS end_time,
            ws.status
         FROM work_shifts ws
         INNER JOIN shift_types st ON ws.shift_type_id = st.id
         WHERE ws.tenant_id = ?
           AND ws.user_id = ?
           AND ws.deleted_at IS NULL
           AND ws.status != 'cancelled'
           AND ws.shift_date >= ?
           AND ws.shift_date <= ?
           {$excludeSql}",
        $params
    ) ?: [];

    $ws = $weekStart->getTimestamp();
    $we = $weekEndExclusive->getTimestamp();
    $minutes = 0;

    foreach ($rows as $r) {
        $interval = cnx_shift_build_interval((string)$r['shift_date'], (string)$r['start_time'], (string)$r['end_time']);
        $s = $interval['start']->getTimestamp();
        $e = $interval['end']->getTimestamp();
        $ovS = max($s, $ws);
        $ovE = min($e, $we);
        if ($ovE > $ovS) {
            $minutes += (int)floor(($ovE - $ovS) / 60);
        }
    }

    return $minutes;
}

