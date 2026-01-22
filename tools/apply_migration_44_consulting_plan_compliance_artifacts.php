<?php
declare(strict_types=1);

/**
 * One-click idempotent apply for migration 44 (consulting_plan_compliance_artifacts).
 *
 * Safe to run multiple times.
 */

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/db.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo '<h3>403</h3><div>Solo super_admin.</div>';
    exit;
}

$db = Database::getInstance();
$sqlFile = __DIR__ . '/../database/migrations/44_consulting_plan_compliance_artifacts.sql';

echo '<h2>Apply migration 44 — consulting_plan_compliance_artifacts</h2>';

try {
    $exists = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_compliance_artifacts'");
    $sql = @file_get_contents($sqlFile);
    if (!$sql) {
        throw new RuntimeException('SQL file non leggibile: ' . htmlspecialchars($sqlFile));
    }
    $db->getConnection()->exec($sql);

    if ($exists) {
        echo '<div>OK: tabella già presente (verificata).</div>';
    } else {
        echo '<div>OK: tabella creata.</div>';
    }
} catch (Throwable $e) {
    echo '<div style="color:#b91c1c; font-weight:700;">ERRORE</div>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

