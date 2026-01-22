<?php
declare(strict_types=1);

/**
 * AI Providers Registry (server-side)
 *
 * Used by:
 * - ai.php UI (provider/model selector)
 * - api/ai/chat.php (validation + dispatch)
 *
 * Keys are stored server-side in config.secrets.php (gitignored).
 */

/**
 * @return array<string,array<string,mixed>>
 */
function cnx_ai_provider_specs(): array {
    $openaiDefault = defined('OPENAI_MODEL') ? (string)OPENAI_MODEL : 'gpt-5.2';
    $pplxDefault = defined('PPLX_DEFAULT_MODEL') ? (string)PPLX_DEFAULT_MODEL : 'sonar';

    return [
        'openai' => [
            'label' => 'OpenAI',
            'default_model' => $openaiDefault !== '' ? $openaiDefault : 'gpt-5.2',
            'key_const' => 'OPENAI_API_KEY',
        ],
        'perplexity' => [
            'label' => 'Perplexity',
            'default_model' => $pplxDefault !== '' ? $pplxDefault : 'sonar',
            'key_const' => 'PPLX_API_KEY',
        ],
    ];
}

/**
 * Public list for UI (no secrets).
 * @return array<int,array<string,string>>
 */
function cnx_ai_public_provider_list(): array {
    $specs = cnx_ai_provider_specs();
    $out = [];
    foreach ($specs as $k => $s) {
        $out[] = [
            'provider' => (string)$k,
            'label' => (string)($s['label'] ?? $k),
            'default_model' => (string)($s['default_model'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Validate provider+model input and apply defaults.
 *
 * @return array{ok:bool,provider?:string,model?:string,error?:string}
 */
function cnx_ai_validate_provider_model(string $provider, string $model): array {
    $provider = strtolower(trim($provider));
    $model = trim($model);

    $specs = cnx_ai_provider_specs();
    if ($provider === '' || !isset($specs[$provider])) {
        return ['ok' => false, 'error' => 'Provider AI non valido'];
    }

    if ($model === '') {
        $model = (string)($specs[$provider]['default_model'] ?? '');
    }
    $model = trim($model);
    if ($model === '') {
        return ['ok' => false, 'error' => 'Model AI mancante'];
    }

    // Keep this permissive but safe.
    // Allowed: letters, digits, dot, dash, underscore, colon, slash
    if (!preg_match('/^[a-zA-Z0-9._:\\/\\-]{1,80}$/', $model)) {
        return ['ok' => false, 'error' => 'Model AI non valido'];
    }

    return ['ok' => true, 'provider' => $provider, 'model' => $model];
}

