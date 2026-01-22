<?php
/**
 * Custom 404 Error Page
 * CollaboraNexio - Error Handler
 */
header('HTTP/1.0 404 Not Found');
header('Content-Type: application/json');

$isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

if ($isApiRequest) {
    // API request - return JSON
    echo json_encode([
        'success' => false,
        'error' => 'Endpoint not found',
        'code' => 404,
        'requested_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ]);
} else {
    // Web request - return HTML
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Pagina non trovata | Nexio</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .container { text-align: center; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; font-size: 72px; margin: 0; }
        p { color: #666; margin: 20px 0; }
        a { color: #3b82f6; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>404</h1>
        <p>La pagina richiesta non e stata trovata.</p>
        <a href="/CollaboraNexio/">Torna alla Dashboard</a>
    </div>
</body>
</html>
    <?php
}
