#!/bin/bash
# ============================================
# TEST WORKFLOW API - Linux/Mac Shell Script
# ============================================
# Questo script testa il workflow usando le API HTTP
# Richiede curl installato
# ============================================

echo "=== TEST WORKFLOW API ==="
echo ""

# Configurazione
BASE_URL="http://localhost:8888/CollaboraNexio/api/documents/workflow"
AUTH_TOKEN="YOUR_AUTH_TOKEN_HERE"
FILE_ID=123
TENANT_ID=1

# Headers comuni
HEADERS=(-H "Content-Type: application/json" -H "X-CSRF-Token: $AUTH_TOKEN")

echo "1. Test Submit (Invio per validazione)"
echo "====================================="
curl -X POST "$BASE_URL/submit.php" \
  "${HEADERS[@]}" \
  -d "{\"file_id\": $FILE_ID, \"tenant_id\": $TENANT_ID}"
echo -e "\n\n"

sleep 2

echo "2. Test Validate (Validazione documento)"
echo "========================================"
curl -X POST "$BASE_URL/validate.php" \
  "${HEADERS[@]}" \
  -d "{\"file_id\": $FILE_ID, \"tenant_id\": $TENANT_ID, \"comment\": \"Documento validato\"}"
echo -e "\n\n"

sleep 2

echo "3. Test Approve (Approvazione finale)"
echo "====================================="
curl -X POST "$BASE_URL/approve.php" \
  "${HEADERS[@]}" \
  -d "{\"file_id\": $FILE_ID, \"tenant_id\": $TENANT_ID, \"comment\": \"Documento approvato\"}"
echo -e "\n\n"

echo "=== TEST COMPLETATO ==="

# Test aggiuntivi per error handling
echo ""
echo "4. Test Reject (Rifiuto documento)"
echo "=================================="
curl -X POST "$BASE_URL/reject.php" \
  "${HEADERS[@]}" \
  -d "{\"file_id\": $FILE_ID, \"tenant_id\": $TENANT_ID, \"comment\": \"Documento rifiutato per test\"}"
echo -e "\n\n"

sleep 2

echo "5. Test Recall (Richiamo documento)"
echo "==================================="
curl -X POST "$BASE_URL/recall.php" \
  "${HEADERS[@]}" \
  -d "{\"file_id\": $FILE_ID, \"tenant_id\": $TENANT_ID, \"reason\": \"Richiamato per modifiche\"}"
echo -e "\n"
