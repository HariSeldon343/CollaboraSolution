<?php
declare(strict_types=1);

/**
 * Compliance Artifact Inputs API (Document Wizard)
 *
 * GET  /api/compliance/artifact_inputs.php?action=get&artifact_id=...
 * POST /api/compliance/artifact_inputs.php?action=save
 *
 * Storage: compliance_artifact_inputs (migration 47)
 */

require_once __DIR__ . '/_common.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'get'));

cnx_compliance_require_csrf_for_write();

// Storage check
$chk = cnx_compliance_check_tables(
    $db,
    ['compliance_artifact_inputs', 'compliance_artifacts', 'compliance_programs'],
    'database/migrations/47_document_wizard.sql'
);
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/47_document_wizard.sql',
    ]);
}

/**
 * Fetch artifact and authorize tenant access.
 * @return array{artifact:array,tenant_id:int,program_id:int}
 */
function cnx_dw_get_artifact_or_404(Database $db, array $userInfo, int $artifactId): array {
    $artifact = $db->fetchOne(
        "SELECT a.id, a.program_id, a.artifact_key, a.file_id, a.folder_id,
                p.tenant_id
         FROM compliance_artifacts a
         JOIN compliance_programs p ON p.id = a.program_id
         WHERE a.id = ?
         LIMIT 1",
        [$artifactId]
    );
    if (!$artifact) api_error('Deliverable non trovato', 404);
    $tenantId = (int)($artifact['tenant_id'] ?? 0);
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
        api_error('Accesso negato al tenant del deliverable', 403);
    }
    return ['artifact' => $artifact, 'tenant_id' => $tenantId, 'program_id' => (int)$artifact['program_id']];
}

try {
    if ($action === 'get') {
        $artifactId = (int)($_GET['artifact_id'] ?? 0);
        if ($artifactId <= 0) api_error('artifact_id richiesto', 400);

        $ctx = cnx_dw_get_artifact_or_404($db, $userInfo, $artifactId);
        $artifact = $ctx['artifact'];

        $row = $db->fetchOne(
            "SELECT id, input_json, updated_by, updated_at, created_at
             FROM compliance_artifact_inputs
             WHERE tenant_id = ? AND artifact_id = ?
             LIMIT 1",
            [$ctx['tenant_id'], $artifactId]
        );

        $inputs = null;
        if ($row && !empty($row['input_json'])) {
            try { $inputs = json_decode((string)$row['input_json'], true) ?: null; } catch (Throwable $e) { $inputs = null; }
        }

        api_success([
            'storage_available' => true,
            'artifact_id' => $artifactId,
            'program_id' => (int)$artifact['program_id'],
            'template_key' => (string)($artifact['artifact_key'] ?? ''),
            'inputs' => $inputs,
            'meta' => $row ? [
                'updated_by' => $row['updated_by'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ] : null,
        ]);
    }

    if ($action === 'save') {
        $payload = json_decode(cnx_get_raw_request_body(), true);
        if (!is_array($payload)) api_error('Body JSON non valido', 400);

        $artifactId = (int)($payload['artifact_id'] ?? 0);
        if ($artifactId <= 0) api_error('artifact_id richiesto', 400);

        $ctx = cnx_dw_get_artifact_or_404($db, $userInfo, $artifactId);
        $artifact = $ctx['artifact'];

        $inputsObj = $payload['inputs'] ?? null;
        if ($inputsObj !== null && !is_array($inputsObj)) {
            api_error('inputs deve essere un oggetto JSON', 400);
        }
        $inputsJson = $inputsObj !== null ? json_encode($inputsObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

        $existing = $db->fetchOne(
            "SELECT id FROM compliance_artifact_inputs WHERE tenant_id = ? AND artifact_id = ? LIMIT 1",
            [$ctx['tenant_id'], $artifactId]
        );
        if ($existing) {
            $db->update('compliance_artifact_inputs', [
                'input_json' => $inputsJson,
                'updated_by' => $userId > 0 ? $userId : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int)$existing['id']]);
        } else {
            $db->insert('compliance_artifact_inputs', [
                'tenant_id' => $ctx['tenant_id'],
                'program_id' => (int)$artifact['program_id'],
                'artifact_id' => $artifactId,
                'template_key' => (string)($artifact['artifact_key'] ?? ''),
                'input_json' => $inputsJson,
                'updated_by' => $userId > 0 ? $userId : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        api_success(['storage_available' => true, 'artifact_id' => $artifactId], 'Dati salvati');
    }

    api_error('Azione non supportata', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_ARTIFACT_INPUTS] ' . $e->getMessage());
    api_error('Errore dati deliverable', 500);
}

