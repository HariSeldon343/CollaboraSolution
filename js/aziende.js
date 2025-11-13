/**
 * Gestione Aziende - JavaScript Module
 * Handles company form validation, dynamic fields, and API interactions
 */

class CompanyFormManager {
    constructor() {
        this.form = document.getElementById('companyForm');
        this.csrfToken = document.getElementById('csrfToken').value;
        this.managers = [];
        this.sediOperative = [];
        this.maxSediOperative = 5;
        this.provinces = this.getItalianProvinces();

        this.init();
    }

    init() {
        this.setupProvinceSelects();
        this.loadManagers();
        this.bindEvents();
        this.initializeSediOperative();
        this.setupValidation();
    }

    /**
     * Get Italian provinces list
     */
    getItalianProvinces() {
        return [
            { code: 'AG', name: 'Agrigento' },
            { code: 'AL', name: 'Alessandria' },
            { code: 'AN', name: 'Ancona' },
            { code: 'AO', name: 'Aosta' },
            { code: 'AR', name: 'Arezzo' },
            { code: 'AP', name: 'Ascoli Piceno' },
            { code: 'AT', name: 'Asti' },
            { code: 'AV', name: 'Avellino' },
            { code: 'BA', name: 'Bari' },
            { code: 'BT', name: 'Barletta-Andria-Trani' },
            { code: 'BL', name: 'Belluno' },
            { code: 'BN', name: 'Benevento' },
            { code: 'BG', name: 'Bergamo' },
            { code: 'BI', name: 'Biella' },
            { code: 'BO', name: 'Bologna' },
            { code: 'BZ', name: 'Bolzano' },
            { code: 'BS', name: 'Brescia' },
            { code: 'BR', name: 'Brindisi' },
            { code: 'CA', name: 'Cagliari' },
            { code: 'CL', name: 'Caltanissetta' },
            { code: 'CB', name: 'Campobasso' },
            { code: 'CI', name: 'Carbonia-Iglesias' },
            { code: 'CE', name: 'Caserta' },
            { code: 'CT', name: 'Catania' },
            { code: 'CZ', name: 'Catanzaro' },
            { code: 'CH', name: 'Chieti' },
            { code: 'CO', name: 'Como' },
            { code: 'CS', name: 'Cosenza' },
            { code: 'CR', name: 'Cremona' },
            { code: 'KR', name: 'Crotone' },
            { code: 'CN', name: 'Cuneo' },
            { code: 'EN', name: 'Enna' },
            { code: 'FM', name: 'Fermo' },
            { code: 'FE', name: 'Ferrara' },
            { code: 'FI', name: 'Firenze' },
            { code: 'FG', name: 'Foggia' },
            { code: 'FC', name: 'Forlì-Cesena' },
            { code: 'FR', name: 'Frosinone' },
            { code: 'GE', name: 'Genova' },
            { code: 'GO', name: 'Gorizia' },
            { code: 'GR', name: 'Grosseto' },
            { code: 'IM', name: 'Imperia' },
            { code: 'IS', name: 'Isernia' },
            { code: 'SP', name: 'La Spezia' },
            { code: 'AQ', name: "L'Aquila" },
            { code: 'LT', name: 'Latina' },
            { code: 'LE', name: 'Lecce' },
            { code: 'LC', name: 'Lecco' },
            { code: 'LI', name: 'Livorno' },
            { code: 'LO', name: 'Lodi' },
            { code: 'LU', name: 'Lucca' },
            { code: 'MC', name: 'Macerata' },
            { code: 'MN', name: 'Mantova' },
            { code: 'MS', name: 'Massa-Carrara' },
            { code: 'MT', name: 'Matera' },
            { code: 'ME', name: 'Messina' },
            { code: 'MI', name: 'Milano' },
            { code: 'MO', name: 'Modena' },
            { code: 'MB', name: 'Monza e Brianza' },
            { code: 'NA', name: 'Napoli' },
            { code: 'NO', name: 'Novara' },
            { code: 'NU', name: 'Nuoro' },
            { code: 'OT', name: 'Olbia-Tempio' },
            { code: 'OR', name: 'Oristano' },
            { code: 'PD', name: 'Padova' },
            { code: 'PA', name: 'Palermo' },
            { code: 'PR', name: 'Parma' },
            { code: 'PV', name: 'Pavia' },
            { code: 'PG', name: 'Perugia' },
            { code: 'PU', name: 'Pesaro e Urbino' },
            { code: 'PE', name: 'Pescara' },
            { code: 'PC', name: 'Piacenza' },
            { code: 'PI', name: 'Pisa' },
            { code: 'PT', name: 'Pistoia' },
            { code: 'PN', name: 'Pordenone' },
            { code: 'PZ', name: 'Potenza' },
            { code: 'PO', name: 'Prato' },
            { code: 'RG', name: 'Ragusa' },
            { code: 'RA', name: 'Ravenna' },
            { code: 'RC', name: 'Reggio Calabria' },
            { code: 'RE', name: 'Reggio Emilia' },
            { code: 'RI', name: 'Rieti' },
            { code: 'RN', name: 'Rimini' },
            { code: 'RM', name: 'Roma' },
            { code: 'RO', name: 'Rovigo' },
            { code: 'SA', name: 'Salerno' },
            { code: 'VS', name: 'Medio Campidano' },
            { code: 'SS', name: 'Sassari' },
            { code: 'SV', name: 'Savona' },
            { code: 'SI', name: 'Siena' },
            { code: 'SR', name: 'Siracusa' },
            { code: 'SO', name: 'Sondrio' },
            { code: 'TA', name: 'Taranto' },
            { code: 'TE', name: 'Teramo' },
            { code: 'TR', name: 'Terni' },
            { code: 'TO', name: 'Torino' },
            { code: 'OG', name: 'Ogliastra' },
            { code: 'TP', name: 'Trapani' },
            { code: 'TN', name: 'Trento' },
            { code: 'TV', name: 'Treviso' },
            { code: 'TS', name: 'Trieste' },
            { code: 'UD', name: 'Udine' },
            { code: 'VA', name: 'Varese' },
            { code: 'VE', name: 'Venezia' },
            { code: 'VB', name: 'Verbano-Cusio-Ossola' },
            { code: 'VC', name: 'Vercelli' },
            { code: 'VR', name: 'Verona' },
            { code: 'VV', name: 'Vibo Valentia' },
            { code: 'VI', name: 'Vicenza' },
            { code: 'VT', name: 'Viterbo' }
        ];
    }

    /**
     * Setup province select dropdowns
     */
    setupProvinceSelects() {
        const sedeProvinciaSelect = document.getElementById('sede_provincia');
        if (sedeProvinciaSelect) {
            this.provinces.forEach(prov => {
                const option = document.createElement('option');
                option.value = prov.code;
                option.textContent = `${prov.code} - ${prov.name}`;
                sedeProvinciaSelect.appendChild(option);
            });
        }
    }

    /**
     * Load managers from API
     */
    async loadManagers() {
        try {
            this.showLoading(true);

            // Call the API to get managers
            const response = await fetch('/CollaboraNexio/api/users/list_managers.php', {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.data) {
                this.managers = data.data;
                this.populateManagerSelect();
            } else {
                this.showNoManagersWarning();
            }
        } catch (error) {
            console.error('Error loading managers:', error);
            this.showNoManagersWarning();
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Populate manager select dropdown
     */
    populateManagerSelect() {
        const managerSelect = document.getElementById('manager_aziendale');
        if (!managerSelect) return;

        managerSelect.innerHTML = '<option value="">Seleziona un manager</option>';

        if (this.managers.length === 0) {
            this.showNoManagersWarning();
            return;
        }

        this.managers.forEach(manager => {
            const option = document.createElement('option');
            option.value = manager.id;
            option.textContent = `${manager.name} (${manager.email}) - ${manager.role}`;
            managerSelect.appendChild(option);
        });

        // Hide warning if managers are available
        const alert = document.getElementById('managerAlert');
        if (alert) alert.style.display = 'none';
    }

    /**
     * Show warning when no managers available
     */
    showNoManagersWarning() {
        const managerSelect = document.getElementById('manager_aziendale');
        const alert = document.getElementById('managerAlert');
        const submitBtn = document.getElementById('submitBtn');

        if (managerSelect) {
            managerSelect.innerHTML = '<option value="">Nessun manager disponibile</option>';
            managerSelect.disabled = true;
        }

        if (alert) {
            alert.style.display = 'flex';
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.title = 'Crea prima un utente manager';
        }
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Form submission
        if (this.form) {
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleSubmit();
            });
        }

        // Add sede operativa button
        const btnAddSede = document.getElementById('btnAddSede');
        if (btnAddSede) {
            btnAddSede.addEventListener('click', () => this.addSedeOperativa());
        }

        // Auto uppercase for Codice Fiscale
        const codiceFiscale = document.getElementById('codice_fiscale');
        if (codiceFiscale) {
            codiceFiscale.addEventListener('input', (e) => {
                e.target.value = e.target.value.toUpperCase();
            });
        }

        // Real-time validation
        this.setupRealtimeValidation();
    }

    /**
     * Setup real-time validation
     */
    setupRealtimeValidation() {
        // Codice Fiscale validation
        const cfInput = document.getElementById('codice_fiscale');
        if (cfInput) {
            cfInput.addEventListener('blur', () => {
                if (cfInput.value && !this.validateCodiceFiscale(cfInput.value)) {
                    this.showFieldError(cfInput.parentElement, 'Codice Fiscale non valido');
                } else {
                    this.clearFieldError(cfInput.parentElement);
                }
            });
        }

        // Partita IVA validation
        const pivaInput = document.getElementById('partita_iva');
        if (pivaInput) {
            pivaInput.addEventListener('blur', () => {
                if (pivaInput.value && !this.validatePartitaIVA(pivaInput.value)) {
                    this.showFieldError(pivaInput.parentElement, 'Partita IVA non valida');
                } else {
                    this.clearFieldError(pivaInput.parentElement);
                }
            });
        }

        // Email validation
        const emailInputs = ['email_aziendale', 'pec'];
        emailInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('blur', () => {
                    if (input.value && !this.validateEmail(input.value)) {
                        this.showFieldError(input.parentElement, 'Email non valida');
                    } else {
                        this.clearFieldError(input.parentElement);
                    }
                });
            }
        });

        // Phone validation
        const phoneInput = document.getElementById('telefono');
        if (phoneInput) {
            phoneInput.addEventListener('blur', () => {
                if (phoneInput.value && !this.validatePhone(phoneInput.value)) {
                    this.showFieldError(phoneInput.parentElement, 'Numero di telefono non valido');
                } else {
                    this.clearFieldError(phoneInput.parentElement);
                }
            });
        }
    }

    /**
     * Setup form validation
     */
    setupValidation() {
        // Custom validation for CF/PIVA (at least one required)
        const cfInput = document.getElementById('codice_fiscale');
        const pivaInput = document.getElementById('partita_iva');

        if (cfInput && pivaInput) {
            const validateCfPiva = () => {
                const cfValue = cfInput.value.trim();
                const pivaValue = pivaInput.value.trim();

                if (!cfValue && !pivaValue) {
                    cfInput.setCustomValidity('Inserire almeno uno tra Codice Fiscale e Partita IVA');
                    pivaInput.setCustomValidity('Inserire almeno uno tra Codice Fiscale e Partita IVA');
                } else {
                    cfInput.setCustomValidity('');
                    pivaInput.setCustomValidity('');
                }
            };

            cfInput.addEventListener('input', validateCfPiva);
            pivaInput.addEventListener('input', validateCfPiva);
        }
    }

    /**
     * Initialize sedi operative section
     */
    initializeSediOperative() {
        this.updateSediCount();
        // Start with one empty sede operativa (optional)
        this.addSedeOperativa();
    }

    /**
     * Add sede operativa
     */
    addSedeOperativa() {
        if (this.sediOperative.length >= this.maxSediOperative) {
            this.showToast('Massimo 5 sedi operative consentite', 'warning');
            return;
        }

        const sedeId = Date.now();
        const sedeIndex = this.sediOperative.length;

        const sedeHtml = `
            <div class="sede-container" data-sede-id="${sedeId}">
                <div class="sede-header">
                    <span class="sede-title">Sede Operativa ${sedeIndex + 1}</span>
                    <button type="button" class="btn-remove-sede" onclick="companyForm.removeSedeOperativa(${sedeId})">
                        ✕ Rimuovi
                    </button>
                </div>
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="sede_op_indirizzo_${sedeId}">
                            Indirizzo
                        </label>
                        <input type="text" id="sede_op_indirizzo_${sedeId}"
                               name="sedi_operative[${sedeIndex}][indirizzo]"
                               placeholder="Es. Via Milano">
                    </div>
                    <div class="form-group">
                        <label for="sede_op_civico_${sedeId}">
                            Civico
                        </label>
                        <input type="text" id="sede_op_civico_${sedeId}"
                               name="sedi_operative[${sedeIndex}][civico]"
                               placeholder="Es. 25/B">
                    </div>
                    <div class="form-group">
                        <label for="sede_op_comune_${sedeId}">
                            Comune
                        </label>
                        <input type="text" id="sede_op_comune_${sedeId}"
                               name="sedi_operative[${sedeIndex}][comune]"
                               placeholder="Es. Roma">
                    </div>
                    <div class="form-group">
                        <label for="sede_op_provincia_${sedeId}">
                            Provincia
                        </label>
                        <select id="sede_op_provincia_${sedeId}"
                                name="sedi_operative[${sedeIndex}][provincia]">
                            <option value="">Seleziona provincia</option>
                            ${this.provinces.map(prov =>
                                `<option value="${prov.code}">${prov.code} - ${prov.name}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="sede_op_cap_${sedeId}">
                            CAP
                        </label>
                        <input type="text" id="sede_op_cap_${sedeId}"
                               name="sedi_operative[${sedeIndex}][cap]"
                               pattern="^[0-9]{5}$" maxlength="5"
                               placeholder="Es. 00100">
                    </div>
                </div>
            </div>
        `;

        const container = document.getElementById('sediOperativeContainer');
        if (container) {
            container.insertAdjacentHTML('beforeend', sedeHtml);
            this.sediOperative.push(sedeId);
            this.updateSediCount();
            this.updateAddButton();
        }
    }

    /**
     * Remove sede operativa
     */
    removeSedeOperativa(sedeId) {
        const sedeElement = document.querySelector(`[data-sede-id="${sedeId}"]`);
        if (sedeElement) {
            sedeElement.remove();
            this.sediOperative = this.sediOperative.filter(id => id !== sedeId);
            this.updateSediCount();
            this.updateAddButton();
            this.reindexSediOperative();
        }
    }

    /**
     * Reindex sedi operative after removal
     */
    reindexSediOperative() {
        const sediContainers = document.querySelectorAll('.sede-container');
        sediContainers.forEach((container, index) => {
            // Update title
            const title = container.querySelector('.sede-title');
            if (title) title.textContent = `Sede Operativa ${index + 1}`;

            // Update input names
            const inputs = container.querySelectorAll('input, select');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace(/\[\d+\]/, `[${index}]`));
                }
            });
        });
    }

    /**
     * Update sedi count display
     */
    updateSediCount() {
        const countElement = document.getElementById('sediCount');
        if (countElement) {
            countElement.textContent = this.sediOperative.length;
        }
    }

    /**
     * Update add button state
     */
    updateAddButton() {
        const btnAdd = document.getElementById('btnAddSede');
        if (btnAdd) {
            btnAdd.disabled = this.sediOperative.length >= this.maxSediOperative;
        }
    }

    /**
     * Validate Codice Fiscale
     */
    validateCodiceFiscale(cf) {
        return /^[A-Z0-9]{16}$/.test(cf);
    }

    /**
     * Validate Partita IVA
     */
    validatePartitaIVA(piva) {
        if (!/^[0-9]{11}$/.test(piva)) return false;

        // Italian VAT number validation algorithm
        let sum = 0;
        for (let i = 0; i < 11; i++) {
            const digit = parseInt(piva.charAt(i));
            if (i % 2 === 0) {
                sum += digit;
            } else {
                const doubled = digit * 2;
                sum += doubled > 9 ? doubled - 9 : doubled;
            }
        }
        return sum % 10 === 0;
    }

    /**
     * Validate email
     */
    validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    /**
     * Validate phone
     */
    validatePhone(phone) {
        const re = /^(\+39)?[ ]?([0-9]{2,4})[ ]?([0-9]{5,10})$/;
        return re.test(phone);
    }

    /**
     * Validate form
     */
    validateForm() {
        let isValid = true;
        const errors = [];

        // Check CF or PIVA
        const cf = document.getElementById('codice_fiscale').value.trim();
        const piva = document.getElementById('partita_iva').value.trim();

        if (!cf && !piva) {
            errors.push('Inserire almeno uno tra Codice Fiscale e Partita IVA');
            isValid = false;
        }

        // Validate CF if provided
        if (cf && !this.validateCodiceFiscale(cf)) {
            errors.push('Codice Fiscale non valido');
            isValid = false;
        }

        // Validate PIVA if provided
        if (piva && !this.validatePartitaIVA(piva)) {
            errors.push('Partita IVA non valida');
            isValid = false;
        }

        // Check required fields
        const requiredFields = [
            'denominazione',
            'sede_indirizzo',
            'sede_civico',
            'sede_comune',
            'sede_provincia',
            'sede_cap',
            'settore_merceologico',
            'numero_dipendenti',
            'telefono',
            'email_aziendale',
            'pec',
            'manager_aziendale',
            'rappresentante_legale',
            'stato'
        ];

        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field && !field.value.trim()) {
                const label = field.parentElement.querySelector('label');
                const fieldName = label ? label.textContent.replace('*', '').trim() : fieldId;
                errors.push(`${fieldName} è obbligatorio`);
                isValid = false;
                this.showFieldError(field.parentElement);
            }
        });

        // Validate email fields
        ['email_aziendale', 'pec'].forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field && field.value && !this.validateEmail(field.value)) {
                const label = field.parentElement.querySelector('label');
                const fieldName = label ? label.textContent.replace('*', '').trim() : fieldId;
                errors.push(`${fieldName} non valida`);
                isValid = false;
                this.showFieldError(field.parentElement);
            }
        });

        // Validate phone
        const phoneField = document.getElementById('telefono');
        if (phoneField && phoneField.value && !this.validatePhone(phoneField.value)) {
            errors.push('Numero di telefono non valido');
            isValid = false;
            this.showFieldError(phoneField.parentElement);
        }

        if (!isValid) {
            this.showToast(errors.join('. '), 'error');
        }

        return isValid;
    }

    /**
     * Show field error
     */
    showFieldError(fieldGroup, message = null) {
        fieldGroup.classList.add('error');
        if (message) {
            const errorMsg = fieldGroup.querySelector('.error-message');
            if (errorMsg) {
                errorMsg.textContent = message;
            }
        }
    }

    /**
     * Clear field error
     */
    clearFieldError(fieldGroup) {
        fieldGroup.classList.remove('error');
    }

    /**
     * Handle form submission
     */
    async handleSubmit() {
        // Clear previous errors
        document.querySelectorAll('.form-group').forEach(group => {
            this.clearFieldError(group);
        });

        // Validate form
        if (!this.validateForm()) {
            return;
        }

        // Prepare form data
        const formData = this.prepareFormData();

        try {
            this.showLoading(true);

            // Submit to API
            const response = await fetch('/CollaboraNexio/api/tenants/create.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Azienda creata con successo!', 'success');

                // Reset form after 2 seconds and redirect
                setTimeout(() => {
                    this.resetForm();
                    window.location.href = '/CollaboraNexio/aziende.php';
                }, 2000);
            } else {
                this.showToast(data.message || 'Errore durante la creazione dell\'azienda', 'error');
            }
        } catch (error) {
            console.error('Error submitting form:', error);
            this.showToast('Errore di connessione. Riprova più tardi.', 'error');
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Prepare form data for submission
     */
    prepareFormData() {
        const formData = {
            // Identificazione
            denominazione: document.getElementById('denominazione').value.trim(),
            codice_fiscale: document.getElementById('codice_fiscale').value.trim(),
            partita_iva: document.getElementById('partita_iva').value.trim(),

            // Sede Legale
            sede_legale: {
                indirizzo: document.getElementById('sede_indirizzo').value.trim(),
                civico: document.getElementById('sede_civico').value.trim(),
                comune: document.getElementById('sede_comune').value.trim(),
                provincia: document.getElementById('sede_provincia').value,
                cap: document.getElementById('sede_cap').value.trim()
            },

            // Sedi Operative
            sedi_operative: this.collectSediOperative(),

            // Informazioni Aziendali
            settore_merceologico: document.getElementById('settore_merceologico').value,
            numero_dipendenti: parseInt(document.getElementById('numero_dipendenti').value),
            capitale_sociale: parseFloat(document.getElementById('capitale_sociale').value) || null,

            // Contatti
            telefono: document.getElementById('telefono').value.trim(),
            email_aziendale: document.getElementById('email_aziendale').value.trim(),
            pec: document.getElementById('pec').value.trim(),

            // Gestione
            manager_user_id: document.getElementById('manager_aziendale').value,
            rappresentante_legale: document.getElementById('rappresentante_legale').value.trim(),
            stato: document.getElementById('stato').value
        };

        return formData;
    }

    /**
     * Collect sedi operative data
     */
    collectSediOperative() {
        const sedi = [];
        const sediContainers = document.querySelectorAll('.sede-container');

        sediContainers.forEach(container => {
            const sedeId = container.dataset.sedeId;
            const indirizzo = container.querySelector(`[id^="sede_op_indirizzo_"]`).value.trim();
            const civico = container.querySelector(`[id^="sede_op_civico_"]`).value.trim();
            const comune = container.querySelector(`[id^="sede_op_comune_"]`).value.trim();
            const provincia = container.querySelector(`[id^="sede_op_provincia_"]`).value;
            const cap = container.querySelector(`[id^="sede_op_cap_"]`).value.trim();

            // Only include if at least one field has value
            if (indirizzo || civico || comune || provincia || cap) {
                sedi.push({
                    indirizzo,
                    civico,
                    comune,
                    provincia,
                    cap
                });
            }
        });

        return sedi;
    }

    /**
     * Reset form
     */
    resetForm() {
        if (this.form) {
            this.form.reset();

            // Clear sedi operative
            const container = document.getElementById('sediOperativeContainer');
            if (container) {
                container.innerHTML = '';
            }
            this.sediOperative = [];

            // Reinitialize
            this.initializeSediOperative();

            // Clear errors
            document.querySelectorAll('.form-group.error').forEach(group => {
                this.clearFieldError(group);
            });

            // Reset stato to default
            document.getElementById('stato').value = 'Attivo';
        }
    }

    /**
     * Show/hide loading overlay
     */
    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.toggle('active', show);
        }
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        if (toast) {
            toast.textContent = message;
            toast.className = `toast show ${type}`;

            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }
    }
}

// Global functions for inline handlers
function resetForm() {
    if (window.companyForm) {
        window.companyForm.resetForm();
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize if form exists (super admin check)
    if (document.getElementById('companyForm')) {
        window.companyForm = new CompanyFormManager();
    }
});