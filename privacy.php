<?php
// Privacy Policy (GDPR) - Nexio
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/legal_policy.php';
require_once __DIR__ . '/includes/company_filter.php';

$auth = new Auth();
$isAuthed = $auth->checkAuth();
$currentUser = $isAuthed ? $auth->getCurrentUser() : null;

// Resolve tenant context similarly to API/legal/status.php
$role = (string)($currentUser['role'] ?? ($_SESSION['role'] ?? 'user'));
$tenantId = (int)($currentUser['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
if (in_array($role, ['admin', 'super_admin'], true)) {
    $cfId = (int)($_SESSION['company_filter_id'] ?? 0);
    if ($cfId > 0) {
        $tenantId = $cfId;
    } else {
        $cfIds = $_SESSION['company_filter_ids'] ?? [];
        if (is_array($cfIds) && !empty($cfIds)) {
            $first = (int)($cfIds[0] ?? 0);
            if ($first > 0) $tenantId = $first;
        }
    }
}

$db = Database::getInstance();
$tenantName = $tenantId > 0 ? (cnx_get_tenant_display_name($db, $tenantId) ?? '') : '';
$tenantSector = $tenantId > 0 ? (cnx_get_tenant_sector($db, $tenantId) ?? '') : '';
$policyVersion = cnx_get_legal_policy_version(); // internal (ack/versioning), not user-facing

// Page chrome
$pageTitle = 'Informativa Privacy - Nexio';
$pageCss = ['assets/css/dashboard.css', 'assets/css/legal_pages.css'];
require __DIR__ . '/includes/layout_head.php';

function cnx_h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <!-- CNX_POLICY_VERSION: <?php echo cnx_h($policyVersion); ?> -->
</head>
<?php if ($isAuthed): ?>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
<?php endif; ?>

<div class="page-content legal-page">
    <div class="legal-header">
        <img class="legal-logo" src="assets/images/logo.svg" alt="Nexio">
        <div>
            <div class="legal-title">NEXIO — Informativa Privacy (GDPR)</div>
            <div class="legal-meta">Versione: <strong>1.0</strong> — Data: <strong>29/12/2025</strong></div>
        </div>
    </div>

    <div class="legal-card">
        <h3>1. Chi tratta i dati</h3>
        <p><strong>Titolare del trattamento (tenant)</strong>: l’azienda/ente che ti ha abilitato l’accesso a Nexio, per i dati presenti nel proprio ambiente (documenti, workflow, turni, task, ticket e log operativi).</p>
        <p><strong>Fornitore piattaforma</strong>: Nexio (fornitore del servizio), che opera tipicamente come Responsabile del trattamento ai sensi dell’art. 28 GDPR, secondo accordi con il Titolare.</p>

        <h3>2. Contatti Privacy/DPO</h3>
        <p>Per richieste privacy e/o contatti DPO (se designato dal Titolare), usa il modulo:</p>
        <p><a class="legal-link" href="https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php">https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php</a></p>
        <div class="legal-note">Nota: i contatti del DPO vanno resi disponibili agli interessati quando applicabile.</div>

        <h3>3. Quali dati tratta Nexio</h3>
        <p><strong>Dati account</strong>: nome, email, ruolo, tenant, informazioni di accesso.</p>
        <p><strong>Dati operativi</strong>: documenti e metadati (es. nome file, autore, timestamp, assegnazioni), workflow, turni, task, ticket.</p>
        <p><strong>Dati tecnici</strong>: indirizzo IP, user agent, identificatori di sessione; eventi di audit log (se abilitato).</p>

        <h3>4. Perché li tratta (finalità)</h3>
        <ul>
            <li>Erogazione delle funzionalità Nexio (gestione documentale, collaborazione, workflow, turni, task, ticket).</li>
            <li>Sicurezza e prevenzione accessi non autorizzati (permessi, logging, integrità).</li>
            <li>Supporto tecnico e manutenzione.</li>
            <li>Tracciabilità/Compliance operativa tramite audit log (se abilitato dal tenant).</li>
        </ul>

        <h3>5. Base giuridica (in sintesi)</h3>
        <p>La base giuridica dipende dal contesto del Titolare (es. esecuzione del servizio/rapporto, obblighi di legge, legittimo interesse per sicurezza).</p>

        <h3>6. Destinatari e trasferimenti</h3>
        <ul>
            <li>Accesso consentito a utenti autorizzati del tenant secondo ruoli/permessi.</li>
            <li>Possibile accesso da parte di fornitori tecnici (sub‑responsabili) per erogazione/assistenza.</li>
            <li>Eventuali trasferimenti extra‑UE, se presenti, devono essere gestiti con garanzie adeguate (es. SCC).</li>
        </ul>

        <h3>7. Conservazione</h3>
        <p><strong>Contenuti (documenti, workflow, turni, task, ticket)</strong>: secondo le regole di conservazione definite dal Titolare.</p>
        <p><strong>Log tecnici/audit</strong>: secondo configurazioni del tenant e requisiti di sicurezza/legge.</p>

        <h3>8. Sicurezza (misure principali)</h3>
        <ul>
            <li>Controlli di accesso basati su ruoli e assegnazioni (utente/gruppo).</li>
            <li>Isolamento logico tra tenant (segregazione dati).</li>
            <li>Protezioni di sessione e tracciamenti operativi ove previsti.</li>
        </ul>

        <h3>9. Diritti dell’interessato</h3>
        <p>Puoi esercitare i diritti GDPR (artt. 15–22) rivolgendoti al Titolare del tuo tenant e/o tramite il modulo:</p>
        <p><a class="legal-link" href="https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php">https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php</a></p>

        <hr class="legal-divider">
        <p><strong>Link informativa online</strong>:</p>
        <p><a class="legal-link" href="https://app.nexiosolution.it/CollaboraNexio/privacy.php">https://app.nexiosolution.it/CollaboraNexio/privacy.php</a></p>
        <?php if ($tenantSector === 'sanita'): ?>
            <div class="legal-note">
                <strong>Nota settore sanitario</strong>: in contesti sanitari possono essere trattati dati particolari (art. 9 GDPR). La liceità e le basi giuridiche specifiche sono in carico al Titolare.
            </div>
        <?php endif; ?>
    </div>
</div>

        <hr style="margin: 18px 0; border: 0; border-top: 1px solid var(--color-gray-200);">
        <p style="color: var(--color-gray-500); font-size: 12px; margin-bottom: 0;">
            Documento informativo. Il testo può essere personalizzato dal Titolare con contatti specifici (referente privacy/DPO) e policy interne.
        </p>
    </div>
</div>

<?php if ($isAuthed): ?>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
<?php else: ?>
</body>
</html>
<?php endif; ?>


