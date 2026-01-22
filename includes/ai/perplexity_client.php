<?php
declare(strict_types=1);

/**
 * Perplexity API client (best-effort).
 *
 * We intentionally keep it minimal and compatible with OpenAI-like chat format.
 * Endpoint (typical): POST https://api.perplexity.ai/chat/completions
 *
 * Secrets:
 * - PPLX_API_KEY should be defined in config.secrets.php (gitignored).
 */

/**
 * @return array{ok:bool,text?:string,error?:string,debug?:array}
 */
function cnx_pplx_chat_text(array $messages, string $model, array $opts = []): array {
    $apiKey = defined('PPLX_API_KEY') ? (string)PPLX_API_KEY : '';
    if (trim($apiKey) === '') {
        return ['ok' => false, 'error' => 'Perplexity non configurato (PPLX_API_KEY mancante)', 'debug' => ['kind' => 'missing_key']];
    }

    $base = defined('PPLX_API_BASE') ? rtrim((string)PPLX_API_BASE, '/') : 'https://api.perplexity.ai';
    $url = $base . '/chat/completions';

    $timeout = isset($opts['timeout_seconds']) ? (int)$opts['timeout_seconds'] : 30;
    $maxTokens = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 1200;
    $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2;
    $maxRetries = isset($opts['max_retries']) ? max(0, (int)$opts['max_retries']) : 0;

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];

    $attempt = 0;
    $lastDebug = null;
    while (true) {
        $attempt++;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Perplexity client non disponibile (curl_init)'];
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

        if ($raw === false || $errno) {
            $lastDebug = ['kind' => 'transport', 'http' => $http, 'errno' => $errno, 'attempt' => $attempt, 'timeout_seconds' => $timeout];
            if ($attempt <= $maxRetries && $errno === 28) { // timeout
                usleep((int)min(800000, 200000 * $attempt));
                continue;
            }
            return ['ok' => false, 'error' => 'Errore comunicazione Perplexity', 'debug' => $lastDebug];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $lastDebug = ['kind' => 'non_json', 'http' => $http, 'attempt' => $attempt];
            return ['ok' => false, 'error' => 'Risposta Perplexity non valida', 'debug' => $lastDebug];
        }

        if ($http < 200 || $http >= 300) {
            $msg = (string)($decoded['error']['message'] ?? $decoded['message'] ?? 'Perplexity error');
            $lastDebug = ['kind' => 'http_error', 'http' => $http, 'attempt' => $attempt];
            if ($attempt <= $maxRetries && $http >= 500) {
                usleep((int)min(800000, 200000 * $attempt));
                continue;
            }
            return ['ok' => false, 'error' => 'Perplexity: ' . $msg, 'debug' => $lastDebug];
        }

        $text = (string)($decoded['choices'][0]['message']['content'] ?? $decoded['choices'][0]['text'] ?? '');
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Perplexity: contenuto vuoto', 'debug' => $lastDebug ?: ['kind' => 'empty_content']];
        }
        return ['ok' => true, 'text' => $text];
    }
}

