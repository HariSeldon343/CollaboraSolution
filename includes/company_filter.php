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
    private ?array $activeCompanyFilters = null; // list of tenant IDs (null => all)

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
        $this->activeCompanyFilters = $this->normalizeSessionCompanyFilterIds();
        if (is_array($this->activeCompanyFilters) && !empty($this->activeCompanyFilters)) {
            $this->activeCompanyFilter = (int)$this->activeCompanyFilters[0];
        } else {
            // null => all companies; keep scalar null as well
            $this->activeCompanyFilter = null;
        }
    }

    /**
     * Normalize company filter selection from session.
     * - Supports legacy scalar company_filter_id
     * - Supports multi-select company_filter_ids (array)
     * - Returns null when "all companies" is selected
     */
    private function normalizeSessionCompanyFilterIds(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ids = $_SESSION['company_filter_ids'] ?? null;
        if (is_array($ids)) {
            $clean = [];
            foreach ($ids as $v) {
                $id = (int)$v;
                if ($id > 0) $clean[] = $id;
            }
            $clean = array_values(array_unique($clean));
            return !empty($clean) ? $clean : null;
        }

        // Legacy scalar
        if (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null && $_SESSION['company_filter_id'] !== '') {
            $id = (int)$_SESSION['company_filter_id'];
            return $id > 0 ? [$id] : null;
        }

        return null;
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
                    WHERE status = 'active' AND deleted_at IS NULL
                    ORDER BY name ASC
                ");
                $stmt->execute();
            } else {
                // BUG-144 FIX: Admin can see assigned companies via user_tenant_access
                $stmt = $this->pdo->prepare("
                    SELECT DISTINCT t.id, t.name, t.status, t.domain
                    FROM tenants t
                    LEFT JOIN user_tenant_access uta ON uta.tenant_id = t.id AND uta.deleted_at IS NULL
                    WHERE t.status = 'active' AND t.deleted_at IS NULL
                    AND (
                        t.id = :tenant_id
                        OR uta.user_id = :user_id
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Multi-select form uses company_filter[]; legacy uses company_filter
        $raw = $_POST['company_filter'] ?? null;
        if ($raw === null) {
            $raw = $_POST['company_filter_multi'] ?? null;
        }
        if ($raw === null) {
            return;
        }

        // Build allowlist of available company IDs
        $allowed = [];
        foreach ($this->availableCompanies as $company) {
            $allowed[(int)$company['id']] = (string)$company['name'];
        }

        // "All companies" (single-select legacy)
        if (is_string($raw) && $raw === 'all') {
            $_SESSION['company_filter_ids'] = null;
            $_SESSION['company_filter_id'] = null;
            $_SESSION['company_filter_name'] = 'Tutte le aziende';
            return;
        }

        // Multi-select array
        $selectedIds = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if ($v === 'all') {
                    // "All" wins: clear selection
                    $_SESSION['company_filter_ids'] = null;
                    $_SESSION['company_filter_id'] = null;
                    $_SESSION['company_filter_name'] = 'Tutte le aziende';
                    return;
                }
                $id = (int)$v;
                if ($id > 0 && isset($allowed[$id])) {
                    $selectedIds[] = $id;
                }
            }
        } else {
            // Single value but not 'all'
            $id = (int)$raw;
            if ($id > 0 && isset($allowed[$id])) {
                $selectedIds[] = $id;
            }
        }

        $selectedIds = array_values(array_unique($selectedIds));

        if (empty($selectedIds)) {
            // Invalid selection => clear filter
            $_SESSION['company_filter_ids'] = null;
            $_SESSION['company_filter_id'] = null;
            $_SESSION['company_filter_name'] = 'Tutte le aziende';
            return;
        }

        // Persist multi-selection and keep legacy scalar for backward compatibility
        $_SESSION['company_filter_ids'] = $selectedIds;
        $_SESSION['company_filter_id'] = $selectedIds[0];

        if (count($selectedIds) === 1) {
            $_SESSION['company_filter_name'] = $allowed[$selectedIds[0]] ?? 'Azienda';
        } else {
            $_SESSION['company_filter_name'] = count($selectedIds) . ' aziende selezionate';
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

        $currentFilterIds = $this->normalizeSessionCompanyFilterIds(); // null => all
        $isAll = ($currentFilterIds === null);

        // Summary label on trigger
        $summary = 'Tutte le aziende';
        if (!$isAll && is_array($currentFilterIds)) {
            if (count($currentFilterIds) === 1) {
                $only = $currentFilterIds[0];
                $name = null;
                foreach ($this->availableCompanies as $c) {
                    if ((int)$c['id'] === (int)$only) {
                        $name = (string)($c['name'] ?? null);
                        break;
                    }
                }
                $summary = $name ?: '1 azienda';
            } else {
                $summary = count($currentFilterIds) . ' aziende';
            }
        }

        $html = '<div class="company-filter" data-cnx-company-filter>';
        $html .= '<form method="POST" id="companyFilterForm" class="company-filter-form" autocomplete="off">';

        $html .= '<button type="button" class="company-filter-trigger" data-cnx-company-filter-trigger="" aria-expanded="false">';
        $html .= '<span class="company-filter-label">Azienda:</span>';
        $html .= '<span class="company-filter-value">' . htmlspecialchars($summary) . '</span>';
        $html .= '<span class="company-filter-chevron" aria-hidden="true"></span>';
        $html .= '</button>';

        $html .= '<div class="company-filter-popover" data-cnx-company-filter-popover="" hidden>';
        $html .= '<div class="company-filter-search-wrap">';
        $html .= '<input type="text" class="company-filter-search" data-cnx-company-filter-search="" placeholder="Cerca azienda..." autocomplete="off" spellcheck="false">';
        $html .= '</div>';
        $html .= '<div class="company-filter-options" role="menu" aria-label="Seleziona aziende">';

        // "All companies" (exclusive)
        $html .= '<label class="company-filter-option">';
        $html .= '<input type="checkbox" name="company_filter[]" value="all" ' . ($isAll ? 'checked' : '') . '>';
        $html .= '<span>Tutte le aziende</span>';
        $html .= '</label>';

        // Companies
        foreach ($this->availableCompanies as $company) {
            $id = (int)$company['id'];
            $name = (string)($company['name'] ?? '');
            $checked = (!$isAll && is_array($currentFilterIds) && in_array($id, $currentFilterIds, true)) ? 'checked' : '';
            $html .= '<label class="company-filter-option">';
            $html .= '<input type="checkbox" name="company_filter[]" value="' . (int)$id . '" ' . $checked . '>';
            $html .= '<span>' . htmlspecialchars($name) . '</span>';
            $html .= '</label>';
        }

        $html .= '<div class="company-filter-empty" data-cnx-company-filter-empty="" hidden>Nessun risultato</div>';
        $html .= '</div>';

        $html .= '<div class="company-filter-actions">';
        $html .= '<button type="submit" class="btn btn-primary" data-cnx-company-filter-apply="">Applica</button>';
        $html .= '<button type="button" class="btn" data-cnx-company-filter-close="">Chiudi</button>';
        $html .= '</div>';

        $html .= '</div>'; // popover
        $html .= '</form>';
        $html .= '</div>'; // wrapper

        // Inline assets are now opt-in only (default off) to avoid breaking layout across pages.
        if (!empty($options['inline_assets'])) {
            $html .= $this->getInlineStyles();
            $html .= $this->getInlineScripts();
        }

        return $html;
    }

    /**
     * Get active filter IDs (null => all companies)
     */
    public function getActiveFilterIds(): ?array {
        return $this->normalizeSessionCompanyFilterIds();
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
        unset($_SESSION['company_filter_ids']);
        unset($_SESSION['company_filter_id']);
        unset($_SESSION['company_filter_name']);
        $this->activeCompanyFilter = null;
        $this->activeCompanyFilters = null;
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
                $_SESSION['company_filter_ids'] = [$companyId];
                $_SESSION['company_filter_id'] = $companyId;
                $_SESSION['company_filter_name'] = $company['name'];
                $this->activeCompanyFilter = $companyId;
                $this->activeCompanyFilters = [$companyId];
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
    const select = document.getElementById('company_filter');
    if (!select) return;

    // Previeni doppio submit
    let isSubmitting = false;
    let changeTimer = null;

    function normalizeSelection() {
        const opts = Array.from(select.options);
        const allOpt = opts.find(o => o.value === 'all');
        if (!allOpt) return;

        const selected = opts.filter(o => o.selected).map(o => o.value);
        const hasAll = selected.includes('all');

        if (hasAll && selected.length > 1) {
            // If "all" is selected, clear other selections
            opts.forEach(o => { o.selected = (o.value === 'all'); });
        } else if (!hasAll && selected.length > 0) {
            // If selecting specific companies, ensure "all" is not selected
            allOpt.selected = false;
        } else if (selected.length === 0) {
            // If nothing is selected, default back to "all"
            allOpt.selected = true;
        }
    }

    function scheduleSubmit() {
        if (changeTimer) clearTimeout(changeTimer);
        // Allow multi-click selection (Ctrl/Cmd) then submit after a short idle
        changeTimer = setTimeout(() => {
            if (isSubmitting) return;
            filterForm.requestSubmit ? filterForm.requestSubmit() : filterForm.submit();
        }, 600);
    }

    select.addEventListener('change', function() {
        normalizeSelection();
        scheduleSubmit();
    });

    filterForm.addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }
        isSubmitting = true;

        // Mostra indicatore di caricamento
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