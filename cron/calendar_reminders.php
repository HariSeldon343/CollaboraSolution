<?php
/**
 * Calendar Reminders Runner (Cron)
 *
 * Sends pending calendar email reminders per tenant.
 * - Uses Calendar::sendEmailReminders() which also tries to create in-app notifications (best effort).
 *
 * Usage (Windows Task Scheduler / cron):
 *   php cron/calendar_reminders.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/calendar.php';

// Basic safety: ensure we run from CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

date_default_timezone_set(date_default_timezone_get());

try {
    $pdo = Database::getInstance()->getConnection();

    $tenantsStmt = $pdo->query("SELECT id FROM tenants WHERE deleted_at IS NULL");
    $tenantIds = $tenantsStmt ? $tenantsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $totalSent = 0;
    $byTenant = [];

    foreach ($tenantIds as $tenantIdRaw) {
        $tenantId = (int)$tenantIdRaw;
        if ($tenantId <= 0) continue;

        try {
            $calendar = new Calendar($pdo, $tenantId, null);
            $sent = $calendar->sendEmailReminders();
            $byTenant[$tenantId] = $sent;
            $totalSent += $sent;
        } catch (Throwable $e) {
            // Continue other tenants
            error_log("[CRON][calendar_reminders] tenant={$tenantId} error: " . $e->getMessage());
            $byTenant[$tenantId] = -1;
        }
    }

    // CLI output summary
    echo "Calendar reminders processed.\n";
    echo "Total sent: {$totalSent}\n";
    foreach ($byTenant as $tid => $cnt) {
        echo " - tenant {$tid}: {$cnt}\n";
    }
    exit(0);
} catch (Throwable $e) {
    error_log("[CRON][calendar_reminders] fatal: " . $e->getMessage());
    fwrite(STDERR, "Fatal: " . $e->getMessage() . "\n");
    exit(2);
}


