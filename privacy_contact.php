<?php
// Privacy/DPO contact form (authenticated users)
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/company_filter.php';
require_once __DIR__ . '/includes/dpo_config.php';

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

// Require active tenant access
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess((int)$currentUser['id'], (string)$currentUser['role']);

$csrfToken = $auth->generateCSRFToken();
$db = Database::getInstance();
$tenantId = (int)($currentUser['tenant_id'] ?? 0);
$tenantRow = $tenantId > 0 ? $db->fetchOne("SELECT COALESCE(denominazione, name) AS tn FROM tenants WHERE id = ? LIMIT 1", [$tenantId]) : null;
$tenantName = (string)($tenantRow['tn'] ?? '');
$dpoCfg = cnx_get_dpo_config_from_database();
$protocolNumber = (string)($dpoCfg['protocol_number'] ?? '20250009908');

$pageTitle = 'Contatti Privacy/DPO - Nexio';
$pageCss = ['assets/css/dashboard.css'];
require __DIR__ . '/includes/layout_head.php';
?>
<!DOCTYPE html>
<html lang="it">
<?php require __DIR__ . '/includes/layout_start.php'; ?>

<div class="page-content" style="padding: 24px; max-width: 980px; margin: 0 auto;">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom: 14px;">
        <img src="assets/images/logo.svg" alt="Nexio" style="height:42px; width:auto;">
        <div>
            <div style="font-weight:800; font-size:22px; color: var(--color-gray-900);">Contatti Privacy/DPO</div>
            <div style="color: var(--color-gray-600); font-size: 13px;">
                Usa questo modulo per inviare richieste privacy (GDPR) o segnalazioni.
                Riferimento operativo: <strong>prot. <?php echo htmlspecialchars($protocolNumber, ENT_QUOTES, 'UTF-8'); ?></strong>.
                Le richieste vengono inoltrate al super user <strong>asamodeo@fortibyte.it</strong>.
                <?php if ($tenantName): ?> · Tenant: <strong><?php echo htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8'); ?></strong><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 18px; border-radius: 12px;">
        <div style="display:none;" id="privacyContactSuccess" class="alert-box info">
            <div>Segnalazione inviata con successo.</div>
        </div>
        <div style="display:none;" id="privacyContactError" class="alert-box warning">
            <div id="privacyContactErrorMsg">Errore durante l’invio.</div>
        </div>

        <form id="privacyContactForm">
            <div class="form-group">
                <label class="form-label required" for="requestType">Tipologia richiesta</label>
                <select id="requestType" class="form-control" required>
                    <option value="info">Informazioni</option>
                    <option value="access">Accesso ai dati (art. 15)</option>
                    <option value="rectification">Rettifica (art. 16)</option>
                    <option value="deletion">Cancellazione (art. 17)</option>
                    <option value="limitation">Limitazione (art. 18)</option>
                    <option value="objection">Opposizione (art. 21)</option>
                    <option value="portability">Portabilità (art. 20)</option>
                    <option value="other">Altro</option>
                </select>
                <div class="form-help">Scegli la categoria più vicina: aiuta a gestire la richiesta più rapidamente.</div>
            </div>

            <div class="form-group">
                <label class="form-label required" for="subject">Oggetto</label>
                <input id="subject" class="form-control" type="text" required minlength="4" placeholder="Es. Richiesta accesso ai dati / Segnalazione privacy" />
            </div>

            <div class="form-group">
                <label class="form-label required" for="message">Messaggio</label>
                <textarea id="message" class="form-control" rows="6" required minlength="20" placeholder="Scrivi qui la tua richiesta (min 20 caratteri)."></textarea>
                <div class="form-help">Non inserire password. Se devi segnalare un documento specifico, indica nome file/cartella e contesto.</div>
            </div>

            <div class="config-actions" style="border-top:none; padding-top:0;">
                <a class="btn btn--secondary" href="privacy.php">Torna a Informativa Privacy</a>
                <button type="submit" class="btn btn--primary" id="privacyContactSubmitBtn">Invia</button>
            </div>
        </form>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('privacyContactForm');
    const okEl = document.getElementById('privacyContactSuccess');
    const errEl = document.getElementById('privacyContactError');
    const errMsgEl = document.getElementById('privacyContactErrorMsg');
    const btn = document.getElementById('privacyContactSubmitBtn');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        okEl.style.display = 'none';
        errEl.style.display = 'none';

        btn.disabled = true;
        btn.textContent = 'Invio...';

        try {
            const resp = await fetch('api/legal/privacy_contact.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.getElementById('csrfToken').value
                },
                body: JSON.stringify({
                    csrf_token: document.getElementById('csrfToken').value,
                    request_type: document.getElementById('requestType').value,
                    subject: document.getElementById('subject').value,
                    message: document.getElementById('message').value
                })
            });

            const data = await resp.json();
            if (data && data.success) {
                okEl.style.display = 'flex';
                form.reset();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                errMsgEl.textContent = (data && (data.error || data.message)) ? (data.error || data.message) : 'Errore durante l’invio.';
                errEl.style.display = 'flex';
            }
        } catch (e2) {
            errMsgEl.textContent = 'Errore di connessione durante l’invio.';
            errEl.style.display = 'flex';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Invia';
        }
    });
});
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>


