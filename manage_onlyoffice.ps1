# Script PowerShell per gestione container OnlyOffice con CORS abilitato
# CollaboraNexio - OnlyOffice Document Server Management

param(
    [Parameter(Mandatory=$false)]
    [ValidateSet("start", "stop", "restart", "status", "logs", "recreate")]
    [string]$Action = "status"
)

$ContainerName = "collaboranexio-onlyoffice"
$ImageName = "onlyoffice/documentserver"
$Port = "8083"
$JwtSecret = "16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af"
$UploadsPath = "C:\xampp\htdocs\CollaboraNexio\uploads"

function Write-ColorOutput {
    param([string]$Message, [string]$Color = "White")
    Write-Host $Message -ForegroundColor $Color
}

function Start-OnlyOffice {
    Write-ColorOutput "Avvio container OnlyOffice con CORS abilitato..." "Yellow"

    # Verifica se il container esiste già
    $existing = docker ps -a --filter "name=$ContainerName" --format "{{.Names}}"
    if ($existing -eq $ContainerName) {
        Write-ColorOutput "Container esistente trovato. Riavvio..." "Yellow"
        docker start $ContainerName
    } else {
        Write-ColorOutput "Creazione nuovo container con configurazione CORS..." "Yellow"

        $dockerCmd = @"
docker run -d ``
  --name $ContainerName ``
  --restart=always ``
  -p ${Port}:80 ``
  -e JWT_ENABLED=true ``
  -e JWT_SECRET="$JwtSecret" ``
  -e JWT_HEADER="Authorization" ``
  -e JWT_IN_BODY=true ``
  -e WOPI_ENABLED=true ``
  -e ALLOW_PRIVATE_IP_ADDRESS=true ``
  -e ALLOW_META_IP_ADDRESS=true ``
  -v ${UploadsPath}:/var/www/onlyoffice/documentserver/App_Data/cache/files ``
  $ImageName
"@

        Invoke-Expression $dockerCmd
    }

    # Attendi che il container sia pronto
    Write-ColorOutput "Attesa avvio servizi (30 secondi)..." "Yellow"
    Start-Sleep -Seconds 30

    # Applica configurazione CORS
    Apply-CorsConfiguration

    Write-ColorOutput "✅ Container OnlyOffice avviato con successo!" "Green"
    Show-Status
}

function Stop-OnlyOffice {
    Write-ColorOutput "Arresto container OnlyOffice..." "Yellow"
    docker stop $ContainerName
    Write-ColorOutput "✅ Container arrestato" "Green"
}

function Restart-OnlyOffice {
    Stop-OnlyOffice
    Start-Sleep -Seconds 2
    Start-OnlyOffice
}

function Show-Status {
    Write-ColorOutput "`n=== Stato Container OnlyOffice ===" "Cyan"

    $status = docker ps --filter "name=$ContainerName" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    if ($status) {
        Write-Output $status

        Write-ColorOutput "`n=== Test Endpoints ===" "Cyan"

        # Test healthcheck
        try {
            $health = Invoke-WebRequest -Uri "http://localhost:$Port/healthcheck" -UseBasicParsing -ErrorAction SilentlyContinue
            if ($health.StatusCode -eq 200) {
                Write-ColorOutput "✅ Healthcheck: OK" "Green"
            }
        } catch {
            Write-ColorOutput "❌ Healthcheck: FAIL" "Red"
        }

        # Test API endpoint
        try {
            $api = Invoke-WebRequest -Uri "http://localhost:$Port/web-apps/apps/api/documents/api.js" -UseBasicParsing -ErrorAction SilentlyContinue
            if ($api.StatusCode -eq 200) {
                Write-ColorOutput "✅ API Endpoint: OK" "Green"

                # Verifica CORS headers
                if ($api.Headers["Access-Control-Allow-Origin"]) {
                    Write-ColorOutput "✅ CORS Headers: Presenti" "Green"
                } else {
                    Write-ColorOutput "⚠️ CORS Headers: Non rilevati (potrebbero richiedere configurazione aggiuntiva)" "Yellow"
                }
            }
        } catch {
            Write-ColorOutput "❌ API Endpoint: Non raggiungibile" "Red"
        }

        Write-ColorOutput "`nURL di test: http://localhost:8888/test_onlyoffice_api_load.html" "Cyan"
    } else {
        Write-ColorOutput "❌ Container non in esecuzione" "Red"
    }
}

function Show-Logs {
    Write-ColorOutput "Ultimi 50 log del container:" "Cyan"
    docker logs --tail 50 $ContainerName
}

function Apply-CorsConfiguration {
    Write-ColorOutput "Applicazione configurazione CORS..." "Yellow"

    # Modifica configurazione request-filtering
    docker exec $ContainerName sed -i 's/"allowPrivateIPAddress": false/"allowPrivateIPAddress": true/g' /etc/onlyoffice/documentserver/default.json
    docker exec $ContainerName sed -i 's/"allowMetaIPAddress": false/"allowMetaIPAddress": true/g' /etc/onlyoffice/documentserver/default.json

    # Aggiungi header CORS a nginx
    $corsConfig = @'

# CORS configuration
add_header 'Access-Control-Allow-Origin' '*' always;
add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS, PUT, DELETE' always;
add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization' always;
add_header 'Access-Control-Expose-Headers' 'Content-Length,Content-Range' always;
'@

    docker exec $ContainerName bash -c "echo '$corsConfig' >> /etc/nginx/includes/ds-common.conf"

    # Riavvia servizi
    docker exec $ContainerName supervisorctl restart all 2>$null
    Start-Sleep -Seconds 5
    docker exec $ContainerName nginx -s reload 2>$null

    Write-ColorOutput "✅ Configurazione CORS applicata" "Green"
}

function Recreate-Container {
    Write-ColorOutput "Ricreazione completa del container..." "Yellow"

    # Ferma e rimuovi container esistente
    docker stop $ContainerName 2>$null
    docker rm $ContainerName 2>$null

    # Ricrea con nuova configurazione
    Start-OnlyOffice
}

# Menu principale
Write-ColorOutput "`n╔══════════════════════════════════════════════╗" "Cyan"
Write-ColorOutput "║     CollaboraNexio OnlyOffice Manager       ║" "Cyan"
Write-ColorOutput "╚══════════════════════════════════════════════╝" "Cyan"

switch ($Action) {
    "start" { Start-OnlyOffice }
    "stop" { Stop-OnlyOffice }
    "restart" { Restart-OnlyOffice }
    "status" { Show-Status }
    "logs" { Show-Logs }
    "recreate" { Recreate-Container }
    default { Show-Status }
}

Write-ColorOutput "`n" "White"