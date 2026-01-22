<?php
declare(strict_types=1);

/**
 * Compliance Program Profile API (Document Wizard)
 *
 * GET  /api/compliance/program_profile.php?action=get&program_id=...
 * POST /api/compliance/program_profile.php?action=save
 *
 * Storage: compliance_program_profiles (migration 47)
 */

require_once __DIR__ . '/_common.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'get'));

// CSRF for writes
cnx_compliance_require_csrf_for_write();

// Storage check
$chk = cnx_compliance_check_tables($db, ['compliance_program_profiles', 'compliance_programs'], 'database/migrations/47_document_wizard.sql');
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/47_document_wizard.sql',
    ]);
}

function cnx_dw_table_exists(Database $db, string $tableName): bool {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$tableName]
        );
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_dw_has_col(Database $db, string $tableName, string $colName): bool {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$tableName, $colName]
        );
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_dw_format_site_line(?string $indirizzo, ?string $civico, ?string $cap, ?string $comune, ?string $provincia): string {
    $p = [];
    $addr = trim((string)$indirizzo);
    $civ = trim((string)$civico);
    $cap = trim((string)$cap);
    $com = trim((string)$comune);
    $pro = trim((string)$provincia);
    if ($addr !== '') $p[] = $addr;
    if ($civ !== '') $p[] = $civ;
    $city = trim(implode(' ', array_values(array_filter([$cap, $com]))));
    if ($city !== '') $p[] = $city;
    $line = trim(implode(', ', $p));
    if ($line !== '' && $pro !== '') $line .= " ({$pro})";
    return trim($line);
}

/**
 * Build a best-effort fallback profile from tenant registry (aziende.php / tenants data).
 * This does NOT persist anything; it only helps prefill the wizard.
 */
function cnx_dw_build_fallback_profile_from_tenant(Database $db, int $tenantId): array {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0) return [];

    $t = $db->fetchOne("SELECT denominazione FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$tenantId]);
    if (!$t) return [];
    $companyName = trim((string)($t['denominazione'] ?? ''));

    $sites = [];

    if (cnx_dw_table_exists($db, 'tenant_locations')) {
        // Sede legale (primary)
        $sl = $db->fetchOne(
            "SELECT indirizzo, civico, cap, comune, provincia
             FROM tenant_locations
             WHERE tenant_id = ?
               AND location_type = 'sede_legale'
               AND deleted_at IS NULL
               AND is_active = 1
             ORDER BY is_primary DESC, id DESC
             LIMIT 1",
            [$tenantId]
        );
        if ($sl) {
            $line = cnx_dw_format_site_line($sl['indirizzo'] ?? null, $sl['civico'] ?? null, $sl['cap'] ?? null, $sl['comune'] ?? null, $sl['provincia'] ?? null);
            if ($line !== '') $sites[] = $line;
        }

        // Sedi operative (limit)
        $ops = $db->fetchAll(
            "SELECT indirizzo, civico, cap, comune, provincia
             FROM tenant_locations
             WHERE tenant_id = ?
               AND location_type = 'sede_operativa'
               AND deleted_at IS NULL
               AND is_active = 1
             ORDER BY is_primary DESC, id DESC
             LIMIT 30",
            [$tenantId]
        ) ?: [];
        foreach ($ops as $r) {
            $line = cnx_dw_format_site_line($r['indirizzo'] ?? null, $r['civico'] ?? null, $r['cap'] ?? null, $r['comune'] ?? null, $r['provincia'] ?? null);
            if ($line !== '') $sites[] = $line;
        }
    } else {
        // Legacy columns (schema drift safe)
        $cols = ['denominazione'];
        foreach (['sede_legale_indirizzo', 'sede_legale_civico', 'sede_legale_cap', 'sede_legale_comune', 'sede_legale_provincia', 'sedi_operative'] as $c) {
            if (cnx_dw_has_col($db, 'tenants', $c)) $cols[] = $c;
        }
        $sel = $db->fetchOne("SELECT " . implode(', ', array_map(fn($c) => "t.`{$c}`", $cols)) . " FROM tenants t WHERE t.id = ? LIMIT 1", [$tenantId]);
        if ($sel) {
            $line = cnx_dw_format_site_line($sel['sede_legale_indirizzo'] ?? null, $sel['sede_legale_civico'] ?? null, $sel['sede_legale_cap'] ?? null, $sel['sede_legale_comune'] ?? null, $sel['sede_legale_provincia'] ?? null);
            if ($line !== '') $sites[] = $line;
            if (!empty($sel['sedi_operative'])) {
                $decoded = json_decode((string)$sel['sedi_operative'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $r) {
                        if (!is_array($r)) continue;
                        $line2 = cnx_dw_format_site_line($r['indirizzo'] ?? null, $r['civico'] ?? null, $r['cap'] ?? null, $r['comune'] ?? null, $r['provincia'] ?? null);
                        if ($line2 !== '') $sites[] = $line2;
                    }
                }
            }
        }
    }

    $sites = array_values(array_unique(array_filter(array_map('trim', $sites))));

    return [
        'company_name' => $companyName,
        'sites' => $sites,
        // leave other fields empty; they can be filled via wizard/AI over time
    ];
}

try {
    if ($action === 'get') {
        $programId = (int)($_GET['program_id'] ?? 0);
        if ($programId <= 0) api_error('program_id richiesto', 400);

        $program = $db->fetchOne("SELECT tenant_id FROM compliance_programs WHERE id = ? LIMIT 1", [$programId]);
        if (!$program) api_error('Programma non trovato', 404);
        $tenantId = (int)($program['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant del programma', 403);
        }

        $row = $db->fetchOne(
            "SELECT id, profile_json, updated_by, updated_at, created_at
             FROM compliance_program_profiles
             WHERE tenant_id = ? AND program_id = ?
             LIMIT 1",
            [$tenantId, $programId]
        );

        $profile = null;
        if ($row && !empty($row['profile_json'])) {
            try { $profile = json_decode((string)$row['profile_json'], true) ?: null; } catch (Throwable $e) { $profile = null; }
        }

        $prefilledFromTenant = false;
        if (!$profile || !is_array($profile)) {
            $profile = cnx_dw_build_fallback_profile_from_tenant($db, $tenantId);
            if (!empty($profile)) $prefilledFromTenant = true;
        }

        api_success([
            'storage_available' => true,
            'program_id' => $programId,
            'profile' => $profile,
            'prefilled_from_tenant' => $prefilledFromTenant,
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

        $programId = (int)($payload['program_id'] ?? 0);
        if ($programId <= 0) api_error('program_id richiesto', 400);

        $program = $db->fetchOne("SELECT tenant_id FROM compliance_programs WHERE id = ? LIMIT 1", [$programId]);
        if (!$program) api_error('Programma non trovato', 404);
        $tenantId = (int)($program['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant del programma', 403);
        }

        $profileObj = $payload['profile'] ?? null;
        if ($profileObj !== null && !is_array($profileObj)) {
            api_error('profile deve essere un oggetto JSON', 400);
        }
        $profileJson = $profileObj !== null ? json_encode($profileObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

        $existing = $db->fetchOne(
            "SELECT id FROM compliance_program_profiles WHERE tenant_id = ? AND program_id = ? LIMIT 1",
            [$tenantId, $programId]
        );
        if ($existing) {
            $db->update('compliance_program_profiles', [
                'profile_json' => $profileJson,
                'updated_by' => $userId > 0 ? $userId : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int)$existing['id']]);
        } else {
            $db->insert('compliance_program_profiles', [
                'tenant_id' => $tenantId,
                'program_id' => $programId,
                'profile_json' => $profileJson,
                'updated_by' => $userId > 0 ? $userId : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        api_success(['storage_available' => true, 'program_id' => $programId], 'Profilo salvato');
    }

    api_error('Azione non supportata', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_PROGRAM_PROFILE] ' . $e->getMessage());
    api_error('Errore profilo programma', 500);
}

