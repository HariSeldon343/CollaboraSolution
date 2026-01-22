<?php
// Super Admin: DPO/Privacy registry and protocol recordkeeping
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/tenant_access_check.php';
require_once __DIR__ . '/includes/page_access_check.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

requireTenantAccess((int)$currentUser['id'], (string)$currentUser['role']);

if (($currentUser['role'] ?? '') !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

checkPageAccess('configurazioni');
$csrfToken = $auth->generateCSRFToken();

$pageTitle = 'Registro DPO/Privacy - Nexio';
$pageCss = ['assets/css/dashboard.css', 'assets/css/legal_pages.css'];
require __DIR__ . '/includes/layout_head.php';
?>
<!DOCTYPE html>
<html lang="it">
<?php require __DIR__ . '/includes/layout_start.php'; ?>

<div class="page-content legal-page">
    <div class="legal-header">
        <img class="legal-logo" src="assets/images/logo.svg" alt="Nexio">
        <div>
            <div class="legal-title">Registro Privacy/DPO (Autorità/Garante)</div>
            <div class="legal-meta">
                Gestione record e variazioni/revoche. Riferimento operativo attuale: <strong>prot. 20250009908</strong>.
            </div>
        </div>
    </div>

    <div class="legal-card">
        <div class="legal-note">
            Per la gestione verso l’Autorità, è coerente conservare la documentazione della comunicazione dei dati di contatto del RPD/DPO e gestire variazioni/revoche
            tramite la procedura indicata dal Garante. Questo pannello mantiene una cronologia delle modifiche (se la tabella è presente a DB).
        </div>

        <h3>Configurazione attuale</h3>
        <form id="dpoForm">
            <div class="form-row" style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label required" for="dpoProtocol">Numero protocollo (Garante)</label>
                    <input class="form-control" id="dpoProtocol" type="text" required placeholder="Es. 20250009908">
                </div>
                <div class="form-group">
                    <label class="form-label required" for="dpoRecipient">Email destinatario richieste</label>
                    <input class="form-control" id="dpoRecipient" type="email" required placeholder="Es. asamodeo@fortibyte.it">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label required" for="dpoPublicUrl">URL pubblico form contatti</label>
                <input class="form-control" id="dpoPublicUrl" type="url" required placeholder="https://.../privacy_contact.php">
            </div>
            <div class="form-group">
                <label class="form-label" for="dpoNote">Nota (facoltativa)</label>
                <textarea class="form-control" id="dpoNote" rows="3" placeholder="Motivo modifica, variazione, revoca..."></textarea>
            </div>
            <div class="config-actions" style="border-top:none; padding-top:0;">
                <button type="submit" class="btn btn--primary" id="dpoSaveBtn">Salva</button>
            </div>
        </form>

        <hr class="legal-divider">

        <h3>Cronologia modifiche</h3>
        <div id="dpoHistoryInfo" class="text-muted" style="margin-bottom: 10px;">Caricamento...</div>
        <div class="legal-table-wrap">
            <table class="legal-table" id="dpoHistoryTable">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Protocollo</th>
                        <th>Email</th>
                        <th>URL</th>
                        <th>Tipo</th>
                        <th>Nota</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<script>
async function dpoFetch(action, options = {}) {
    const url = `api/legal/dpo_registry.php?action=${encodeURIComponent(action)}&_ts=${Date.now()}`;
    const headers = options.headers || {};
    headers['X-CSRF-Token'] = document.getElementById('csrfToken').value;
    options.headers = headers;
    options.credentials = 'same-origin';
    const res = await fetch(url, options);
    const data = await res.json();
    if (!data || !data.success) throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore');
    return data.data || {};
}

async function loadCurrent() {
    const d = await dpoFetch('get_current');
    const c = d.current || {};
    document.getElementById('dpoProtocol').value = c.protocol_number || '20250009908';
    document.getElementById('dpoRecipient').value = c.recipient_email || 'asamodeo@fortibyte.it';
    document.getElementById('dpoPublicUrl').value = c.public_contact_url || 'https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php';
}

function renderHistory(rows, available) {
    const info = document.getElementById('dpoHistoryInfo');
    if (!available) {
        info.textContent = 'Cronologia non disponibile (tabella dpo_protocol_history mancante).';
    } else {
        info.textContent = `Ultime modifiche: ${rows.length}`;
    }

    const tbody = document.querySelector('#dpoHistoryTable tbody');
    tbody.innerHTML = (rows || []).map(r => {
        const dt = (r.changed_at || '').toString();
        return `<tr>
            <td>${dt}</td>
            <td>${(r.protocol_number || '')}</td>
            <td>${(r.recipient_email || '')}</td>
            <td>${(r.public_contact_url || '')}</td>
            <td>${(r.event_type || '')}</td>
            <td>${(r.note || '')}</td>
        </tr>`;
    }).join('');
}

async function loadHistory() {
    const d = await dpoFetch('get_history');
    renderHistory(d.history || [], !!d.history_storage_available);
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadCurrent();
    await loadHistory();

    document.getElementById('dpoForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('dpoSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Salvataggio...';
        try {
            await dpoFetch('update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: document.getElementById('csrfToken').value,
                    protocol_number: document.getElementById('dpoProtocol').value,
                    recipient_email: document.getElementById('dpoRecipient').value,
                    public_contact_url: document.getElementById('dpoPublicUrl').value,
                    note: document.getElementById('dpoNote').value
                })
            });
            document.getElementById('dpoNote').value = '';
            await loadHistory();
            alert('Configurazione DPO aggiornata');
        } catch (err) {
            alert('Errore: ' + (err && err.message ? err.message : 'Salvataggio fallito'));
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salva';
        }
    });
});
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>


