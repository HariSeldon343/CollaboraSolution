<?php
/**
 * Test API Endpoints
 */

// Test auth.php API
$baseUrl = 'http://localhost:8888/CollaboraNexio';

echo "Testing API Endpoints\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Auth API without action
echo "Test 1: Auth API (no action)\n";
$ch = curl_init($baseUrl . '/api/auth.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($response, $headerSize);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . $body . "\n\n";

// Test 2: Auth API check action
echo "Test 2: Auth API (check action)\n";
$ch = curl_init($baseUrl . '/api/auth.php?action=check');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . $response . "\n\n";

// Test 3: Test login with demo credentials
echo "Test 3: Auth API (login with demo credentials)\n";
$loginData = json_encode([
    'email' => 'admin@demo.com',
    'password' => 'Admin123!'
]);

$ch = curl_init($baseUrl . '/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . $response . "\n\n";

echo "All tests completed!\n";