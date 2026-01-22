<?php
// One-click migration tool for Migration 33 (dpo_protocol_history + settings seed)

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/db.php';

$auth = new Auth();
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if (!$auth->checkAuth()) {
        header('Location: ../index.php');
        exit;
    }
    $currentUser = $auth->getCurrentUser();
    if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
        header('Location: ../dashboard.php');
        exit;
    }
}

$sqlFile = __DIR__ . '/../database/migrations/33_dpo_protocol_registry.sql';
if (!file_exists($sqlFile)) {
    http_response_code(404);
    echo "Missing SQL file: {$sqlFile}";
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

function strip_sql_comments(string $sql): string {
    $sql = preg_replace('!/\\*.*?\\*/!s', '', $sql);
    $lines = preg_split("/\\r\\n|\\r|\\n/", $sql);
    $out = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}

function split_sql_statements(string $sql): array {
    $stmts = [];
    $buf = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';
        if ($ch === "'" && !$inDouble && $prev !== '\\') $inSingle = !$inSingle;
        if ($ch === '"' && !$inSingle && $prev !== '\\') $inDouble = !$inDouble;
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($buf);
            $buf = '';
            if ($stmt !== '') $stmts[] = $stmt;
            continue;
        }
        $buf .= $ch;
    }
    $last = trim($buf);
    if ($last !== '') $stmts[] = $last;
    return $stmts;
}

$raw = file_get_contents($sqlFile) ?: '';
$raw = strip_sql_comments($raw);
$statements = split_sql_statements($raw);

$errors = [];
$executed = 0;

try {
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        $trim = ltrim($stmt);
        $kw = strtoupper(strtok($trim, " \t\r\n"));
        if (in_array($kw, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true)) {
            $q = $conn->query($stmt);
            if ($q) { $q->fetchAll(); }
        } else {
            $conn->exec($stmt);
        }
        $executed++;
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($isCli) {
    if ($errors) {
        fwrite(STDERR, "ERROR: " . implode("\n", $errors) . "\n");
        exit(1);
    }
    echo "OK: Migration 33 executed statements: {$executed}\n";
    exit(0);
}

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apply Migration 33 - Nexio</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:24px;background:#f6f7fb;color:#111827}
    .card{max-width:920px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    .h{padding:16px 18px;border-bottom:1px solid #e5e7eb}
    .b{padding:18px}
    .ok{color:#065f46}
    .bad{color:#991b1b}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
    pre{background:#0b1220;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto}
  </style>
</head>
<body>
  <div class="card">
    <div class="h"><strong>Apply Migration 33</strong> — DPO protocol registry</div>
    <div class="b">
      <div>SQL file: <code><?php echo htmlspecialchars(basename($sqlFile)); ?></code></div>
      <div>Statements executed: <strong><?php echo (int)$executed; ?></strong></div>
      <?php if ($errors): ?>
        <h3 class="bad">Errore</h3>
        <pre><?php echo htmlspecialchars(implode("\n", $errors)); ?></pre>
      <?php else: ?>
        <h3 class="ok">OK</h3>
        <div>Ora <code>dpo_protocol_history</code> deve risultare OK in <code>tools/db_integrity_audit.php</code>.</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>


