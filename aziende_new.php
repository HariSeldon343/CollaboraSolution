<?php
session_start();
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}
$currentUser = $auth->getCurrentUser();

// Solo super_admin può creare aziende
if ($currentUser['role'] !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova Azienda - CollaboraNexio</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/aziende.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>📊 Nuova Azienda</h1>
            <p>Crea una nuova azienda nel sistema</p>
        </div>

        <!-- Alert per mancanza managers -->
        <div id="managerAlert" class="alert alert-warning" style="display: none;">
            <strong>⚠️ Attenzione:</strong> Nessun manager disponibile. Crea prima un utente con ruolo Manager, Admin o Super Admin.
        </div>

        <form id="companyForm" class="company-form" novalidate>
            <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- SEZIONE 1: DATI IDENTIFICATIVI -->
            <div class="form-section">
                <h2>📋 Dati Identificativi</h2>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="denominazione">Denominazione *</label>
                        <input type="text" id="denominazione" name="denominazione" required>
                    </div>
                    <div class="form-group">
                        <label for="codice_fiscale">Codice Fiscale</label>
                        <input type="text" id="codice_fiscale" name="codice_fiscale"
                               pattern="[A-Z0-9]{16}" maxlength="16"
                               placeholder="16 caratteri alfanumerici"
                               style="text-transform: uppercase;">
                        <small class="form-hint">16 caratteri alfanumerici (es. RSSMRA80A01H501U)</small>
                    </div>
                    <div class="form-group">
                        <label for="partita_iva">Partita IVA</label>
                        <input type="text" id="partita_iva" name="partita_iva"
                               pattern="[0-9]{11}" maxlength="11"
                               placeholder="11 cifre">
                        <small class="form-hint">11 cifre (es. 12345678901). Almeno uno tra CF e P.IVA obbligatorio</small>
                    </div>
                </div>
            </div>

            <!-- SEZIONE 2: SEDE LEGALE -->
            <div class="form-section">
                <h2>🏢 Sede Legale</h2>
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label for="sede_indirizzo">Indirizzo *</label>
                        <input type="text" id="sede_indirizzo" name="sede_indirizzo" required
                               placeholder="Es. Via Roma">
                    </div>
                    <div class="form-group">
                        <label for="sede_civico">Numero Civico *</label>
                        <input type="text" id="sede_civico" name="sede_civico" required
                               placeholder="Es. 25/B">
                    </div>
                    <div class="form-group">
                        <label for="sede_comune">Comune *</label>
                        <input type="text" id="sede_comune" name="sede_comune" required
                               placeholder="Es. Milano">
                    </div>
                    <div class="form-group">
                        <label for="sede_provincia">Provincia *</label>
                        <select id="sede_provincia" name="sede_provincia" required>
                            <option value="">Seleziona provincia</option>
                            <option value="AG">Agrigento</option>
                            <option value="AL">Alessandria</option>
                            <option value="AN">Ancona</option>
                            <option value="AO">Aosta</option>
                            <option value="AR">Arezzo</option>
                            <option value="AP">Ascoli Piceno</option>
                            <option value="AT">Asti</option>
                            <option value="AV">Avellino</option>
                            <option value="BA">Bari</option>
                            <option value="BT">Barletta-Andria-Trani</option>
                            <option value="BL">Belluno</option>
                            <option value="BN">Benevento</option>
                            <option value="BG">Bergamo</option>
                            <option value="BI">Biella</option>
                            <option value="BO">Bologna</option>
                            <option value="BZ">Bolzano</option>
                            <option value="BS">Brescia</option>
                            <option value="BR">Brindisi</option>
                            <option value="CA">Cagliari</option>
                            <option value="CL">Caltanissetta</option>
                            <option value="CB">Campobasso</option>
                            <option value="CI">Carbonia-Iglesias</option>
                            <option value="CE">Caserta</option>
                            <option value="CT">Catania</option>
                            <option value="CZ">Catanzaro</option>
                            <option value="CH">Chieti</option>
                            <option value="CO">Como</option>
                            <option value="CS">Cosenza</option>
                            <option value="CR">Cremona</option>
                            <option value="KR">Crotone</option>
                            <option value="CN">Cuneo</option>
                            <option value="EN">Enna</option>
                            <option value="FM">Fermo</option>
                            <option value="FE">Ferrara</option>
                            <option value="FI">Firenze</option>
                            <option value="FG">Foggia</option>
                            <option value="FC">Forlì-Cesena</option>
                            <option value="FR">Frosinone</option>
                            <option value="GE">Genova</option>
                            <option value="GO">Gorizia</option>
                            <option value="GR">Grosseto</option>
                            <option value="IM">Imperia</option>
                            <option value="IS">Isernia</option>
                            <option value="SP">La Spezia</option>
                            <option value="AQ">L'Aquila</option>
                            <option value="LT">Latina</option>
                            <option value="LE">Lecce</option>
                            <option value="LC">Lecco</option>
                            <option value="LI">Livorno</option>
                            <option value="LO">Lodi</option>
                            <option value="LU">Lucca</option>
                            <option value="MC">Macerata</option>
                            <option value="MN">Mantova</option>
                            <option value="MS">Massa-Carrara</option>
                            <option value="MT">Matera</option>
                            <option value="ME">Messina</option>
                            <option value="MI">Milano</option>
                            <option value="MO">Modena</option>
                            <option value="MB">Monza e della Brianza</option>
                            <option value="NA">Napoli</option>
                            <option value="NO">Novara</option>
                            <option value="NU">Nuoro</option>
                            <option value="OT">Olbia-Tempio</option>
                            <option value="OR">Oristano</option>
                            <option value="PD">Padova</option>
                            <option value="PA">Palermo</option>
                            <option value="PR">Parma</option>
                            <option value="PV">Pavia</option>
                            <option value="PG">Perugia</option>
                            <option value="PU">Pesaro e Urbino</option>
                            <option value="PE">Pescara</option>
                            <option value="PC">Piacenza</option>
                            <option value="PI">Pisa</option>
                            <option value="PT">Pistoia</option>
                            <option value="PN">Pordenone</option>
                            <option value="PZ">Potenza</option>
                            <option value="PO">Prato</option>
                            <option value="RG">Ragusa</option>
                            <option value="RA">Ravenna</option>
                            <option value="RC">Reggio Calabria</option>
                            <option value="RE">Reggio Emilia</option>
                            <option value="RI">Rieti</option>
                            <option value="RN">Rimini</option>
                            <option value="RM">Roma</option>
                            <option value="RO">Rovigo</option>
                            <option value="SA">Salerno</option>
                            <option value="VS">Medio Campidano</option>
                            <option value="SS">Sassari</option>
                            <option value="SV">Savona</option>
                            <option value="SI">Siena</option>
                            <option value="SR">Siracusa</option>
                            <option value="SO">Sondrio</option>
                            <option value="SU">Sud Sardegna</option>
                            <option value="TA">Taranto</option>
                            <option value="TE">Teramo</option>
                            <option value="TR">Terni</option>
                            <option value="TO">Torino</option>
                            <option value="OG">Ogliastra</option>
                            <option value="TP">Trapani</option>
                            <option value="TN">Trento</option>
                            <option value="TV">Treviso</option>
                            <option value="TS">Trieste</option>
                            <option value="UD">Udine</option>
                            <option value="VA">Varese</option>
                            <option value="VE">Venezia</option>
                            <option value="VB">Verbano-Cusio-Ossola</option>
                            <option value="VC">Vercelli</option>
                            <option value="VR">Verona</option>
                            <option value="VV">Vibo Valentia</option>
                            <option value="VI">Vicenza</option>
                            <option value="VT">Viterbo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="sede_cap">CAP *</label>
                        <input type="text" id="sede_cap" name="sede_cap" required
                               pattern="[0-9]{5}" maxlength="5"
                               placeholder="Es. 20100">
                    </div>
                </div>
            </div>

            <!-- SEZIONE 3: SEDI OPERATIVE -->
            <div class="form-section">
                <div class="section-header">
                    <h2>🏭 Sedi Operative</h2>
                    <span class="sede-count">Sedi operative: <span id="sediCount">0</span>/5</span>
                </div>
                <div id="sediOperativeContainer">
                    <!-- Le sedi operative vengono aggiunte qui dinamicamente -->
                </div>
                <button type="button" id="btnAddSede" class="btn btn-secondary">
                    ➕ Aggiungi Sede Operativa
                </button>
            </div>

            <!-- SEZIONE 4: INFORMAZIONI AZIENDALI -->
            <div class="form-section">
                <h2>📊 Informazioni Aziendali</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="settore_merceologico">Settore Merceologico *</label>
                        <select id="settore_merceologico" name="settore_merceologico" required>
                            <option value="">Seleziona un settore</option>
                            <option value="IT">Tecnologia e IT</option>
                            <option value="Manifatturiero">Manifatturiero</option>
                            <option value="Servizi">Servizi</option>
                            <option value="Commercio">Commercio</option>
                            <option value="Edilizia">Edilizia</option>
                            <option value="Sanità">Sanità</option>
                            <option value="Altro">Altro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_dipendenti">Numero Dipendenti *</label>
                        <input type="number" id="numero_dipendenti" name="numero_dipendenti"
                               required min="0" placeholder="Es. 50">
                    </div>
                    <div class="form-group">
                        <label for="capitale_sociale">Capitale Sociale (€)</label>
                        <input type="number" id="capitale_sociale" name="capitale_sociale"
                               step="0.01" min="0" placeholder="Es. 100000.00">
                    </div>
                </div>
            </div>

            <!-- SEZIONE 5: CONTATTI -->
            <div class="form-section">
                <h2>📞 Contatti</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="telefono">Telefono *</label>
                        <input type="tel" id="telefono" name="telefono" required
                               placeholder="+39 02 1234567">
                    </div>
                    <div class="form-group">
                        <label for="email_aziendale">Email Aziendale *</label>
                        <input type="email" id="email_aziendale" name="email_aziendale" required
                               placeholder="info@azienda.it">
                    </div>
                    <div class="form-group span-2">
                        <label for="pec">PEC (Posta Elettronica Certificata) *</label>
                        <input type="email" id="pec" name="pec" required
                               placeholder="pec@azienda.it">
                    </div>
                </div>
            </div>

            <!-- SEZIONE 6: GESTIONE -->
            <div class="form-section">
                <h2>👤 Gestione</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="manager_aziendale">Manager Aziendale *</label>
                        <select id="manager_aziendale" name="manager_aziendale" required>
                            <option value="">Caricamento...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rappresentante_legale">Rappresentante Legale *</label>
                        <input type="text" id="rappresentante_legale" name="rappresentante_legale"
                               required placeholder="Es. Mario Rossi">
                    </div>
                    <div class="form-group">
                        <label for="stato">Stato *</label>
                        <select id="stato" name="stato" required>
                            <option value="Attivo">Attivo</option>
                            <option value="Sospeso">Sospeso</option>
                            <option value="Inattivo">Inattivo</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- BOTTONI -->
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='aziende.php'">
                    ❌ Annulla
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    🔄 Reset
                </button>
                <button type="submit" id="submitBtn" class="btn btn-primary">
                    💾 Salva Azienda
                </button>
            </div>
        </form>
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
        <p>Salvataggio in corso...</p>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <script src="js/aziende.js"></script>
    <script>
        // Funzione per resettare il form
        function resetForm() {
            if (confirm('Sei sicuro di voler cancellare tutti i dati inseriti?')) {
                document.getElementById('companyForm').reset();
                // Reset delle sedi operative
                document.getElementById('sediOperativeContainer').innerHTML = '';
                document.getElementById('sediCount').textContent = '0';
                // Re-carica i manager
                if (typeof loadManagers === 'function') {
                    loadManagers();
                }
            }
        }

        // Trasforma codice fiscale in maiuscolo automaticamente
        document.getElementById('codice_fiscale').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });
    </script>
</body>
</html>