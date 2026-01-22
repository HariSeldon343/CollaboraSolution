<?php
/**
 * OnlyOffice Same-Origin Reverse Proxy
 *
 * Maps:   /CollaboraNexio/onlyoffice/<path>
 * To:     ONLYOFFICE_INTERNAL_URL/<path>
 *
 * This preserves OnlyOffice's expected asset paths like:
 *   /web-apps/apps/api/documents/api.js
 * preventing 404s caused by loading api.js from a non-standard location.
 *
 * Security:
 * - No arbitrary upstream host (prevents SSRF). Upstream is ONLYOFFICE_INTERNAL_URL.
 * - Path is sanitized to prevent traversal.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/onlyoffice_config.php';

$path = $_GET['path'] ?? '';
$path = ltrim((string)$path, '/');

// Disallow traversal / control characters
if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request';
    exit;
}

$upstreamBase = defined('ONLYOFFICE_INTERNAL_URL') ? ONLYOFFICE_INTERNAL_URL : ONLYOFFICE_SERVER_URL;
$upstreamUrl = rtrim($upstreamBase, '/') . '/' . $path;

// Forward query string except "path"
$query = $_GET;
unset($query['path']);
if (!empty($query)) {
    $upstreamUrl .= (str_contains($upstreamUrl, '?') ? '&' : '?') . http_build_query($query);
}

// Hop-by-hop headers we shouldn't forward
$hopByHop = [
    'connection',
    'keep-alive',
    'proxy-authenticate',
    'proxy-authorization',
    'te',
    'trailers',
    'transfer-encoding',
    'upgrade'
];

// Build request headers
$reqHeaders = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $lname = strtolower($name);
        if (in_array($lname, $hopByHop, true)) continue;
        // We'll set Host/X-Forwarded-* explicitly below
        if (in_array($lname, ['host', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-for'], true)) continue;
        $reqHeaders[] = $name . ': ' . $value;
    }
}

// Preserve original host/proto so OnlyOffice generates URLs on the tunnel origin
$clientHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$clientProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// Cloudflare tunnel terminates TLS, so from PHP we often see HTTP. Force https for external origin.
$forwardedProto = 'https';
$forwardedHost = $clientHost;
$forwardedFor = $_SERVER['REMOTE_ADDR'] ?? '';

$reqHeaders[] = 'Host: ' . $forwardedHost;
$reqHeaders[] = 'X-Forwarded-Proto: ' . $forwardedProto;
$reqHeaders[] = 'X-Forwarded-Host: ' . $forwardedHost;
if (!empty($forwardedFor)) {
    $reqHeaders[] = 'X-Forwarded-For: ' . $forwardedFor;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $body = file_get_contents('php://input');
}

// Use cURL for streaming + header forwarding
if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Proxy unavailable (cURL missing)';
    exit;
}

$ch = curl_init($upstreamUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// Forward upstream status + headers
$sentHeaders = false;
$upstreamBaseNoSlash = rtrim($upstreamBase, '/');
$publicBaseNoSlash = rtrim((defined('BASE_URL') ? BASE_URL : '') . '/onlyoffice', '/');
$responseContentType = '';
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$sentHeaders, $hopByHop, $upstreamBaseNoSlash, $publicBaseNoSlash, &$responseContentType) {
    $len = strlen($headerLine);
    $headerLineTrimmed = trim($headerLine);
    if ($headerLineTrimmed === '') return $len;

    // Status line
    if (stripos($headerLineTrimmed, 'HTTP/') === 0) {
        // We'll let PHP set status via http_response_code from CURLINFO later
        return $len;
    }

    $parts = explode(':', $headerLineTrimmed, 2);
    if (count($parts) !== 2) return $len;

    $name = trim($parts[0]);
    $value = trim($parts[1]);
    $lname = strtolower($name);

    if (in_array($lname, $hopByHop, true)) return $len;

    // Avoid overriding our app cookies / security headers; OnlyOffice doesn't need them from upstream
    if ($lname === 'set-cookie') return $len;

    // Prevent caching surprises for dynamic endpoints
    if ($lname === 'cache-control') return $len;

    if ($lname === 'content-type') {
        $responseContentType = $value;
    }

    // Rewrite Location headers so browser never sees localhost:8083 redirects
    if ($lname === 'location' && $publicBaseNoSlash !== '' && $upstreamBaseNoSlash !== '') {
        $value = str_replace($upstreamBaseNoSlash, $publicBaseNoSlash, $value);
    }

    header($name . ': ' . $value, false);
    $sentHeaders = true;
    return $len;
});

// Rewrite absolute URLs inside text-like responses to avoid leaking localhost:8083 into the browser.
// This helps prevent requests like http://localhost:8083/cache/... from being blocked/mixed-content.
$rewriteBuffer = '';
$rewriteEnabled = null; // null=unknown, true/false after content-type known
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) use (&$rewriteBuffer, &$rewriteEnabled, &$responseContentType, $upstreamBaseNoSlash, $publicBaseNoSlash) {
    $len = strlen($chunk);

    if ($rewriteEnabled === null) {
        $ct = strtolower(trim((string)$responseContentType));
        // Enable rewrite only for textual formats
        $rewriteEnabled = (
            str_starts_with($ct, 'text/') ||
            str_contains($ct, 'application/javascript') ||
            str_contains($ct, 'application/json') ||
            str_contains($ct, 'application/xml') ||
            str_contains($ct, 'application/xhtml') ||
            str_contains($ct, 'text/html')
        );
    }

    if ($rewriteEnabled) {
        $rewriteBuffer .= $chunk;
        // Safety cap: if response is huge, stop buffering and stream as-is
        if (strlen($rewriteBuffer) > 5 * 1024 * 1024) {
            echo $rewriteBuffer;
            $rewriteBuffer = '';
            $rewriteEnabled = false;
        }
        return $len;
    }

    echo $chunk;
    return $len;
});

// Disable buffering
header('X-Accel-Buffering: no');

$ok = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code > 0) {
    http_response_code($code);
}

// Flush buffered rewritten content (if any)
if (!empty($rewriteBuffer)) {
    // Replace upstream base URL (http://localhost:8083) with the public /onlyoffice base
    if ($publicBaseNoSlash !== '' && $upstreamBaseNoSlash !== '') {
        $rewriteBuffer = str_replace($upstreamBaseNoSlash, $publicBaseNoSlash, $rewriteBuffer);
        // Also normalize websocket scheme if present
        $rewriteBuffer = str_replace('ws://' . preg_replace('#^https?://#i', '', $upstreamBaseNoSlash), 'wss://' . preg_replace('#^https?://#i', '', $publicBaseNoSlash), $rewriteBuffer);
    }
    echo $rewriteBuffer;
    $rewriteBuffer = '';
}

if ($ok === false) {
    // If streaming failed before output, provide a minimal error
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Upstream error';
}


