# Start-ApacheXAMPP.ps1
# Script PowerShell per avviare Apache XAMPP su Windows
# CollaboraNexio - Sistema di Gestione Apache
# Data: 2025-10-21

#Requires -RunAsAdministrator

[CmdletBinding()]
param()

# Configurazione
$XAMPPPath = "C:\xampp"
$ApacheExe = "$XAMPPPath\apache\bin\httpd.exe"
$ApacheConfig = "$XAMPPPath\apache\conf\httpd.conf"
$ApacheServiceName = "Apache2.4"
$TestURL = "http://localhost:8888/CollaboraNexio/"
$MaxWaitSeconds = 30

# Colori per output
$Host.UI.RawUI.ForegroundColor = "White"

function Write-Success {
    param([string]$Message)
    Write-Host "[SUCCESS] " -ForegroundColor Green -NoNewline
    Write-Host $Message
}

function Write-Info {
    param([string]$Message)
    Write-Host "[INFO] " -ForegroundColor Cyan -NoNewline
    Write-Host $Message
}

function Write-Warning {
    param([string]$Message)
    Write-Host "[WARNING] " -ForegroundColor Yellow -NoNewline
    Write-Host $Message
}

function Write-Error {
    param([string]$Message)
    Write-Host "[ERROR] " -ForegroundColor Red -NoNewline
    Write-Host $Message
}

function Test-Administrator {
    $currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-ApacheRunning {
    # Metodo 1: Controlla processo httpd.exe
    $process = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
    if ($process) {
        return $true
    }

    # Metodo 2: Controlla servizio Windows se installato
    $service = Get-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
    if ($service -and $service.Status -eq "Running") {
        return $true
    }

    # Metodo 3: Test HTTP su porta 8888
    try {
        $response = Invoke-WebRequest -Uri "http://localhost:8888" -Method Head -TimeoutSec 2 -ErrorAction SilentlyContinue
        return $true
    } catch {
        return $false
    }
}

function Stop-ApacheProcess {
    Write-Info "Arresto processi Apache esistenti..."

    # Termina tutti i processi httpd.exe
    $processes = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
    if ($processes) {
        foreach ($proc in $processes) {
            try {
                Stop-Process -Id $proc.Id -Force -ErrorAction Stop
                Write-Info "Terminato processo httpd.exe (PID: $($proc.Id))"
            } catch {
                Write-Warning "Impossibile terminare processo PID $($proc.Id): $_"
            }
        }
        Start-Sleep -Seconds 2
    }

    # Prova a fermare il servizio se esiste
    $service = Get-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
    if ($service -and $service.Status -eq "Running") {
        try {
            Stop-Service -Name $ApacheServiceName -Force -ErrorAction Stop
            Write-Info "Servizio Apache fermato"
            Start-Sleep -Seconds 2
        } catch {
            Write-Warning "Impossibile fermare servizio: $_"
        }
    }
}

function Start-ApacheXAMPP {
    Write-Info "Avvio Apache XAMPP..."

    # Verifica che httpd.exe esista
    if (-not (Test-Path $ApacheExe)) {
        Write-Error "Apache non trovato in: $ApacheExe"
        Write-Error "Verifica che XAMPP sia installato in C:\xampp"
        return $false
    }

    # Verifica configurazione
    if (-not (Test-Path $ApacheConfig)) {
        Write-Error "File di configurazione non trovato: $ApacheConfig"
        return $false
    }

    # Testa configurazione Apache
    Write-Info "Verifica configurazione Apache..."
    $testConfig = & $ApacheExe -t 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Configurazione Apache non valida:"
        Write-Host $testConfig -ForegroundColor Red
        return $false
    }
    Write-Success "Configurazione Apache valida"

    # Metodo 1: Prova ad avviare come servizio Windows
    $service = Get-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
    if ($service) {
        Write-Info "Avvio Apache come servizio Windows..."
        try {
            Start-Service -Name $ApacheServiceName -ErrorAction Stop
            Write-Success "Servizio Apache avviato"
            return $true
        } catch {
            Write-Warning "Impossibile avviare come servizio: $_"
            Write-Info "Tentativo avvio standalone..."
        }
    }

    # Metodo 2: Avvia Apache standalone
    Write-Info "Avvio Apache in modalità standalone..."
    try {
        $pinfo = New-Object System.Diagnostics.ProcessStartInfo
        $pinfo.FileName = $ApacheExe
        $pinfo.RedirectStandardError = $true
        $pinfo.RedirectStandardOutput = $true
        $pinfo.UseShellExecute = $false
        $pinfo.WindowStyle = "Hidden"
        $pinfo.CreateNoWindow = $true

        $process = New-Object System.Diagnostics.Process
        $process.StartInfo = $pinfo
        $process.Start() | Out-Null

        # Aspetta un momento per vedere se il processo resta attivo
        Start-Sleep -Seconds 3

        if ($process.HasExited) {
            $error = $process.StandardError.ReadToEnd()
            Write-Error "Apache si è arrestato immediatamente:"
            Write-Host $error -ForegroundColor Red
            return $false
        }

        Write-Success "Processo Apache avviato (PID: $($process.Id))"
        return $true

    } catch {
        Write-Error "Errore durante l'avvio di Apache: $_"
        return $false
    }
}

function Test-ApacheEndpoint {
    param([string]$URL)

    Write-Info "Test endpoint: $URL"
    try {
        $response = Invoke-WebRequest -Uri $URL -Method Get -TimeoutSec 5 -ErrorAction Stop
        if ($response.StatusCode -eq 200) {
            Write-Success "Endpoint raggiungibile (HTTP $($response.StatusCode))"
            return $true
        } else {
            Write-Warning "Endpoint restituisce HTTP $($response.StatusCode)"
            return $false
        }
    } catch {
        Write-Error "Endpoint non raggiungibile: $_"
        return $false
    }
}

function Show-PortStatus {
    Write-Info "Verifica porta 8888..."
    $connection = Get-NetTCPConnection -LocalPort 8888 -ErrorAction SilentlyContinue
    if ($connection) {
        Write-Success "Porta 8888 in ascolto"
        foreach ($conn in $connection) {
            Write-Info "  PID: $($conn.OwningProcess), Stato: $($conn.State)"
        }
        return $true
    } else {
        Write-Warning "Porta 8888 non in ascolto"
        return $false
    }
}

# ================== MAIN SCRIPT ==================

Clear-Host
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "    CollaboraNexio - Apache Starter     " -ForegroundColor White
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Verifica privilegi amministratore
if (-not (Test-Administrator)) {
    Write-Error "Questo script richiede privilegi di amministratore!"
    Write-Info "Rilancia PowerShell come Amministratore e riprova."
    exit 1
}

# Controlla se Apache è già in esecuzione
Write-Info "Controllo stato Apache..."
if (Test-ApacheRunning) {
    Write-Warning "Apache è già in esecuzione!"
    $response = Read-Host "Vuoi riavviare Apache? (S/N)"
    if ($response -ne 'S' -and $response -ne 's') {
        Write-Info "Operazione annullata"
        exit 0
    }

    # Ferma Apache
    Stop-ApacheProcess
}

# Avvia Apache
if (Start-ApacheXAMPP) {
    Write-Host ""
    Write-Info "Attesa avvio completo Apache..."

    # Aspetta che Apache sia completamente avviato
    $elapsed = 0
    $started = $false
    while ($elapsed -lt $MaxWaitSeconds) {
        Start-Sleep -Seconds 2
        $elapsed += 2

        if (Test-ApacheRunning) {
            $started = $true
            break
        }

        Write-Host "." -NoNewline
    }
    Write-Host ""

    if ($started) {
        Write-Success "Apache avviato correttamente!"
        Write-Host ""

        # Verifica porta
        Show-PortStatus | Out-Null
        Write-Host ""

        # Test endpoint principale
        Write-Host "Test endpoints CollaboraNexio:" -ForegroundColor Cyan
        Write-Host "-------------------------------" -ForegroundColor Gray

        Test-ApacheEndpoint "http://localhost:8888/" | Out-Null
        Test-ApacheEndpoint "http://localhost:8888/CollaboraNexio/" | Out-Null
        Test-ApacheEndpoint "http://localhost:8888/CollaboraNexio/index.php" | Out-Null
        Test-ApacheEndpoint "http://localhost:8888/CollaboraNexio/api/files/upload.php" | Out-Null

        Write-Host ""
        Write-Success "Apache XAMPP è pronto!"
        Write-Info "Apri il browser su: http://localhost:8888/CollaboraNexio/"
        Write-Host ""

        # Mostra log recenti
        $errorLog = "$XAMPPPath\apache\logs\error.log"
        if (Test-Path $errorLog) {
            Write-Info "Ultimi messaggi dal log Apache:"
            Write-Host "-------------------------------" -ForegroundColor Gray
            Get-Content $errorLog -Tail 5 | ForEach-Object {
                Write-Host "  $_" -ForegroundColor Gray
            }
        }

    } else {
        Write-Error "Apache non si è avviato entro $MaxWaitSeconds secondi"
        Write-Info "Controlla i log in: C:\xampp\apache\logs\error.log"
        exit 1
    }

} else {
    Write-Error "Impossibile avviare Apache"
    Write-Info "Suggerimenti:"
    Write-Host "  1. Verifica che XAMPP sia installato in C:\xampp" -ForegroundColor Gray
    Write-Host "  2. Controlla che la porta 8888 non sia già in uso" -ForegroundColor Gray
    Write-Host "  3. Verifica i log in C:\xampp\apache\logs\error.log" -ForegroundColor Gray
    Write-Host "  4. Prova ad avviare XAMPP Control Panel manualmente" -ForegroundColor Gray
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Premi un tasto per chiudere..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")