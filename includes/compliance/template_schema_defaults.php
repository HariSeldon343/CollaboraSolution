<?php
declare(strict_types=1);

/**
 * Fallback schema + hint for templates that don't have `input_schema_json` / `ai_hint` configured.
 *
 * Goals:
 * - Keep the system usable even when the template catalog has no schema yet.
 * - Provide a minimal "body" field so AI can generate complete content and the placeholder engine can apply it.
 * - Avoid ISO/UNI text and clause citations (enforced also in artifact_ai.php).
 *
 * NOTE: This does NOT write to DB; it's runtime fallback.
 */

/**
 * @return array<string,mixed>
 */
function cnx_compliance_fallback_schema(string $templateKey, string $title, string $docType): array {
    $docType = strtolower(trim($docType));
    $templateKey = trim($templateKey);
    $title = trim($title) !== '' ? trim($title) : ($templateKey !== '' ? $templateKey : 'Documento');

    $bodyLabel = 'Contenuto del documento (bozza)';
    if ($docType === 'policy') $bodyLabel = 'Testo politica (bozza)';
    if ($docType === 'procedure') $bodyLabel = 'Procedura (bozza)';
    if ($docType === 'manual') $bodyLabel = 'Manuale (bozza)';
    if ($docType === 'plan') $bodyLabel = 'Piano (bozza)';
    if ($docType === 'register') $bodyLabel = 'Contenuti / note registro (bozza)';
    if ($docType === 'record') $bodyLabel = 'Contenuto registrazione (bozza)';
    if ($docType === 'form') $bodyLabel = 'Contenuto modulo (bozza)';

    return [
        'sections' => [
            [
                'title' => 'Dati organizzazione',
                'fields' => [
                    ['key' => 'company_name', 'label' => 'Ragione sociale', 'type' => 'text', 'required' => true, 'ai' => false],
                    ['key' => 'scope', 'label' => 'Scopo / campo di applicazione (sintesi)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'sites', 'label' => 'Sedi / siti (1 per riga)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'products_services', 'label' => 'Prodotti/Servizi (1 per riga)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'processes', 'label' => 'Processi principali (1 per riga)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'roles', 'label' => 'Ruoli / responsabilità (1 per riga)', 'type' => 'textarea', 'required' => false, 'ai' => true],
                    ['key' => 'notes', 'label' => 'Note', 'type' => 'textarea', 'required' => false, 'ai' => true],
                ],
            ],
            [
                'title' => 'Contenuto',
                'fields' => [
                    [
                        'key' => 'body',
                        'label' => $bodyLabel,
                        'type' => 'textarea',
                        'required' => true,
                        'ai' => true,
                    ],
                ],
            ],
        ],
    ];
}

function cnx_compliance_fallback_ai_hint(string $templateKey, string $title, string $docType): string {
    $docType = strtolower(trim($docType));
    $templateKey = trim($templateKey);
    $title = trim($title) !== '' ? trim($title) : ($templateKey !== '' ? $templateKey : 'Documento');

    $kind = $docType !== '' ? $docType : 'documento';
    return
        "Scrivi contenuti originali e operativi per il seguente deliverable:\n"
        . "- Titolo: {$title}\n"
        . "- Tipo: {$kind}\n\n"
        . "Requisiti:\n"
        . "- NON riportare testo di norme ISO/UNI e NON citare clausole/paragrafi.\n"
        . "- Evita frasi tipo \"la norma richiede\"; scrivi come lavora realmente l’organizzazione.\n"
        . "- Se mancano dati, inserisci TODO/domande mirate (non inventare).\n"
        . "- Testo pronto da incollare nel documento, con paragrafi chiari ed elenchi puntati quando utili.\n";
}

