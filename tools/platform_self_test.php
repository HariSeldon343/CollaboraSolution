<?php
// Platform Self-Test Tool (Super Admin only)
// Runs a lightweight same-origin fetch smoke-test for key pages/APIs.

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';

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

$csrfToken = $auth->generateCSRFToken();

?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Self Test - Nexio';
    require __DIR__ . '/../includes/layout_head.php';
?>
<style>
  .selftest-wrap { padding: var(--space-6); max-width: 1200px; }
  .selftest-card { background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); }
  .selftest-card-h { padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-gray-200); display:flex; align-items:center; justify-content:space-between; gap: var(--space-3);}
  .selftest-card-b { padding: var(--space-5); }
  .selftest-row { display:flex; flex-wrap:wrap; gap: var(--space-3); align-items:center; }
  .selftest-muted { color: var(--color-gray-600); font-size: var(--text-sm); }
  .selftest-table { width: 100%; border-collapse: collapse; margin-top: var(--space-4); }
  .selftest-table th, .selftest-table td { border-bottom: 1px solid var(--color-gray-100); padding: 10px 8px; font-size: var(--text-sm); vertical-align: top; }
  .selftest-table th { text-align:left; color: var(--color-gray-600); font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
  .pill { display:inline-flex; align-items:center; gap:6px; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:700; }
  .pill.ok { background:#ECFDF5; color:#065F46; }
  .pill.fail { background:#FEE2E2; color:#991B1B; }
  .pill.warn { background:#FFFBEB; color:#92400E; }
  code { font-size: 12px; }
</style>
</head>
<?php require __DIR__ . '/../includes/layout_start.php'; ?>

<div class="selftest-wrap">
  <div class="header">
    <h1 class="page-title">Self Test piattaforma</h1>
    <div class="selftest-muted">Esegue controlli “smoke” su pagine e API (same-origin). Utile dopo deploy/migrazioni.</div>
  </div>

  <div class="selftest-card">
    <div class="selftest-card-h">
      <div>
        <div style="font-weight:700;">Esecuzione test</div>
        <div class="selftest-muted">Nota: i test usano la tua sessione corrente (cookie). Se sei loggato ma ottieni 401, c’è un problema di sessione/cookie.</div>
      </div>
      <div class="selftest-row">
        <button type="button" class="btn btn-primary btn-sm" id="selfTestRunBtn">Avvia test</button>
        <button type="button" class="btn btn-secondary btn-sm" id="selfTestClearBtn">Pulisci</button>
      </div>
    </div>
    <div class="selftest-card-b">
      <div id="selfTestSummary" class="selftest-muted">Pronto.</div>
      <table class="selftest-table">
        <thead>
          <tr>
            <th style="width:260px;">Target</th>
            <th style="width:110px;">Esito</th>
            <th style="width:120px;">HTTP</th>
            <th>Dettagli</th>
          </tr>
        </thead>
        <tbody id="selfTestTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
  const CSRF = <?php echo json_encode($csrfToken); ?>;

  function addRow(target, status, httpCode, details) {
    const tbody = document.getElementById('selfTestTbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    const pill = status === 'ok' ? 'ok' : (status === 'warn' ? 'warn' : 'fail');
    tr.innerHTML = `
      <td><code>${escapeHtml(target)}</code></td>
      <td><span class="pill ${pill}">${status.toUpperCase()}</span></td>
      <td>${httpCode ? String(httpCode) : '-'}</td>
      <td class="selftest-muted">${escapeHtml(details || '')}</td>
    `;
    tbody.appendChild(tr);
  }

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  }

  async function fetchText(url) {
    const t0 = performance.now();
    const res = await fetch(url, { method: 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } });
    const text = await res.text().catch(() => '');
    return { res, text, ms: Math.round(performance.now() - t0) };
  }

  async function fetchJson(url, options = {}) {
    const t0 = performance.now();
    const res = await fetch(url, { credentials: 'same-origin', ...options, headers: { ...(options.headers || {}), 'X-CSRF-Token': CSRF } });
    const json = await res.json().catch(() => null);
    return { res, json, ms: Math.round(performance.now() - t0) };
  }

  async function runSelfTest() {
    const summary = document.getElementById('selfTestSummary');
    const tbody = document.getElementById('selfTestTbody');
    if (tbody) tbody.innerHTML = '';
    if (summary) summary.textContent = 'Esecuzione...';

    const pages = [
      'dashboard.php',
      'files.php',
      'calendar.php',
      'turni.php',
      'tasks.php',
      'ticket.php',
      'aziende.php',
      'utenti.php',
      'audit_log.php',
      'planning.php',
      'privacy.php',
      'privacy_contact.php',
      'cookie-policy.php',
      'configurazioni.php',
    ];

    const apis = [
      { kind: 'json', url: 'api/legal/status.php', name: 'api/legal/status.php' },
      { kind: 'json', url: 'api/system/page_visibility.php?action=get', name: 'api/system/page_visibility.php?action=get' },
      { kind: 'json', url: 'api/system/config.php?action=get', name: 'api/system/config.php?action=get' },
      { kind: 'json', url: 'api/consulting_plans/list.php', name: 'api/consulting_plans/list.php' },
      { kind: 'json', url: 'api/consulting_plans/activity_types.php', name: 'api/consulting_plans/activity_types.php' },
      { kind: 'json', url: 'api/consulting_plans/consultants.php', name: 'api/consulting_plans/consultants.php' },
    ];

    let ok = 0, fail = 0, warn = 0;

    for (const p of pages) {
      try {
        const { res, text, ms } = await fetchText(p + '?_st=' + Date.now());
        if (res.ok) {
          ok++;
          addRow(p, 'ok', res.status, `OK (${ms}ms, ${text.length} bytes)`);
        } else if (res.status === 302 || res.status === 401) {
          warn++;
          addRow(p, 'warn', res.status, `Redirect/unauthorized (${ms}ms). Controlla sessione/visibilità pagina.`);
        } else {
          fail++;
          addRow(p, 'fail', res.status, `HTTP non OK (${ms}ms).`);
        }
      } catch (e) {
        fail++;
        addRow(p, 'fail', '', e.message || String(e));
      }
    }

    for (const a of apis) {
      try {
        const { res, json, ms } = await fetchJson(a.url + (a.url.includes('?') ? '&' : '?') + '_st=' + Date.now(), { method: 'GET' });
        if (res.status === 401) {
          warn++;
          addRow(a.name, 'warn', res.status, '401 (sessione scaduta o cookie non inviato).');
          continue;
        }
        if (!res.ok) {
          fail++;
          addRow(a.name, 'fail', res.status, `HTTP non OK (${ms}ms).`);
          continue;
        }
        if (!json) {
          fail++;
          addRow(a.name, 'fail', res.status, `JSON non valido (${ms}ms).`);
          continue;
        }
        if (json.success === false) {
          fail++;
          addRow(a.name, 'fail', res.status, json.error || json.message || `success=false (${ms}ms).`);
          continue;
        }
        ok++;
        addRow(a.name, 'ok', res.status, `OK (${ms}ms).`);
      } catch (e) {
        fail++;
        addRow(a.name, 'fail', '', e.message || String(e));
      }
    }

    if (summary) summary.textContent = `Completato. OK=${ok}, WARN=${warn}, FAIL=${fail}.`;
  }

  document.getElementById('selfTestRunBtn')?.addEventListener('click', runSelfTest);
  document.getElementById('selfTestClearBtn')?.addEventListener('click', () => {
    const tbody = document.getElementById('selfTestTbody');
    const summary = document.getElementById('selfTestSummary');
    if (tbody) tbody.innerHTML = '';
    if (summary) summary.textContent = 'Pronto.';
  });
</script>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
</html>


