<?php
/**
 * IMS v2: Standards catalog API (tenant 28 managed).
 *
 * GET  /api/compliance/standards.php?action=list
 * POST /api/compliance/standards.php?action=upsert
 * POST /api/compliance/standards.php?action=toggle_active
 *
 * Security:
 * - Auth required
 * - Tenant 28 gate required (vendor-only admin tool)
 * - CSRF required for POST
 *
 * Schema drift safe:
 * - If migration 45 missing -> 503 with migration hint
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

// Standards catalog is vendor-managed: always require tenant 28 access.
cnx_compliance_require_tenant28_planning($userInfo);

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

// Storage check (schema drift safe)
$has = cnx_compliance_check_tables($db, ['compliance_standards']);
if (!$has['ok']) {
    api_error(
        'Modulo template IMS non inizializzato: applica la migrazione database 45 (templates + standards)',
        503,
        [
            'storage_available' => false,
            'missing' => $has['missing'] ?? [],
            'migration' => 'database/migrations/45_compliance_templates_and_standards.sql',
        ]
    );
}

try {
    if ($action === 'list') {
        $rows = $db->fetchAll(
            "SELECT code, name, edition_label, family, is_active, created_at, updated_at
             FROM compliance_standards
             ORDER BY is_active DESC, family ASC, code ASC"
        ) ?: [];
        api_success([
            'storage_available' => true,
            'standards' => array_map(static fn($r) => [
                'code' => (string)$r['code'],
                'name' => (string)$r['name'],
                'edition_label' => (string)$r['edition_label'],
                'family' => (string)$r['family'],
                'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                'created_at' => (string)($r['created_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ], $rows),
        ]);
    }

    if ($action === 'upsert') {
        cnx_compliance_require_csrf_for_write();
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];

        $code = strtoupper(trim((string)($payload['code'] ?? '')));
        $name = trim((string)($payload['name'] ?? ''));
        $edition = trim((string)($payload['edition_label'] ?? ''));
        $family = trim((string)($payload['family'] ?? 'HLS'));
        $active = isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1;

        if ($code === '' || strlen($code) > 32) api_error('code non valido', 400);
        if ($name === '' || strlen($name) > 120) api_error('name non valido', 400);
        if ($edition === '' || strlen($edition) > 120) api_error('edition_label non valido', 400);
        if (!in_array($family, ['HLS', 'nonHLS', 'unknown'], true)) api_error('family non valido', 400);

        // Idempotent upsert by primary key code
        $db->getConnection()->exec("SET time_zone = '+00:00'");
        $db->insert('compliance_standards', [
            'code' => $code,
            'name' => $name,
            'edition_label' => $edition,
            'family' => $family,
            'is_active' => $active,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // If already exists, update instead (schema drift safe: UPDATE works even if INSERT failed)
        $db->update('compliance_standards', [
            'name' => $name,
            'edition_label' => $edition,
            'family' => $family,
            'is_active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['code' => $code]);

        api_success(['storage_available' => true, 'code' => $code], 'Salvato');
    }

    if ($action === 'toggle_active') {
        cnx_compliance_require_csrf_for_write();
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $code = strtoupper(trim((string)($payload['code'] ?? '')));
        $active = isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : null;
        if ($code === '') api_error('code richiesto', 400);
        if ($active === null) api_error('is_active richiesto', 400);

        $row = $db->fetchOne("SELECT code FROM compliance_standards WHERE code = ? LIMIT 1", [$code]);
        if (!$row) api_error('Standard non trovato', 404);
        $db->update('compliance_standards', [
            'is_active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['code' => $code]);
        api_success(['storage_available' => true, 'code' => $code, 'is_active' => $active], 'Aggiornato');
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_STANDARDS] ' . $e->getMessage());
    api_error('Errore standards', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

