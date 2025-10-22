# Clear-BrowserCache.ps1
# Script PowerShell per pulizia cache browser e fix errore 404 upload
# CollaboraNexio - Cache Fix Utility
# Versione 1.0 - 2025-10-22

param(
    [switch]$Force = $false,
    [switch]$Quiet = $false
)

# Configurazione colori output
$Host.UI.RawUI.BackgroundColor = 'Black'
$Host.UI.RawUI.ForegroundColor = 'White'
Clear-Host

function Write-ColorOutput {
    param([string]$Text, [string]$Color = "White")
    Write-Host $Text -ForegroundColor $Color
}

function Show-Banner {
    Write-ColorOutput "╔══════════════════════════════════════════════════════════════╗" "Cyan"
    Write-ColorOutput "║         CollaboraNexio - Browser Cache Cleaner              ║" "Cyan"
    Write-ColorOutput "║              Fix per errore 404 Upload PDF                  ║" "Cyan"
    Write-ColorOutput "╚══════════════════════════════════════════════════════════════╝" "Cyan"
    Write-Host ""
}

function Test-AdminRights {
    $currentUser = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($currentUser)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-RunningBrowsers {
    $browsers = @()
    $browserProcesses = @{
        "chrome" = "Google Chrome"
        "firefox" = "Mozilla Firefox"
        "msedge" = "Microsoft Edge"
        "iexplore" = "Internet Explorer"
        "opera" = "Opera"
        "brave" = "Brave"
    }

    foreach ($process in $browserProcesses.Keys) {
        if (Get-Process -Name $process -ErrorAction SilentlyContinue) {
            $browsers += $browserProcesses[$process]
        }
    }

    return $browsers
}

function Stop-Browsers {
    param([switch]$Force)

    Write-ColorOutput "→ Chiusura browser in corso..." "Yellow"

    $browserProcesses = @("chrome", "firefox", "msedge", "iexplore", "opera", "brave")
    $stopped = $false

    foreach ($process in $browserProcesses) {
        $procs = Get-Process -Name $process -ErrorAction SilentlyContinue
        if ($procs) {
            try {
                if ($Force) {
                    Stop-Process -Name $process -Force -ErrorAction SilentlyContinue
                    Write-ColorOutput "  ✓ $process chiuso forzatamente" "Green"
                } else {
                    Stop-Process -Name $process -ErrorAction SilentlyContinue
                    Write-ColorOutput "  ✓ $process chiuso" "Green"
                }
                $stopped = $true
            } catch {
                Write-ColorOutput "  ⚠ Impossibile chiudere $process" "Yellow"
            }
        }
    }

    if ($stopped) {
        Start-Sleep -Seconds 2
    }
}

function Clear-ChromeCache {
    Write-ColorOutput "→ Pulizia cache Google Chrome..." "Cyan"

    $chromePaths = @(
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Cache",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Cache2",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Code Cache",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\GPUCache",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Storage\ext",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Service Worker\CacheStorage",
        "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Service Worker\ScriptCache"
    )

    $cleaned = $false
    foreach ($path in $chromePaths) {
        if (Test-Path $path) {
            try {
                Remove-Item -Path "$path\*" -Recurse -Force -ErrorAction SilentlyContinue
                $cleaned = $true
            } catch {
                # Ignora errori per file in uso
            }
        }
    }

    if ($cleaned) {
        Write-ColorOutput "  ✓ Cache Chrome pulita" "Green"
    } else {
        Write-ColorOutput "  ℹ Chrome non installato o cache già vuota" "Gray"
    }
}

function Clear-FirefoxCache {
    Write-ColorOutput "→ Pulizia cache Mozilla Firefox..." "Cyan"

    $firefoxProfiles = "$env:APPDATA\Mozilla\Firefox\Profiles"
    $cleaned = $false

    if (Test-Path $firefoxProfiles) {
        $profiles = Get-ChildItem -Path $firefoxProfiles -Directory
        foreach ($profile in $profiles) {
            $cachePaths = @(
                "$($profile.FullName)\cache2",
                "$($profile.FullName)\startupCache",
                "$($profile.FullName)\shader-cache"
            )

            foreach ($cachePath in $cachePaths) {
                if (Test-Path $cachePath) {
                    try {
                        Remove-Item -Path "$cachePath\*" -Recurse -Force -ErrorAction SilentlyContinue
                        $cleaned = $true
                    } catch {
                        # Ignora errori
                    }
                }
            }
        }
    }

    if ($cleaned) {
        Write-ColorOutput "  ✓ Cache Firefox pulita" "Green"
    } else {
        Write-ColorOutput "  ℹ Firefox non installato o cache già vuota" "Gray"
    }
}

function Clear-EdgeCache {
    Write-ColorOutput "→ Pulizia cache Microsoft Edge..." "Cyan"

    $edgePaths = @(
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Cache",
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Cache2",
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Code Cache",
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\GPUCache",
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Service Worker\CacheStorage",
        "$env:LOCALAPPDATA\Microsoft\Edge\User Data\Default\Service Worker\ScriptCache"
    )

    $cleaned = $false
    foreach ($path in $edgePaths) {
        if (Test-Path $path) {
            try {
                Remove-Item -Path "$path\*" -Recurse -Force -ErrorAction SilentlyContinue
                $cleaned = $true
            } catch {
                # Ignora errori
            }
        }
    }

    if ($cleaned) {
        Write-ColorOutput "  ✓ Cache Edge pulita" "Green"
    } else {
        Write-ColorOutput "  ℹ Edge non installato o cache già vuota" "Gray"
    }
}

function Clear-IECache {
    Write-ColorOutput "→ Pulizia cache Internet Explorer..." "Cyan"

    try {
        # Usa RunDll32 per pulire la cache di IE
        RunDll32.exe InetCpl.cpl,ClearMyTracksByProcess 8
        RunDll32.exe InetCpl.cpl,ClearMyTracksByProcess 2
        Write-ColorOutput "  ✓ Cache IE pulita" "Green"
    } catch {
        Write-ColorOutput "  ⚠ Impossibile pulire cache IE" "Yellow"
    }
}

function Clear-DNSCache {
    Write-ColorOutput "→ Pulizia cache DNS..." "Cyan"

    try {
        ipconfig /flushdns | Out-Null
        Write-ColorOutput "  ✓ Cache DNS pulita" "Green"
    } catch {
        Write-ColorOutput "  ⚠ Impossibile pulire cache DNS (richiede privilegi admin)" "Yellow"
    }
}

function Test-UploadEndpoint {
    Write-ColorOutput "`n→ Test endpoint upload..." "Cyan"

    try {
        $response = Invoke-WebRequest -Uri "http://localhost:8888/CollaboraNexio/api/files/upload.php" `
                                     -Method POST `
                                     -UseBasicParsing `
                                     -ErrorAction SilentlyContinue `
                                     -TimeoutSec 5

        if ($response.StatusCode -eq 401) {
            Write-ColorOutput "  ✓ Endpoint raggiungibile (401 - richiede autenticazione)" "Green"
            return $true
        } elseif ($response.StatusCode -eq 404) {
            Write-ColorOutput "  ✗ ERRORE 404 - Cache ancora presente!" "Red"
            return $false
        } else {
            Write-ColorOutput "  ℹ Status: $($response.StatusCode)" "Yellow"
            return $true
        }
    } catch {
        if ($_.Exception.Response.StatusCode -eq 401) {
            Write-ColorOutput "  ✓ Endpoint raggiungibile (401 - richiede autenticazione)" "Green"
            return $true
        } elseif ($_.Exception.Response.StatusCode -eq 404) {
            Write-ColorOutput "  ✗ ERRORE 404 - Cache ancora presente!" "Red"
            return $false
        } else {
            Write-ColorOutput "  ⚠ Impossibile testare endpoint: $_" "Yellow"
            return $false
        }
    }
}

# MAIN EXECUTION
Show-Banner

# Check for running browsers
$runningBrowsers = Get-RunningBrowsers
if ($runningBrowsers.Count -gt 0) {
    Write-ColorOutput "Browser attivi rilevati:" "Yellow"
    foreach ($browser in $runningBrowsers) {
        Write-ColorOutput "  • $browser" "Yellow"
    }
    Write-Host ""

    if (-not $Force -and -not $Quiet) {
        $response = Read-Host "Vuoi chiudere i browser per pulire la cache? (S/N)"
        if ($response -eq 'S' -or $response -eq 's') {
            Stop-Browsers
        } else {
            Write-ColorOutput "`n⚠ ATTENZIONE: La cache potrebbe non essere pulita completamente con i browser aperti" "Yellow"
            Start-Sleep -Seconds 2
        }
    } elseif ($Force) {
        Stop-Browsers -Force
    }
} else {
    Write-ColorOutput "✓ Nessun browser attivo rilevato" "Green"
}

Write-Host ""
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-ColorOutput "PULIZIA CACHE IN CORSO..." "White"
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-Host ""

# Clear browser caches
Clear-ChromeCache
Clear-FirefoxCache
Clear-EdgeCache
Clear-IECache
Clear-DNSCache

Write-Host ""
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-ColorOutput "VERIFICA ENDPOINT" "White"
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"

$testResult = Test-UploadEndpoint

Write-Host ""
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-ColorOutput "RISULTATO OPERAZIONE" "White"
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-Host ""

if ($testResult) {
    Write-ColorOutput "✓✓✓ CACHE PULITA CON SUCCESSO! ✓✓✓" "Green"
    Write-Host ""
    Write-ColorOutput "PROSSIMI PASSI:" "Cyan"
    Write-ColorOutput "1. Apri il browser" "White"
    Write-ColorOutput "2. Naviga a: http://localhost:8888/CollaboraNexio/files.php" "White"
    Write-ColorOutput "3. Premi CTRL+F5 per ricaricare senza cache" "White"
    Write-ColorOutput "4. Prova a caricare un file PDF" "White"
    Write-Host ""
    Write-ColorOutput "SUGGERIMENTI AGGIUNTIVI:" "Yellow"
    Write-ColorOutput "• Se il problema persiste, prova la modalità incognito (CTRL+SHIFT+N)" "Gray"
    Write-ColorOutput "• Puoi anche testare con: test_upload_cache_bypass.html" "Gray"
} else {
    Write-ColorOutput "⚠ ATTENZIONE: L'endpoint restituisce ancora 404" "Red"
    Write-Host ""
    Write-ColorOutput "AZIONI CONSIGLIATE:" "Yellow"
    Write-ColorOutput "1. Riavvia Apache: .\Start-ApacheXAMPP.ps1" "White"
    Write-ColorOutput "2. Pulisci cache manualmente:" "White"
    Write-ColorOutput "   - Apri browser" "Gray"
    Write-ColorOutput "   - Premi CTRL+SHIFT+DELETE" "Gray"
    Write-ColorOutput "   - Seleziona 'Tutto' come periodo" "Gray"
    Write-ColorOutput "   - Pulisci dati navigazione" "Gray"
    Write-ColorOutput "3. Usa modalità incognito per testare" "White"
    Write-ColorOutput "4. Prova il file di test: test_upload_cache_bypass.html" "White"
}

Write-Host ""
Write-ColorOutput "═══════════════════════════════════════════════════════════════" "DarkGray"
Write-ColorOutput "Premi un tasto per uscire..." "Gray"
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")