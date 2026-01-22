<?php
// GET: list available checklist templates (tenant 28 tool)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

try {
    $templates = cnx_checklists_list_templates_meta();

    $storage = cnx_checklists_check_storage($db);
    api_success([
        'storage_available' => (bool)($storage['ok'] ?? false),
        'missing' => $storage['ok'] ? [] : ($storage['missing'] ?? []),
        'migration' => $storage['ok'] ? null : ($storage['migration'] ?? CNX_CHECKLISTS_MIGRATION_HINT),
        'templates' => $templates,
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_CHECKLIST_TEMPLATES] ' . $e->getMessage());
    api_error('Errore caricamento template checklist', 500);
}

