# Test Query String Support in .htaccess
# Tests POST requests with and without query string parameters

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   BUG-008 Query String Fix Test       " -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# Test 1: POST with query string (cache busting timestamp)
Write-Host "[TEST 1] POST upload.php with query string (?_t=timestamp)" -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri 'http://localhost:8888/CollaboraNexio/api/files/upload.php?_t=123456789' `
        -Method POST `
        -UseBasicParsing `
        -ErrorAction SilentlyContinue

    Write-Host "  Status: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(80, $response.Content.Length)))"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-Host "  Status: 401 (EXPECTED - No auth)" -ForegroundColor Green
    } else {
        Write-Host "  Status: $statusCode (ERROR!)" -ForegroundColor Red
    }
}

Write-Host ""

# Test 2: POST create_document.php with query string
Write-Host "[TEST 2] POST create_document.php with query string" -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri 'http://localhost:8888/CollaboraNexio/api/files/create_document.php?_t=987654321' `
        -Method POST `
        -UseBasicParsing `
        -ErrorAction SilentlyContinue

    Write-Host "  Status: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(80, $response.Content.Length)))"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-Host "  Status: 401 (EXPECTED - No auth)" -ForegroundColor Green
    } else {
        Write-Host "  Status: $statusCode (ERROR!)" -ForegroundColor Red
    }
}

Write-Host ""

# Test 3: POST without query string (baseline)
Write-Host "[TEST 3] POST upload.php WITHOUT query string (baseline)" -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri 'http://localhost:8888/CollaboraNexio/api/files/upload.php' `
        -Method POST `
        -UseBasicParsing `
        -ErrorAction SilentlyContinue

    Write-Host "  Status: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(80, $response.Content.Length)))"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-Host "  Status: 401 (EXPECTED - No auth)" -ForegroundColor Green
    } else {
        Write-Host "  Status: $statusCode (ERROR!)" -ForegroundColor Red
    }
}

Write-Host ""

# Test 4: GET with query string
Write-Host "[TEST 4] GET upload.php with query string" -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri 'http://localhost:8888/CollaboraNexio/api/files/upload.php?test=param' `
        -Method GET `
        -UseBasicParsing `
        -ErrorAction SilentlyContinue

    Write-Host "  Status: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(80, $response.Content.Length)))"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-Host "  Status: 401 (EXPECTED - No auth)" -ForegroundColor Green
    } else {
        Write-Host "  Status: $statusCode (ERROR!)" -ForegroundColor Red
    }
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "           SUMMARY                      " -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Expected: All tests should return 401 (Unauthorized)" -ForegroundColor White
Write-Host "401 = Endpoint works, requires authentication" -ForegroundColor Green
Write-Host "404 = Endpoint not found - .htaccess problem!" -ForegroundColor Red
Write-Host "========================================`n" -ForegroundColor Cyan
