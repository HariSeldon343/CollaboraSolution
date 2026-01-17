<?php
// GET: list client tenant locations (sede legale + sedi operative) for Planning wizard
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/locations_municipalities.php';

try {
    $clientTenantId = isset($_GET['client_tenant_id']) ? (int)$_GET['client_tenant_id'] : 0;
    if ($clientTenantId <= 0) {
        api_error('client_tenant_id obbligatorio', 400);
    }
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato all’azienda cliente', 403);
    }

    $hasTenantLocations = false;
    try {
        $hasTenantLocations = (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenant_locations'
             LIMIT 1"
        );
    } catch (Throwable $e) {
        $hasTenantLocations = false;
    }

    $locations = [];

    if ($hasTenantLocations) {
        $rows = $db->fetchAll(
            "SELECT
                id,
                tenant_id,
                location_type,
                indirizzo,
                civico,
                cap,
                comune,
                provincia,
                is_primary,
                is_active,
                note,
                created_at
             FROM tenant_locations
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND is_active = 1
             ORDER BY
                is_primary DESC,
                CASE location_type WHEN 'sede_legale' THEN 0 ELSE 1 END,
                created_at ASC",
            [$clientTenantId]
        ) ?: [];

        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $indirizzo = trim((string)($r['indirizzo'] ?? ''));
            $civico = trim((string)($r['civico'] ?? ''));
            $cap = trim((string)($r['cap'] ?? ''));
            $comune = trim((string)($r['comune'] ?? ''));
            $provincia = strtoupper(trim((string)($r['provincia'] ?? '')));

            $labelParts = [];
            $type = (string)($r['location_type'] ?? '');
            $labelParts[] = ($type === 'sede_operativa') ? 'Sede operativa' : 'Sede legale';
            if (!empty($r['is_primary'])) $labelParts[] = '(primaria)';
            $label = trim(implode(' ', $labelParts));

            $full = trim(sprintf(
                '%s %s, %s %s (%s)',
                $indirizzo,
                $civico,
                $cap,
                $comune,
                $provincia
            ));

            $locations[] = [
                'id' => $id,
                'location_type' => $type,
                'is_primary' => (bool)($r['is_primary'] ?? false),
                'indirizzo' => $indirizzo,
                'civico' => $civico,
                'cap' => $cap,
                'comune' => $comune,
                'provincia' => $provincia,
                'label' => $label,
                'indirizzo_completo' => $full,
                'municipality_valid' => ($comune !== '' && $provincia !== '') ? cnx_locations_city_in_province_exists($db, $comune, $provincia) : null,
                'note' => isset($r['note']) ? (string)$r['note'] : null,
            ];
        }
    } else {
        // Legacy fallback: use tenants.sede_legale_* and tenants.sedi_operative JSON (best-effort).
        $tenantColsRows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenants'"
        ) ?: [];
        $tenantCols = [];
        foreach ($tenantColsRows as $r) {
            $k = (string)($r['COLUMN_NAME'] ?? '');
            if ($k !== '') $tenantCols[$k] = true;
        }

        $sel = "SELECT id";
        $fields = [
            'sede_legale_indirizzo',
            'sede_legale_civico',
            'sede_legale_cap',
            'sede_legale_comune',
            'sede_legale_provincia',
            'sedi_operative',
        ];
        foreach ($fields as $f) {
            if (!empty($tenantCols[$f])) $sel .= ", {$f}";
        }
        $sel .= " FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1";

        $t = $db->fetchOne($sel, [$clientTenantId]);
        if ($t) {
            $indirizzo = trim((string)($t['sede_legale_indirizzo'] ?? ''));
            $civico = trim((string)($t['sede_legale_civico'] ?? ''));
            $cap = trim((string)($t['sede_legale_cap'] ?? ''));
            $comune = trim((string)($t['sede_legale_comune'] ?? ''));
            $provincia = strtoupper(trim((string)($t['sede_legale_provincia'] ?? '')));
            $full = trim(sprintf('%s %s, %s %s (%s)', $indirizzo, $civico, $cap, $comune, $provincia));
            $locations[] = [
                'id' => null,
                'location_type' => 'sede_legale',
                'is_primary' => true,
                'indirizzo' => $indirizzo,
                'civico' => $civico,
                'cap' => $cap,
                'comune' => $comune,
                'provincia' => $provincia,
                'label' => 'Sede legale',
                'indirizzo_completo' => $full,
                'municipality_valid' => ($comune !== '' && $provincia !== '') ? cnx_locations_city_in_province_exists($db, $comune, $provincia) : null,
                'note' => null,
                'legacy_key' => 'sede_legale',
            ];

            if (isset($t['sedi_operative']) && $t['sedi_operative'] !== null && trim((string)$t['sedi_operative']) !== '') {
                try {
                    $decoded = json_decode((string)$t['sedi_operative'], true);
                    if (is_array($decoded)) {
                        $idx = 0;
                        foreach ($decoded as $op) {
                            if (!is_array($op)) continue;
                            $indirizzo = trim((string)($op['indirizzo'] ?? ''));
                            $civico = trim((string)($op['civico'] ?? ''));
                            $cap = trim((string)($op['cap'] ?? ''));
                            $comune = trim((string)($op['comune'] ?? ''));
                            $provincia = strtoupper(trim((string)($op['provincia'] ?? '')));
                            $full = trim(sprintf('%s %s, %s %s (%s)', $indirizzo, $civico, $cap, $comune, $provincia));
                            $locations[] = [
                                'id' => null,
                                'location_type' => 'sede_operativa',
                                'is_primary' => false,
                                'indirizzo' => $indirizzo,
                                'civico' => $civico,
                                'cap' => $cap,
                                'comune' => $comune,
                                'provincia' => $provincia,
                                'label' => 'Sede operativa',
                                'indirizzo_completo' => $full,
                                'municipality_valid' => ($comune !== '' && $provincia !== '') ? cnx_locations_city_in_province_exists($db, $comune, $provincia) : null,
                                'note' => null,
                                'legacy_key' => 'sede_operativa_' . $idx,
                            ];
                            $idx++;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }

    api_success([
        'client_tenant_id' => $clientTenantId,
        'has_tenant_locations_table' => $hasTenantLocations,
        'locations' => $locations,
        'sede_legale_count' => count(array_filter($locations, static fn($l) => ($l['location_type'] ?? '') === 'sede_legale')),
        'sedi_operative_count' => count(array_filter($locations, static fn($l) => ($l['location_type'] ?? '') === 'sede_operativa')),
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_CLIENT_LOCATIONS] ' . $e->getMessage());
    api_error('Errore caricamento sedi azienda', 500);
}

