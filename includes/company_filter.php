<?php
/**
 * Sistema di filtro aziende per utenti admin e super_admin
 * Fornisce un dropdown per filtrare i dati per azienda
 *
 * @author CollaboraNexio Backend Team
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

class CompanyFilter {
    private PDO $pdo;
    private ?array $currentUser;
    private array $availableCompanies = [];
    private ?int $activeCompanyFilter = null;

    /**
     * Costruttore del filtro aziende
     *
     * @param array|null $currentUser Dati dell'utente corrente
     */
    public function __construct(?array $currentUser = null) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->currentUser = $currentUser;

        if ($this->canUseCompanyFilter()) {
            $this->initializeFilter();
        }
    }

    /**
     * Verifica se l'utente può utilizzare il filtro aziende
     *
     * @return bool True se l'utente è admin o super_admin
     */
    public function canUseCompanyFilter(): bool {
        if (!$this->currentUser) {
            return false;
        }

        return in_array($this->currentUser['role'], ['admin', 'super_admin']);
    }

    /**
     * Inizializza il filtro caricando le aziende disponibili
     */
    private function initializeFilter(): void {
        // Inizializza la sessione se necessario
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Carica le aziende disponibili per l'utente
        $this->loadAvailableCompanies();

        // Gestisce la selezione del filtro
        $this->handleFilterSelection();

        // Recupera il filtro attivo dalla sessione
        if (isset($_SESSION['company_filter_id'])) {
            $this->activeCompanyFilter = (int)$_SESSION['company_filter_id'];
        }
    }

    /**
     * Carica le aziende disponibili per l'utente corrente
     */
    private function loadAvailableCompanies(): void {
        try {
            if ($this->currentUser['role'] === 'super_admin') {
                // Super admin può vedere tutte le aziende
                $stmt = $this->pdo->prepare("
                    SELECT id, name, status, domain
                    FROM tenants
                    WHERE status = 'active'
                    ORDER BY name ASC
                ");
                $stmt->execute();
            } else {
                // Admin può vedere solo le aziende assegnate
                $stmt = $this->pdo->prepare("
                    SELECT DISTINCT t.id, t.name, t.status, t.domain
                    FROM tenants t
                    LEFT JOIN user_companies uc ON uc.company_id = t.id
                    WHERE t.status = 'active'
                    AND (
                        t.id = :tenant_id
                        OR (uc.user_id = :user_id AND uc.company_id = t.id)
                    )
                    ORDER BY t.name ASC
                ");
                $stmt->execute([
                    ':tenant_id' => $this->currentUser['tenant_id'],
                    ':user_id' => $this->currentUser['id']
                ]);
            }

            $this->availableCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log('Errore caricamento aziende: ' . $e->getMessage());
            $this->availableCompanies = [];
        }
    }

    /**
     * Gestisce la selezione del filtro dal form
     */
    private function handleFilterSelection(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_filter'])) {
            $selectedFilter = $_POST['company_filter'];

            if ($selectedFilter === 'all') {
                $_SESSION['company_filter_id'] = null;
                $_SESSION['company_filter_name'] = 'Tutte le aziende';
            } else {
                $companyId = (int)$selectedFilter;

                // Verifica che l'azienda sia tra quelle disponibili
                $companyValid = false;
                foreach ($this->availableCompanies as $company) {
                    if ($company['id'] === $companyId) {
                        $_SESSION['company_filter_id'] = $companyId;
                        $_SESSION['company_filter_name'] = $company['name'];
                        $companyValid = true;
                        break;
                    }
                }

                if (!$companyValid) {
                    unset($_SESSION['company_filter_id']);
                    unset($_SESSION['company_filter_name']);
                }
            }
        }
    }

    /**
     * Renderizza il componente dropdown del filtro
     *
     * @param array $options Opzioni per il rendering
     * @return string HTML del componente
     */
    public function renderDropdown(array $options = []): string {
        if (!$this->canUseCompanyFilter() || empty($this->availableCompanies)) {
            return '';
        }

        $currentFilterId = $_SESSION['company_filter_id'] ?? null;
        $currentFilterName = $_SESSION['company_filter_name'] ?? 'Tutte le aziende';

        $html = '<div class="company-filter-wrapper">';
        $html .= '<form method="POST" id="companyFilterForm" class="company-filter-form">';
        $html .= '<div class="filter-select-group">';
        $html .= '<label for="company_filter" class="filter-label">Azienda:</label>';
        $html .= '<select name="company_filter" id="company_filter" class="filter-select" onchange="this.form.submit()">';

        // Opzione per vedere tutte le aziende
        $selected = ($currentFilterId === null) ? 'selected' : '';
        $html .= '<option value="all" ' . $selected . '>Tutte le aziende</option>';

        // Opzioni per ogni azienda disponibile
        foreach ($this->availableCompanies as $company) {
            $selected = ($currentFilterId === $company['id']) ? 'selected' : '';
            $statusBadge = ($company['status'] !== 'active') ? ' (Inattiva)' : '';
            $html .= sprintf(
                '<option value="%d" %s>%s%s</option>',
                $company['id'],
                $selected,
                htmlspecialchars($company['name']),
                $statusBadge
            );
        }

        $html .= '</select>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        // Aggiungi CSS inline se richiesto
        if (!isset($options['no_styles']) || !$options['no_styles']) {
            $html .= $this->getInlineStyles();
        }

        // Aggiungi JavaScript per auto-submit e gestione AJAX se richiesto
        if (!isset($options['no_scripts']) || !$options['no_scripts']) {
            $html .= $this->getInlineScripts();
        }

        return $html;
    }

    /**
     * Applica il filtro azienda a una query
     *
     * @param string $query Query SQL da modificare
     * @param string $tenantColumn Nome della colonna tenant_id (default: 'tenant_id')
     * @param array $params Parametri esistenti della query
     * @return array Array con query modificata e parametri aggiornati
     */
    public function applyFilterToQuery(string $query, string $tenantColumn = 'tenant_id', array $params = []): array {
        if (!$this->canUseCompanyFilter()) {
            return ['query' => $query, 'params' => $params];
        }

        $filterId = $this->getActiveFilterId();

        if ($filterId !== null) {
            // Aggiungi la condizione WHERE o AND a seconda che ci sia già un WHERE
            if (stripos($query, 'WHERE') !== false) {
                $query = str_replace('WHERE', "WHERE {$tenantColumn} = :filter_tenant_id AND ", $query);
            } else {
                // Se non c'è WHERE, lo aggiungiamo prima di GROUP BY, ORDER BY, o LIMIT
                $patterns = ['GROUP BY', 'ORDER BY', 'LIMIT'];
                $replaced = false;

                foreach ($patterns as $pattern) {
                    if (stripos($query, $pattern) !== false) {
                        $query = str_ireplace($pattern, "WHERE {$tenantColumn} = :filter_tenant_id " . $pattern, $query);
                        $replaced = true;
                        break;
                    }
                }

                if (!$replaced) {
                    $query .= " WHERE {$tenantColumn} = :filter_tenant_id";
                }
            }

            $params['filter_tenant_id'] = $filterId;
        }

        return ['query' => $query, 'params' => $params];
    }

    /**
     * Ottiene l'ID del filtro attivo
     *
     * @return int|null ID dell'azienda filtrata o null per tutte
     */
    public function getActiveFilterId(): ?int {
        return $this->activeCompanyFilter;
    }

    /**
     * Ottiene il nome dell'azienda filtrata
     *
     * @return string Nome dell'azienda o "Tutte le aziende"
     */
    public function getActiveFilterName(): string {
        return $_SESSION['company_filter_name'] ?? 'Tutte le aziende';
    }

    /**
     * Ottiene le aziende disponibili per l'utente
     *
     * @return array Lista delle aziende disponibili
     */
    public function getAvailableCompanies(): array {
        return $this->availableCompanies;
    }

    /**
     * Resetta il filtro azienda
     */
    public function resetFilter(): void {
        unset($_SESSION['company_filter_id']);
        unset($_SESSION['company_filter_name']);
        $this->activeCompanyFilter = null;
    }

    /**
     * Imposta manualmente un filtro azienda
     *
     * @param int $companyId ID dell'azienda da filtrare
     * @return bool True se il filtro è stato impostato con successo
     */
    public function setFilter(int $companyId): bool {
        foreach ($this->availableCompanies as $company) {
            if ($company['id'] === $companyId) {
                $_SESSION['company_filter_id'] = $companyId;
                $_SESSION['company_filter_name'] = $company['name'];
                $this->activeCompanyFilter = $companyId;
                return true;
            }
        }
        return false;
    }

    /**
     * Ottiene gli stili CSS inline per il componente
     *
     * @return string CSS inline
     */
    private function getInlineStyles(): string {
        return <<<CSS
<style>
.company-filter-wrapper {
    display: inline-flex;
    align-items: center;
    margin: 0 1rem;
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    backdrop-filter: blur(10px);
}

.company-filter-form {
    margin: 0;
}

.filter-select-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.filter-label {
    color: var(--color-sidebar-text-muted, #9ca3af);
    font-size: 0.875rem;
    font-weight: 500;
    white-space: nowrap;
}

.filter-select {
    min-width: 200px;
    padding: 0.5rem 2.5rem 0.5rem 0.75rem;
    font-size: 0.875rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 0.375rem;
    background-color: rgba(255, 255, 255, 0.05);
    color: var(--color-sidebar-text, #ffffff);
    cursor: pointer;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.5rem center;
    background-size: 1.25rem;
}

.filter-select:hover {
    border-color: rgba(255, 255, 255, 0.3);
    background-color: rgba(255, 255, 255, 0.1);
}

.filter-select:focus {
    outline: none;
    border-color: var(--color-primary, #3b82f6);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Tema chiaro */
@media (prefers-color-scheme: light) {
    .company-filter-wrapper {
        background: rgba(0, 0, 0, 0.05);
    }

    .filter-label {
        color: #4b5563;
    }

    .filter-select {
        border-color: #d1d5db;
        background-color: white;
        color: #1f2937;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 20 20' fill='%236b7280'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd'/%3E%3C/svg%3E");
    }

    .filter-select:hover {
        border-color: #9ca3af;
    }
}
</style>
CSS;
    }

    /**
     * Ottiene gli script JavaScript inline per il componente
     *
     * @return string JavaScript inline
     */
    private function getInlineScripts(): string {
        return <<<JS
<script>
(function() {
    const filterForm = document.getElementById('companyFilterForm');
    if (!filterForm) return;

    // Previeni doppio submit
    let isSubmitting = false;
    filterForm.addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }
        isSubmitting = true;

        // Mostra indicatore di caricamento
        const select = this.querySelector('select');
        if (select) {
            select.disabled = true;
            select.style.opacity = '0.5';
        }
    });
})();
</script>
JS;
    }
}

/**
 * Funzioni helper globali per retrocompatibilità
 */

/**
 * Crea e restituisce un'istanza del filtro aziende
 *
 * @param array|null $currentUser Dati dell'utente corrente
 * @return CompanyFilter Istanza del filtro
 */
function getCompanyFilter(?array $currentUser = null): CompanyFilter {
    return new CompanyFilter($currentUser);
}

/**
 * Renderizza il dropdown del filtro aziende
 *
 * @param array|null $currentUser Dati dell'utente corrente
 * @param array $options Opzioni per il rendering
 * @return string HTML del componente
 */
function renderCompanyFilter(?array $currentUser = null, array $options = []): string {
    $filter = new CompanyFilter($currentUser);
    return $filter->renderDropdown($options);
}

/**
 * Applica il filtro azienda a una query SQL
 *
 * @param string $query Query SQL originale
 * @param array|null $currentUser Dati dell'utente corrente
 * @param string $tenantColumn Nome della colonna tenant_id
 * @param array $params Parametri esistenti
 * @return array Query e parametri modificati
 */
function applyCompanyFilter(string $query, ?array $currentUser = null, string $tenantColumn = 'tenant_id', array $params = []): array {
    $filter = new CompanyFilter($currentUser);
    return $filter->applyFilterToQuery($query, $tenantColumn, $params);
}