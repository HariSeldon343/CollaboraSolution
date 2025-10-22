# Test Upload 200 Error Fix
# Verifica che upload.php restituisca 401 invece di 200 senza auth

Write-Host "=== TEST UPLOAD 200 ERROR FIX ===" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8888/CollaboraNexio"

# Test Function
function Test-Endpoint {
    param(
        [string]$Url,
        [string]$Description,
        [string]$Method = "POST"
    )

    Write-Host "TEST: $Description" -ForegroundColor Yellow
    Write-Host "URL: $Url" -ForegroundColor Gray

    try {
        $response = Invoke-WebRequest -Uri $Url -Method $Method -UseBasicParsing -ErrorAction Stop
        Write-Host "  Result: $($response.StatusCode) $($response.StatusDescription)" -ForegroundColor Red
        Write-Host "  Body: $($response.Content)" -ForegroundColor Gray
        Write-Host ""
        return $response.StatusCode
    } catch {
        $statusCode = $_.Exception.Response.StatusCode.value__
        Write-Host "  Result: $statusCode (Expected 401)" -ForegroundColor $(if ($statusCode -eq 401) { "Green" } else { "Red" })
        Write-Host ""
        return $statusCode
    }
}

Write-Host "1. Testing upload.php WITHOUT query string" -ForegroundColor Cyan
Write-Host "   (Should return 401, was returning 200)" -ForegroundColor Gray
Write-Host ""

$status1 = Test-Endpoint -Url "$baseUrl/api/files/upload.php" -Description "POST upload.php (no query)" -Method "POST"

Write-Host "2. Testing upload.php WITH query string" -ForegroundColor Cyan
Write-Host "   (Should return 401)" -ForegroundColor Gray
Write-Host ""

$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$status2 = Test-Endpoint -Url "$baseUrl/api/files/upload.php?_t=$timestamp" -Description "POST upload.php?_t=timestamp" -Method "POST"

Write-Host "3. Testing create_document.php WITHOUT query string" -ForegroundColor Cyan
Write-Host "   (Should return 401 - control test)" -ForegroundColor Gray
Write-Host ""

$status3 = Test-Endpoint -Url "$baseUrl/api/files/create_document.php" -Description "POST create_document.php (no query)" -Method "POST"

Write-Host "4. Testing create_document.php WITH query string" -ForegroundColor Cyan
Write-Host "   (Should return 401 - control test)" -ForegroundColor Gray
Write-Host ""

$timestamp2 = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$status4 = Test-Endpoint -Url "$baseUrl/api/files/create_document.php?_t=$timestamp2" -Description "POST create_document.php?_t=timestamp" -Method "POST"

# Summary
Write-Host "=== SUMMARY ===" -ForegroundColor Cyan
Write-Host ""

$allPassed = $true

Write-Host "Test 1 - upload.php (no query):" -NoNewline
if ($status1 -eq 401) {
    Write-Host " PASS (401)" -ForegroundColor Green
} else {
    Write-Host " FAIL ($status1 - expected 401)" -ForegroundColor Red
    $allPassed = $false
}

Write-Host "Test 2 - upload.php (with query):" -NoNewline
if ($status2 -eq 401) {
    Write-Host " PASS (401)" -ForegroundColor Green
} else {
    Write-Host " FAIL ($status2 - expected 401)" -ForegroundColor Red
    $allPassed = $false
}

Write-Host "Test 3 - create_document.php (no query):" -NoNewline
if ($status3 -eq 401) {
    Write-Host " PASS (401)" -ForegroundColor Green
} else {
    Write-Host " FAIL ($status3 - expected 401)" -ForegroundColor Red
    $allPassed = $false
}

Write-Host "Test 4 - create_document.php (with query):" -NoNewline
if ($status4 -eq 401) {
    Write-Host " PASS (401)" -ForegroundColor Green
} else {
    Write-Host " FAIL ($status4 - expected 401)" -ForegroundColor Red
    $allPassed = $false
}

Write-Host ""
if ($allPassed) {
    Write-Host "=== ALL TESTS PASSED ===" -ForegroundColor Green
    Write-Host "Fix applicato con successo! Tutti gli endpoint restituiscono 401 correttamente." -ForegroundColor Green
} else {
    Write-Host "=== SOME TESTS FAILED ===" -ForegroundColor Red
    Write-Host "Verificare configurazione Apache e .htaccess" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Premere un tasto per chiudere..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
