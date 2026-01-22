<?php
// GET: Requirements Catalog (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/tenant28_access_check.php';
require_once __DIR__ . '/../../includes/compliance/iso9001_requirements_fallback.php';

initializeApiEnvironment();
verifyApiAuthentication();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

// Enforce tenant 28 access
$t28 = cnxCheckTenant28Access($userInfo);
if (!$t28['ok']) {
    api_error($t28['reason'], 403);
}

/**
 * @return array<int,array<string,mixed>>
 */
function cnx_load_requirements_from_db(Database $db, string $standardCode, string $edition): array {
    $rows = $db->fetchAll(
        "SELECT clause, section, title, intent_summary,
                evidence_examples_json, artifacts_json, special_notes_json,
                sort_order
         FROM compliance_requirements_catalog
         WHERE standard_code = ?
           AND edition = ?
           AND is_active = 1
         ORDER BY sort_order ASC, clause ASC",
        [$standardCode, $edition]
    ) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $evidence = [];
        $artifacts = [];
        $notes = [];
        try { $evidence = $r['evidence_examples_json'] ? (json_decode((string)$r['evidence_examples_json'], true) ?: []) : []; } catch (Throwable $e) { $evidence = []; }
        try { $artifacts = $r['artifacts_json'] ? (json_decode((string)$r['artifacts_json'], true) ?: []) : []; } catch (Throwable $e) { $artifacts = []; }
        try { $notes = $r['special_notes_json'] ? (json_decode((string)$r['special_notes_json'], true) ?: []) : []; } catch (Throwable $e) { $notes = []; }

        $out[] = [
            'clause' => (string)($r['clause'] ?? ''),
            'section' => (string)($r['section'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
            'intent_summary' => (string)($r['intent_summary'] ?? ''),
            'evidence_examples' => is_array($evidence) ? array_values($evidence) : [],
            'artifacts' => is_array($artifacts) ? array_values($artifacts) : [],
            'special_notes' => is_array($notes) ? array_values($notes) : [],
            'sort_order' => (int)($r['sort_order'] ?? 0),
        ];
    }

    return $out;
}

try {
    $action = (string)($_GET['action'] ?? 'list');
    $standardCode = trim((string)($_GET['standard_code'] ?? 'ISO 9001'));
    if ($standardCode === '') $standardCode = 'ISO 9001';

    // Phase 1 supports ISO 9001 only
    if ($standardCode !== 'ISO 9001') {
        api_error('standard_code non supportato (fase 1: solo ISO 9001)', 400);
    }

    $edition = '2015+Amd1:2024';

    // Schema drift safe: if table missing, fallback
    $storageAvailable = false;
    try {
        $storageAvailable = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_requirements_catalog'");
    } catch (Throwable $e) {
        $storageAvailable = false;
    }

    $requirements = [];
    $source = 'fallback';
    if ($storageAvailable) {
        $requirements = cnx_load_requirements_from_db($db, $standardCode, $edition);
        $source = 'db';
    }
    if (empty($requirements)) {
        $fb = cnx_get_iso9001_requirements_fallback();
        $requirements = (array)($fb['requirements'] ?? []);
        $source = 'fallback';
        $storageAvailable = false;
    }

    $payload = [
        'standard_code' => $standardCode,
        'edition' => $edition,
        'storage_available' => $storageAvailable,
        'catalog_source' => $source,
        'count' => count($requirements),
        'requirements' => array_values($requirements),
    ];

    if ($action === 'export') {
        // best-effort download header
        header('Content-Disposition: attachment; filename="iso9001_requirements_catalog.json"');
        api_success($payload);
    }

    if ($action === 'list') {
        api_success($payload);
    }

    api_error('Azione non valida', 400);
} catch (Exception $e) {
    error_log('[REQUIREMENTS_CATALOG] ' . $e->getMessage());
    api_error('Errore caricamento catalogo requisiti', 500);
}

