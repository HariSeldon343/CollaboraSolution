# Test-DeploymentStatus.ps1
# Verifica completa deployment e configurazione per BUG-008 404 issue

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DEPLOYMENT STATUS VERIFICATION SCRIPT  " -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# 1. Verifica Apache Service
Write-Host "[1] Checking Apache Service..." -ForegroundColor Yellow
$apache = Get-Service Apache2.4 -ErrorAction SilentlyContinue
if ($apache -and $apache.Status -eq 'Running') {
    Write-Host "    OK - Apache is RUNNING" -ForegroundColor Green
} else {
    Write-Host "    ERROR - Apache NOT running or not found!" -ForegroundColor Red
    Write-Host "    Run: Start-ApacheXAMPP.ps1 to start Apache" -ForegroundColor Yellow
}

# 2. Verifica porta 8888
Write-Host ""
Write-Host "[2] Checking Port 8888..." -ForegroundColor Yellow
$port = Get-NetTCPConnection -LocalPort 8888 -State Listen -ErrorAction SilentlyContinue
if ($port) {
    Write-Host "    OK - Port 8888 is LISTENING" -ForegroundColor Green
} else {
    Write-Host "    ERROR - Port 8888 NOT listening!" -ForegroundColor Red
}

# 3. Verifica .htaccess
Write-Host ""
Write-Host "[3] Checking .htaccess File..." -ForegroundColor Yellow
$htaccess = "C:\xampp\htdocs\CollaboraNexio\api\.htaccess"
if (Test-Path $htaccess) {
    Write-Host "    OK - .htaccess EXISTS" -ForegroundColor Green

    # Verifica contenuto
    $content = Get-Content $htaccess -Raw
    if ($content -match '\[END\]') {
        Write-Host "    OK - Contains [END] flags (BUG-010 fix)" -ForegroundColor Green
    } else {
        Write-Host "    ERROR - Missing [END] flags!" -ForegroundColor Red
    }

    if ($content -match 'api/files/') {
        Write-Host "    OK - Contains /api/files/ rules" -ForegroundColor Green
    } else {
        Write-Host "    ERROR - Missing /api/files/ rules!" -ForegroundColor Red
    }
} else {
    Write-Host "    ERROR - .htaccess NOT FOUND!" -ForegroundColor Red
}

# 4. Test endpoint responses
Write-Host ""
Write-Host "[4] Testing API Endpoints..." -ForegroundColor Yellow

# Helper function per test HTTP
function Test-Endpoint {
    param(
        [string]$Url,
        [string]$Method = "POST"
    )

    try {
        $response = Invoke-WebRequest -Uri $Url -Method $Method -UseBasicParsing -ErrorAction Stop
        return @{
            StatusCode = $response.StatusCode
            Content = $response.Content
        }
    } catch {
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
            $content = $_.ErrorDetails.Message
            return @{
                StatusCode = $statusCode
                Content = $content
            }
        } else {
            return @{
                StatusCode = 0
                Content = "Connection failed"
            }
        }
    }
}

# Test upload.php senza query
$url1 = "http://localhost:8888/CollaboraNexio/api/files/upload.php"
Write-Host "    Testing: upload.php (no query)" -ForegroundColor Cyan
$result1 = Test-Endpoint -Url $url1
if ($result1.StatusCode -eq 401) {
    Write-Host "    OK - 401 Unauthorized (correct)" -ForegroundColor Green
} elseif ($result1.StatusCode -eq 404) {
    Write-Host "    ERROR - 404 Not Found - BUG PRESENT!" -ForegroundColor Red
} elseif ($result1.StatusCode -eq 403) {
    Write-Host "    ERROR - 403 Forbidden - CHECK .htaccess!" -ForegroundColor Red
} elseif ($result1.StatusCode -eq 200) {
    Write-Host "    ERROR - 200 OK - Missing auth check!" -ForegroundColor Red
} else {
    Write-Host "    WARNING - Status: $($result1.StatusCode)" -ForegroundColor Yellow
}

# Test upload.php con query string
$url2 = "http://localhost:8888/CollaboraNexio/api/files/upload.php?_t=123456789"
Write-Host "    Testing: upload.php (with query)" -ForegroundColor Cyan
$result2 = Test-Endpoint -Url $url2
if ($result2.StatusCode -eq 401) {
    Write-Host "    OK - 401 Unauthorized (correct)" -ForegroundColor Green
} elseif ($result2.StatusCode -eq 404) {
    Write-Host "    ERROR - 404 Not Found - BUG PRESENT!" -ForegroundColor Red
} elseif ($result2.StatusCode -eq 403) {
    Write-Host "    ERROR - 403 Forbidden - CHECK .htaccess!" -ForegroundColor Red
} else {
    Write-Host "    WARNING - Status: $($result2.StatusCode)" -ForegroundColor Yellow
}

# Test create_document.php
$url3 = "http://localhost:8888/CollaboraNexio/api/files/create_document.php"
Write-Host "    Testing: create_document.php" -ForegroundColor Cyan
$result3 = Test-Endpoint -Url $url3
if ($result3.StatusCode -eq 401) {
    Write-Host "    OK - 401 Unauthorized (correct)" -ForegroundColor Green
} elseif ($result3.StatusCode -eq 404) {
    Write-Host "    ERROR - 404 Not Found - BUG PRESENT!" -ForegroundColor Red
} else {
    Write-Host "    WARNING - Status: $($result3.StatusCode)" -ForegroundColor Yellow
}

# 5. Verifica JavaScript cache busting
Write-Host ""
Write-Host "[5] Checking JavaScript Files..." -ForegroundColor Yellow
$jsFile = "C:\xampp\htdocs\CollaboraNexio\assets\js\filemanager_enhanced.js"
if (Test-Path $jsFile) {
    $jsContent = Get-Content $jsFile -Raw
    if ($jsContent -like '*Math.floor*') {
        Write-Host "    OK - Math.floor() implemented (no decimals)" -ForegroundColor Green
    } else {
        Write-Host "    ERROR - Math.random() without floor - decimals in query!" -ForegroundColor Red
    }
} else {
    Write-Host "    ERROR - filemanager_enhanced.js NOT FOUND!" -ForegroundColor Red
}

# 6. Check Apache logs
Write-Host ""
Write-Host "[6] Recent Apache Access Log..." -ForegroundColor Yellow
$accessLog = "C:\xampp\apache\logs\access.log"
if (Test-Path $accessLog) {
    Write-Host "    Last 5 requests:" -ForegroundColor Cyan
    Get-Content $accessLog -Tail 5 | ForEach-Object {
        if ($_ -match '404') {
            Write-Host "    $_" -ForegroundColor Red
        } elseif ($_ -match '401') {
            Write-Host "    $_" -ForegroundColor Green
        } else {
            Write-Host "    $_" -ForegroundColor Gray
        }
    }
} else {
    Write-Host "    Access log not found" -ForegroundColor Yellow
}

# 7. Summary
Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "              SUMMARY                    " -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan

$issues = @()

if (!$apache -or $apache.Status -ne 'Running') {
    $issues += "Apache not running"
}
if (!$port) {
    $issues += "Port 8888 not listening"
}
if (!(Test-Path $htaccess)) {
    $issues += ".htaccess missing"
}
if ($result1.StatusCode -eq 404 -or $result2.StatusCode -eq 404 -or $result3.StatusCode -eq 404) {
    $issues += "404 errors detected"
}

if ($issues.Count -eq 0) {
    Write-Host ""
    Write-Host "ALL SYSTEMS OPERATIONAL!" -ForegroundColor Green
    Write-Host "  Server is configured correctly." -ForegroundColor Green
    Write-Host ""
    Write-Host "  If still seeing 404 in browser:" -ForegroundColor Yellow
    Write-Host "  1. Press CTRL+F5 in browser (hard refresh)" -ForegroundColor Cyan
    Write-Host "  2. Clear browser cache completely" -ForegroundColor Cyan
    Write-Host "  3. Try Incognito/Private mode" -ForegroundColor Cyan
} else {
    Write-Host ""
    Write-Host "ISSUES DETECTED:" -ForegroundColor Red
    foreach ($issue in $issues) {
        Write-Host "  - $issue" -ForegroundColor Red
    }
    Write-Host ""
    Write-Host "  ACTIONS REQUIRED:" -ForegroundColor Yellow
    if ($issues -contains "Apache not running") {
        Write-Host "  1. Run: .\Start-ApacheXAMPP.ps1" -ForegroundColor Cyan
    }
    if ($issues -contains ".htaccess missing") {
        Write-Host "  2. Check .htaccess file exists in api/" -ForegroundColor Cyan
    }
    if ($issues -contains "404 errors detected") {
        Write-Host "  3. Restart Apache: Restart-Service Apache2.4" -ForegroundColor Cyan
    }
}

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")