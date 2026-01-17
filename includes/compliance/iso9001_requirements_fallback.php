<?php
/**
 * ISO 9001 Requirements Catalog (Fallback)
 *
 * Purpose:
 * - Provide a hardcoded minimal ISO 9001 requirements dataset for environments where
 *   the DB table `compliance_requirements_catalog` is missing (schema drift).
 *
 * Notes:
 * - This file must NOT include any standard text. Only operational summaries.
 * - Keep this dataset aligned with migration 40 seed.
 */
declare(strict_types=1);

/**
 * @return array{standard_code:string,edition:string,requirements:array<int,array<string,mixed>>}
 */
function cnx_get_iso9001_requirements_fallback(): array {
    $standardCode = 'ISO 9001';
    $edition = '2015+Amd1:2024';

    $req = [
        [
            'clause' => '4.1',
            'section' => 'Context',
            'title' => 'Contesto e fattori rilevanti',
            'intent_summary' => 'Identificare fattori interni/esterni che influenzano scopo, risultati e direzione del QMS. Riesaminare periodicamente e aggiornare decisioni e rischi collegati.',
            'evidence_examples' => ['analisi contesto (PESTEL/SWOT)', 'elenco fattori interni/esterni', 'mappa rischi di contesto', 'verbali di riesame contesto'],
            'artifacts' => [
                [
                    'artifact_type' => 'analysis',
                    'title' => 'Analisi del contesto',
                    'doc_code_hint' => 'QMS-CTX-01',
                    'outline' => ['perimetro', 'fattori esterni', 'fattori interni', 'impatti su QMS', 'riesami e aggiornamenti'],
                    'repository_path_hint' => '/IMS/02_QMS/01_Context/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => ['Amd1:2024: includere esplicitamente climate change tra i fattori di contesto rilevanti.'],
            'sort_order' => 401,
        ],
        [
            'clause' => '4.2',
            'section' => 'Context',
            'title' => 'Parti interessate e bisogni/aspettative',
            'intent_summary' => 'Identificare parti interessate rilevanti e i requisiti/aspettative che impattano il QMS (clienti, autorità, personale, fornitori, comunità). Tenere traccia e aggiornare.',
            'evidence_examples' => ['mappa stakeholder', 'registro requisiti parti interessate', 'evidenze contrattuali/regolatorie', 'riesami periodici'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro parti interessate e requisiti',
                    'doc_code_hint' => 'QMS-CTX-02',
                    'outline' => ['stakeholder', 'bisogni/aspettative', 'requisiti applicabili', 'fonti', 'riesame', 'azioni'],
                    'repository_path_hint' => '/IMS/02_QMS/01_Context/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => ['Amd1:2024: considerare climate change anche nelle parti interessate (es. clienti, autorità, comunità).'],
            'sort_order' => 402,
        ],
        [
            'clause' => '4.3',
            'section' => 'Context',
            'title' => 'Scopo del QMS',
            'intent_summary' => 'Definire e mantenere lo scopo del QMS (confini, prodotti/servizi, siti, esclusioni motivate). Assicurare coerenza con contesto e requisiti applicabili.',
            'evidence_examples' => ['documento scopo', 'mappa siti/processi inclusi', 'motivazioni esclusioni', 'comunicazione scopo'],
            'artifacts' => [
                [
                    'artifact_type' => 'document',
                    'title' => 'Scopo del QMS',
                    'doc_code_hint' => 'QMS-SCP-01',
                    'outline' => ['azienda', 'sedi incluse', 'prodotti/servizi', 'confini', 'esclusioni motivate', 'interfacce esterne'],
                    'repository_path_hint' => '/IMS/02_QMS/01_Context/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 403,
        ],
        [
            'clause' => '4.4',
            'section' => 'Context',
            'title' => 'Processi del QMS (mappa, KPI, interazioni)',
            'intent_summary' => 'Definire i processi del QMS: input/output, sequenze e interazioni, criteri/metodi, risorse, responsabilità, KPI e rischi di processo. Mantenere informazioni documentate adeguate.',
            'evidence_examples' => ['mappa processi', 'schede processo', 'KPI e obiettivi', 'riesami processi', 'azioni su performance'],
            'artifacts' => [
                [
                    'artifact_type' => 'model',
                    'title' => 'Mappa processi e interazioni',
                    'doc_code_hint' => 'QMS-PROC-01',
                    'outline' => ['processi core/supporto/direzione', 'interazioni', 'input/output', 'owner', 'KPI'],
                    'repository_path_hint' => '/IMS/02_QMS/02_Process_Map/',
                    'mandatory' => true,
                ],
                [
                    'artifact_type' => 'template',
                    'title' => 'Scheda processo (template)',
                    'doc_code_hint' => 'QMS-PROC-TPL',
                    'outline' => ['scopo', 'input/output', 'indicatori', 'rischi', 'controlli', 'record'],
                    'repository_path_hint' => '/IMS/02_QMS/02_Process_Map/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 404,
        ],

        [
            'clause' => '5.1',
            'section' => 'Leadership',
            'title' => 'Leadership e impegno',
            'intent_summary' => 'Dimostrare leadership sul QMS: integrazione nei processi, risorse, focus cliente, promozione miglioramento e cultura qualità. Assegnare responsabilità e sostenere il sistema.',
            'evidence_examples' => ['evidenze decisioni direzione', 'allocazione risorse', 'comunicazioni interne', 'azioni su rischi/opportunità'],
            'artifacts' => [
                [
                    'artifact_type' => 'evidence_pack',
                    'title' => 'Evidenze leadership (raccolta)',
                    'doc_code_hint' => 'QMS-LDR-01',
                    'outline' => ['decisioni', 'risorse', 'priorità qualità', 'azioni correttive/preventive'],
                    'repository_path_hint' => '/IMS/02_QMS/03_Leadership/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 501,
        ],
        [
            'clause' => '5.2',
            'section' => 'Leadership',
            'title' => 'Politica Qualità',
            'intent_summary' => 'Definire, approvare e comunicare la politica qualità. Assicurare che sia appropriata allo scopo e che includa impegni a soddisfare requisiti e migliorare continuamente.',
            'evidence_examples' => ['politica pubblicata', 'comunicazione a personale', 'riesami periodici', 'coerenza con obiettivi'],
            'artifacts' => [
                [
                    'artifact_type' => 'policy',
                    'title' => 'Politica Qualità',
                    'doc_code_hint' => 'QMS-POL-01',
                    'outline' => ['scopo e principi', 'impegni', 'applicazione', 'riesame'],
                    'repository_path_hint' => '/IMS/02_QMS/03_Leadership/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 502,
        ],
        [
            'clause' => '5.3',
            'section' => 'Leadership',
            'title' => 'Ruoli, responsabilità e autorità',
            'intent_summary' => 'Chiarire ruoli e responsabilità del QMS: chi fa cosa, chi approva, chi monitora performance e conformità, chi gestisce cambiamenti e non conformità.',
            'evidence_examples' => ['organigramma/ruoli', 'job description', 'matrice responsabilità (RACI)', 'nomine/lettere incarico'],
            'artifacts' => [
                [
                    'artifact_type' => 'matrix',
                    'title' => 'Matrice responsabilità (RACI) QMS',
                    'doc_code_hint' => 'QMS-RACI-01',
                    'outline' => ['processi', 'ruoli', 'responsabile/approvatore/consultato/informato'],
                    'repository_path_hint' => '/IMS/02_QMS/03_Leadership/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 503,
        ],

        [
            'clause' => '6.1',
            'section' => 'Planning',
            'title' => 'Rischi e opportunità (registro e azioni)',
            'intent_summary' => 'Determinare rischi/opportunità del QMS e pianificare azioni proporzionate. Integrare nel QMS e valutare efficacia delle azioni.',
            'evidence_examples' => ['registro rischi/opportunità', 'piani di trattamento', 'riesami efficacia', 'collegamenti a KPI e audit'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro rischi e opportunità',
                    'doc_code_hint' => 'QMS-RISK-01',
                    'outline' => ['contesto', 'rischi', 'opportunità', 'azioni', 'owner', 'scadenze', 'stato', 'verifica efficacia'],
                    'repository_path_hint' => '/IMS/02_QMS/04_Planning/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 601,
        ],
        [
            'clause' => '6.2',
            'section' => 'Planning',
            'title' => 'Obiettivi Qualità e piano',
            'intent_summary' => 'Definire obiettivi misurabili, coerenti con la politica, monitorati e aggiornati. Pianificare come raggiungerli (azioni, risorse, responsabili, tempi, misure).',
            'evidence_examples' => ['obiettivi e KPI', 'piani azione', 'riesami periodici', 'evidenze avanzamento'],
            'artifacts' => [
                [
                    'artifact_type' => 'plan',
                    'title' => 'Obiettivi Qualità e piano di azione',
                    'doc_code_hint' => 'QMS-OBJ-01',
                    'outline' => ['obiettivi', 'metriche', 'baseline/target', 'azioni', 'owner', 'scadenze', 'risorse', 'stato'],
                    'repository_path_hint' => '/IMS/02_QMS/04_Planning/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 602,
        ],
        [
            'clause' => '6.3',
            'section' => 'Planning',
            'title' => 'Pianificazione delle modifiche',
            'intent_summary' => 'Gestire i cambiamenti al QMS in modo pianificato: valutare impatti, risorse, responsabilità, rischi e mantenere integrità del sistema.',
            'evidence_examples' => ['change log', 'valutazioni impatto', 'approvazioni', 'comunicazioni'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro cambiamenti QMS',
                    'doc_code_hint' => 'QMS-CHG-01',
                    'outline' => ['descrizione cambio', 'motivo', 'impatti', 'rischi', 'approvazioni', 'esecuzione', 'verifica'],
                    'repository_path_hint' => '/IMS/02_QMS/04_Planning/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 603,
        ],

        [
            'clause' => '7.1',
            'section' => 'Support',
            'title' => 'Risorse (persone, infrastrutture, ambiente, monitoraggi)',
            'intent_summary' => 'Determinare e fornire risorse necessarie per processi e conformità: persone, infrastrutture, ambiente di lavoro, strumenti di monitoraggio/misura e conoscenza organizzativa.',
            'evidence_examples' => ['piani risorse', 'manutenzioni', 'tarature/verifiche', 'inventari', 'knowledge base'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro risorse e strumenti di misura',
                    'doc_code_hint' => 'QMS-RES-01',
                    'outline' => ['asset', 'uso', 'responsabile', 'taratura/verifica', 'scadenze', 'evidenze'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 701,
        ],
        [
            'clause' => '7.2',
            'section' => 'Support',
            'title' => 'Competenza (matrice + formazione)',
            'intent_summary' => 'Assicurare competenza per ruoli che impattano qualità: definire requisiti, valutare competenze, formare dove necessario e mantenere evidenze.',
            'evidence_examples' => ['matrice competenze', 'piani formazione', 'valutazioni efficacia', 'attestati'],
            'artifacts' => [
                [
                    'artifact_type' => 'matrix',
                    'title' => 'Matrice competenze',
                    'doc_code_hint' => 'QMS-HR-01',
                    'outline' => ['ruolo', 'competenze richieste', 'livello', 'gap', 'azioni formazione', 'evidenze'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/02_People/',
                    'mandatory' => true,
                ],
                [
                    'artifact_type' => 'plan',
                    'title' => 'Piano formazione',
                    'doc_code_hint' => 'QMS-HR-02',
                    'outline' => ['fabbisogni', 'calendario', 'partecipanti', 'valutazione efficacia'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/02_People/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 702,
        ],
        [
            'clause' => '7.3',
            'section' => 'Support',
            'title' => 'Consapevolezza',
            'intent_summary' => 'Rendere il personale consapevole di politica/obiettivi, contributo al QMS, implicazioni di non conformità e miglioramento. Usare comunicazioni e briefing.',
            'evidence_examples' => ['briefing', 'materiale comunicazione', 'quiz/valutazioni', 'campagne interne'],
            'artifacts' => [
                [
                    'artifact_type' => 'plan',
                    'title' => 'Piano comunicazione/consapevolezza qualità',
                    'doc_code_hint' => 'QMS-AWR-01',
                    'outline' => ['messaggi chiave', 'target', 'canali', 'frequenza', 'evidenze'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/02_People/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 703,
        ],
        [
            'clause' => '7.4',
            'section' => 'Support',
            'title' => 'Comunicazione (interna/esterna)',
            'intent_summary' => 'Determinare cosa comunicare, a chi, quando e come (interno/esterno), inclusi temi qualità, clienti, autorità e fornitori. Tenere evidenze dove opportuno.',
            'evidence_examples' => ['piani comunicazione', 'template email/avvisi', 'log comunicazioni critiche', 'comunicazioni cliente'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura comunicazioni QMS',
                    'doc_code_hint' => 'QMS-COM-01',
                    'outline' => ['canali', 'ruoli', 'escalation', 'registrazioni'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/03_Communication/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 704,
        ],
        [
            'clause' => '7.5',
            'section' => 'Support',
            'title' => 'Informazioni documentate (controllo documenti/registrazioni)',
            'intent_summary' => 'Creare e controllare informazioni documentate: approvazione, distribuzione, accesso, versioni, conservazione e protezione. Assicurare tracciabilità delle modifiche.',
            'evidence_examples' => ['procedura gestione documenti', 'registro revisioni', 'controllo accessi', 'evidenze conservazione'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura gestione documenti e registrazioni',
                    'doc_code_hint' => 'QMS-DOC-01',
                    'outline' => ['creazione', 'approvazione', 'versioning', 'distribuzione', 'archiviazione', 'backup', 'retention'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/01_Documented_Information/',
                    'mandatory' => true,
                ],
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro revisioni documenti',
                    'doc_code_hint' => 'QMS-DOC-REG',
                    'outline' => ['doc_code', 'titolo', 'versione', 'data', 'approvatore', 'note'],
                    'repository_path_hint' => '/IMS/02_QMS/05_Support/01_Documented_Information/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 705,
        ],

        [
            'clause' => '8.1',
            'section' => 'Operation',
            'title' => 'Pianificazione e controllo operativi',
            'intent_summary' => 'Pianificare, implementare e controllare processi operativi per garantire requisiti su prodotti/servizi. Definire criteri, controlli, risorse e gestire output non conformi.',
            'evidence_examples' => ['piani operativi', 'work instructions', 'checklist', 'monitoraggi'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura controllo operativo',
                    'doc_code_hint' => 'QMS-OPS-01',
                    'outline' => ['pianificazione', 'controlli', 'criteri accettazione', 'gestione output', 'registrazioni'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 801,
        ],
        [
            'clause' => '8.2',
            'section' => 'Operation',
            'title' => 'Requisiti per prodotti/servizi (cliente)',
            'intent_summary' => 'Determinare, riesaminare e comunicare requisiti cliente (contrattuali, legali, impliciti). Gestire cambiamenti e assicurare capacità di soddisfarli.',
            'evidence_examples' => ['riesame ordine/contratto', 'offerte', 'conferme', 'tracciamento requisiti'],
            'artifacts' => [
                [
                    'artifact_type' => 'template',
                    'title' => 'Checklist riesame requisiti cliente',
                    'doc_code_hint' => 'QMS-CUS-CHK',
                    'outline' => ['requisiti dichiarati', 'requisiti impliciti', 'requisiti legali', 'fattibilità', 'cambiamenti'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/01_Customer/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 802,
        ],
        [
            'clause' => '8.3',
            'section' => 'Operation',
            'title' => 'Progettazione e sviluppo (se applicabile)',
            'intent_summary' => 'Gestire progettazione/sviluppo: pianificazione, input/output, riesami, verifiche/validazioni e controllo modifiche. Applicabile solo se l’organizzazione progetta/sviluppa.',
            'evidence_examples' => ['piani progetto', 'riesami design', 'verifiche/validazioni', 'gestione modifiche'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura progettazione e sviluppo (se applicabile)',
                    'doc_code_hint' => 'QMS-DES-01',
                    'outline' => ['fasi', 'input', 'output', 'riesami', 'verifica/validazione', 'cambiamenti'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/02_Design/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => ['Se non applicabile, documentare la motivazione nello scopo e nei processi.'],
            'sort_order' => 803,
        ],
        [
            'clause' => '8.4',
            'section' => 'Operation',
            'title' => 'Controllo processi/forniture esterne',
            'intent_summary' => 'Assicurare che prodotti/servizi esterni soddisfino requisiti: criteri selezione e valutazione fornitori, controlli in ingresso, monitoraggio performance e azioni su fornitori non conformi.',
            'evidence_examples' => ['albo fornitori', 'valutazioni periodiche', 'criteri qualifica', 'audit fornitori', 'NC fornitori'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Albo fornitori qualificati + valutazione',
                    'doc_code_hint' => 'QMS-SUP-01',
                    'outline' => ['fornitore', 'ambito', 'criteri', 'valutazioni', 'riesami', 'azioni'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/03_Suppliers/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 804,
        ],
        [
            'clause' => '8.5.1',
            'section' => 'Operation',
            'title' => 'Erogazione (controllo produzione/servizio)',
            'intent_summary' => 'Erogare prodotti/servizi in condizioni controllate: istruzioni, criteri, risorse, competenze e tracciabilità dove necessario.',
            'evidence_examples' => ['istruzioni operative', 'checklist erogazione', 'registrazioni controlli', 'tracciabilità'],
            'artifacts' => [
                [
                    'artifact_type' => 'template',
                    'title' => 'Checklist controllo erogazione',
                    'doc_code_hint' => 'QMS-SVC-CHK',
                    'outline' => ['fasi', 'controlli', 'criteri', 'esiti', 'firma'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/04_Delivery/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 851,
        ],
        [
            'clause' => '8.5.2',
            'section' => 'Operation',
            'title' => 'Identificazione e tracciabilità (se richiesta)',
            'intent_summary' => 'Assicurare identificazione e tracciabilità quando richiesto da requisiti/leggi/contratti. Definire regole e registrazioni.',
            'evidence_examples' => ['codifiche lotto/commessa', 'etichette', 'registri tracciabilità', 'log sistemi'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura identificazione e tracciabilità (se applicabile)',
                    'doc_code_hint' => 'QMS-TRC-01',
                    'outline' => ['quando applica', 'regole codifica', 'registrazioni', 'responsabilità'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/04_Delivery/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => ['Applicabilità dipende da settore/prodotto/servizio.'],
            'sort_order' => 852,
        ],
        [
            'clause' => '8.5.3',
            'section' => 'Operation',
            'title' => 'Proprietà del cliente/fornitori esterni',
            'intent_summary' => 'Gestire e proteggere proprietà del cliente o di terzi (materiale, dati, IP). Segnalare danni/perdite e mantenere evidenze.',
            'evidence_examples' => ['registro beni/dati cliente', 'policy accesso', 'incident log', 'comunicazioni al cliente'],
            'artifacts' => [
                [
                    'artifact_type' => 'procedure',
                    'title' => 'Procedura gestione proprietà del cliente',
                    'doc_code_hint' => 'QMS-CUST-PROP',
                    'outline' => ['ricezione', 'uso', 'protezione', 'restituzione', 'incidenti'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/01_Customer/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 853,
        ],
        [
            'clause' => '8.5.5',
            'section' => 'Operation',
            'title' => 'Attività post-consegna (se applicabile)',
            'intent_summary' => 'Definire e controllare attività post-consegna quando applicabili (assistenza, garanzia, manutenzione, richiami).',
            'evidence_examples' => ['SLA/assistenza', 'registri interventi', 'ticket', 'report manutenzione'],
            'artifacts' => [
                [
                    'artifact_type' => 'process',
                    'title' => 'Processo post-consegna (assistenza/garanzia)',
                    'doc_code_hint' => 'QMS-AFTER-01',
                    'outline' => ['canali', 'tempi', 'responsabilità', 'registrazioni', 'analisi reclami'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/05_After_Delivery/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => ['Applicabilità dipende da contratto e settore.'],
            'sort_order' => 855,
        ],
        [
            'clause' => '8.6',
            'section' => 'Operation',
            'title' => 'Rilascio di prodotti/servizi',
            'intent_summary' => 'Definire criteri di accettazione e assicurare che verifiche siano completate prima del rilascio. Mantenere evidenze di conformità e autorizzazioni.',
            'evidence_examples' => ['checklist accettazione', 'record collaudo/verifica', 'approvazioni rilascio', 'tracciamento non conformità'],
            'artifacts' => [
                [
                    'artifact_type' => 'template',
                    'title' => 'Registro/Checklist rilascio',
                    'doc_code_hint' => 'QMS-REL-01',
                    'outline' => ['criteri', 'verifiche', 'esito', 'approvatore', 'data'],
                    'repository_path_hint' => '/IMS/02_QMS/06_Operation/04_Delivery/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 806,
        ],
        [
            'clause' => '8.7',
            'section' => 'Operation',
            'title' => 'Gestione output non conforme',
            'intent_summary' => 'Identificare e controllare output non conformi per prevenire uso/erogazione non intenzionale. Definire azioni (correzione, segregazione, concessione) e registrare decisioni.',
            'evidence_examples' => ['registro NC prodotto/servizio', 'azioni correttive immediate', 'concessioni approvate', 'evidenze segregazione'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro non conformità operative',
                    'doc_code_hint' => 'QMS-NC-OPS',
                    'outline' => ['descrizione', 'causa', 'azione immediata', 'decisione', 'owner', 'evidenze', 'stato'],
                    'repository_path_hint' => '/IMS/02_QMS/07_Performance/03_Nonconformities/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 807,
        ],

        [
            'clause' => '9.1',
            'section' => 'Performance',
            'title' => 'Monitoraggio, misurazione, analisi e valutazione',
            'intent_summary' => 'Definire cosa misurare, come, quando e come analizzare risultati. Usare dati per valutare performance processi, soddisfazione cliente e miglioramento.',
            'evidence_examples' => ['cruscotto KPI', 'report periodici', 'analisi trend', 'azioni su scostamenti'],
            'artifacts' => [
                [
                    'artifact_type' => 'dashboard',
                    'title' => 'Cruscotto KPI QMS',
                    'doc_code_hint' => 'QMS-KPI-01',
                    'outline' => ['KPI per processo', 'trend', 'target', 'azioni'],
                    'repository_path_hint' => '/IMS/02_QMS/07_Performance/01_KPI/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 901,
        ],
        [
            'clause' => '9.2',
            'section' => 'Performance',
            'title' => 'Audit interno (piano + report)',
            'intent_summary' => 'Pianificare ed eseguire audit interni per verificare conformità e efficacia del QMS. Definire programma, criteri, competenze auditor, report e follow-up.',
            'evidence_examples' => ['piano audit', 'checklist audit', 'rapporti audit', 'azioni su rilievi', 'verifica efficacia'],
            'artifacts' => [
                [
                    'artifact_type' => 'plan',
                    'title' => 'Programma audit interno',
                    'doc_code_hint' => 'QMS-AUD-PLAN',
                    'outline' => ['ambito', 'frequenza', 'criteri', 'auditor', 'calendario', 'reporting'],
                    'repository_path_hint' => '/IMS/02_QMS/07_Performance/02_Internal_Audit/',
                    'mandatory' => true,
                ],
                [
                    'artifact_type' => 'template',
                    'title' => 'Rapporto audit interno (template)',
                    'doc_code_hint' => 'QMS-AUD-REP',
                    'outline' => ['scopo', 'criteri', 'evidenze', 'rilievi', 'azioni'],
                    'repository_path_hint' => '/IMS/02_QMS/07_Performance/02_Internal_Audit/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 902,
        ],
        [
            'clause' => '9.3',
            'section' => 'Performance',
            'title' => 'Riesame della direzione (input/output)',
            'intent_summary' => 'Eseguire riesami periodici della direzione: valutare performance, rischi, risorse, cambiamenti e opportunità di miglioramento. Documentare input/output e decisioni.',
            'evidence_examples' => ['verbali riesame', 'azioni e decisioni', 'riesame KPI', 'riesame rischi', 'riesame audit/NC'],
            'artifacts' => [
                [
                    'artifact_type' => 'minutes',
                    'title' => 'Verbale riesame di direzione',
                    'doc_code_hint' => 'QMS-MR-01',
                    'outline' => ['input richiesti', 'decisioni', 'azioni', 'risorse', 'cambiamenti', 'follow-up'],
                    'repository_path_hint' => '/IMS/02_QMS/07_Performance/04_Management_Review/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 903,
        ],

        [
            'clause' => '10.1',
            'section' => 'Improvement',
            'title' => 'Miglioramento (opportunità e azioni)',
            'intent_summary' => 'Identificare opportunità di miglioramento e implementare azioni per aumentare soddisfazione cliente e performance del QMS.',
            'evidence_examples' => ['backlog miglioramenti', 'Kaizen/azioni', 'benefici misurati', 'riesami'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro opportunità di miglioramento',
                    'doc_code_hint' => 'QMS-IMP-01',
                    'outline' => ['idea', 'fonte', 'priorità', 'azione', 'owner', 'beneficio', 'stato'],
                    'repository_path_hint' => '/IMS/02_QMS/08_Improvement/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 1001,
        ],
        [
            'clause' => '10.2',
            'section' => 'Improvement',
            'title' => 'Non conformità e azioni correttive (con efficacia)',
            'intent_summary' => 'Gestire non conformità: contenimento, analisi causa, azioni correttive, aggiornamenti rischi/processi e verifica efficacia. Mantenere tracciabilità completa.',
            'evidence_examples' => ['registro NC', 'analisi causa (5Why/Ishikawa)', 'piano azioni', 'verifica efficacia', 'chiusura'],
            'artifacts' => [
                [
                    'artifact_type' => 'register',
                    'title' => 'Registro NC e azioni correttive',
                    'doc_code_hint' => 'QMS-CAPA-01',
                    'outline' => ['NC', 'causa', 'azioni', 'responsabile', 'scadenze', 'evidenze', 'verifica efficacia', 'chiusura'],
                    'repository_path_hint' => '/IMS/02_QMS/08_Improvement/02_NC_CAPA/',
                    'mandatory' => true,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 1002,
        ],
        [
            'clause' => '10.3',
            'section' => 'Improvement',
            'title' => 'Miglioramento continuo',
            'intent_summary' => 'Mantenere un meccanismo continuo per migliorare adeguatezza, efficacia e idoneità del QMS (trend, feedback, audit, NC, obiettivi).',
            'evidence_examples' => ['trend KPI', 'riesami periodici', 'lezioni apprese', 'azioni preventive/priorità'],
            'artifacts' => [
                [
                    'artifact_type' => 'process',
                    'title' => 'Processo miglioramento continuo',
                    'doc_code_hint' => 'QMS-CI-01',
                    'outline' => ['input', 'valutazione', 'prioritizzazione', 'azioni', 'verifica'],
                    'repository_path_hint' => '/IMS/02_QMS/08_Improvement/',
                    'mandatory' => false,
                ],
            ],
            'special_notes' => [],
            'sort_order' => 1003,
        ],
    ];

    // Keep minimum useful set: include 5.2 / 6.2 / 7.5 / 8.4 / 9.2 / 10.2 etc.
    // (This fallback intentionally stays minimal; DB seed is the authoritative reference when available.)

    return [
        'standard_code' => $standardCode,
        'edition' => $edition,
        'requirements' => $req,
    ];
}

