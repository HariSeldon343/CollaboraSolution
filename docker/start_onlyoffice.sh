#!/bin/bash
#############################################
# Start OnlyOffice Document Server
# Per CollaboraNexio
#############################################

echo "============================================="
echo "Avvio OnlyOffice Document Server..."
echo "============================================="

# Verifica se il container esiste
if docker ps -a | grep -q collaboranexio-onlyoffice; then
    echo "Container trovato, avvio in corso..."
    docker start collaboranexio-onlyoffice
else
    echo "Container non trovato, creazione in corso..."
    docker run -d \
        --name collaboranexio-onlyoffice \
        --restart=always \
        -p 8083:80 \
        -e JWT_ENABLED=true \
        -e JWT_SECRET="16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af" \
        -e JWT_HEADER="Authorization" \
        -e JWT_IN_BODY=true \
        -v /mnt/c/xampp/htdocs/CollaboraNexio/uploads/onlyoffice:/var/www/onlyoffice/documentserver/App_Data/cache/files \
        onlyoffice/documentserver
fi

echo ""
echo "Attendo che OnlyOffice sia pronto..."
sleep 15

# Verifica stato del server
echo ""
echo "Verifica stato del server..."
if curl -s http://localhost:8083/healthcheck > /dev/null 2>&1; then
    echo "✅ OnlyOffice è attivo e funzionante!"
    echo ""
    echo "============================================="
    echo "OnlyOffice Document Server è pronto su:"
    echo "URL: http://localhost:8083"
    echo "============================================="
else
    echo "⚠️  OnlyOffice potrebbe richiedere più tempo per avviarsi."
    echo "Riprova tra qualche secondo o controlla i log con:"
    echo "docker logs collaboranexio-onlyoffice"
fi

echo ""
echo "Container status:"
docker ps | grep collaboranexio-onlyoffice