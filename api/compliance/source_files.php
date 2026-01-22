<?php
/**
 * IMS v2: Source (master) files search for templates (tenant 28).
 *
 * GET  /api/compliance/source_files.php?action=search&q=...&file_kind=docx|xlsx|pptx|txt
 * POST /api/compliance/source_files.php?action=ensure_master_folder
 * POST /api/compliance/source_files.php?action=install_iso9001_pack
 *
 * Master folder default: /Templates/IMS (under tenant 28 root)
 *
 * Security:
 * - Auth required
 * - Tenant 28 gate required
 * - CSRF required for POST
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/tenant_folder_helper.php';
require_once __DIR__ . '/../../includes/compliance/provisioning_files_helper.php';

cnx_compliance_require_tenant28_planning($userInfo);

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'search'));

/**
 * Create/ensure /Templates/IMS in tenant 28.
 *
 * @return array{ok:bool,folder_id:int,path:string,created?:bool}
 */
function cnx_ensure_master_templates_folder(Database $db, int $actorUserId): array {
    $tenantId = 28;
    $tName = 'Tenant 28';
    try {
        $row = $db->fetchOne("SELECT name FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
        if ($row && !empty($row['name'])) $tName = (string)$row['name'];
    } catch (Throwable $e) {
        // ignore
    }

    $root = cnx_ensure_tenant_root_folder($db, $tenantId, $tName);
    $templates = cnx_ensure_folder($db, $tenantId, (int)$root['folder_id'], 'Templates', $actorUserId);
    $ims = cnx_ensure_folder($db, $tenantId, (int)$templates['folder_id'], 'IMS', $actorUserId);

    return [
        'ok' => true,
        'folder_id' => (int)$ims['folder_id'],
        'path' => '/Templates/IMS',
        'created' => (bool)($ims['created'] ?? false),
    ];
}

try {
    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($actorUserId <= 0) api_error('Utente non valido', 401);

    if ($action === 'install_iso9001_pack') {
        cnx_compliance_require_csrf_for_write();

        // Required storages: templates (45), modules (46). Schema/hint (47) is optional but recommended.
        $need45 = cnx_compliance_check_tables($db, ['compliance_artifact_templates'], 'database/migrations/45_compliance_templates_and_standards.sql');
        if (!$need45['ok']) {
            api_error('Catalogo template IMS non inizializzato: applica migrazione 45', 503, [
                'storage_available' => false,
                'missing' => $need45['missing'] ?? [],
                'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
            ]);
        }
        $need46 = cnx_compliance_check_tables($db, ['compliance_template_modules', 'compliance_template_module_items'], 'database/migrations/46_ims_template_modules_and_source_files.sql');
        if (!$need46['ok']) {
            api_error('Modulo moduli template IMS non inizializzato: applica migrazione 46', 503, [
                'storage_available' => false,
                'missing' => $need46['missing'] ?? [],
                'migration' => 'database/migrations/46_ims_template_modules_and_source_files.sql',
            ]);
        }

        $need47 = cnx_compliance_check_tables($db, ['compliance_program_profiles'], 'database/migrations/47_document_wizard.sql');
        $has47 = (bool)$need47['ok'];

        $folder = cnx_ensure_master_templates_folder($db, $actorUserId);
        $masterFolderId = (int)($folder['folder_id'] ?? 0);
        if ($masterFolderId <= 0) api_error('Cartella master non disponibile', 500);

        // Pack definition (config-driven; fallback to hardcoded defaults if missing/invalid)
        $packConfig = null;
        $packConfigPath = __DIR__ . '/../../configs/ims/iso9001_sgq_completo.json';
        try {
            if (is_file($packConfigPath)) {
                $rawCfg = file_get_contents($packConfigPath);
                $cfg = json_decode((string)$rawCfg, true);
                if (is_array($cfg)) $packConfig = $cfg;
            }
        } catch (Throwable $e) {
            $packConfig = null;
        }

        // Master file definitions (NO ISO text, only structure + placeholders)
        // NOTE: Keep master names stable; templates will reference these source files by ID (copy_source).
        $masters = [
            [
                'name' => 'MASTER_MANUALE_QUALITA_ISO9001.docx',
                'kind' => 'docx',
                'file_key' => 'ISO9001_MASTER_MANUALE_QUALITA',
            ],
            [
                'name' => 'MASTER_PROCEDURA_ISO9001.docx',
                'kind' => 'docx',
                'file_key' => 'ISO9001_MASTER_PROCEDURA',
            ],
            [
                'name' => 'MASTER_VERBALE_RAPPORTO_ISO9001.docx',
                'kind' => 'docx',
                'file_key' => 'ISO9001_MASTER_VERBALE_RAPPORTO',
            ],
            [
                'name' => 'MASTER_REGISTRO_BASE.xlsx',
                'kind' => 'xlsx',
                'file_key' => 'ISO9001_MASTER_REGISTRO_BASE',
            ],
            // Special registers (allowed exception): keep dedicated masters for structured XLSX.
            [
                'name' => 'MASTER_REG_NC_AZIONI.xlsx',
                'kind' => 'xlsx',
                'file_key' => 'ISO9001_MASTER_REG_NC_AZIONI',
            ],
            [
                'name' => 'MASTER_REG_RISCHI_OPP.xlsx',
                'kind' => 'xlsx',
                'file_key' => 'ISO9001_MASTER_REG_RISCHI_OPP',
            ],
        ];

        // Allow overrides from config file (best-effort; keeps only known keys/fields)
        if (is_array($packConfig['masters'] ?? null)) {
            $tmp = [];
            foreach ($packConfig['masters'] as $m) {
                if (!is_array($m)) continue;
                $name = trim((string)($m['name'] ?? ''));
                $kind = strtolower(trim((string)($m['kind'] ?? '')));
                $fileKey = strtoupper(trim((string)($m['file_key'] ?? '')));
                if ($name === '' || $fileKey === '') continue;
                if (!in_array($kind, ['docx','xlsx'], true)) continue;
                if (!preg_match('/^[A-Z0-9_]+$/', $fileKey)) continue;
                $tmp[] = ['name' => $name, 'kind' => $kind, 'file_key' => $fileKey];
            }
            if (!empty($tmp)) $masters = $tmp;
        }

        $created = ['files' => 0, 'templates' => 0, 'module' => 0, 'items' => 0];
        $reused  = ['files' => 0, 'templates' => 0, 'module' => 0, 'items' => 0];
        $warnings = [];

        // 1) Create/ensure master files in /Templates/IMS
        $fileIdByKey = [];
        foreach ($masters as $m) {
            $name = (string)$m['name'];
            $kind = (string)$m['kind'];
            $fileKey = (string)($m['file_key'] ?? '');
            try {
                if ($kind === 'docx') {
                    $paragraphs = [];
                    if ($fileKey === 'ISO9001_MASTER_MANUALE_QUALITA') {
                        $paragraphs = [
                            "MANUALE QUALITÀ — SGQ ISO 9001 (template)",
                            "",
                            "CAPITOLO 0 — Introduzione",
                            "0.1 Premessa",
                            "0.2 Campo di applicazione del manuale",
                            "0.3 Struttura del manuale",
                            "0.4 Riferimenti interni",
                            "0.5 Gestione e revisioni",
                            "{{manual_intro}}",
                            "",
                            "CAPITOLO 1 — Scopo e campo di applicazione",
                            "1.1 Scopo del SGQ",
                            "1.2 Campo di applicazione (sedi, prodotti/servizi)",
                            "1.3 Esclusioni motivate",
                            "{{manual_scope}}",
                            "{{manual_scope_of_application}}",
                            "{{manual_exclusions}}",
                            "",
                            "CAPITOLO 2 — Riferimenti normativi (solo elenco, NO testo norma)",
                            "2.1 Documenti esterni",
                            "2.2 Documenti interni",
                            "{{manual_normative_references}}",
                            "",
                            "CAPITOLO 3 — Termini e definizioni",
                            "3.1 Termini",
                            "3.2 Definizioni",
                            "{{manual_terms_definitions}}",
                            "",
                            "CAPITOLO 4 — Contesto",
                            "4.1 Organizzazione e contesto",
                            "4.2 Parti interessate",
                            "4.3 Scopo del SGQ",
                            "4.4 Processi del SGQ",
                            "{{manual_context}}",
                            "",
                            "CAPITOLO 5 — Leadership",
                            "5.1 Leadership e impegno",
                            "5.2 Politica per la qualità",
                            "5.3 Ruoli, responsabilità e autorità",
                            "{{manual_leadership_roles}}",
                            "",
                            "CAPITOLO 6 — Pianificazione",
                            "6.1 Rischi e opportunità",
                            "6.2 Obiettivi per la qualità e pianificazione",
                            "6.3 Pianificazione delle modifiche",
                            "{{manual_planning}}",
                            "",
                            "CAPITOLO 7 — Supporto",
                            "7.1 Risorse",
                            "7.2 Competenze",
                            "7.3 Consapevolezza",
                            "7.4 Comunicazione",
                            "7.5 Informazioni documentate",
                            "{{manual_support}}",
                            "",
                            "CAPITOLO 8 — Attività operative",
                            "8.1 Pianificazione e controllo operativi",
                            "8.2 Requisiti per prodotti/servizi",
                            "8.3 Progettazione e sviluppo (se applicabile)",
                            "8.4 Controllo fornitori esterni",
                            "8.5 Produzione/erogazione (sotto-clausole)",
                            "8.6 Rilascio",
                            "8.7 Gestione output non conforme",
                            "{{manual_operations}}",
                            "",
                            "CAPITOLO 9 — Valutazione prestazioni",
                            "9.1 Monitoraggio, misurazione, analisi e valutazione",
                            "9.2 Audit interno",
                            "9.3 Riesame della direzione",
                            "{{manual_performance}}",
                            "",
                            "CAPITOLO 10 — Miglioramento",
                            "10.1 Miglioramento",
                            "10.2 Non conformità e azioni correttive",
                            "10.3 Miglioramento continuo",
                            "{{manual_improvement}}",
                            "",
                            "ALLEGATI (A…F)",
                            "Allegato A — Politica Qualità",
                            "Allegato B — Organigramma",
                            "Allegato C — Mappa processi",
                            "Allegato D — Matrice responsabilità",
                            "Allegato E — Elenco procedure/moduli",
                            "Allegato F — Tabella correlazione ISO 9001",
                            "{{manual_annexes}}",
                            "",
                            "NOTE / TODO",
                            "{{manual_todo}}",
                            "",
                            // Generic fallback field for drift-safe schema (if client uses fallback schema)
                            "{{body}}",
                        ];
                    } elseif ($fileKey === 'ISO9001_MASTER_PROCEDURA') {
                        $paragraphs = [
                            "1) Scopo",
                            "{{proc_scope}}",
                            "",
                            "2) Campo di applicazione",
                            "{{proc_scope_of_application}}",
                            "",
                            "3) Ruoli e responsabilità",
                            "{{proc_roles}}",
                            "",
                            "4) Modalità operative",
                            "{{proc_steps}}",
                            "",
                            "5) Registrazioni / evidenze",
                            "{{proc_records}}",
                            "",
                            "6) KPI / monitoraggi",
                            "{{proc_kpi}}",
                            "",
                            "7) Allegati (se presenti)",
                            "{{proc_attachments}}",
                            "",
                            "NOTE / TODO",
                            "{{proc_todo}}",
                            "",
                            "{{body}}",
                        ];
                    } else {
                        // Verbale / rapporto (generic)
                        $paragraphs = [
                            "1) Oggetto",
                            "{{report_subject}}",
                            "",
                            "2) Data e luogo",
                            "{{report_date_place}}",
                            "",
                            "3) Partecipanti",
                            "{{report_participants}}",
                            "",
                            "4) Ordine del giorno",
                            "{{report_agenda}}",
                            "",
                            "5) Sintesi / evidenze",
                            "{{report_notes}}",
                            "",
                            "6) Decisioni",
                            "{{report_decisions}}",
                            "",
                            "7) Azioni",
                            "{{report_actions}}",
                            "",
                            "NOTE / TODO",
                            "{{report_todo}}",
                            "",
                            "{{body}}",
                        ];
                    }
                    $doc = cnx_create_docx_from_paragraphs($paragraphs);
                    $res = cnx_ensure_document_from_content($db, 28, $masterFolderId, $actorUserId, 'docx', $name, $doc);
                } else {
                    // XLSX registers
                    if ($fileKey === 'ISO9001_MASTER_REG_NC_AZIONI') {
                        $rows = [
                            ["Registro NC/Azioni", ""],
                            ["Azienda", "{{company_name}}"],
                            ["Versione", "{{doc_version}}"],
                            ["Data", "{{doc_date}}"],
                            [""],
                            ["ID","Data","Processo","Descrizione","Tipo","Azione immediata","Causa","Azione correttiva","Responsabile","Scadenza","Stato","Verifica efficacia","Data chiusura","Note"],
                            ["{{nc_id}}","{{nc_date}}","{{nc_process}}","{{nc_description}}","{{nc_type}}","{{nc_immediate_action}}","{{nc_root_cause}}","{{nc_corrective_action}}","{{nc_owner}}","{{nc_due_date}}","{{nc_status}}","{{nc_effectiveness_check}}","{{nc_close_date}}","{{nc_notes}}"],
                        ];
                        $xlsx = cnx_create_xlsx_from_rows($rows, 'NC_Azioni');
                        $res = cnx_ensure_document_from_content($db, 28, $masterFolderId, $actorUserId, 'xlsx', $name, $xlsx);
                    } elseif ($fileKey === 'ISO9001_MASTER_REG_RISCHI_OPP') {
                        $rows = [
                            ["Registro Rischi/Opportunità", ""],
                            ["Azienda", "{{company_name}}"],
                            ["Versione", "{{doc_version}}"],
                            ["Data", "{{doc_date}}"],
                            [""],
                            ["Scala (esempio)","Valori"],
                            ["Probabilità","1-5"],
                            ["Impatto","1-5"],
                            ["Livello","P x I"],
                            [""],
                            ["ID","Data","Processo","Rischio/Opportunità","Tipo","Causa","Effetto","Probabilità","Impatto","Livello","Azioni","Owner","Scadenza","KPI","Stato","Note"],
                            ["{{risk_id}}","{{risk_date}}","{{risk_process}}","{{risk_description}}","{{risk_type}}","{{risk_cause}}","{{risk_effect}}","{{risk_probability}}","{{risk_impact}}","{{risk_level}}","{{risk_actions}}","{{risk_owner}}","{{risk_due_date}}","{{risk_kpi}}","{{risk_status}}","{{risk_notes}}"],
                        ];
                        $xlsx = cnx_create_xlsx_from_rows($rows, 'Rischi_Opp');
                        $res = cnx_ensure_document_from_content($db, 28, $masterFolderId, $actorUserId, 'xlsx', $name, $xlsx);
                    } else {
                        // Generic register base (rows/cols) — XLSX placeholders, no ISO text.
                        $rows = [
                            ["Registro (template)", ""],
                            ["Titolo", "{{doc_title}}"],
                            ["Codice", "{{doc_code}}"],
                            ["Versione", "{{doc_version}}"],
                            ["Data", "{{doc_date}}"],
                            ["Azienda", "{{company_name}}"],
                            [""],
                            ["ID","Data","Processo/Area","Descrizione","Owner","Scadenza","Stato","Note"],
                            ["{{row_id}}","{{row_date}}","{{row_process}}","{{row_description}}","{{row_owner}}","{{row_due_date}}","{{row_status}}","{{row_notes}}"],
                            [""],
                            ["NOTE / TODO", "{{notes}}"],
                        ];
                        $xlsx = cnx_create_xlsx_from_rows($rows, 'Registro');
                        $res = cnx_ensure_document_from_content($db, 28, $masterFolderId, $actorUserId, 'xlsx', $name, $xlsx);
                    }
                }

                $res['created'] ? $created['files']++ : $reused['files']++;
                if ($fileKey !== '') $fileIdByKey[$fileKey] = (int)$res['file_id'];
            } catch (Throwable $e) {
                $warnings[] = "Master non creato ({$name}): " . $e->getMessage();
            }
        }

        // 2) Upsert templates (metadata + schema/hint + link to master file if available)
        $tplCols = [];
        try {
            $tplColsRows = $db->fetchAll("SHOW COLUMNS FROM `compliance_artifact_templates`") ?: [];
            foreach ($tplColsRows as $r) { if (!empty($r['Field'])) $tplCols[(string)$r['Field']] = true; }
        } catch (Throwable $e) { $tplCols = []; }

        $upsertTemplate = function (array $row) use ($db, $tplCols, &$created, &$reused): void {
            $key = (string)$row['template_key'];
            $exists = $db->fetchOne("SELECT template_key FROM compliance_artifact_templates WHERE template_key = ? LIMIT 1", [$key]);
            $data = $row;
            unset($data['template_key']);
            // Filter to existing columns
            if (!empty($tplCols)) {
                $data = array_filter($data, fn($v, $k) => isset($tplCols[$k]), ARRAY_FILTER_USE_BOTH);
            }
            if ($exists) {
                $db->update('compliance_artifact_templates', array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]), ['template_key' => $key]);
                $reused['templates']++;
            } else {
                $db->insert('compliance_artifact_templates', array_merge(['template_key' => $key], $data, [
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]));
                $created['templates']++;
            }
        };

        // ISO 9001 requirements catalog clauses — must match Coverage entries EXACTLY.
        // Prefer DB catalog when available (so Coverage can reach 100% even if catalog expands).
        $iso9001AllReqClauses = [
            '4.1','4.2','4.3','4.4',
            '5.1','5.2','5.3',
            '6.1','6.2','6.3',
            '7.1','7.2','7.3','7.4','7.5',
            '8.1','8.2','8.3','8.4','8.5.1','8.5.2','8.5.3','8.5.5','8.6','8.7',
            '9.1','9.2','9.3',
            '10.1','10.2','10.3',
        ];
        try {
            $hasReqCatalog = false;
            try { $hasReqCatalog = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_requirements_catalog'"); } catch (Throwable $e) { $hasReqCatalog = false; }
            if ($hasReqCatalog) {
                $rows = $db->fetchAll(
                    "SELECT clause
                     FROM compliance_requirements_catalog
                     WHERE standard_code = ?
                       AND edition = ?
                       AND is_active = 1
                     ORDER BY sort_order ASC, clause ASC",
                    ['ISO 9001', '2015+Amd1:2024']
                ) ?: [];
                $tmpClauses = [];
                foreach ($rows as $r) {
                    $c = trim((string)($r['clause'] ?? ''));
                    if ($c === '') continue;
                    $tmpClauses[] = $c;
                }
                $tmpClauses = array_values(array_unique($tmpClauses));
                if (!empty($tmpClauses)) {
                    $iso9001AllReqClauses = $tmpClauses;
                } else {
                    $warnings[] = 'Catalogo requisiti ISO 9001 vuoto: uso fallback.';
                }
            }
        } catch (Throwable $e) {
            // non-blocking (fallback list stays)
        }

        $baseNoIsoHint = "Regole:\n"
            . "- NON riportare testo di norme ISO/UNI e NON citare clausole/paragrafi.\n"
            . "- Evita frasi tipo \"la norma richiede\".\n"
            . "- Scrivi come lavora realmente l’organizzazione (specifico per l’azienda).\n"
            . "- Se mancano dati, inserisci TODO/domande mirate (non inventare).\n";

        $schemaProcedure = $has47 ? json_encode([
            'sections' => [
                ['title' => 'Metadati', 'fields' => [
                    ['key' => 'doc_title', 'label' => 'Titolo documento', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_version', 'label' => 'Versione', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_date', 'label' => 'Data', 'type' => 'date', 'required' => true],
                    ['key' => 'company_name', 'label' => 'Azienda', 'type' => 'text', 'required' => true, 'ai' => false],
                ]],
                ['title' => 'Procedura', 'fields' => [
                    ['key' => 'proc_scope', 'label' => 'Scopo', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'proc_scope_of_application', 'label' => 'Campo di applicazione', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'proc_roles', 'label' => 'Ruoli e responsabilità', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'proc_steps', 'label' => 'Modalità operative (step)', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'proc_records', 'label' => 'Registrazioni / evidenze collegate', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'proc_kpi', 'label' => 'KPI / monitoraggi', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'proc_attachments', 'label' => 'Allegati (se presenti)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'proc_todo', 'label' => 'TODO / domande aperte', 'type' => 'textarea', 'required' => false, 'ai' => true],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $schemaManual = $has47 ? json_encode([
            'sections' => [
                ['title' => 'Metadati', 'fields' => [
                    ['key' => 'doc_title', 'label' => 'Titolo documento', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_version', 'label' => 'Versione', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_date', 'label' => 'Data', 'type' => 'date', 'required' => true],
                    ['key' => 'company_name', 'label' => 'Azienda', 'type' => 'text', 'required' => true, 'ai' => false],
                ]],
                ['title' => 'Manuale (capitoli)', 'fields' => [
                    ['key' => 'manual_intro', 'label' => 'Capitolo 0 — Introduzione', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_scope', 'label' => 'Capitolo 1 — Scopo', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'manual_scope_of_application', 'label' => 'Capitolo 1 — Campo di applicazione', 'type' => 'textarea', 'required' => true, 'ai' => true],
                    ['key' => 'manual_exclusions', 'label' => 'Capitolo 1 — Esclusioni motivate', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_normative_references', 'label' => 'Capitolo 2 — Riferimenti normativi (solo elenco)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_terms_definitions', 'label' => 'Capitolo 3 — Termini e definizioni', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_context', 'label' => 'Capitolo 4 — Contesto', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_leadership_roles', 'label' => 'Capitolo 5 — Leadership', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_planning', 'label' => 'Capitolo 6 — Pianificazione', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_support', 'label' => 'Capitolo 7 — Supporto', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_operations', 'label' => 'Capitolo 8 — Attività operative', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_performance', 'label' => 'Capitolo 9 — Valutazione prestazioni', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_improvement', 'label' => 'Capitolo 10 — Miglioramento', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_annexes', 'label' => 'Allegati A…F', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'manual_todo', 'label' => 'TODO / domande aperte', 'type' => 'textarea', 'required' => false, 'ai' => true],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $schemaRegister = $has47 ? json_encode([
            'sections' => [
                ['title' => 'Metadati', 'fields' => [
                    ['key' => 'doc_title', 'label' => 'Titolo documento', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_code', 'label' => 'Codice', 'type' => 'text', 'required' => false],
                    ['key' => 'doc_version', 'label' => 'Versione', 'type' => 'text', 'required' => true],
                    ['key' => 'doc_date', 'label' => 'Data', 'type' => 'date', 'required' => true],
                    ['key' => 'company_name', 'label' => 'Azienda', 'type' => 'text', 'required' => true],
                ]],
                ['title' => 'Contenuti (note / riga esempio)', 'fields' => [
                    ['key' => 'body', 'label' => 'Note / contenuto (bozza)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $templatesToEnsure = [];

        // Templates list is config-driven (configs/ims/iso9001_sgq_completo.json)
        $packTemplates = is_array($packConfig['templates'] ?? null) ? $packConfig['templates'] : null;
        if (!is_array($packTemplates) || empty($packTemplates)) {
            $warnings[] = 'Config ISO9001 pack non disponibile o vuota: nessun template aggiornato.';
            $packTemplates = [];
        }

        $seenKeys = [];
        foreach ($packTemplates as $pt) {
            if (!is_array($pt)) continue;
            $tk = strtoupper(trim((string)($pt['template_key'] ?? '')));
            if ($tk === '' || isset($seenKeys[$tk])) continue;
            $seenKeys[$tk] = true;

            $title = trim((string)($pt['title'] ?? ''));
            if ($title === '') $title = $tk;

            $docType = strtolower(trim((string)($pt['doc_type'] ?? '')));
            $fileKind = strtolower(trim((string)($pt['file_kind'] ?? '')));
            if (!in_array($fileKind, ['docx','xlsx'], true)) continue;
            if ($docType === '') $docType = ($fileKind === 'xlsx') ? 'register' : 'procedure';

            // Normalize IMS folder path to the mandatory 4-root structure
            $folderPath = rtrim(trim((string)($pt['folder_path'] ?? '/IMS')), '/');
            if ($folderPath === '' || strpos($folderPath, '/IMS') !== 0) $folderPath = '/IMS';
            $legacyMap = [
                '/IMS/00-IMS/Manuale' => '/IMS/Manuale',
                '/IMS/00-IMS/Procedure' => '/IMS/Procedure',
                '/IMS/00-IMS/Moduli' => '/IMS/Moduli',
                '/IMS/00-IMS/Allegati' => '/IMS/Allegati',
            ];
            foreach ($legacyMap as $from => $to) {
                if ($folderPath === $from || strpos($folderPath, $from . '/') === 0) {
                    $suffix = substr($folderPath, strlen($from));
                    $folderPath = rtrim($to . $suffix, '/');
                    break;
                }
            }
            $allowedRoots = ['Manuale', 'Procedure', 'Moduli', 'Allegati'];
            $parts = array_values(array_filter(explode('/', trim($folderPath, '/'))));
            $first = $parts[1] ?? '';
            if ($folderPath === '/IMS' || ($first !== '' && !in_array($first, $allowedRoots, true))) {
                // Try infer from folder path contents first
                if (strpos($folderPath, '/Manuale') !== false) $folderPath = '/IMS/Manuale';
                elseif (strpos($folderPath, '/Procedure') !== false) $folderPath = '/IMS/Procedure';
                elseif (strpos($folderPath, '/Allegati') !== false) $folderPath = '/IMS/Allegati';
                elseif (strpos($folderPath, '/Records') !== false || strpos($folderPath, '/Moduli') !== false) $folderPath = '/IMS/Moduli';
                else {
                    // Fallback by doc_type
                    if ($docType === 'manual') $folderPath = '/IMS/Manuale';
                    elseif ($docType === 'policy') $folderPath = '/IMS/Allegati';
                    elseif (in_array($docType, ['procedure','instruction'], true)) $folderPath = '/IMS/Procedure';
                    else $folderPath = '/IMS/Moduli';
                }
            }

            $filenameTemplate = trim((string)($pt['filename_template'] ?? ''));
            $docCodeTpl = trim((string)($pt['doc_code_template'] ?? ''));
            if ($filenameTemplate === '') {
                $namePart = $title;
                if ($docCodeTpl !== '' && stripos($namePart, $docCodeTpl) === 0) {
                    $namePart = trim(substr($namePart, strlen($docCodeTpl)));
                    $namePart = ltrim($namePart, "—- \t");
                }
                $safe = preg_replace('/[^A-Za-z0-9_\\-]+/', '_', (string)$namePart) ?: (string)$namePart;
                $safe = trim((string)$safe, '_');
                $filenameTemplate = ($docCodeTpl !== '' ? ($docCodeTpl . ' - ') : '') . ($safe !== '' ? $safe : $tk);
            }

            // clause refs (must match catalog entries exactly)
            $clauses = $pt['clauses'] ?? [];
            $clauseList = [];
            if (is_string($clauses) && strtoupper(trim($clauses)) === 'ALL_REQUIREMENTS') {
                $clauseList = $iso9001AllReqClauses;
            } elseif (is_array($clauses)) {
                foreach ($clauses as $c) {
                    if (!is_string($c)) continue;
                    $c = trim($c);
                    if ($c === '') continue;
                    $clauseList[] = $c;
                }
            }
            $clauseList = array_values(array_unique($clauseList));

            $tags = [];
            if (is_array($pt['tags'] ?? null)) {
                foreach ($pt['tags'] as $tg) {
                    if (!is_string($tg)) continue;
                    $tg = trim($tg);
                    if ($tg === '') continue;
                    $tags[] = $tg;
                }
            }
            if (empty($tags)) {
                $tags = ['ISO9001','SGQ'];
                if ($docType === 'manual') $tags[] = 'Manuale';
                if ($docType === 'procedure') $tags[] = 'Procedura';
                if ($docType === 'register') $tags[] = 'Registro';
            }
            $tags = array_values(array_unique($tags));

            $sourceFileKey = strtoupper(trim((string)($pt['source_file_key'] ?? '')));
            $sourceFileId = (int)($fileIdByKey[$sourceFileKey] ?? 0) ?: null;
            $contentMode = $sourceFileId ? 'copy_source' : 'placeholder';

            $schema = null;
            if ($docType === 'manual') $schema = $schemaManual;
            elseif ($fileKind === 'xlsx') $schema = $schemaRegister;
            else $schema = $schemaProcedure;

            $aiHint = null;
            if ($has47) {
                if ($docType === 'manual') {
                    $aiHint = "Scrivi un Manuale Qualità strutturato per capitoli 0–10 + allegati A–F.\n" . $baseNoIsoHint;
                } elseif ($tk === 'ISO9001_PROC_INFODOC') {
                    $aiHint = "Scrivi una procedura operativa (PD-01) per gestione informazioni documentate (documenti e registrazioni): ruoli, approvazione, versioning, accessi, conservazione.\n" . $baseNoIsoHint;
                } elseif ($tk === 'ISO9001_REG_RISCHI_OPP') {
                    $aiHint = "Per registro rischi/opportunità: genera esempi realistici e brevi, coerenti col contesto aziendale.\n" . $baseNoIsoHint;
                } elseif ($tk === 'ISO9001_REG_NC_AZIONI') {
                    $aiHint = "Per registro NC/azioni: genera esempi realistici (descrizione, causa, azioni, owner, scadenze) senza riferimenti ISO/UNI.\n" . $baseNoIsoHint;
                } elseif ($docType === 'procedure') {
                    $ctxTitle = $title;
                    if ($docCodeTpl !== '' && stripos($ctxTitle, $docCodeTpl) === 0) $ctxTitle = trim(substr($ctxTitle, strlen($docCodeTpl)));
                    $ctxTitle = ltrim($ctxTitle, "—- \t");
                    $codeLabel = $docCodeTpl !== '' ? $docCodeTpl : $tk;
                    $aiHint = "Scrivi una procedura {$codeLabel} coerente con il titolo \"{$ctxTitle}\", completa di scopo/campo/ruoli/step/registrazioni/KPI.\n" . $baseNoIsoHint;
                } else {
                    $aiHint = $baseNoIsoHint;
                }
            }

            $templatesToEnsure[] = [
                'template_key' => $tk,
                'title' => $title,
                'doc_type' => $docType,
                'file_kind' => $fileKind,
                'folder_path' => $folderPath,
                'filename_template' => $filenameTemplate,
                'clause_refs_json' => json_encode(['ISO9001' => $clauseList], JSON_UNESCAPED_SLASHES),
                'tags_json' => json_encode($tags, JSON_UNESCAPED_SLASHES),
                'is_common_hls' => 0,
                'is_active' => 1,
                'source_tenant_id' => 28,
                'source_file_id' => $sourceFileId,
                'content_mode' => $contentMode,
                'doc_code_template' => $docCodeTpl !== '' ? $docCodeTpl : null,
                'input_schema_json' => $schema,
                'ai_hint' => $aiHint,
            ];
        }

        foreach ($templatesToEnsure as $t) {
            try {
                // normalize nulls if column missing
                $upsertTemplate($t);
            } catch (Throwable $e) {
                $warnings[] = "Template non salvato ({$t['template_key']}): " . $e->getMessage();
            }
        }

        // 3) Ensure module ISO9001_SGQ_COMPLETO and items (manuale + 33 procedure + registri)
        try {
            $modCfg = is_array($packConfig['module'] ?? null) ? $packConfig['module'] : [];
            $moduleKey = strtoupper(trim((string)($modCfg['module_key'] ?? 'ISO9001_SGQ_COMPLETO')));
            if ($moduleKey === '') $moduleKey = 'ISO9001_SGQ_COMPLETO';
            $moduleTitle = trim((string)($modCfg['title'] ?? 'ISO 9001 — SGQ Completo'));
            if ($moduleTitle === '') $moduleTitle = 'ISO 9001 — SGQ Completo';
            $moduleDesc = trim((string)($modCfg['description'] ?? 'Pacchetto completo ISO 9001 (Manuale qualità + 33 procedure + registri). NO testo ISO/UNI nei template; contenuti generati in modo originale tramite wizard.'));
            if ($moduleDesc === '') $moduleDesc = 'Pacchetto completo ISO 9001 (Manuale qualità + 33 procedure + registri). NO testo ISO/UNI nei template; contenuti generati in modo originale tramite wizard.';
            $moduleStandards = ['ISO9001'];
            if (is_array($modCfg['standards'] ?? null)) {
                $tmpStd = [];
                foreach ($modCfg['standards'] as $s) {
                    if (!is_string($s)) continue;
                    $s = strtoupper(trim($s));
                    if ($s === '' || strlen($s) > 30) continue;
                    $tmpStd[] = $s;
                }
                if (!empty($tmpStd)) $moduleStandards = array_values(array_unique($tmpStd));
            }
            $moduleTags = ['ISO9001','SGQ'];
            if (is_array($modCfg['tags'] ?? null)) {
                $tmpTags = [];
                foreach ($modCfg['tags'] as $t) {
                    if (!is_string($t)) continue;
                    $t = trim($t);
                    if ($t === '' || strlen($t) > 40) continue;
                    $tmpTags[] = $t;
                }
                if (!empty($tmpTags)) $moduleTags = array_values(array_unique($tmpTags));
            }
            $moduleActive = isset($modCfg['is_active']) ? (int)(bool)$modCfg['is_active'] : 1;

            $m = $db->fetchOne("SELECT id FROM compliance_template_modules WHERE module_key = ? LIMIT 1", [$moduleKey]);
            if ($m) {
                $db->update('compliance_template_modules', [
                    'title' => $moduleTitle,
                    'description' => $moduleDesc,
                    'standards_json' => json_encode($moduleStandards, JSON_UNESCAPED_SLASHES),
                    'tags_json' => json_encode($moduleTags, JSON_UNESCAPED_SLASHES),
                    'is_active' => $moduleActive,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['module_key' => $moduleKey]);
                $reused['module']++;
                $moduleId = (int)$m['id'];
            } else {
                $moduleId = (int)$db->insert('compliance_template_modules', [
                    'module_key' => $moduleKey,
                    'title' => $moduleTitle,
                    'description' => $moduleDesc,
                    'standards_json' => json_encode($moduleStandards, JSON_UNESCAPED_SLASHES),
                    'tags_json' => json_encode($moduleTags, JSON_UNESCAPED_SLASHES),
                    'is_active' => $moduleActive,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $created['module']++;
            }

            // Replace module items list (idempotent, avoids duplicates).
            $moduleItems = [];
            $sort = 10;
            foreach ($templatesToEnsure as $t) {
                $tk = (string)($t['template_key'] ?? '');
                if ($tk === '') continue;
                $moduleItems[] = ['k' => $tk, 'o' => $sort];
                $sort += 10;
            }
            $pdo = $db->getConnection();
            $pdo->beginTransaction();
            try {
                $db->query("DELETE FROM compliance_template_module_items WHERE module_id = ?", [$moduleId]);
                foreach ($moduleItems as $it) {
                    $db->insert('compliance_template_module_items', [
                        'module_id' => $moduleId,
                        'template_key' => (string)$it['k'],
                        'is_required' => 1,
                        'sort_order' => (int)$it['o'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $created['items'] = count($moduleItems);
        } catch (Throwable $e) {
            $warnings[] = 'Modulo non aggiornato: ' . $e->getMessage();
        }

        api_success([
            'ok' => true,
            'master_folder_id' => $masterFolderId,
            'master_folder_path' => '/Templates/IMS',
            'created' => $created,
            'reused' => $reused,
            'template_keys' => array_values(array_unique(array_map(static fn($t) => (string)($t['template_key'] ?? ''), $templatesToEnsure))),
            'warnings' => $warnings,
            'note' => $has47 ? '' : 'Migrazione 47 non rilevata: schema compilazione/AI hint potrebbero non essere salvati.',
        ], 'Pack ISO 9001 installato');
    }

    if ($action === 'ensure_master_folder') {
        cnx_compliance_require_csrf_for_write();
        $res = cnx_ensure_master_templates_folder($db, $actorUserId);
        api_success($res, 'Cartella master pronta');
    }

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        $fileKind = strtolower(trim((string)($_GET['file_kind'] ?? '')));
        if (!in_array($fileKind, ['docx','xlsx','pptx','txt',''], true)) $fileKind = '';

        // Ensure folder exists (non-blocking); doesn’t require CSRF because it may create rows.
        // To keep strict CSRF rules, we only ensure if folder already exists; otherwise return hint.
        // Users can explicitly call ensure_master_folder (POST+CSRF).
        $masterFolderId = 0;
        try {
            $root = $db->fetchOne("SELECT id FROM files WHERE tenant_id = 28 AND folder_id IS NULL AND is_folder = 1 AND deleted_at IS NULL LIMIT 1");
            if ($root && !empty($root['id'])) {
                $templates = $db->fetchOne(
                    "SELECT id FROM files
                     WHERE tenant_id = 28 AND is_folder = 1 AND deleted_at IS NULL
                       AND folder_id = ? AND name = 'Templates'
                     LIMIT 1",
                    [(int)$root['id']]
                );
                if ($templates && !empty($templates['id'])) {
                    $ims = $db->fetchOne(
                        "SELECT id FROM files
                         WHERE tenant_id = 28 AND is_folder = 1 AND deleted_at IS NULL
                           AND folder_id = ? AND name = 'IMS'
                         LIMIT 1",
                        [(int)$templates['id']]
                    );
                    if ($ims && !empty($ims['id'])) $masterFolderId = (int)$ims['id'];
                }
            }
        } catch (Throwable $e) {
            $masterFolderId = 0;
        }

        if ($masterFolderId <= 0) {
            api_success([
                'master_folder_available' => false,
                'master_folder_path' => '/Templates/IMS',
                'files' => [],
                'hint' => 'Cartella master non presente: usa action=ensure_master_folder (POST) dal tenant 28.',
            ]);
        }

        $limit = 50;
        $params = [28, $masterFolderId];
        $where = "tenant_id = ? AND deleted_at IS NULL AND is_folder = 0 AND folder_id = ?";
        if ($fileKind !== '') {
            $where .= " AND file_type = ?";
            $params[] = $fileKind;
        }
        if ($q !== '') {
            $where .= " AND name LIKE ?";
            $params[] = '%' . $q . '%';
        }
        $rows = $db->fetchAll(
            "SELECT id, name, folder_id, updated_at
             FROM files
             WHERE $where
             ORDER BY updated_at DESC, id DESC
             LIMIT $limit",
            $params
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'folder_id' => (int)($r['folder_id'] ?? 0),
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }

        api_success([
            'master_folder_available' => true,
            'master_folder_id' => $masterFolderId,
            'master_folder_path' => '/Templates/IMS',
            'files' => $out,
        ]);
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_SOURCE_FILES] ' . $e->getMessage());
    api_error('Errore source files', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

