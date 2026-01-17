<?php
// Consulting Planning: update a single schedule draft row (edit slot)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/calendar.php';

verifyApiCsrfToken();

try {
    $has = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_schedule_drafts'");
    if (!$has) {
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
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $draftId = (int)($payload['id'] ?? 0);
    if ($draftId <= 0) api_error('id obbligatorio', 400);

    $draft = $db->fetchOne(
        "SELECT * FROM consulting_plan_schedule_drafts WHERE id = ? AND deleted_at IS NULL",
        [$draftId]
    );
    if (!$draft) api_error('Slot non trovato', 404);

    $planId = (int)($draft['plan_id'] ?? 0);
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    if ((string)($draft['status'] ?? '') !== 'draft') {
        api_error('Slot non modificabile (non in bozza)', 409);
    }

    $start = isset($payload['start_datetime']) ? trim((string)$payload['start_datetime']) : '';
    $end = isset($payload['end_datetime']) ? trim((string)$payload['end_datetime']) : '';
    $assignedUserId = array_key_exists('assigned_user_id', $payload) ? (int)($payload['assigned_user_id'] ?? 0) : null;

    if ($start === '' || $end === '') api_error('start_datetime e end_datetime obbligatori', 400);

    // Accept both "Y-m-d H:i:s" and "Y-m-d\TH:i" (datetime-local)
    $startDt = new DateTime(str_replace('T', ' ', $start));
    $endDt = new DateTime(str_replace('T', ' ', $end));
    if ($endDt <= $startDt) api_error('Intervallo non valido', 400);

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

    /**
     * client_blocking rule (best-effort):
     * - Overlap is forbidden if EITHER the current draft OR the conflicting row is client_blocking=true.
     * - If explain_json is missing, default to blocking=true (safe).
     *
     * @param array<string,mixed> $row
     */
    $isBlocking = static function (array $row) use ($hasExplainCol): bool {
        if (!$hasExplainCol) return true;
        try {
            $raw = (string)($row['explain_json'] ?? '');
            if ($raw === '') {
                // confirmed rows without explain should be treated as blocking
                return ((string)($row['status'] ?? '') !== 'draft');
            }
            $exp = json_decode($raw, true);
            if (!is_array($exp)) return true;
            if (array_key_exists('client_blocking', $exp)) return (bool)$exp['client_blocking'];
            if (isset($exp['rules']['no_overlap']['plan'])) return (bool)$exp['rules']['no_overlap']['plan'];
            return true;
        } catch (Throwable $e) {
            return true;
        }
    };

    $currentBlocking = $isBlocking($draft);

    // Plan overlap (client-side): allow overlap only if BOTH drafts are non-blocking
    $sel = "SELECT id, plan_id, title, start_datetime, end_datetime, status";
    if ($hasExplainCol) $sel .= ", explain_json";
    $confPlanRows = $db->fetchAll(
        $sel . "
         FROM consulting_plan_schedule_drafts
         WHERE deleted_at IS NULL
           AND id <> ?
           AND plan_id = ?
           AND status IN ('draft','confirmed')
           AND start_datetime < ?
           AND end_datetime > ?",
        [$draftId, $planId, $endDt->format('Y-m-d H:i:s'), $startDt->format('Y-m-d H:i:s')]
    ) ?: [];
    foreach ($confPlanRows as $r) {
        if (!is_array($r)) continue;
        $otherBlocking = $isBlocking($r);
        if ($currentBlocking || $otherBlocking) {
            api_error(
                'Conflitto bozza: sovrapposizione non permessa per una fase client-blocking',
                409,
                ['conflict_plan' => $r, 'client_blocking' => ['current' => $currentBlocking, 'other' => $otherBlocking]]
            );
        }
    }

    // Client overlap across plans (confirmed only): same rule (other treated blocking by default)
    if ($clientTenantId > 0) {
        $selC = "SELECT id, plan_id, title, start_datetime, end_datetime, status";
        if ($hasExplainCol) $selC .= ", explain_json";
        $confClientRows = $db->fetchAll(
            $selC . "
             FROM consulting_plan_schedule_drafts
             WHERE deleted_at IS NULL
               AND id <> ?
               AND client_tenant_id = ?
               AND plan_id <> ?
               AND status = 'confirmed'
               AND start_datetime < ?
               AND end_datetime > ?",
            [$draftId, $clientTenantId, $planId, $endDt->format('Y-m-d H:i:s'), $startDt->format('Y-m-d H:i:s')]
        ) ?: [];
        foreach ($confClientRows as $r) {
            if (!is_array($r)) continue;
            $otherBlocking = $isBlocking($r);
            if ($currentBlocking || $otherBlocking) {
                api_error(
                    'Conflitto cliente: esiste già un evento confermato in quel periodo (fase client-blocking)',
                    409,
                    ['conflict_client' => $r, 'client_blocking' => ['current' => $currentBlocking, 'other' => $otherBlocking]]
                );
            }
        }
    }

    $assignee = ($assignedUserId && $assignedUserId > 0) ? (int)$assignedUserId : 0;
    if ($assignee > 0) {
        // Ensure assignee is one of the selected plan consultants
        $okAssignee = $db->fetchOne(
            "SELECT 1 FROM consulting_plan_consultants WHERE plan_id = ? AND user_id = ? LIMIT 1",
            [$planId, $assignee]
        );
        if (!$okAssignee) {
            api_error('Consulente non selezionato nel piano', 400);
        }

        // Hard constraint: avoid overlapping with other active drafts (all plans)
        $confDraft = $db->fetchOne(
            "SELECT id, plan_id, title, start_datetime, end_datetime, status
             FROM consulting_plan_schedule_drafts
             WHERE deleted_at IS NULL
               AND id <> ?
               AND assigned_user_id = ?
               AND status IN ('draft','confirmed')
               AND start_datetime < ?
               AND end_datetime > ?
             LIMIT 1",
            [$draftId, $assignee, $endDt->format('Y-m-d H:i:s'), $startDt->format('Y-m-d H:i:s')]
        );
        if ($confDraft) {
            api_error(
                'Conflitto bozza: il consulente ha già uno slot in quel periodo',
                409,
                ['conflict_draft' => $confDraft]
            );
        }

        // Hard constraint: avoid overlapping with real calendar events (tenant 28)
        $pdo = Database::getInstance()->getConnection();
        $apiUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
        $calendar = new Calendar($pdo, CNX_VENDOR_TENANT_ID, $apiUserId);
        $conflicts = [];
        try {
            $conflicts = $calendar->detectConflicts($startDt, $endDt, [$assignee]);
        } catch (Throwable $e) {
            $conflicts = [];
        }
        if (!empty($conflicts)) {
            api_error(
                'Conflitto calendario: il consulente ha un evento in quel periodo',
                409,
                ['conflicts' => $conflicts]
            );
        }
    }

    $ok = $db->update('consulting_plan_schedule_drafts', [
        'start_datetime' => $startDt->format('Y-m-d H:i:s'),
        'end_datetime' => $endDt->format('Y-m-d H:i:s'),
        'assigned_user_id' => $assignee > 0 ? $assignee : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $draftId]);
    if (!$ok) api_error('Aggiornamento fallito', 500);

    api_success(['id' => $draftId], 'Slot aggiornato');
} catch (Exception $e) {
    error_log('[CONSULTING_SCHEDULE_UPDATE] ' . $e->getMessage());
    api_error('Errore aggiornamento slot', 500);
}


