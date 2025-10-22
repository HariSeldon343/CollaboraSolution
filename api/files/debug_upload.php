<?php
/**
 * DEBUG VERSION OF UPLOAD.PHP
 * Cattura TUTTO per diagnosticare il problema 404
 */

// LOGGING INIZIALE IMMEDIATO
$debugLog = __DIR__ . '/../../logs/upload_debug_' . date('Ymd') . '.log';
$logEntry = "\n\n========== REQUEST START: " . date('Y-m-d H:i:s') . " ==========\n";
$logEntry .= "Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "\n";
$logEntry .= "URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') . "\n";
$logEntry .= "User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN') . "\n";
$logEntry .= "Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'NONE') . "\n";
$logEntry .= "Remote Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') . "\n";
file_put_contents($debugLog, $logEntry, FILE_APPEND | LOCK_EX);

// Headers immediati per evitare cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Debug-Mode: true');
header('X-Debug-Time: ' . date('Y-m-d H:i:s'));

// Log che siamo arrivati qui
file_put_contents($debugLog, "Headers sent OK\n", FILE_APPEND | LOCK_EX);

// Risposta di test semplice
$response = [
    'debug' => true,
    'timestamp' => time(),
    'message' => 'Debug endpoint reached successfully',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
];

// Log risposta
file_put_contents($debugLog, "Response prepared: " . json_encode($response) . "\n", FILE_APPEND | LOCK_EX);

// Invia risposta JSON
header('Content-Type: application/json');
echo json_encode($response);

// Log finale
file_put_contents($debugLog, "Response sent successfully\n", FILE_APPEND | LOCK_EX);
file_put_contents($debugLog, "========== REQUEST END ==========\n", FILE_APPEND | LOCK_EX);