<?php
/**
 * Scheduling phase library (Tenant 28 internal planning).
 *
 * NOTE:
 * - This is used for:
 *   - phase_key -> phase_order/label (ordering + UI rendering)
 *   - fallback mapping when DB columns are not present (schema drift safe)
 */
declare(strict_types=1);

return [
    // Core (requested)
    'kickoff' => ['order' => 10, 'label' => 'Kickoff / Pianificazione'],
    'data_collection' => ['order' => 15, 'label' => 'Raccolta dati / Input'],
    'context_scope' => ['order' => 20, 'label' => 'Analisi contesto / Scopo e campo applicazione'],
    'process_mapping' => ['order' => 25, 'label' => 'Mappatura processi'],
    'risk_assessment' => ['order' => 30, 'label' => 'Valutazione rischi'],
    'compliance_obligations' => ['order' => 35, 'label' => 'Obblighi di conformità'],
    'system_design' => ['order' => 40, 'label' => 'Progettazione sistema'],
    'documentation' => ['order' => 50, 'label' => 'Documentazione'],
    'implementation' => ['order' => 60, 'label' => 'Implementazione / Formazione / Affiancamento'],
    'monitoring' => ['order' => 70, 'label' => 'Monitoraggio / KPI'],
    'internal_audit' => ['order' => 80, 'label' => 'Audit interno'],
    'management_review' => ['order' => 85, 'label' => 'Riesame di direzione'],
    'external_audit_support' => ['order' => 90, 'label' => 'Supporto audit esterno / certificazione'],
    'nc_closure' => ['order' => 95, 'label' => 'Chiusura NC / Azioni correttive'],
    'ongoing' => ['order' => 99, 'label' => 'Ongoing / Follow-up'],

    // Backward compatibility aliases (older templates / heuristics)
    'gap_analysis' => ['order' => 20, 'label' => 'Analisi gap / Analisi contesto'],
    'cert_support' => ['order' => 90, 'label' => 'Supporto verifica / certificazione'],
];

