<?php
// DB Integrity Audit (Super Admin only)
// Reports PK/FK/index/type mismatches and module table availability.

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/db.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: ../index.php?timeout=1');
    exit;
}
$currentUser = $auth->getCurrentUser();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    header('Location: ../dashboard.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

function q(PDO $conn, string $sql, array $params = []): array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scalar(PDO $conn, string $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$schema = (string)scalar($conn, 'SELECT DATABASE()');

$tablesWithoutPk = q($conn, "
    SELECT t.TABLE_NAME AS table_name
    FROM information_schema.TABLES t
    LEFT JOIN information_schema.TABLE_CONSTRAINTS c
      ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
     AND c.TABLE_NAME = t.TABLE_NAME
     AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
    WHERE t.TABLE_SCHEMA = DATABASE()
      AND t.TABLE_TYPE = 'BASE TABLE'
      AND c.CONSTRAINT_NAME IS NULL
    ORDER BY t.TABLE_NAME
");

$fkCount = (int)scalar($conn, "
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
");

$unsignedMismatches = q($conn, "
    SELECT
      k.TABLE_NAME AS child_table,
      k.COLUMN_NAME AS child_column,
      k.REFERENCED_TABLE_NAME AS parent_table,
      k.REFERENCED_COLUMN_NAME AS parent_column,
      c.COLUMN_TYPE AS child_type,
      p.COLUMN_TYPE AS parent_type
    FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.COLUMNS c
      ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
     AND c.TABLE_NAME = k.TABLE_NAME
     AND c.COLUMN_NAME = k.COLUMN_NAME
    JOIN information_schema.COLUMNS p
      ON p.TABLE_SCHEMA = k.REFERENCED_TABLE_SCHEMA
     AND p.TABLE_NAME = k.REFERENCED_TABLE_NAME
     AND p.COLUMN_NAME = k.REFERENCED_COLUMN_NAME
    WHERE k.TABLE_SCHEMA = DATABASE()
      AND k.REFERENCED_TABLE_NAME IS NOT NULL
      AND (
        (c.COLUMN_TYPE LIKE '%unsigned%' AND p.COLUMN_TYPE NOT LIKE '%unsigned%')
        OR
        (c.COLUMN_TYPE NOT LIKE '%unsigned%' AND p.COLUMN_TYPE LIKE '%unsigned%')
      )
    ORDER BY k.TABLE_NAME, k.COLUMN_NAME
");

// Module readiness checks (tables created by our recent migrations)
$moduleTables = [
    // Consulting planning (34/35)
    'consulting_plans',
    'consulting_plan_items',
    'consulting_plan_task_links',
    'consulting_activity_types',
    'consulting_activity_type_overrides',
    'consulting_plan_consultants',
    'consulting_plan_schedule_drafts',
    // Files download approvals (30)
    'file_download_requests',
    // GDPR ack (32)
    'privacy_acknowledgements',
    // DPO registry (33)
    'dpo_protocol_history',
    // Page visibility helper
    'page_visibility_settings',
];

$tableStatus = [];
foreach ($moduleTables as $t) {
    $exists = (int)scalar($conn, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]) > 0;
    $tableStatus[] = ['table_name' => $t, 'exists' => $exists ? 'YES' : 'NO'];
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'DB Integrity Audit - Nexio';
    require __DIR__ . '/../includes/layout_head.php';
?>
<style>
  .wrap { padding: var(--space-6); max-width: 1200px; }
  .card { background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: var(--space-6); }
  .card-h { padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-gray-200); display:flex; align-items:center; justify-content:space-between; gap: var(--space-3);}
  .card-b { padding: var(--space-5); }
  .muted { color: var(--color-gray-600); font-size: var(--text-sm); }
  .table { width:100%; border-collapse: collapse; }
  .table th, .table td { border-bottom: 1px solid var(--color-gray-100); padding: 10px 8px; font-size: var(--text-sm); vertical-align: top; }
  .table th { text-align:left; color: var(--color-gray-600); font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
  .pill { display:inline-flex; align-items:center; gap:6px; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:700; }
  .pill.ok { background:#ECFDF5; color:#065F46; }
  .pill.fail { background:#FEE2E2; color:#991B1B; }
  code { font-size: 12px; }
</style>
</head>
<?php require __DIR__ . '/../includes/layout_start.php'; ?>

<div class="wrap">
  <div class="header">
    <h1 class="page-title">DB Integrity Audit</h1>
    <div class="muted">Schema: <code><?php echo htmlspecialchars($schema); ?></code> · FK totali: <strong><?php echo (int)$fkCount; ?></strong></div>
  </div>

  <div class="card">
    <div class="card-h">
      <div style="font-weight:700;">Tabelle modulo (presenza)</div>
      <div class="muted">Serve per diagnosticare errori 503/500 dovuti a migrazioni mancanti.</div>
    </div>
    <div class="card-b">
      <table class="table">
        <thead><tr><th>Tabella</th><th>Stato</th></tr></thead>
        <tbody>
        <?php foreach ($tableStatus as $r): ?>
          <tr>
            <td><code><?php echo htmlspecialchars($r['table_name']); ?></code></td>
            <td>
              <?php if ($r['exists'] === 'YES'): ?>
                <span class="pill ok">OK</span>
              <?php else: ?>
                <span class="pill fail">MISSING</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <div style="font-weight:700;">Tabelle senza PRIMARY KEY</div>
      <div class="muted"><?php echo count($tablesWithoutPk); ?> trovate</div>
    </div>
    <div class="card-b">
      <?php if (!count($tablesWithoutPk)): ?>
        <div class="muted">Nessuna tabella senza PK.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Tabella</th></tr></thead>
          <tbody>
          <?php foreach ($tablesWithoutPk as $r): ?>
            <tr><td><code><?php echo htmlspecialchars($r['table_name']); ?></code></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <div style="font-weight:700;">Mismatch UNSIGNED tra FK e PK</div>
      <div class="muted"><?php echo count($unsignedMismatches); ?> trovati</div>
    </div>
    <div class="card-b">
      <?php if (!count($unsignedMismatches)): ?>
        <div class="muted">Nessun mismatch rilevato.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Child</th><th>Colonna</th><th>Tipo</th>
              <th>Parent</th><th>Colonna</th><th>Tipo</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($unsignedMismatches as $r): ?>
            <tr>
              <td><code><?php echo htmlspecialchars($r['child_table']); ?></code></td>
              <td><code><?php echo htmlspecialchars($r['child_column']); ?></code></td>
              <td><code><?php echo htmlspecialchars($r['child_type']); ?></code></td>
              <td><code><?php echo htmlspecialchars($r['parent_table']); ?></code></td>
              <td><code><?php echo htmlspecialchars($r['parent_column']); ?></code></td>
              <td><code><?php echo htmlspecialchars($r['parent_type']); ?></code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="muted" style="margin-top:10px;">
          Nota: per i moduli nuovi (es. consulting) questi mismatch possono impedire l’aggiunta di foreign key. Applica la migrazione di “DB integrity” per correggere i tipi.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
</html>


