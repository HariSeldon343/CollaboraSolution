#!/bin/bash
#############################################
# Restart OnlyOffice Document Server
# Per CollaboraNexio
#############################################

echo "============================================="
echo "Riavvio OnlyOffice Document Server..."
echo "============================================="

if docker ps -a | grep -q collaboranexio-onlyoffice; then
    echo "Arresto container..."
    docker stop collaboranexio-onlyoffice
    sleep 2
    echo "Riavvio container..."
    docker start collaboranexio-onlyoffice
else
    echo "Container non trovato. Avvio script di start..."
    bash /mnt/c/xampp/htdocs/CollaboraNexio/docker/start_onlyoffice.sh
    exit 0
fi

echo ""
echo "Attendo che OnlyOffice sia pronto..."
sleep 15

# Verifica stato del server
echo ""
echo "Verifica stato del server..."
if curl -s http://localhost:8083/healthcheck > /dev/null 2>&1; then
    echo "✅ OnlyOffice è stato riavviato con successo!"
    echo ""
    echo "============================================="
    echo "OnlyOffice Document Server è pronto su:"
    echo "URL: http://localhost:8083"
    echo "============================================="
else
    echo "⚠️  OnlyOffice potrebbe richiedere più tempo per riavviarsi."
    echo "Riprova tra qualche secondo o controlla i log con:"
    echo "docker logs collaboranexio-onlyoffice"
fi

echo ""
echo "Container status:"
docker ps | grep collaboranexio-onlyoffice