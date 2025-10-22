Write-Host "`n=== TEST FIX 403 FORBIDDEN ===" -ForegroundColor Cyan
Write-Host "Testing POST requests with and without query strings`n" -ForegroundColor Yellow

# Test configurations
$baseUrl = "http://localhost:8888/CollaboraNexio"
$endpoints = @(
    @{Path = "/api/files/upload.php"; Name = "Upload without query"},
    @{Path = "/api/files/upload.php?_t=123456789"; Name = "Upload WITH query"},
    @{Path = "/api/files/create_document.php"; Name = "Create Doc without query"},
    @{Path = "/api/files/create_document.php?_t=987654321"; Name = "Create Doc WITH query"}
)

$results = @()

foreach ($endpoint in $endpoints) {
    Write-Host "[TEST] $($endpoint.Name)" -ForegroundColor White
    Write-Host "  URL: $baseUrl$($endpoint.Path)" -ForegroundColor Gray

    try {
        $response = Invoke-WebRequest -Uri "$baseUrl$($endpoint.Path)" `
                                    -Method POST `
                                    -ContentType "application/json" `
                                    -Body "{}" `
                                    -UseBasicParsing `
                                    -ErrorAction Stop

        $status = $response.StatusCode
        Write-Host "  Result: $status" -ForegroundColor Yellow
        $results += @{Test = $endpoint.Name; Status = $status; Result = "Unexpected Success"}
    }
    catch {
        $statusCode = $null
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
        }

        if ($statusCode -eq 401) {
            Write-Host "  Result: 401 (Unauthorized) " -ForegroundColor Green -NoNewline
            Write-Host "OK!" -ForegroundColor Green
            $results += @{Test = $endpoint.Name; Status = 401; Result = "PASS"}
        }
        elseif ($statusCode -eq 403) {
            Write-Host "  Result: 403 (Forbidden) " -ForegroundColor Red -NoNewline
            Write-Host "BUG!" -ForegroundColor Red
            $results += @{Test = $endpoint.Name; Status = 403; Result = "FAIL"}
        }
        elseif ($statusCode -eq 404) {
            Write-Host "  Result: 404 (Not Found) " -ForegroundColor Yellow -NoNewline
            Write-Host "Error!" -ForegroundColor Yellow
            $results += @{Test = $endpoint.Name; Status = 404; Result = "FAIL"}
        }
        else {
            Write-Host "  Result: Unknown error - $($_.Exception.Message)" -ForegroundColor Magenta
            $results += @{Test = $endpoint.Name; Status = 0; Result = "Unknown"}
        }
    }
    Write-Host ""
}

# Summary
Write-Host "`n=== SUMMARY ===" -ForegroundColor Cyan
$passCount = ($results | Where-Object {$_.Result -eq "PASS"}).Count
$failCount = ($results | Where-Object {$_.Result -eq "FAIL"}).Count

if ($failCount -eq 0) {
    Write-Host "ALL TESTS PASSED! " -ForegroundColor Green -NoNewline
    Write-Host "OK!" -ForegroundColor Green
    Write-Host "The 403 Forbidden issue with query strings is FIXED!" -ForegroundColor Green
} else {
    Write-Host "SOME TESTS FAILED! " -ForegroundColor Red -NoNewline
    Write-Host "ERROR!" -ForegroundColor Red
    Write-Host "$failCount test(s) still showing issues" -ForegroundColor Red
}

Write-Host "`nDetailed Results:" -ForegroundColor Yellow
$results | Format-Table -AutoSize

Write-Host "`nNote: All endpoints should return 401 (Unauthorized) since no session is provided." -ForegroundColor Gray
Write-Host "This is the EXPECTED behavior. 403 or 404 indicate problems.`n" -ForegroundColor Gray