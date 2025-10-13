#!/bin/bash

##############################################################################
# OnlyOffice Diagnostic Script
#
# Runs all diagnostic tests to identify why the editor is showing errors
#
# Usage: bash diagnose_onlyoffice.sh
##############################################################################

echo "=================================================="
echo "OnlyOffice Editor Diagnostic Tool"
echo "=================================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test results
TESTS_PASSED=0
TESTS_FAILED=0

##############################################################################
# Test 1: Check XAMPP is running on port 8888
##############################################################################
echo -e "${BLUE}[Test 1]${NC} Checking XAMPP on port 8888..."

if curl -s -o /dev/null -w "%{http_code}" http://localhost:8888/CollaboraNexio/ | grep -q "200\|302\|301"; then
    echo -e "${GREEN}✓ PASS${NC} - XAMPP is accessible on port 8888"
    ((TESTS_PASSED++))
else
    echo -e "${RED}✗ FAIL${NC} - XAMPP is NOT accessible on port 8888"
    echo "  Fix: Start Apache in XAMPP and ensure it's configured for port 8888"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 2: Check OnlyOffice Docker container is running
##############################################################################
echo -e "${BLUE}[Test 2]${NC} Checking OnlyOffice Docker container..."

if docker ps | grep -q "onlyoffice-document-server"; then
    echo -e "${GREEN}✓ PASS${NC} - OnlyOffice container is running"
    ((TESTS_PASSED++))
else
    echo -e "${RED}✗ FAIL${NC} - OnlyOffice container is NOT running"
    echo "  Fix: Run 'docker start onlyoffice-document-server'"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 3: Check OnlyOffice health endpoint
##############################################################################
echo -e "${BLUE}[Test 3]${NC} Checking OnlyOffice health endpoint..."

HEALTH_RESPONSE=$(curl -s http://localhost:8083/healthcheck)
if echo "$HEALTH_RESPONSE" | grep -q "true"; then
    echo -e "${GREEN}✓ PASS${NC} - OnlyOffice server is healthy"
    ((TESTS_PASSED++))
else
    echo -e "${RED}✗ FAIL${NC} - OnlyOffice server is not responding correctly"
    echo "  Response: $HEALTH_RESPONSE"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 4: Check download endpoint from host
##############################################################################
echo -e "${BLUE}[Test 4]${NC} Testing download endpoint from host (localhost:8888)..."

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    "http://localhost:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy")

if [ "$HTTP_CODE" -eq 401 ] || [ "$HTTP_CODE" -eq 403 ] || [ "$HTTP_CODE" -eq 200 ]; then
    echo -e "${GREEN}✓ PASS${NC} - Endpoint is accessible (HTTP $HTTP_CODE)"
    echo "  (401/403 is OK - it means endpoint exists but rejects invalid token)"
    ((TESTS_PASSED++))
else
    echo -e "${RED}✗ FAIL${NC} - Endpoint not accessible (HTTP $HTTP_CODE)"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 5: CRITICAL - Test download endpoint from Docker container
##############################################################################
echo -e "${BLUE}[Test 5]${NC} ${YELLOW}CRITICAL TEST${NC} - Testing from Docker container..."
echo "This simulates what OnlyOffice Document Server actually does..."

# Try with host.docker.internal
echo -e "${YELLOW}Testing: host.docker.internal:8888${NC}"
DOCKER_TEST=$(docker exec onlyoffice-document-server curl -s -o /dev/null -w "%{http_code}" \
    "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy" 2>&1)

if [ "$?" -eq 0 ]; then
    if echo "$DOCKER_TEST" | grep -q "401\|403\|200"; then
        echo -e "${GREEN}✓ PASS${NC} - Docker can reach XAMPP via host.docker.internal"
        echo "  Configuration is correct!"
        ((TESTS_PASSED++))
    else
        echo -e "${YELLOW}⚠ PARTIAL${NC} - Connection made but got HTTP $DOCKER_TEST"
        echo "  This might still work depending on the response"
        ((TESTS_PASSED++))
    fi
else
    echo -e "${RED}✗ FAIL${NC} - Docker CANNOT reach XAMPP"
    echo "  Error: $DOCKER_TEST"
    echo ""
    echo -e "${YELLOW}Possible solutions:${NC}"
    echo "  1. Add to Docker container's /etc/hosts:"
    echo "     docker exec -it onlyoffice-document-server bash"
    echo "     echo '172.17.0.1 host.docker.internal' >> /etc/hosts"
    echo "     exit"
    echo ""
    echo "  2. Or get your host IP and use it instead:"
    echo "     ip addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}'"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 6: Network connectivity from Docker to host
##############################################################################
echo -e "${BLUE}[Test 6]${NC} Testing network connectivity from Docker to host..."

if docker exec onlyoffice-document-server ping -c 2 host.docker.internal > /dev/null 2>&1; then
    echo -e "${GREEN}✓ PASS${NC} - Docker can ping host.docker.internal"
    ((TESTS_PASSED++))
else
    echo -e "${RED}✗ FAIL${NC} - Docker cannot ping host.docker.internal"
    echo "  This means host.docker.internal is not resolvable in Docker"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 7: Check JWT configuration
##############################################################################
echo -e "${BLUE}[Test 7]${NC} Checking JWT secret configuration..."

# Extract JWT from Docker
DOCKER_JWT=$(docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json 2>/dev/null | grep -oP '"string"\s*:\s*"\K[^"]+' | head -1)

if [ -n "$DOCKER_JWT" ]; then
    echo -e "${GREEN}✓ Found${NC} - Docker JWT Secret (first 20 chars): ${DOCKER_JWT:0:20}..."
    echo "  Compare this with ONLYOFFICE_JWT_SECRET in includes/onlyoffice_config.php"
else
    echo -e "${YELLOW}⚠ WARNING${NC} - Could not extract JWT from Docker container"
    echo "  Check manually: docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json"
fi
echo ""

##############################################################################
# Test 8: Check file upload directory
##############################################################################
echo -e "${BLUE}[Test 8]${NC} Checking upload directory..."

UPLOAD_DIR="/mnt/c/xampp/htdocs/CollaboraNexio/uploads"

if [ -d "$UPLOAD_DIR" ]; then
    echo -e "${GREEN}✓ EXISTS${NC} - Upload directory found"

    if [ -w "$UPLOAD_DIR" ]; then
        echo -e "${GREEN}✓ WRITABLE${NC} - Upload directory is writable"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ NOT WRITABLE${NC} - Upload directory is not writable"
        echo "  Fix: chmod -R 755 $UPLOAD_DIR"
        ((TESTS_FAILED++))
    fi
else
    echo -e "${RED}✗ NOT FOUND${NC} - Upload directory does not exist"
    echo "  Creating: mkdir -p $UPLOAD_DIR"
    mkdir -p "$UPLOAD_DIR"
    ((TESTS_FAILED++))
fi
echo ""

##############################################################################
# Test 9: View recent PHP errors
##############################################################################
echo -e "${BLUE}[Test 9]${NC} Checking PHP error log for OnlyOffice errors..."

LOG_FILE="/mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log"

if [ -f "$LOG_FILE" ]; then
    echo "Last 10 lines containing 'OnlyOffice' or 'Editor':"
    grep -i "onlyoffice\|editor\|download.*editor" "$LOG_FILE" | tail -10

    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${YELLOW}Note:${NC} Check the log file for more details: $LOG_FILE"
    else
        echo -e "${GREEN}✓ CLEAN${NC} - No recent OnlyOffice errors in log"
    fi
else
    echo -e "${YELLOW}⚠ INFO${NC} - PHP error log not found (may not have errors yet)"
fi
echo ""

##############################################################################
# Test 10: View Docker logs for errors
##############################################################################
echo -e "${BLUE}[Test 10]${NC} Checking Docker logs for recent errors..."

echo "Last 10 lines of OnlyOffice Docker logs:"
docker logs onlyoffice-document-server --tail 10 2>&1
echo ""

##############################################################################
# Summary
##############################################################################
echo "=================================================="
echo "DIAGNOSTIC SUMMARY"
echo "=================================================="
echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
    echo ""
    echo "Configuration appears correct. If the editor still shows errors:"
    echo "1. Open browser console (F12) and check for JavaScript errors"
    echo "2. Try opening test_onlyoffice_config.php in browser for detailed info"
    echo "3. Watch logs while opening editor:"
    echo "   tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log"
else
    echo -e "${RED}✗ Some tests failed${NC}"
    echo ""
    echo "Review the failed tests above and apply the suggested fixes."
    echo ""
    echo "Most common issue: Docker cannot reach host.docker.internal"
    echo "Solution: Add to Docker's /etc/hosts or use host IP directly"
fi

echo ""
echo "=================================================="
echo "NEXT STEPS"
echo "=================================================="
echo ""
echo "1. Review any failed tests above"
echo "2. Open in browser for detailed diagnostics:"
echo "   http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php"
echo ""
echo "3. Test download endpoint in browser:"
echo "   http://localhost:8888/CollaboraNexio/test_download_endpoint.php"
echo ""
echo "4. Monitor logs in real-time:"
echo "   Terminal 1: tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log"
echo "   Terminal 2: docker logs onlyoffice-document-server --follow"
echo ""
echo "5. Try opening a document and watch the logs"
echo ""
echo "For full documentation, see:"
echo "   /mnt/c/xampp/htdocs/CollaboraNexio/ONLYOFFICE_DIAGNOSTIC_REPORT.md"
echo ""

exit 0
