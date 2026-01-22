<?php
/**
 * OpenAI Client Helper (minimal, safe)
 *
 * - Uses constants from config.php / config.production.php:
 *   - OPENAI_API_KEY (string)
 *   - OPENAI_MODEL (string)
 *   - OPENAI_TIMEOUT_SECONDS (int)
 *   - OPENAI_API_BASE (string)
 *
 * Security notes:
 * - Never logs API key or full prompt.
 * - Caller should avoid logging user-provided sensitive content.
 */

declare(strict_types=1);

/**
 * Convert chat-style messages into a single text prompt (best-effort).
 * Keeps role markers to preserve intent without relying on chat endpoints.
 */
function cnx_openai_messages_to_text(array $messages): string {
    $out = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = trim((string)($m['role'] ?? 'user'));
        $content = (string)($m['content'] ?? '');
        $role = $role !== '' ? strtoupper($role) : 'USER';
        $out[] = $role . ":\n" . $content;
    }
    return implode("\n\n", $out);
}

/**
 * Extract output text from Responses API payload (best-effort across variants).
 */
function cnx_openai_extract_responses_text(array $decoded): string {
    if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
        return (string)$decoded['output_text'];
    }
    // Typical structure: output[0].content[0].text
    if (isset($decoded['output']) && is_array($decoded['output'])) {
        foreach ($decoded['output'] as $o) {
            if (!is_array($o)) continue;
            $content = $o['content'] ?? null;
            if (!is_array($content)) continue;
            foreach ($content as $c) {
                if (!is_array($c)) continue;
                if (isset($c['text']) && is_string($c['text'])) return (string)$c['text'];
                if (isset($c['output_text']) && is_string($c['output_text'])) return (string)$c['output_text'];
            }
        }
    }
    // Fallback: some variants use choices/message/content even for responses-like wrappers
    return (string)($decoded['choices'][0]['message']['content'] ?? '');
}

/**
 * @return array{ok:bool,data?:array,error?:string,debug?:array}
 */
function cnx_openai_chat_json(array $messages, array $jsonSchema = null, array $opts = []): array {
    $apiKey = defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '';
    if (trim($apiKey) === '') {
        return [
            'ok' => false,
            'error' => 'OpenAI non configurato (OPENAI_API_KEY mancante)',
            'debug' => ['kind' => 'missing_key'],
        ];
    }

    $base = defined('OPENAI_API_BASE') ? rtrim((string)OPENAI_API_BASE, '/') : 'https://api.openai.com';
    $urlChat = $base . '/v1/chat/completions';
    $urlResponses = $base . '/v1/responses';
    $fallbackModel = 'gpt-4o-mini';
    $model = defined('OPENAI_MODEL') ? (string)OPENAI_MODEL : $fallbackModel;
    // Allow per-call model override (used by AI Hub multi-provider selector).
    if (isset($opts['model']) && trim((string)$opts['model']) !== '') {
        $model = (string)$opts['model'];
    }
    $timeout = isset($opts['timeout_seconds'])
        ? (int)$opts['timeout_seconds']
        : (defined('OPENAI_TIMEOUT_SECONDS') ? (int)OPENAI_TIMEOUT_SECONDS : 20);
    $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2;
    $maxTokens = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 1200;
    $maxRetries = isset($opts['max_retries']) ? max(0, (int)$opts['max_retries']) : 0;

    // Build chat payload (default path)
    $payloadChat = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    ];

    // Build responses payload (fallback for non-chat models)
    $payloadResponses = [
        'model' => $model,
        'input' => cnx_openai_messages_to_text($messages),
        'max_output_tokens' => $maxTokens,
    ];

    // Prefer JSON schema when provided (best-effort; older models may ignore)
    if (is_array($jsonSchema) && !empty($jsonSchema)) {
        // Chat Completions: response_format (legacy)
        $payloadChat['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => $jsonSchema,
        ];

        // Responses API: text.format (current)
        $schemaName = trim((string)($jsonSchema['name'] ?? 'response_schema'));
        if ($schemaName === '') $schemaName = 'response_schema';
        $schemaObj = $jsonSchema['schema'] ?? $jsonSchema;
        if (!is_array($schemaObj)) $schemaObj = [];
        $payloadResponses['text'] = [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'schema' => $schemaObj,
                'strict' => true,
            ],
        ];
    } else {
        // Chat Completions: older JSON mode
        $payloadChat['response_format'] = ['type' => 'json_object'];

        // Responses API: json_object format
        $payloadResponses['text'] = [
            'format' => ['type' => 'json_object'],
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    $attempt = 0;
    $lastDebug = null;

    // Decide initial mode:
    // - Prefer /v1/responses for gpt-5* and o* models (many are not chat-compatible)
    // - Allow explicit override via opts['force_mode'] = 'chat'|'responses'
    $forceMode = isset($opts['force_mode']) ? strtolower(trim((string)$opts['force_mode'])) : '';
    $modelLc = strtolower($model);
    $preferResponses = ($forceMode === 'responses')
        || ($forceMode === '' && (str_starts_with($modelLc, 'gpt-5') || str_starts_with($modelLc, 'o')));
    $preferChat = ($forceMode === 'chat');

    $mode = ($preferChat || !$preferResponses) ? 'chat' : 'responses'; // chat | responses
    $url = ($mode === 'responses') ? $urlResponses : $urlChat;
    $payload = ($mode === 'responses') ? $payloadResponses : $payloadChat;
    while (true) {
        $attempt++;

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'OpenAI client non disponibile (curl_init)'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Transport errors (network, DNS, timeout)
        if ($raw === false || $errno) {
            $lastDebug = ['kind' => 'transport', 'http' => $http, 'errno' => $errno, 'attempt' => $attempt, 'timeout_seconds' => $timeout, 'mode' => $mode];
            error_log('[OPENAI] transport error http=' . $http . ' errno=' . $errno . ' attempt=' . $attempt . ' err=' . $err);
            if ($attempt <= ($maxRetries + 1)) {
                // Exponential-ish backoff (best-effort)
                usleep((int)min(800000, 200000 * $attempt));
                if ($attempt <= $maxRetries) continue;
            }
            $msg = 'Errore comunicazione OpenAI';
            if ($errno === 28) { // CURLE_OPERATION_TIMEDOUT
                $msg = 'Timeout OpenAI (aumentare OPENAI_TIMEOUT_SECONDS o timeout_seconds)';
            }
            return [
                'ok' => false,
                'error' => $msg,
                'debug' => $lastDebug,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $lastDebug = ['kind' => 'non_json', 'http' => $http, 'attempt' => $attempt];
            error_log('[OPENAI] non-JSON response http=' . $http . ' attempt=' . $attempt);
            if ($attempt <= $maxRetries && $http >= 500) {
                usleep((int)min(800000, 200000 * $attempt));
                continue;
            }
            return [
                'ok' => false,
                'error' => 'Risposta OpenAI non valida',
                'debug' => $lastDebug,
            ];
        }

        // HTTP errors
        if ($http < 200 || $http >= 300) {
            $msg = (string)($decoded['error']['message'] ?? 'OpenAI error');
            $lastDebug = ['kind' => 'http_error', 'http' => $http, 'attempt' => $attempt];
            error_log('[OPENAI] http=' . $http . ' attempt=' . $attempt . ' message=' . $msg);

            // If model is not a chat model, switch endpoint to /v1/responses (best-effort)
            if ($mode === 'chat' && $http >= 400 && $http < 500) {
                $low = mb_strtolower($msg, 'UTF-8');
                if (str_contains($low, 'not a chat model') || (str_contains($low, 'chat model') && str_contains($low, 'not supported'))) {
                    $mode = 'responses';
                    $url = $urlResponses;
                    $payload = $payloadResponses;
                    $lastDebug = array_merge($lastDebug, ['switched_to' => 'responses']);
                    // Reset attempts so max_retries applies to the real (responses) call too
                    $attempt = 0;
                    // retry immediately once
                    continue;
                }
            }

            // If model is invalid/unavailable, retry once with fallback model (best-effort)
            if ($http >= 400 && $http < 500 && $model !== $fallbackModel) {
                $low = mb_strtolower($msg, 'UTF-8');
                if (str_contains($low, 'model') && (str_contains($low, 'not found') || str_contains($low, 'does not exist') || str_contains($low, 'invalid'))) {
                    $model = $fallbackModel;
                    $payloadChat['model'] = $model;
                    $payloadResponses['model'] = $model;
                    $payload['model'] = $model;
                    $lastDebug = array_merge($lastDebug, ['fallback_model' => $fallbackModel, 'fallback_used' => true]);
                    // retry immediately once
                    if ($attempt <= ($maxRetries + 1)) {
                        continue;
                    }
                }
            }
            // Retry only on server-side errors
            if ($attempt <= $maxRetries && $http >= 500) {
                usleep((int)min(800000, 200000 * $attempt));
                continue;
            }
            return [
                'ok' => false,
                'error' => 'OpenAI: ' . $msg,
                'debug' => $lastDebug,
            ];
        }

        // Success path
        break;
    }

    $content = $mode === 'responses'
        ? cnx_openai_extract_responses_text($decoded)
        : (string)($decoded['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return ['ok' => false, 'error' => 'OpenAI: contenuto vuoto', 'debug' => $lastDebug ?: ['kind' => 'empty_content'] ];
    }

    $json = json_decode($content, true);
    if (!is_array($json)) {
        // Some models may wrap JSON in text; attempt to extract first JSON object
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $maybe = substr($content, $start, $end - $start + 1);
            $json = json_decode($maybe, true);
        }
    }

    if (!is_array($json)) {
        return [
            'ok' => false,
            'error' => 'OpenAI: JSON non parsabile',
            'debug' => ['kind' => 'parse_error', 'http' => $http],
        ];
    }

    return ['ok' => true, 'data' => $json];
}

/**
 * OpenAI embeddings (best-effort).
 *
 * @param string[] $texts
 * @return array{ok:bool,embeddings?:array<int,array<float>>,error?:string}
 */
function cnx_openai_embed_texts(array $texts, array $opts = []): array {
    $apiKey = defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '';
    if (trim($apiKey) === '') {
        return ['ok' => false, 'error' => 'OpenAI non configurato (OPENAI_API_KEY mancante)'];
    }
    $base = defined('OPENAI_API_BASE') ? rtrim((string)OPENAI_API_BASE, '/') : 'https://api.openai.com';
    $url = $base . '/v1/embeddings';
    $model = defined('OPENAI_EMBEDDING_MODEL') ? (string)OPENAI_EMBEDDING_MODEL : 'text-embedding-3-small';
    if (isset($opts['model']) && trim((string)$opts['model']) !== '') {
        $model = (string)$opts['model'];
    }
    $timeout = isset($opts['timeout_seconds'])
        ? (int)$opts['timeout_seconds']
        : (defined('OPENAI_TIMEOUT_SECONDS') ? (int)OPENAI_TIMEOUT_SECONDS : 20);
    $payload = [
        'model' => $model,
        'input' => array_values($texts),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Errore cURL: ' . $err];
    }
    $decoded = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';
        if ($msg === '') $msg = "HTTP {$code}";
        return ['ok' => false, 'error' => $msg];
    }
    $data = $decoded['data'] ?? null;
    if (!is_array($data)) return ['ok' => false, 'error' => 'Risposta embeddings non valida'];
    $out = [];
    foreach ($data as $row) {
        $emb = $row['embedding'] ?? null;
        if (!is_array($emb)) continue;
        $out[] = array_map('floatval', $emb);
    }
    if (empty($out)) return ['ok' => false, 'error' => 'Embeddings vuote'];
    return ['ok' => true, 'embeddings' => $out];
}
