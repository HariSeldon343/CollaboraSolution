<?php
declare(strict_types=1);

/**
 * AI Providers list (for UI)
 *
 * GET /api/ai/providers.php?action=list
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/ai/providers_registry.php';

$action = (string)($_GET['action'] ?? 'list');
if ($action !== 'list') api_error('Azione non valida', 400);

api_success([
    'providers' => cnx_ai_public_provider_list(),
]);

