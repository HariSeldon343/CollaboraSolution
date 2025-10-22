# Test-ApacheStatus.ps1
# Script di verifica stato Apache e CollaboraNexio
# Data: 2025-10-21

[CmdletBinding()]
param()

# Configurazione
$TestResults = @()
$ErrorsFound = $false

function Write-TestHeader {
    param([string]$Title)
    Write-Host ""
    Write-Host "=== $Title ===" -ForegroundColor Cyan
    Write-Host ("-" * 50) -ForegroundColor Gray
}

function Test-Component {
    param(
        [string]$Name,
        [scriptblock]$TestScript,
        [string]$SuccessMessage,
        [string]$FailureMessage
    )

    Write-Host "Testing: $Name... " -NoNewline

    try {
        $result = & $TestScript
        if ($result) {
            Write-Host "[PASS]" -ForegroundColor Green
            if ($SuccessMessage) {
                Write-Host "  → $SuccessMessage" -ForegroundColor DarkGreen
            }
            $script:TestResults += @{
                Component = $Name
                Status = "PASS"
                Message = $SuccessMessage
            }
            return $true
        } else {
            Write-Host "[FAIL]" -ForegroundColor Red
            if ($FailureMessage) {
                Write-Host "  → $FailureMessage" -ForegroundColor DarkRed
            }
            $script:TestResults += @{
                Component = $Name
                Status = "FAIL"
                Message = $FailureMessage
            }
            $script:ErrorsFound = $true
            return $false
        }
    } catch {
        Write-Host "[ERROR]" -ForegroundColor Red
        Write-Host "  → Exception: $_" -ForegroundColor DarkRed
        $script:TestResults += @{
            Component = $Name
            Status = "ERROR"
            Message = $_.ToString()
        }
        $script:ErrorsFound = $true
        return $false
    }
}

# ================== MAIN TESTS ==================

Clear-Host
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "   Apache & CollaboraNexio Status Test  " -ForegroundColor White
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "Time: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Gray

# 1. Test Apache Process
Write-TestHeader "Apache Process Check"

Test-Component -Name "Apache Process (httpd.exe)" -TestScript {
    $processes = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
    if ($processes) {
        $pids = ($processes | ForEach-Object { $_.Id }) -join ", "
        Write-Host "  Found PIDs: $pids" -ForegroundColor Gray
        return $true
    }
    return $false
} -SuccessMessage "Apache processes running" -FailureMessage "No Apache processes found"

Test-Component -Name "Apache Service" -TestScript {
    $service = Get-Service -Name "Apache2.4" -ErrorAction SilentlyContinue
    if ($service) {
        Write-Host "  Service Status: $($service.Status)" -ForegroundColor Gray
        return $service.Status -eq "Running"
    }
    return $false
} -SuccessMessage "Apache service is running" -FailureMessage "Apache service not running or not installed"

# 2. Test Network Ports
Write-TestHeader "Network Port Check"

Test-Component -Name "Port 8888 Listener" -TestScript {
    $connections = Get-NetTCPConnection -LocalPort 8888 -State Listen -ErrorAction SilentlyContinue
    return $connections.Count -gt 0
} -SuccessMessage "Port 8888 is listening" -FailureMessage "Port 8888 is not listening"

Test-Component -Name "Port 8888 Binding" -TestScript {
    try {
        $listener = [System.Net.Sockets.TcpClient]::new()
        $listener.Connect("localhost", 8888)
        $connected = $listener.Connected
        $listener.Close()
        return $connected
    } catch {
        return $false
    }
} -SuccessMessage "Can connect to port 8888" -FailureMessage "Cannot connect to port 8888"

# 3. Test HTTP Endpoints
Write-TestHeader "HTTP Endpoint Tests"

$endpoints = @(
    @{Name="Root"; URL="http://localhost:8888/"; ExpectedStatus=200},
    @{Name="CollaboraNexio Home"; URL="http://localhost:8888/CollaboraNexio/"; ExpectedStatus=200},
    @{Name="Login Page"; URL="http://localhost:8888/CollaboraNexio/index.php"; ExpectedStatus=200},
    @{Name="Upload API"; URL="http://localhost:8888/CollaboraNexio/api/files/upload.php"; ExpectedStatus=405}
)

foreach ($endpoint in $endpoints) {
    Test-Component -Name $endpoint.Name -TestScript {
        try {
            $response = Invoke-WebRequest -Uri $endpoint.URL -Method Head -TimeoutSec 3 -ErrorAction Stop
            Write-Host "  HTTP Status: $($response.StatusCode)" -ForegroundColor Gray
            return $true
        } catch {
            if ($_.Exception.Response) {
                $statusCode = [int]$_.Exception.Response.StatusCode
                Write-Host "  HTTP Status: $statusCode" -ForegroundColor Gray
                # 405 Method Not Allowed is OK for POST-only endpoints
                return ($statusCode -eq $endpoint.ExpectedStatus) -or ($statusCode -eq 405 -and $endpoint.ExpectedStatus -eq 405)
            }
            return $false
        }
    } -SuccessMessage "Endpoint is reachable" -FailureMessage "Endpoint not reachable"
}

# 4. Test File System
Write-TestHeader "File System Check"

$paths = @(
    @{Name="XAMPP Root"; Path="C:\xampp"},
    @{Name="Apache Binary"; Path="C:\xampp\apache\bin\httpd.exe"},
    @{Name="Apache Config"; Path="C:\xampp\apache\conf\httpd.conf"},
    @{Name="CollaboraNexio Root"; Path="C:\xampp\htdocs\CollaboraNexio"},
    @{Name="Upload API File"; Path="C:\xampp\htdocs\CollaboraNexio\api\files\upload.php"},
    @{Name="API .htaccess"; Path="C:\xampp\htdocs\CollaboraNexio\api\.htaccess"}
)

foreach ($path in $paths) {
    Test-Component -Name $path.Name -TestScript {
        Test-Path $path.Path
    } -SuccessMessage "File/folder exists" -FailureMessage "File/folder not found: $($path.Path)"
}

# 5. Test Apache Configuration
Write-TestHeader "Apache Configuration"

Test-Component -Name "httpd.conf Syntax" -TestScript {
    $apacheExe = "C:\xampp\apache\bin\httpd.exe"
    if (Test-Path $apacheExe) {
        $output = & $apacheExe -t 2>&1
        return $LASTEXITCODE -eq 0
    }
    return $false
} -SuccessMessage "Apache configuration is valid" -FailureMessage "Apache configuration has errors"

Test-Component -Name "mod_rewrite Module" -TestScript {
    $configFile = "C:\xampp\apache\conf\httpd.conf"
    if (Test-Path $configFile) {
        $content = Get-Content $configFile -Raw
        return $content -match "^LoadModule rewrite_module"
    }
    return $false
} -SuccessMessage "mod_rewrite is enabled" -FailureMessage "mod_rewrite not found or disabled"

# 6. Test Logs
Write-TestHeader "Log Files Check"

Test-Component -Name "Apache Error Log" -TestScript {
    $logFile = "C:\xampp\apache\logs\error.log"
    if (Test-Path $logFile) {
        $lastWrite = (Get-Item $logFile).LastWriteTime
        $hoursSinceWrite = (New-TimeSpan -Start $lastWrite -End (Get-Date)).TotalHours
        Write-Host "  Last modified: $($lastWrite.ToString('yyyy-MM-dd HH:mm:ss'))" -ForegroundColor Gray
        return $hoursSinceWrite -lt 24
    }
    return $false
} -SuccessMessage "Error log is recent" -FailureMessage "Error log is old or missing"

Test-Component -Name "Apache Access Log" -TestScript {
    $logFile = "C:\xampp\apache\logs\access.log"
    if (Test-Path $logFile) {
        $size = (Get-Item $logFile).Length
        Write-Host "  Size: $([math]::Round($size/1KB, 2)) KB" -ForegroundColor Gray
        return $true
    }
    return $false
} -SuccessMessage "Access log exists" -FailureMessage "Access log not found"

# 7. Summary Report
Write-Host ""
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "              TEST SUMMARY              " -ForegroundColor White
Write-Host "========================================" -ForegroundColor Yellow

$passCount = ($TestResults | Where-Object { $_.Status -eq "PASS" }).Count
$failCount = ($TestResults | Where-Object { $_.Status -ne "PASS" }).Count
$totalCount = $TestResults.Count

Write-Host "Total Tests: $totalCount" -ForegroundColor White
Write-Host "Passed: $passCount" -ForegroundColor Green
Write-Host "Failed: $failCount" -ForegroundColor $(if ($failCount -gt 0) { "Red" } else { "Gray" })

if ($ErrorsFound) {
    Write-Host ""
    Write-Host "⚠ ISSUES FOUND ⚠" -ForegroundColor Red
    Write-Host "Failed components:" -ForegroundColor Yellow
    $TestResults | Where-Object { $_.Status -ne "PASS" } | ForEach-Object {
        Write-Host "  - $($_.Component): $($_.Message)" -ForegroundColor Red
    }

    Write-Host ""
    Write-Host "RECOMMENDED ACTIONS:" -ForegroundColor Yellow
    Write-Host "1. Run Start-ApacheXAMPP.ps1 to start Apache" -ForegroundColor White
    Write-Host "2. Check C:\xampp\apache\logs\error.log for details" -ForegroundColor White
    Write-Host "3. Ensure no other application is using port 8888" -ForegroundColor White
} else {
    Write-Host ""
    Write-Host "✓ ALL TESTS PASSED!" -ForegroundColor Green
    Write-Host "Apache and CollaboraNexio are working correctly." -ForegroundColor Green
    Write-Host ""
    Write-Host "You can access the application at:" -ForegroundColor Cyan
    Write-Host "http://localhost:8888/CollaboraNexio/" -ForegroundColor White
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "Test completed at $(Get-Date -Format 'HH:mm:ss')" -ForegroundColor Gray
Write-Host ""