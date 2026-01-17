<?php
/**
 * Service workplans (Tenant 28 internal planning).
 *
 * Each service_code maps to a list of tasks. Each task provides:
 * - title
 * - phase_key + phase_order
 * - default_mode: call|remote|onsite|other
 * - client_blocking: bool (only these must never overlap for the same plan/client)
 * - merge_key: string (allows merging across services for "shared" moments like kickoff/audits)
 * - default_slot: best-effort slot hints by mode
 * - share_per_intervention_type: distribution weights (NEW/MAINT/RECERT/SCOPE_EXT/TRANSITION)
 */
declare(strict_types=1);

$phaseLibPath = __DIR__ . '/phase_library.php';
$phaseLib = is_file($phaseLibPath) ? (require $phaseLibPath) : [];
if (!is_array($phaseLib)) $phaseLib = [];

/**
 * @param array<string,mixed> $t
 * @return array<string,mixed>
 */
$mkTask = static function (array $t) use ($phaseLib): array {
    $phaseKey = strtoupper(trim((string)($t['phase_key'] ?? ''))) !== '' ? trim((string)$t['phase_key']) : 'ongoing';
    $phaseMeta = $phaseLib[$phaseKey] ?? $phaseLib[strtolower($phaseKey)] ?? null;
    $phaseOrder = (int)($t['phase_order'] ?? 0);
    if ($phaseOrder <= 0 && is_array($phaseMeta) && isset($phaseMeta['order'])) {
        $phaseOrder = (int)$phaseMeta['order'];
    }
    if ($phaseOrder <= 0) $phaseOrder = 99;

    $mode = strtolower(trim((string)($t['default_mode'] ?? 'remote')));
    if (!in_array($mode, ['call','remote','onsite','other','travel','communication'], true)) $mode = 'remote';

    $slot = $t['default_slot'] ?? null;
    if (!is_array($slot)) {
        if ($mode === 'call') $slot = ['minutes' => 60, 'starts' => ['09:00','14:00']];
        elseif ($mode === 'onsite') $slot = ['minutes' => 480, 'starts' => ['09:00']]; // 09–17
        else $slot = ['minutes' => 240, 'starts' => ['09:00','14:00']]; // 4h blocks
    }

    $shares = $t['share_per_intervention_type'] ?? null;
    if (!is_array($shares)) $shares = [];

    return [
        'title' => trim((string)($t['title'] ?? 'Attività')),
        'phase_key' => $phaseKey,
        'phase_order' => $phaseOrder,
        'default_mode' => $mode,
        'client_blocking' => (bool)($t['client_blocking'] ?? true),
        'merge_key' => trim((string)($t['merge_key'] ?? '')),
        'default_slot' => $slot,
        'share_per_intervention_type' => $shares,
    ];
};

// Default distributions (weights; generator normalizes per service)
$sharesIsoNew = [
    'NEW' => 1.0,
    'MAINT' => 1.0,
    'RECERT' => 1.0,
    'SCOPE_EXT' => 1.0,
    'TRANSITION' => 1.0,
];

/**
 * Base workplan for ISO-like management system services (ISO9001/14001/45001/50001/37001/SA8000/PDR125/EMAS etc.).
 * NOTE: Some tasks are non-blocking (internal work) and may overlap if needed.
 */
$mgmtBase = [
    $mkTask([
        'title' => 'Kickoff / Pianificazione',
        'phase_key' => 'kickoff',
        'default_mode' => 'call',
        'client_blocking' => true,
        'merge_key' => 'kickoff',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.08, 'RECERT' => 0.08, 'SCOPE_EXT' => 0.06, 'TRANSITION' => 0.06,
        ],
    ]),
    $mkTask([
        'title' => 'Raccolta dati / Input',
        'phase_key' => 'data_collection',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.07, 'RECERT' => 0.06, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.06,
        ],
    ]),
    $mkTask([
        'title' => 'Analisi contesto / Scopo',
        'phase_key' => 'context_scope',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => 'context_scope',
        'share_per_intervention_type' => [
            'NEW' => 0.15, 'MAINT' => 0.12, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.15, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Mappatura processi',
        'phase_key' => 'process_mapping',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.10, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.08,
        ],
    ]),
    $mkTask([
        'title' => 'Valutazione rischi / opportunità',
        'phase_key' => 'risk_assessment',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => 'risk',
        'share_per_intervention_type' => [
            'NEW' => 0.10, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.15, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Progettazione sistema / strumenti',
        'phase_key' => 'system_design',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.02, 'RECERT' => 0.02, 'SCOPE_EXT' => 0.08, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Documentazione (manuale/procedure/moduli)',
        'phase_key' => 'documentation',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.25, 'MAINT' => 0.05, 'RECERT' => 0.05, 'SCOPE_EXT' => 0.20, 'TRANSITION' => 0.20,
        ],
    ]),
    $mkTask([
        'title' => 'Implementazione / Formazione / Affiancamento',
        'phase_key' => 'implementation',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.20, 'MAINT' => 0.05, 'RECERT' => 0.03, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Monitoraggio / KPI (best-effort)',
        'phase_key' => 'monitoring',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.20, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.05, 'TRANSITION' => 0.05,
        ],
    ]),
    $mkTask([
        'title' => 'Audit interno',
        'phase_key' => 'internal_audit',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => 'internal_audit',
        'share_per_intervention_type' => [
            'NEW' => 0.10, 'MAINT' => 0.15, 'RECERT' => 0.20, 'SCOPE_EXT' => 0.06, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Riesame di direzione',
        'phase_key' => 'management_review',
        'default_mode' => 'call',
        'client_blocking' => true,
        'merge_key' => 'management_review',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.05, 'TRANSITION' => 0.05,
        ],
    ]),
    $mkTask([
        'title' => 'Supporto audit esterno / certificazione',
        'phase_key' => 'external_audit_support',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => 'external_audit_support',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.06, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.05, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Chiusura NC / Azioni correttive (best-effort)',
        'phase_key' => 'nc_closure',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => 'nc_closure',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.05, 'RECERT' => 0.05, 'SCOPE_EXT' => 0.05, 'TRANSITION' => 0.05,
        ],
    ]),
];

/**
 * Privacy/GDPR-like workplan (PRIVACY/GDP).
 */
$privacyBase = [
    $mkTask([
        'title' => 'Kickoff / Pianificazione (Privacy/GDPR)',
        'phase_key' => 'kickoff',
        'default_mode' => 'call',
        'client_blocking' => true,
        'merge_key' => 'kickoff',
        'share_per_intervention_type' => [
            'NEW' => 0.08, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Raccolta dati / Data inventory',
        'phase_key' => 'data_collection',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.20, 'MAINT' => 0.15, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.20, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Mappatura processi / trattamenti',
        'phase_key' => 'process_mapping',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.15, 'MAINT' => 0.15, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.15, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Valutazione rischi (DPIA best-effort)',
        'phase_key' => 'risk_assessment',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => 'risk',
        'share_per_intervention_type' => [
            'NEW' => 0.20, 'MAINT' => 0.15, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.20, 'TRANSITION' => 0.20,
        ],
    ]),
    $mkTask([
        'title' => 'Obblighi di conformità / Misure',
        'phase_key' => 'compliance_obligations',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.12, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.12, 'TRANSITION' => 0.12,
        ],
    ]),
    $mkTask([
        'title' => 'Documentazione (registro/ informative/consensi)',
        'phase_key' => 'documentation',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.25, 'MAINT' => 0.15, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.18, 'TRANSITION' => 0.18,
        ],
    ]),
    $mkTask([
        'title' => 'Implementazione / formazione (best-effort)',
        'phase_key' => 'implementation',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.10, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.10,
        ],
    ]),
];

/**
 * ISO/IEC 17025-like workplan
 */
$iso17025Base = [
    $mkTask([
        'title' => 'Kickoff / Pianificazione (17025)',
        'phase_key' => 'kickoff',
        'default_mode' => 'call',
        'client_blocking' => true,
        'merge_key' => 'kickoff',
        'share_per_intervention_type' => [
            'NEW' => 0.08, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Raccolta dati / metodi / attrezzature',
        'phase_key' => 'data_collection',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.18, 'MAINT' => 0.15, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.20, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Analisi contesto / scopo accreditamento',
        'phase_key' => 'context_scope',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => 'context_scope',
        'share_per_intervention_type' => [
            'NEW' => 0.12, 'MAINT' => 0.12, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.12, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Valutazione rischi / imparzialità',
        'phase_key' => 'risk_assessment',
        'default_mode' => 'remote',
        'client_blocking' => true,
        'merge_key' => 'risk',
        'share_per_intervention_type' => [
            'NEW' => 0.12, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.12, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Documentazione / sistema qualità laboratorio',
        'phase_key' => 'documentation',
        'default_mode' => 'remote',
        'client_blocking' => false,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.25, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.20, 'TRANSITION' => 0.15,
        ],
    ]),
    $mkTask([
        'title' => 'Implementazione / affiancamento in laboratorio',
        'phase_key' => 'implementation',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => '',
        'share_per_intervention_type' => [
            'NEW' => 0.15, 'MAINT' => 0.10, 'RECERT' => 0.08, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Audit interno (17025)',
        'phase_key' => 'internal_audit',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => 'internal_audit',
        'share_per_intervention_type' => [
            'NEW' => 0.10, 'MAINT' => 0.18, 'RECERT' => 0.22, 'SCOPE_EXT' => 0.10, 'TRANSITION' => 0.10,
        ],
    ]),
    $mkTask([
        'title' => 'Riesame di direzione',
        'phase_key' => 'management_review',
        'default_mode' => 'call',
        'client_blocking' => true,
        'merge_key' => 'management_review',
        'share_per_intervention_type' => [
            'NEW' => 0.05, 'MAINT' => 0.10, 'RECERT' => 0.10, 'SCOPE_EXT' => 0.05, 'TRANSITION' => 0.05,
        ],
    ]),
    $mkTask([
        'title' => 'Supporto valutazione esterna / accreditamento',
        'phase_key' => 'external_audit_support',
        'default_mode' => 'onsite',
        'client_blocking' => true,
        'merge_key' => 'external_audit_support',
        'share_per_intervention_type' => [
            'NEW' => 0.07, 'MAINT' => 0.05, 'RECERT' => 0.15, 'SCOPE_EXT' => 0.08, 'TRANSITION' => 0.15,
        ],
    ]),
];

// Assemble workplans for requested service codes (atomic services)
$workplans = [];

// ISO-like management standards
$mgmtCodes = [
    'ISO9001','ISO14001','EMAS','ISO45001','ISO50001','SA8000','ISO37001','PDR125',
    'ISO22000','AUTORIZZ','RSPP','UNI16636','NORMA10891','NORMA13895','RT12',
    'BRC','IFS','BRCBROKERS','BIO',
];
foreach ($mgmtCodes as $c) {
    $workplans[$c] = $mgmtBase;
}

// HACCP as a simplified food plan (use mgmtBase as fallback for now)
$workplans['HACCP'] = $mgmtBase;

// CE / GDP / ACCRED / ODV231 / PRIVACY / GDP etc (best-effort)
$workplans['PRIVACY'] = $privacyBase;
$workplans['GDP'] = $privacyBase;
$workplans['ODV231'] = $mgmtBase; // best-effort; can be refined later
$workplans['CE'] = $mgmtBase; // best-effort
$workplans['ACCRED'] = $mgmtBase; // best-effort
$workplans['AUTORIZZ'] = $mgmtBase;

// ISO/IEC 17025
$workplans['ISOIEC17025'] = $iso17025Base;

// Additional requested codes (fallback to mgmtBase)
$moreCodes = [
    'ISO37001','PDR125','ISO22000','ISO50001','SA8000',
];
foreach ($moreCodes as $c) {
    if (!isset($workplans[$c])) $workplans[$c] = $mgmtBase;
}

return $workplans;

