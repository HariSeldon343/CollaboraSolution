#!/bin/bash
#############################################
# Stop OnlyOffice Document Server
# Per CollaboraNexio
#############################################

echo "============================================="
echo "Arresto OnlyOffice Document Server..."
echo "============================================="

if docker ps | grep -q collaboranexio-onlyoffice; then
    docker stop collaboranexio-onlyoffice
    echo "✅ OnlyOffice Document Server arrestato."
else
    echo "⚠️  OnlyOffice Document Server non è in esecuzione."
fi

echo ""
echo "Stato attuale:"
docker ps -a | grep collaboranexio-onlyoffice