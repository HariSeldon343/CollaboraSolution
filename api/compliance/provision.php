<?php
/**
 * Leva 2: Provision IMS repository + placeholder documents (and optional artifacts rows)
 *
 * POST /api/compliance/provision.php
 *
 * Security:
 * - Requires auth + CSRF
 * - Requires tenant 28 gate (planning)
 * - Requires caller has access to client tenant (super_admin or user_tenant_access)
 *
 * Schema drift safe:
 * - If compliance tables missing -> 503 with storage_available=false
 * - If consulting plan tables missing -> 503 (same as planning module pattern)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/tenant_folder_helper.php';
require_once __DIR__ . '/../../includes/compliance/provisioning_files_helper.php';
require_once __DIR__ . '/../../includes/sql_migration_runner.php';

cnx_compliance_require_csrf_for_write();
cnx_compliance_require_tenant28_planning($userInfo);

function cnx_table_exists(Database $db, string $table): bool {
    try { return (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$table]); } catch (Throwable $e) { return false; }
}

function cnx_norm_standard_code(string $raw): string {
    $s = strtoupper(trim($raw));
    $s = preg_replace('/\\s+/', ' ', $s) ?: $s;
    if ($s === '') return '';
    if (strpos($s, '9001') !== false) return 'ISO9001';
    if (strpos($s, '14001') !== false) return 'ISO14001';
    if (strpos($s, '45001') !== false) return 'ISO45001';
    if (strpos($s, '27001') !== false) return 'ISO27001';
    if (strpos($s, '13485') !== false) return 'ISO13485';
    if (strpos($s, '42001') !== false) return 'ISO42001';
    if (strpos($s, '7101') !== false) return 'ISO7101';
    if (strpos($s, '10881') !== false) return 'UNI10881';
    $s = preg_replace('/[^A-Z0-9]/', '', $s) ?: $s;
    return $s;
}

/**
 * @return array<int,array{code:string,edition:string}>
 */
function cnx_extract_standards_from_blueprint(array $blueprint): array {
    $tryLists = [];
    if (isset($blueprint['meta']['standards']) && is_array($blueprint['meta']['standards'])) $tryLists[] = $blueprint['meta']['standards'];
    if (isset($blueprint['compliance_blueprint']['standards']) && is_array($blueprint['compliance_blueprint']['standards'])) $tryLists[] = $blueprint['compliance_blueprint']['standards'];
    if (isset($blueprint['standards']) && is_array($blueprint['standards'])) $tryLists[] = $blueprint['standards'];

    $out = [];
    foreach ($tryLists as $list) {
        foreach ($list as $s) {
            if (is_string($s)) {
                $code = cnx_norm_standard_code($s);
                if ($code !== '') $out[] = ['code' => $code, 'edition' => ''];
                continue;
            }
            if (is_array($s)) {
                $code = cnx_norm_standard_code((string)($s['code'] ?? $s['name'] ?? ''));
                if ($code === '') continue;
                $out[] = ['code' => $code, 'edition' => (string)($s['edition'] ?? $s['edition_label'] ?? '')];
            }
        }
        if (!empty($out)) break;
    }
    if (empty($out)) $out[] = ['code' => 'ISO9001', 'edition' => ''];
    $uniq = [];
    foreach ($out as $r) {
        $c = (string)$r['code'];
        if ($c === '') continue;
        if (!isset($uniq[$c])) $uniq[$c] = ['code' => $c, 'edition' => (string)($r['edition'] ?? '')];
        if ($uniq[$c]['edition'] === '' && !empty($r['edition'])) $uniq[$c]['edition'] = (string)$r['edition'];
    }
    return array_values($uniq);
}

/**
 * @param array<string,int> $pathToFolderId
 */
function cnx_ensure_ims_path(Database $db, int $clientTenantId, int $imsFolderId, int $actorUserId, string $path, array &$pathToFolderId, int &$foldersCreated, int &$foldersExisting): void {
    $path = rtrim(trim($path), '/');
    if ($path === '' || $path === '/IMS') return;
    if (strpos($path, '/IMS/') !== 0) return;
    if (isset($pathToFolderId[$path])) return;

    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    if (empty($parts) || $parts[0] !== 'IMS') return;
    array_shift($parts);
    $currentPath = '/IMS';
    $parentId = $imsFolderId;
    foreach ($parts as $seg) {
        $seg = trim((string)$seg);
        if ($seg === '') continue;
        $currentPath .= '/' . $seg;
        if (isset($pathToFolderId[$currentPath])) {
            $parentId = (int)$pathToFolderId[$currentPath];
            continue;
        }
        $res = cnx_ensure_folder($db, $clientTenantId, $parentId, $seg, $actorUserId);
        if ($res['created']) $foldersCreated++; else $foldersExisting++;
        $pathToFolderId[$currentPath] = (int)$res['folder_id'];
        $parentId = (int)$res['folder_id'];
    }
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$consultingPlanId = (int)($data['consulting_plan_id'] ?? 0);
$options = is_array($data['options'] ?? null) ? $data['options'] : [];
$createDocuments = (bool)($options['create_documents'] ?? true);
$createTasks = (bool)($options['create_tasks'] ?? true);
$createCalendarEvents = (bool)($options['create_calendar_events'] ?? false);

if ($consultingPlanId <= 0) {
    api_error('consulting_plan_id richiesto', 400);
}

// Schema drift: require consulting plans tables (planning module 34)
try {
    $hasPlans = $db->fetchOne("SHOW TABLES LIKE 'consulting_plans'");
    if (!$hasPlans) {
        api_error(
            'Modulo pianificazione non inizializzato: applica la migrazione database 34 (consulting plans)',
            503,
            ['migration' => 'database/migrations/34_consulting_plans_tenant28.sql']
        );
    }
} catch (Throwable $e) {
    api_error('Database non disponibile per il modulo pianificazione', 503);
}

// Schema drift: compliance storage (migration 41)
$chk = cnx_compliance_check_tables($db, ['compliance_programs', 'compliance_artifacts', 'compliance_provisioning_runs']);
if (!$chk['ok']) {
    // Best-effort auto-apply for super_admin to reduce "stuck" provisioning in drifted prod DBs.
    $role = (string)($userInfo['role'] ?? 'user');
    if ($role === 'super_admin') {
        try {
            $pdo = $db->getConnection();
            $sqlFile = __DIR__ . '/../../database/migrations/41_compliance_programs.sql';
            cnx_apply_sql_migration_file($pdo, $sqlFile);
        } catch (Throwable $e) {
            // fall through to error below
        }
        $chk = cnx_compliance_check_tables($db, ['compliance_programs', 'compliance_artifacts', 'compliance_provisioning_runs']);
    }
    if (!$chk['ok']) {
        api_error(
            'Modulo compliance non inizializzato: applica la migrazione database 41 (compliance programs)',
            503,
            ['storage_available' => false, 'missing' => $chk['missing'] ?? [], 'migration' => 'database/migrations/41_compliance_programs.sql']
        );
    }
}

try {
    // Load consulting plan -> client tenant
    $plan = $db->fetchOne(
        "SELECT id, client_tenant_id, title, start_date, end_date, created_by, created_at
         FROM consulting_plans
         WHERE id = ?
         LIMIT 1",
        [$consultingPlanId]
    );
    if (!$plan) {
        api_error('Piano non trovato', 404);
    }

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) {
        api_error('Piano non valido (client_tenant_id mancante)', 400);
    }

    // Multi-tenant access check to client tenant (critical)
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato al tenant cliente', 403);
    }

    // Load blueprint JSON: from DB if available, else from payload
    $blueprint = null;
    $bpJson = null;
    $bpTable = false;
    try { $bpTable = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_blueprints'"); } catch (Throwable $e) { $bpTable = false; }

    if ($bpTable) {
        $row = $db->fetchOne(
            "SELECT blueprint_json
             FROM consulting_plan_blueprints
             WHERE plan_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$consultingPlanId]
        );
        if ($row && !empty($row['blueprint_json'])) {
            $bpJson = (string)$row['blueprint_json'];
        }
    }
    if (!$bpJson) {
        $bpJson = isset($data['blueprint_json']) ? json_encode($data['blueprint_json'], JSON_UNESCAPED_UNICODE) : null;
    }
    if (!$bpJson) {
        api_error('Blueprint mancante: genera prima il blueprint oppure passa blueprint_json', 400, ['storage_available' => $bpTable]);
    }
    $blueprint = json_decode($bpJson, true);
    if (!is_array($blueprint)) {
        api_error('Blueprint non valido (JSON)', 400);
    }

    // Resolve selected standards (IMS multi-standard). Backward compatible: default ISO9001.
    $selectedStandards = cnx_extract_standards_from_blueprint($blueprint);
    $selectedCodes = array_map(static fn($s) => (string)$s['code'], $selectedStandards);
    if (empty($selectedCodes)) $selectedCodes = ['ISO9001'];

    // Migration 45 (standards/templates/join) best-effort
    $role = (string)($userInfo['role'] ?? 'user');
    $hasStd = cnx_table_exists($db, 'compliance_standards');
    $hasTpl = cnx_table_exists($db, 'compliance_artifact_templates');
    $hasProgStd = cnx_table_exists($db, 'compliance_program_standards');
    if ((!$hasStd || !$hasTpl || !$hasProgStd) && $role === 'super_admin') {
        try {
            cnx_apply_sql_migration_file($db->getConnection(), __DIR__ . '/../../database/migrations/45_compliance_templates_and_standards.sql');
            $hasStd = cnx_table_exists($db, 'compliance_standards');
            $hasTpl = cnx_table_exists($db, 'compliance_artifact_templates');
            $hasProgStd = cnx_table_exists($db, 'compliance_program_standards');
        } catch (Throwable $e) {
            // non-blocking: can still provision from blueprint docs
        }
    }

    $standardMeta = [];
    if ($hasStd) {
        try {
            $in = implode(',', array_fill(0, count($selectedCodes), '?'));
            $rows = $db->fetchAll(
                "SELECT code, edition_label, family
                 FROM compliance_standards
                 WHERE code IN ($in) AND is_active = 1",
                $selectedCodes
            ) ?: [];
            foreach ($rows as $r) {
                $c = (string)($r['code'] ?? '');
                if ($c === '') continue;
                $standardMeta[$c] = [
                    'edition_label' => (string)($r['edition_label'] ?? ''),
                    'family' => (string)($r['family'] ?? ''),
                ];
            }
        } catch (Throwable $e) {}
    }

    $hasHlsSelected = false;
    foreach ($selectedCodes as $c) {
        $fam = (string)($standardMeta[$c]['family'] ?? '');
        if ($fam === 'HLS') { $hasHlsSelected = true; break; }
    }
    if (!$hasHlsSelected) {
        foreach ($selectedCodes as $c) {
            if (in_array($c, ['ISO9001','ISO14001','ISO45001','ISO27001','ISO42001'], true)) { $hasHlsSelected = true; break; }
        }
    }

    $primaryCode = in_array('ISO9001', $selectedCodes, true) ? 'ISO9001' : (string)($selectedCodes[0] ?? 'ISO9001');
    $editionFromBlueprint = '';
    foreach ($selectedStandards as $s) { if ((string)$s['code'] === $primaryCode && !empty($s['edition'])) { $editionFromBlueprint = (string)$s['edition']; break; } }
    $primaryEdition = (string)($standardMeta[$primaryCode]['edition_label'] ?? $editionFromBlueprint);
    if ($primaryEdition === '') $primaryEdition = $editionFromBlueprint ?: $primaryCode;
    $standardCode = $primaryCode;
    $standardEdition = $primaryEdition;

    $bp = (array)($blueprint['compliance_blueprint'] ?? $blueprint);
    $repo = isset($bp['repository_structure']) && is_array($bp['repository_structure']) ? $bp['repository_structure'] : [];
    $docs = isset($bp['documents']) && is_array($bp['documents']) ? $bp['documents'] : [];
    $recs = isset($bp['records_register']) && is_array($bp['records_register']) ? $bp['records_register'] : [];

    // Create/Reuse compliance program
    // IMPORTANT (2026-01-12): enforce "single program per tenant" to avoid duplicate dropdown entries.
    // We keep multi-standard under compliance_program_standards; program is a tenant-level container.
    $existingProgram = $db->fetchOne(
        "SELECT id
         FROM compliance_programs
         WHERE tenant_id = ?
           AND (status IS NULL OR status <> 'archived')
         ORDER BY updated_at DESC, id DESC
         LIMIT 1",
        [$clientTenantId]
    );
    $programId = $existingProgram ? (int)$existingProgram['id'] : 0;

    if ($programId <= 0) {
        $programId = (int)$db->insert('compliance_programs', [
            'tenant_id' => $clientTenantId,
            'standard_code' => $standardCode,
            'standard_edition' => $standardEdition,
            'scope_text' => (string)($bp['scope_proposal'] ?? ''),
            'sector_text' => null,
            'size_text' => null,
            'source_consulting_plan_id' => $consultingPlanId,
            'created_by_user_id' => (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0),
            'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        // Best-effort: keep primary metadata reasonably up to date without overwriting user-entered fields.
        try {
            $db->update('compliance_programs', [
                'standard_code' => $standardCode,
                'standard_edition' => $standardEdition,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $programId]);
        } catch (Throwable $e) {
            // non-blocking
        }
    }

    // Upsert program standards (IMS multi-standard) if available (migration 45)
    if ($programId > 0 && $hasProgStd) {
        foreach ($selectedCodes as $c) {
            $ed = (string)($standardMeta[$c]['edition_label'] ?? '');
            if ($ed === '') {
                foreach ($selectedStandards as $s) { if ((string)$s['code'] === $c && !empty($s['edition'])) { $ed = (string)$s['edition']; break; } }
            }
            if ($ed === '') $ed = $c;
            try {
                $db->query(
                    "INSERT INTO compliance_program_standards (program_id, standard_code, edition_label, created_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE edition_label = VALUES(edition_label)",
                    [$programId, $c, $ed]
                );
            } catch (Throwable $e) {}
        }
    }

    // Ensure tenant root folder exists + physical upload dir
    $tenantRow = $db->fetchOne("SELECT name, COALESCE(denominazione, name) AS denominazione FROM tenants WHERE id = ? LIMIT 1", [$clientTenantId]);
    $tenantName = (string)($tenantRow['denominazione'] ?? $tenantRow['name'] ?? ('Tenant ' . $clientTenantId));
    $root = cnx_ensure_tenant_root_folder($db, $clientTenantId, $tenantName);
    $rootFolderId = (int)$root['folder_id'];

    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($actorUserId <= 0) {
        api_error('Utente non valido', 401);
    }

    // Ensure IMS folder under tenant root
    $ims = cnx_ensure_folder($db, $clientTenantId, $rootFolderId, 'IMS', $actorUserId);
    $imsFolderId = (int)$ims['folder_id'];

    $foldersCreated = ($ims['created'] ? 1 : 0);
    $foldersExisting = ($ims['created'] ? 0 : 1);

    // IMS v2: resolve applicable templates from catalog (migration 45) when available.
    $mode = strtolower(trim((string)($options['mode'] ?? 'full')));
    if ($mode !== 'minimal') $mode = 'full';
    $useTemplates = false;
    $templates = [];
    if ($hasTpl) {
        try {
            $rows = $db->fetchAll(
                "SELECT template_key, title, doc_type, file_kind, folder_path, filename_template, clause_refs_json, is_common_hls
                 FROM compliance_artifact_templates
                 WHERE is_active = 1
                 ORDER BY is_common_hls DESC, template_key ASC"
            ) ?: [];
            foreach ($rows as $r) {
                $tKey = trim((string)($r['template_key'] ?? ''));
                if ($tKey === '') continue;
                $isCommon = (int)($r['is_common_hls'] ?? 0) ? true : false;
                $refs = null;
                try { $refs = $r['clause_refs_json'] ? (json_decode((string)$r['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $refs = null; }
                $covers = false;
                if (is_array($refs)) {
                    foreach ($selectedCodes as $c) { if (array_key_exists($c, $refs)) { $covers = true; break; } }
                }
                $include = ($isCommon && $hasHlsSelected) || $covers;
                if ($mode === 'minimal') {
                    $include = ($isCommon && $hasHlsSelected) || (is_array($refs) && array_key_exists('ISO9001', $refs));
                }
                if (!$include) continue;
                $templates[] = [
                    'template_key' => $tKey,
                    'title' => (string)($r['title'] ?? ''),
                    'doc_type' => (string)($r['doc_type'] ?? 'document'),
                    'file_kind' => (string)($r['file_kind'] ?? 'docx'),
                    'folder_path' => (string)($r['folder_path'] ?? '/IMS'),
                    'filename_template' => (string)($r['filename_template'] ?? ''),
                    'clause_refs' => $refs,
                ];
            }
            if (!empty($templates)) $useTemplates = true;
        } catch (Throwable $e) {
            $useTemplates = false;
        }
    }

    // Ensure IMS folders:
    // - Always ensure stable HLS base structure
    // - Merge blueprint repository_structure
    // - Merge template folder paths (if available)
    $pathToFolderId = ['/IMS' => $imsFolderId];
    $baseHlsPaths = [
        '/IMS/00-IMS',
        '/IMS/01-Context',
        '/IMS/01-Context/03-Records',
        '/IMS/02-Leadership',
        '/IMS/02-Leadership/03-Records',
        '/IMS/03-Planning',
        '/IMS/03-Planning/03-Records',
        '/IMS/04-Support',
        '/IMS/04-Support/01-Documents',
        '/IMS/04-Support/03-Records',
        '/IMS/05-Operation',
        '/IMS/05-Operation/03-Records',
        '/IMS/06-Performance',
        '/IMS/06-Performance/03-Records',
        '/IMS/07-Improvement',
        '/IMS/07-Improvement/03-Records',
    ];
    $allPaths = [];
    foreach ($baseHlsPaths as $p) $allPaths[] = (string)$p;
    foreach ($repo as $p) {
        $path = trim((string)($p['path'] ?? ''));
        if ($path !== '') $allPaths[] = $path;
    }
    if ($useTemplates) {
        foreach ($templates as $t) {
            $fp = trim((string)($t['folder_path'] ?? ''));
            if ($fp !== '') $allPaths[] = $fp;
        }
    }
    $seen = [];
    foreach ($allPaths as $p) {
        $p = rtrim(trim((string)$p), '/');
        if ($p === '' || $p === '/IMS') continue;
        if (strpos($p, '/IMS') !== 0) continue;
        if (isset($seen[$p])) continue;
        $seen[$p] = true;
        cnx_ensure_ims_path($db, $clientTenantId, $imsFolderId, $actorUserId, $p, $pathToFolderId, $foldersCreated, $foldersExisting);
    }

    // Default records path for legacy blueprint mode
    $recordsDefaultPath = '/IMS/06-Performance/03-Records';
    if (!$useTemplates) {
        cnx_ensure_ims_path($db, $clientTenantId, $imsFolderId, $actorUserId, $recordsDefaultPath, $pathToFolderId, $foldersCreated, $foldersExisting);
    }

    // Artifacts creation (docs + records)
    $artifactsCreated = 0;
    $artifactsExisting = 0;
    $filesCreated = 0;
    $filesExisting = 0;

    $normalizeKey = static function (string $s): string {
        $s = strtoupper(trim($s));
        $s = preg_replace('/[^A-Z0-9_\\-]+/', '_', $s) ?: $s;
        $s = preg_replace('/_+/', '_', $s) ?: $s;
        return trim($s, '_');
    };

    $ensureArtifact = function (array $row) use ($db, $programId, &$artifactsCreated, &$artifactsExisting) : int {
        $key = (string)$row['artifact_key'];
        $existing = $db->fetchOne(
            "SELECT id FROM compliance_artifacts WHERE program_id = ? AND artifact_key = ? LIMIT 1",
            [$programId, $key]
        );
        if ($existing && !empty($existing['id'])) {
            $artifactsExisting++;
            return (int)$existing['id'];
        }
        $artifactsCreated++;
        return (int)$db->insert('compliance_artifacts', array_merge($row, [
            'program_id' => $programId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
    };

    // Documents / Templates
    if ($useTemplates) {
        foreach ($templates as $t) {
            $tKey = (string)($t['template_key'] ?? '');
            if ($tKey === '') continue;
            $title = trim((string)($t['title'] ?? 'Documento'));
            $docType = strtolower(trim((string)($t['doc_type'] ?? 'document')));
            $fileKind = strtolower(trim((string)($t['file_kind'] ?? 'docx')));
            $folderPath = rtrim(trim((string)($t['folder_path'] ?? '/IMS')), '/');
            $filename = trim((string)($t['filename_template'] ?? $title));

            $folderId = $imsFolderId;
            if ($folderPath !== '' && isset($pathToFolderId[$folderPath])) {
                $folderId = (int)$pathToFolderId[$folderPath];
            }

            // Filter clause refs to selected standards (multi-standard object)
            $refsObj = $t['clause_refs'] ?? null;
            $filteredRefs = null;
            if (is_array($refsObj)) {
                $filteredRefs = [];
                foreach ($selectedCodes as $c) {
                    if (isset($refsObj[$c])) $filteredRefs[$c] = $refsObj[$c];
                }
            }
            $refsJson = $filteredRefs ? json_encode($filteredRefs, JSON_UNESCAPED_UNICODE) : null;

            $row = [
                'artifact_key' => $tKey,
                'title' => $title,
                'artifact_type' => $docType,
                'clause_refs_json' => $refsJson,
                'owner_label' => null,
                'owner_user_id' => null,
                'due_date' => null,
                'status' => 'todo',
                'file_id' => null,
                'folder_id' => $folderId,
            ];
            $artifactId = $ensureArtifact($row);

            if ($createDocuments) {
                $artifactRec = $db->fetchOne("SELECT file_id FROM compliance_artifacts WHERE id = ? LIMIT 1", [$artifactId]);
                $existingFileId = (int)($artifactRec['file_id'] ?? 0);
                if ($existingFileId <= 0) {
                    $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, $fileKind, $filename);
                    if ($docRes['created']) $filesCreated++; else $filesExisting++;
                    $db->update('compliance_artifacts', [
                        'file_id' => (int)$docRes['file_id'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $artifactId]);
                } else {
                    $filesExisting++;
                }
            }
        }
    } else {
        foreach ($docs as $d) {
        if (!is_array($d)) continue;
        $docCode = trim((string)($d['doc_code'] ?? ''));
        $title = trim((string)($d['title'] ?? 'Documento'));
        $docType = strtolower(trim((string)($d['doc_type'] ?? 'docx')));
        $owner = trim((string)($d['owner_role'] ?? ''));
        $repoPath = trim((string)($d['repository_path'] ?? ''));
        $clauseRefs = (isset($d['clause_refs']) && is_array($d['clause_refs'])) ? array_values($d['clause_refs']) : [];

        $artifactKey = $docCode !== '' ? ('DOC_' . $normalizeKey($docCode)) : ('DOC_' . $normalizeKey($title));
        if (strlen($artifactKey) > 80) $artifactKey = substr($artifactKey, 0, 80);

        $folderId = $imsFolderId;
        if ($repoPath !== '' && strpos($repoPath, '/IMS') === 0) {
            $repoPath = rtrim($repoPath, '/');
            if (isset($pathToFolderId[$repoPath])) {
                $folderId = (int)$pathToFolderId[$repoPath];
            }
        }

        $row = [
            'artifact_key' => $artifactKey,
            'title' => $title,
            'artifact_type' => $docType === 'xlsx' ? 'register' : ($docType === 'pptx' ? 'plan' : 'document'),
            'clause_refs_json' => json_encode($clauseRefs, JSON_UNESCAPED_UNICODE),
            'owner_label' => $owner !== '' ? $owner : null,
            'owner_user_id' => null,
            'due_date' => null,
            'status' => 'todo',
            'file_id' => null,
            'folder_id' => $folderId,
        ];

        $artifactId = $ensureArtifact($row);

        if ($createDocuments) {
            // Ensure placeholder doc exists and store file_id if missing
            $artifactRec = $db->fetchOne("SELECT file_id FROM compliance_artifacts WHERE id = ? LIMIT 1", [$artifactId]);
            $existingFileId = (int)($artifactRec['file_id'] ?? 0);
            if ($existingFileId <= 0) {
                $filename = ($docCode !== '' ? $docCode . ' - ' . $title : $title);
                $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, $docType, $filename);
                if ($docRes['created']) $filesCreated++; else $filesExisting++;
                $db->update('compliance_artifacts', [
                    'file_id' => (int)$docRes['file_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $artifactId]);
            } else {
                $filesExisting++;
            }
        }
        }

        // Records register
        foreach ($recs as $r) {
        if (!is_array($r)) continue;
        $recordName = trim((string)($r['record_name'] ?? 'Registro'));
        $owner = trim((string)($r['owner_role'] ?? ''));
        $clauseRefs = (isset($r['clause_refs']) && is_array($r['clause_refs'])) ? array_values($r['clause_refs']) : [];
        $artifactKey = 'REC_' . $normalizeKey($recordName);
        if (strlen($artifactKey) > 80) $artifactKey = substr($artifactKey, 0, 80);
        $folderId = (int)$pathToFolderId[$recordsDefaultPath];

        $row = [
            'artifact_key' => $artifactKey,
            'title' => $recordName,
            'artifact_type' => 'record',
            'clause_refs_json' => json_encode($clauseRefs, JSON_UNESCAPED_UNICODE),
            'owner_label' => $owner !== '' ? $owner : null,
            'owner_user_id' => null,
            'due_date' => null,
            'status' => 'todo',
            'file_id' => null,
            'folder_id' => $folderId,
        ];
        $artifactId = $ensureArtifact($row);

        if ($createDocuments) {
            $artifactRec = $db->fetchOne("SELECT file_id FROM compliance_artifacts WHERE id = ? LIMIT 1", [$artifactId]);
            $existingFileId = (int)($artifactRec['file_id'] ?? 0);
            if ($existingFileId <= 0) {
                // Records: create TXT placeholder by default (no ISO text; easy to fill)
                $docRes = cnx_ensure_placeholder_document($db, $clientTenantId, $folderId, $actorUserId, 'txt', $recordName);
                if ($docRes['created']) $filesCreated++; else $filesExisting++;
                $db->update('compliance_artifacts', [
                    'file_id' => (int)$docRes['file_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $artifactId]);
            } else {
                $filesExisting++;
            }
        }
        }
    }

    // Log provisioning run (best-effort)
    $blueprintHash = hash('sha256', trim($bpJson));
    $runStatus = 'ok';
    $notes = [
        'create_documents' => $createDocuments,
        'create_tasks' => $createTasks,
        'create_calendar_events' => $createCalendarEvents,
    ];
    try {
        $db->insert('compliance_provisioning_runs', [
            'program_id' => $programId,
            'triggered_by_user_id' => $actorUserId,
            'source' => 'planning',
            'blueprint_hash' => $blueprintHash,
            'created_folders_count' => $foldersCreated,
            'created_files_count' => $filesCreated,
            'created_tasks_count' => 0, // Leva 3 handled in tasks_sync
            'status' => $runStatus,
            'notes_json' => json_encode($notes, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // non-blocking
    }

    api_success([
        'storage_available' => true,
        'program_id' => $programId,
        'client_tenant_id' => $clientTenantId,
        'ims_folder_id' => $imsFolderId,
        'counts' => [
            'folders_created' => $foldersCreated,
            'folders_existing' => $foldersExisting,
            'artifacts_created' => $artifactsCreated,
            'artifacts_existing' => $artifactsExisting,
            'files_created' => $filesCreated,
            'files_existing' => $filesExisting,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_PROVISION] ' . $e->getMessage());
    api_error('Errore provisioning compliance', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

