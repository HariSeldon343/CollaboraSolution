/**
 * CollaboraNexio - Company Filter JavaScript Module
 * Gestisce l'interazione con il filtro azienda lato client
 *
 * @version 1.0.0
 */

class CompanyFilterManager {
    constructor(options = {}) {
        this.options = {
            apiBaseUrl: '/CollaboraNexio/api',
            onFilterChange: null,
            autoReload: true,
            ...options
        };

        this.currentFilter = null;
        this.init();
    }

    /**
     * Inizializza il gestore del filtro
     */
    init() {
        // Ottieni il form del filtro
        this.filterForm = document.getElementById('companyFilterForm');
        this.filterSelect = document.getElementById('company_filter');

        if (this.filterForm && this.filterSelect) {
            this.attachEventListeners();
            this.getCurrentFilter();
        }
    }

    /**
     * Collega gli event listener
     */
    attachEventListeners() {
        // Previene il submit standard del form
        this.filterForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleFilterChange();
        });

        // Gestisce il cambio del select
        this.filterSelect.addEventListener('change', () => {
            this.handleFilterChange();
        });
    }

    /**
     * Gestisce il cambio del filtro
     */
    async handleFilterChange() {
        const selectedValue = this.filterSelect.value;
        const selectedText = this.filterSelect.options[this.filterSelect.selectedIndex].text;

        console.log('Company filter changed:', selectedValue, selectedText);

        // Mostra indicatore di caricamento
        this.showLoadingIndicator();

        try {
            // Salva il filtro via AJAX
            const saved = await this.saveFilter(selectedValue);

            if (saved) {
                this.currentFilter = {
                    id: selectedValue === 'all' ? null : parseInt(selectedValue),
                    name: selectedText
                };

                // Chiama il callback se definito
                if (typeof this.options.onFilterChange === 'function') {
                    this.options.onFilterChange(this.currentFilter);
                }

                // Ricarica i dati o la pagina se richiesto
                if (this.options.autoReload) {
                    this.reloadData();
                }

                // Mostra notifica di successo
                this.showNotification('Filtro azienda aggiornato', 'success');
            }
        } catch (error) {
            console.error('Error changing company filter:', error);
            this.showNotification('Errore nell\'aggiornamento del filtro', 'error');
        } finally {
            this.hideLoadingIndicator();
        }
    }

    /**
     * Salva il filtro selezionato sul server
     */
    async saveFilter(companyFilter) {
        try {
            const formData = new FormData();
            formData.append('company_filter', companyFilter);

            const response = await fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to save filter');
            }

            return true;
        } catch (error) {
            console.error('Error saving filter:', error);
            return false;
        }
    }

    /**
     * Ottiene il filtro corrente
     */
    getCurrentFilter() {
        if (this.filterSelect) {
            const value = this.filterSelect.value;
            const text = this.filterSelect.options[this.filterSelect.selectedIndex].text;

            this.currentFilter = {
                id: value === 'all' ? null : parseInt(value),
                name: text
            };
        }

        return this.currentFilter;
    }

    /**
     * Ricarica i dati della pagina
     */
    reloadData() {
        // Se esiste una funzione globale di reload, usala
        if (typeof window.reloadPageData === 'function') {
            window.reloadPageData();
        } else {
            // Altrimenti ricarica la pagina
            window.location.reload();
        }
    }

    /**
     * Mostra indicatore di caricamento
     */
    showLoadingIndicator() {
        if (this.filterSelect) {
            this.filterSelect.disabled = true;
            this.filterSelect.style.opacity = '0.5';
        }

        // Aggiungi spinner se esiste un elemento dedicato
        const spinner = document.querySelector('.company-filter-spinner');
        if (spinner) {
            spinner.style.display = 'inline-block';
        }
    }

    /**
     * Nasconde indicatore di caricamento
     */
    hideLoadingIndicator() {
        if (this.filterSelect) {
            this.filterSelect.disabled = false;
            this.filterSelect.style.opacity = '1';
        }

        const spinner = document.querySelector('.company-filter-spinner');
        if (spinner) {
            spinner.style.display = 'none';
        }
    }

    /**
     * Mostra notifica
     */
    showNotification(message, type = 'info') {
        // Se esiste un sistema di notifiche globale, usalo
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        } else if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
        } else {
            // Fallback a console log
            console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }

    /**
     * Applica il filtro ai parametri di una richiesta API
     */
    applyFilterToApiParams(params = {}) {
        if (this.currentFilter && this.currentFilter.id) {
            params.company_filter_id = this.currentFilter.id;
        }
        return params;
    }

    /**
     * Ottiene l'URL dell'API con il filtro applicato
     */
    getFilteredApiUrl(endpoint, params = {}) {
        const filteredParams = this.applyFilterToApiParams(params);
        const queryString = new URLSearchParams(filteredParams).toString();
        return `${this.options.apiBaseUrl}/${endpoint}${queryString ? '?' + queryString : ''}`;
    }

    /**
     * Helper per fare richieste API con il filtro applicato
     */
    async fetchWithFilter(endpoint, options = {}) {
        // Aggiungi il filtro ai parametri GET
        if (options.params) {
            options.params = this.applyFilterToApiParams(options.params);
        }

        // Costruisci l'URL
        const url = this.getFilteredApiUrl(endpoint, options.params);

        // Prepara le opzioni della fetch
        const fetchOptions = {
            method: options.method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.getCSRFToken(),
                ...options.headers
            },
            credentials: 'same-origin'
        };

        if (options.body) {
            fetchOptions.body = JSON.stringify(options.body);
        }

        try {
            const response = await fetch(url, fetchOptions);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('API request failed:', error);
            throw error;
        }
    }

    /**
     * Ottiene il token CSRF dalla pagina
     */
    getCSRFToken() {
        const tokenElement = document.getElementById('csrfToken');
        return tokenElement ? tokenElement.value : '';
    }

    /**
     * Resetta il filtro
     */
    async resetFilter() {
        if (this.filterSelect) {
            this.filterSelect.value = 'all';
            await this.handleFilterChange();
        }
    }

    /**
     * Imposta un filtro specifico
     */
    async setFilter(companyId) {
        if (this.filterSelect) {
            // Verifica che l'opzione esista
            const option = Array.from(this.filterSelect.options).find(
                opt => opt.value === companyId.toString()
            );

            if (option) {
                this.filterSelect.value = companyId;
                await this.handleFilterChange();
                return true;
            }
        }
        return false;
    }
}

// Esporta per uso globale
window.CompanyFilterManager = CompanyFilterManager;

// Inizializzazione automatica quando il DOM è pronto
document.addEventListener('DOMContentLoaded', () => {
    // Inizializza il gestore del filtro se esiste il componente
    if (document.getElementById('companyFilterForm')) {
        window.companyFilter = new CompanyFilterManager({
            onFilterChange: (filter) => {
                console.log('Filter changed to:', filter);

                // Trigger eventi personalizzati che altre parti dell'app possono ascoltare
                const event = new CustomEvent('companyFilterChanged', {
                    detail: filter
                });
                document.dispatchEvent(event);
            }
        });
    }
});

// Helper functions globali per retrocompatibilità
window.getCompanyFilter = function() {
    if (window.companyFilter) {
        return window.companyFilter.getCurrentFilter();
    }
    return null;
};

window.applyCompanyFilter = function(params) {
    if (window.companyFilter) {
        return window.companyFilter.applyFilterToApiParams(params);
    }
    return params;
};