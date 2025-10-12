# OnlyOffice Document Server Installation Script for CollaboraNexio
# Requires: Windows 10/11 with WSL2, Docker Desktop

param(
    [switch]$Force,
    [switch]$SkipHealthCheck,
    [int]$Port = 8083,
    [string]$ContainerName = "collaboranexio-onlyoffice"
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "Continue"

# Colors for output
function Write-Success { Write-Host $args -ForegroundColor Green }
function Write-Error { Write-Host $args -ForegroundColor Red }
function Write-Warning { Write-Host $args -ForegroundColor Yellow }
function Write-Info { Write-Host $args -ForegroundColor Cyan }

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host " OnlyOffice Document Server Installer" -ForegroundColor Cyan
Write-Host " for CollaboraNexio" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Check Docker
Write-Info "[Step 1/6] Checking Docker installation..."
try {
    $dockerVersion = docker --version
    Write-Success "✓ Docker found: $dockerVersion"
} catch {
    Write-Error "✗ Docker is not installed or not in PATH"
    Write-Host ""
    Write-Host "Please install Docker Desktop for Windows:"
    Write-Host "https://www.docker.com/products/docker-desktop"
    exit 1
}

# Check Docker daemon
try {
    docker ps | Out-Null
    Write-Success "✓ Docker daemon is running"
} catch {
    Write-Error "✗ Docker Desktop is not running"
    Write-Host "Please start Docker Desktop and wait for it to be ready"
    exit 1
}

# Step 2: Check existing container
Write-Info "`n[Step 2/6] Checking for existing container..."
$existingContainer = docker ps -a --filter "name=$ContainerName" --format "{{.Names}}" | Where-Object { $_ -eq $ContainerName }

if ($existingContainer) {
    Write-Warning "! Container '$ContainerName' already exists"

    $runningContainer = docker ps --filter "name=$ContainerName" --format "{{.Names}}" | Where-Object { $_ -eq $ContainerName }

    if ($runningContainer) {
        if ($Force) {
            Write-Warning "  Force flag set - removing existing container..."
            docker stop $ContainerName | Out-Null
            docker rm $ContainerName | Out-Null
            Write-Success "✓ Existing container removed"
        } else {
            Write-Success "✓ Container is already running!"

            # Quick health check
            Write-Info "`n[Quick Health Check]"
            $health = Invoke-WebRequest -Uri "http://localhost:$Port/healthcheck" -UseBasicParsing -ErrorAction SilentlyContinue
            if ($health.Content -eq "true") {
                Write-Success "✓ OnlyOffice is healthy and responding"

                Write-Host ""
                Write-Success "OnlyOffice is already running and healthy!"
                Write-Host ""
                Write-Host "Test URL: http://localhost:8888/CollaboraNexio/test_onlyoffice_simple.html"
                exit 0
            }
        }
    } else {
        Write-Info "  Starting existing container..."
        docker start $ContainerName | Out-Null
        Write-Success "✓ Container started"
        Start-Sleep -Seconds 30
    }
}

# Step 3: Pull OnlyOffice image
if (-not $runningContainer -or $Force) {
    Write-Info "`n[Step 3/6] Checking OnlyOffice Docker image..."
    $imageExists = docker images onlyoffice/documentserver --format "{{.Repository}}" | Where-Object { $_ -eq "onlyoffice/documentserver" }

    if (-not $imageExists -or $Force) {
        Write-Info "  Pulling OnlyOffice Document Server image..."
        Write-Warning "  This may take 5-10 minutes (image is ~2GB)..."

        docker pull onlyoffice/documentserver
        if ($LASTEXITCODE -ne 0) {
            Write-Error "✗ Failed to pull OnlyOffice image"
            exit 1
        }
        Write-Success "✓ OnlyOffice image downloaded"
    } else {
        Write-Success "✓ OnlyOffice image already available"
    }

    # Step 4: Create container
    Write-Info "`n[Step 4/6] Creating OnlyOffice container..."

    # Prepare volume path
    $volumePath = "C:\xampp\htdocs\CollaboraNexio\uploads"
    if (-not (Test-Path $volumePath)) {
        New-Item -ItemType Directory -Path $volumePath -Force | Out-Null
        Write-Success "✓ Created uploads directory"
    }

    # Create container with all environment variables
    $containerCmd = @"
docker run -d `
    --name $ContainerName `
    --restart=always `
    -p ${Port}:80 `
    -e JWT_ENABLED=true `
    -e JWT_SECRET="16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af" `
    -e JWT_HEADER="Authorization" `
    -e JWT_IN_BODY=true `
    -e WOPI_ENABLED=true `
    -e ALLOW_PRIVATE_IP_ADDRESS=true `
    -e ALLOW_META_IP_ADDRESS=true `
    -v "${volumePath}:/var/www/onlyoffice/documentserver/App_Data/cache/files" `
    onlyoffice/documentserver
"@

    $containerId = Invoke-Expression $containerCmd
    if ($LASTEXITCODE -ne 0) {
        Write-Error "✗ Failed to create container"
        Write-Host ""
        Write-Host "Possible issues:"
        Write-Host "- Port $Port is already in use"
        Write-Host "- Docker permissions issue"
        exit 1
    }

    Write-Success "✓ Container created: $($containerId.Substring(0,12))"

    # Step 5: Wait for startup
    Write-Info "`n[Step 5/6] Waiting for OnlyOffice to start..."
    Write-Info "  This typically takes 30-60 seconds..."

    $maxAttempts = 12
    $attempt = 0
    $ready = $false

    while ($attempt -lt $maxAttempts -and -not $ready) {
        $attempt++
        Write-Progress -Activity "Starting OnlyOffice" -Status "Attempt $attempt of $maxAttempts" -PercentComplete (($attempt / $maxAttempts) * 100)

        Start-Sleep -Seconds 5

        try {
            $health = Invoke-WebRequest -Uri "http://localhost:$Port/healthcheck" -UseBasicParsing -TimeoutSec 2 -ErrorAction SilentlyContinue
            if ($health.Content -eq "true") {
                $ready = $true
            }
        } catch {
            # Still starting...
        }
    }

    Write-Progress -Activity "Starting OnlyOffice" -Completed

    if ($ready) {
        Write-Success "✓ OnlyOffice is ready!"
    } else {
        Write-Warning "! OnlyOffice may still be starting..."
    }
}

# Step 6: Verify installation
if (-not $SkipHealthCheck) {
    Write-Info "`n[Step 6/6] Verifying installation..."

    # Test healthcheck
    try {
        $health = Invoke-WebRequest -Uri "http://localhost:$Port/healthcheck" -UseBasicParsing -TimeoutSec 5
        if ($health.Content -eq "true") {
            Write-Success "✓ Healthcheck: OK"
        } else {
            Write-Warning "! Healthcheck returned unexpected value: $($health.Content)"
        }
    } catch {
        Write-Error "✗ Healthcheck failed"
    }

    # Test API endpoint
    try {
        $api = Invoke-WebRequest -Uri "http://localhost:$Port/web-apps/apps/api/documents/api.js" -Method Head -UseBasicParsing -TimeoutSec 5
        if ($api.StatusCode -eq 200) {
            Write-Success "✓ API endpoint: OK"

            # Check CORS headers
            if ($api.Headers["Access-Control-Allow-Origin"]) {
                Write-Success "✓ CORS headers: Present"
            } else {
                Write-Warning "! CORS headers not detected"
            }
        }
    } catch {
        Write-Error "✗ API endpoint not accessible"
    }

    # Check container logs for errors
    Write-Info "`nChecking container logs..."
    $logs = docker logs $ContainerName --tail 10 2>&1
    $errors = $logs | Select-String -Pattern "error|fatal|critical" -SimpleMatch

    if ($errors) {
        Write-Warning "! Found potential issues in logs:"
        $errors | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
    } else {
        Write-Success "✓ No errors in recent logs"
    }
}

# Final summary
Write-Host ""
Write-Host "======================================" -ForegroundColor Green
Write-Host " Installation Complete!" -ForegroundColor Green
Write-Host "======================================" -ForegroundColor Green
Write-Host ""

Write-Info "OnlyOffice Details:"
Write-Host "  Container Name: $ContainerName"
Write-Host "  Port: $Port"
Write-Host "  Status: Running"
Write-Host ""

Write-Info "Test URLs:"
Write-Host "  Simple Test: http://localhost:8888/CollaboraNexio/test_onlyoffice_simple.html"
Write-Host "  Files Page: http://localhost:8888/CollaboraNexio/files.php"
Write-Host "  Direct Access: http://localhost:$Port"
Write-Host ""

Write-Info "Useful Commands:"
Write-Host "  View logs: docker logs $ContainerName"
Write-Host "  Stop: docker stop $ContainerName"
Write-Host "  Start: docker start $ContainerName"
Write-Host "  Restart: docker restart $ContainerName"
Write-Host "  Remove: docker rm -f $ContainerName"
Write-Host ""

Write-Info "Troubleshooting:"
Write-Host "  1. Clear browser cache (Ctrl+Shift+Delete)"
Write-Host "  2. Try in Incognito/Private mode"
Write-Host "  3. Check Windows Firewall for port $Port"
Write-Host "  4. Restart Docker Desktop if issues persist"
Write-Host ""

# Open test page in browser
$openBrowser = Read-Host "Do you want to open the test page in your browser? (Y/N)"
if ($openBrowser -eq 'Y' -or $openBrowser -eq 'y') {
    Start-Process "http://localhost:8888/CollaboraNexio/test_onlyoffice_simple.html"
}