#!/bin/bash
#
# Test OnlyOffice Connectivity from Docker Container to Host
# Author: CollaboraNexio DevOps Team
# Version: 1.0.0
# Date: 2025-10-24

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Default values
FILE_ID=100
VERBOSE=false

# Parse arguments
while [[ "$#" -gt 0 ]]; do
    case $1 in
        -v|--verbose) VERBOSE=true ;;
        -f|--file-id) FILE_ID="$2"; shift ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  -v, --verbose    Enable verbose output"
            echo "  -f, --file-id    File ID to test (default: 100)"
            echo "  -h, --help       Show this help message"
            exit 0
            ;;
        *) echo "Unknown parameter: $1"; exit 1 ;;
    esac
    shift
done

# Functions
print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_info() {
    echo -e "${CYAN}ℹ${NC} $1"
}

print_step() {
    echo -e "\n${BLUE}→${NC} ${1}"
}

# Banner
echo -e "${CYAN}"
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║        OnlyOffice Connectivity Test - CollaboraNexio        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}\n"

# Check if running in WSL
IS_WSL=false
if grep -qi microsoft /proc/version 2>/dev/null || [ -f /proc/sys/fs/binfmt_misc/WSLInterop ]; then
    IS_WSL=true
    print_info "Rilevato ambiente WSL"
fi

# Test 1: Check if OnlyOffice container is running
print_step "Verifica Container OnlyOffice"
CONTAINER_NAME="collaboranexio-onlyoffice"

if docker ps --filter "name=$CONTAINER_NAME" --format "{{.Names}}" | grep -q "$CONTAINER_NAME"; then
    print_success "Container OnlyOffice trovato: $CONTAINER_NAME"

    # Get container details
    CONTAINER_IP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $CONTAINER_NAME 2>/dev/null)
    CONTAINER_STATE=$(docker inspect -f '{{.State.Status}}' $CONTAINER_NAME 2>/dev/null)

    [ "$VERBOSE" = true ] && print_info "Container IP: $CONTAINER_IP"
    [ "$VERBOSE" = true ] && print_info "Container State: $CONTAINER_STATE"
else
    print_error "Container OnlyOffice NON trovato!"
    print_warning "Avvia il container con: docker-compose up -d onlyoffice"
    exit 1
fi

# Test 2: Test DNS resolution of host.docker.internal
print_step "Test DNS Resolution (host.docker.internal)"

# Try nslookup first
DNS_RESULT=$(docker exec $CONTAINER_NAME nslookup host.docker.internal 2>&1)
if echo "$DNS_RESULT" | grep -q "Address.*[0-9]"; then
    HOST_IP=$(echo "$DNS_RESULT" | grep "Address" | tail -1 | awk '{print $2}')
    print_success "DNS risolve host.docker.internal a: $HOST_IP"
else
    # Try ping as fallback
    PING_RESULT=$(docker exec $CONTAINER_NAME ping -c 1 host.docker.internal 2>&1)
    if echo "$PING_RESULT" | grep -q "PING.*([0-9]"; then
        HOST_IP=$(echo "$PING_RESULT" | grep -oP '\(\K[0-9.]+(?=\))')
        print_success "Ping risolve host.docker.internal a: $HOST_IP"
    else
        print_warning "Impossibile risolvere host.docker.internal"
        print_info "Usando IP fisso 172.17.0.1 (Docker bridge default)"
        HOST_IP="172.17.0.1"
    fi
fi

# Test 3: Test HTTP connectivity to Apache on port 8888
print_step "Test HTTP verso Apache (porta 8888)"

HTTP_CODE=$(docker exec $CONTAINER_NAME curl -s -o /dev/null -w "%{http_code}" -I "http://host.docker.internal:8888/CollaboraNexio/" 2>&1)

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
    print_success "Apache raggiungibile dal container (HTTP $HTTP_CODE)"
else
    print_error "Apache NON raggiungibile dal container (HTTP $HTTP_CODE)"
    print_info "Verifica che Apache sia in esecuzione su porta 8888"
fi

# Test 4: Test download_for_editor.php endpoint
print_step "Test Endpoint Download"

DOWNLOAD_URL="http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=$FILE_ID&token=test123"
[ "$VERBOSE" = true ] && print_info "URL Test: $DOWNLOAD_URL"

DOWNLOAD_CODE=$(docker exec $CONTAINER_NAME curl -s -o /dev/null -w "%{http_code}" "$DOWNLOAD_URL" 2>&1)

case $DOWNLOAD_CODE in
    401|403)
        print_success "Endpoint download raggiungibile (HTTP $DOWNLOAD_CODE - Auth richiesta)"
        ;;
    200)
        print_success "Endpoint download raggiungibile e accessibile (HTTP 200)"
        ;;
    404)
        print_error "Endpoint download restituisce 404 - File o route non trovato"
        print_info "Verifica che il file .htaccess non blocchi l'accesso"
        ;;
    *)
        print_warning "Endpoint download risponde con codice: $DOWNLOAD_CODE"
        ;;
esac

# Test 5: Test callback endpoint with POST
print_step "Test Callback Endpoint (POST)"

CALLBACK_URL="http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php"
POST_DATA='{"status":0}'

CALLBACK_CODE=$(docker exec $CONTAINER_NAME curl -s -o /dev/null -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d "$POST_DATA" \
    "$CALLBACK_URL" 2>&1)

case $CALLBACK_CODE in
    401|403)
        print_success "Endpoint callback raggiungibile (HTTP $CALLBACK_CODE - Auth richiesta)"
        ;;
    200)
        print_success "Endpoint callback raggiungibile e accessibile (HTTP 200)"
        ;;
    404)
        print_error "Endpoint callback restituisce 404 - Route non trovata"
        ;;
    *)
        print_warning "Endpoint callback risponde con codice: $CALLBACK_CODE"
        ;;
esac

# Test 6: Check OnlyOffice Document Server health
print_step "Verifica OnlyOffice Document Server"

HEALTH_CODE=$(docker exec $CONTAINER_NAME curl -s -o /dev/null -w "%{http_code}" "http://localhost/healthcheck" 2>&1)

if [ "$HEALTH_CODE" = "200" ]; then
    print_success "OnlyOffice Document Server attivo e funzionante"
else
    print_warning "OnlyOffice Document Server potrebbe non essere pronto (HTTP $HEALTH_CODE)"
fi

# Test 7: Check PHP configuration
print_step "Verifica Configurazione PHP"

# Create temporary PHP test file
PHP_TEST_FILE="/tmp/test_onlyoffice_config_$$.php"
cat > "$PHP_TEST_FILE" << 'EOF'
<?php
// Simula ambiente WSL se necessario
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
define('DEBUG_MODE', false);
define('BASE_URL', 'http://localhost:8888/CollaboraNexio');
define('PRODUCTION_MODE', false);

// Include the config
require_once '/mnt/c/xampp/htdocs/CollaboraNexio/includes/onlyoffice_config.php';

$info = [
    'ONLYOFFICE_SERVER_URL' => ONLYOFFICE_SERVER_URL,
    'ONLYOFFICE_DOWNLOAD_URL' => ONLYOFFICE_DOWNLOAD_URL,
    'ONLYOFFICE_CALLBACK_URL' => ONLYOFFICE_CALLBACK_URL,
    'PHP_OS' => PHP_OS,
    'PHP_OS_FAMILY' => PHP_OS_FAMILY,
    'WSL_DETECTED' => (stripos(php_uname(), 'microsoft') !== false || file_exists('/proc/sys/fs/binfmt_misc/WSLInterop'))
];

echo json_encode($info, JSON_PRETTY_PRINT);
EOF

# Execute PHP test
PHP_OUTPUT=$(php "$PHP_TEST_FILE" 2>/dev/null)

if [ ! -z "$PHP_OUTPUT" ]; then
    DOWNLOAD_URL_VALUE=$(echo "$PHP_OUTPUT" | grep -oP '"ONLYOFFICE_DOWNLOAD_URL":\s*"\K[^"]+')
    SERVER_URL_VALUE=$(echo "$PHP_OUTPUT" | grep -oP '"ONLYOFFICE_SERVER_URL":\s*"\K[^"]+')

    [ "$VERBOSE" = true ] && print_info "OnlyOffice Server URL: $SERVER_URL_VALUE"
    [ "$VERBOSE" = true ] && print_info "Download URL: $DOWNLOAD_URL_VALUE"

    if echo "$DOWNLOAD_URL_VALUE" | grep -q "host\.docker\.internal"; then
        print_success "Configurazione corretta: usa host.docker.internal"
    else
        print_warning "Configurazione potrebbe non usare host.docker.internal"
        print_info "URL rilevato: $DOWNLOAD_URL_VALUE"
    fi
fi

# Clean up
rm -f "$PHP_TEST_FILE"

# Summary
echo -e "\n${CYAN}════════════════════════════════════════════════════════════════${NC}"
echo -e "                           RIEPILOGO"
echo -e "${CYAN}════════════════════════════════════════════════════════════════${NC}\n"

# Check for issues and provide solutions
HAS_ISSUES=false

if [ "$HTTP_CODE" != "200" ] && [ "$HTTP_CODE" != "301" ] && [ "$HTTP_CODE" != "302" ]; then
    print_warning "Apache non raggiungibile dal container"
    print_info "Soluzioni possibili:"
    print_info "  1. Verifica che Apache sia in esecuzione"
    print_info "  2. Verifica firewall per porta 8888"
    print_info "  3. Riavvia Docker Desktop"
    HAS_ISSUES=true
fi

if [ "$DOWNLOAD_CODE" = "404" ]; then
    print_warning "Endpoint download restituisce 404"
    print_info "Soluzioni possibili:"
    print_info "  1. Verifica .htaccess in api/"
    print_info "  2. Verifica che il file PHP esista"
    print_info "  3. Pulisci cache browser"
    HAS_ISSUES=true
fi

if [ "$HAS_ISSUES" = false ]; then
    echo ""
    print_success "Tutti i test superati! OnlyOffice dovrebbe funzionare correttamente."
else
    echo ""
    print_warning "Alcuni test hanno riportato problemi. Verifica i suggerimenti sopra."
fi

echo ""
print_info "Per test completo, apri un documento DOCX da files.php"
echo ""