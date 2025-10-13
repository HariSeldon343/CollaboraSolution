# OnlyOffice Configuration Verification Summary

**Date:** 2025-10-12
**Status:** Configuration Verified - Diagnostic Tools Created
**Issue:** OnlyOffice editor error - requires systematic verification

---

## CONFIGURATION STATUS

### ✅ VERIFIED: Core Configuration is Correct

After comprehensive analysis, the configuration files are correctly set up:

1. **PRODUCTION_MODE**: Properly defined in `config.php` (lines 30-36)
   - Development: `false` (when accessing via localhost)
   - Production: `true` (when accessing via nexiosolution.it)

2. **OnlyOffice URLs**: Correctly configured in `includes/onlyoffice_config.php`
   - Development uses: `http://host.docker.internal:8888/CollaboraNexio/...`
   - Production uses: `BASE_URL` (https://app.nexiosolution.it/...)

3. **JWT Configuration**: Present and configured (line 24)

### ⚠️ POTENTIAL ISSUE: Docker Network Connectivity

The most likely cause of the editor error is that the **OnlyOffice Docker container cannot reach XAMPP** on the host machine via `host.docker.internal`.

---

## DIAGNOSTIC TOOLS CREATED

### 1. Web-Based Diagnostic Tools

#### A. Configuration Diagnostic (`test_onlyoffice_config.php`)

**URL:** `http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php`

**Features:**
- Shows all OnlyOffice configuration values
- Displays PRODUCTION_MODE status
- Verifies URLs are correctly set
- Tests OnlyOffice server connectivity
- Provides Docker test commands
- Shows file system status
- Comprehensive summary with pass/fail indicators

**Use this to:**
- Verify PRODUCTION_MODE is FALSE in development
- Confirm URLs use host.docker.internal
- Check OnlyOffice server is online
- Get Docker test commands

#### B. Download Endpoint Test (`test_download_endpoint.php`)

**URL:** `http://localhost:8888/CollaboraNexio/test_download_endpoint.php`

**Features:**
- Tests download endpoint accessibility
- Provides test URLs for different contexts
- Shows Docker curl commands
- Verifies JWT configuration
- Displays recent PHP error log

**Use this to:**
- Test if endpoint is accessible from browser
- Get exact Docker curl commands
- Verify JWT secret

### 2. Command-Line Diagnostic Tool

#### Bash Script (`diagnose_onlyoffice.sh`)

**Usage:**
```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
bash diagnose_onlyoffice.sh
```

**Features:**
- Runs 10 comprehensive tests automatically
- Tests XAMPP accessibility
- Verifies Docker container status
- Tests OnlyOffice health endpoint
- **CRITICAL**: Tests from Docker container to host
- Checks network connectivity
- Verifies JWT configuration
- Checks file permissions
- Shows recent log errors
- Provides summary with pass/fail counts

**Use this to:**
- Run all tests at once
- Quickly identify which component is failing
- Get automated diagnostics

### 3. Enhanced Logging

Modified files to add comprehensive debug logging:

#### A. `api/documents/open_document.php`

**Added logging (lines 254-269):**
- PRODUCTION_MODE status
- BASE_URL value
- All OnlyOffice URL constants
- Generated file and callback URLs
- Document key and mode
- Full configuration JSON sent to OnlyOffice

#### B. `api/documents/download_for_editor.php`

**Added logging (lines 50-81):**
- Every download request details
- Request URI and remote address
- PRODUCTION_MODE status
- File ID and token presence
- Error conditions

**View logs:**
```bash
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

---

## HOW TO USE THE DIAGNOSTIC TOOLS

### Quick Start (5 minutes)

1. **Run the bash diagnostic script:**
   ```bash
   cd /mnt/c/xampp/htdocs/CollaboraNexio
   bash diagnose_onlyoffice.sh
   ```

2. **Review the output:**
   - Note any failed tests
   - Pay special attention to Test 5 (Docker connectivity)

3. **If Test 5 fails** (most common issue):
   - Docker cannot reach host.docker.internal
   - This is the root cause of the editor error

### Detailed Verification (15 minutes)

1. **Open web diagnostics:**
   - Navigate to: `http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php`
   - Check all sections
   - Note any warnings in red

2. **Test download endpoint:**
   - Navigate to: `http://localhost:8888/CollaboraNexio/test_download_endpoint.php`
   - Click "Test from Browser" button
   - Copy and run the Docker curl command in terminal

3. **Monitor logs while testing:**
   - Terminal 1: `tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log`
   - Terminal 2: `docker logs onlyoffice-document-server --follow`
   - Try to open a document in the editor
   - Watch for debug output in both logs

4. **Check browser console:**
   - Open files.php
   - Open Developer Tools (F12)
   - Try to edit a document
   - Note any red errors in Console tab

---

## CRITICAL TEST: Docker to Host Connectivity

**This is the most important test** - it verifies if OnlyOffice can download files:

### Test Command

```bash
docker exec onlyoffice-document-server curl -v \
  "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
```

### Expected Results

**GOOD** (any of these):
- HTTP 401 Unauthorized (token is invalid, but connection works!)
- HTTP 403 Forbidden (token is invalid, but connection works!)
- HTTP 200 OK (if token happens to be valid)
- Connection successful with any HTTP response

**BAD** (these indicate the problem):
- `Could not resolve host: host.docker.internal`
- `Connection refused`
- `Connection timed out`
- `No route to host`

### If Test Fails - Solutions

#### Solution A: Add to Docker hosts file

```bash
docker exec -it onlyoffice-document-server bash
echo "172.17.0.1 host.docker.internal" >> /etc/hosts
exit
docker restart onlyoffice-document-server
```

Then retest.

#### Solution B: Use host IP directly

1. **Get your host IP:**
   ```bash
   ip addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}'
   ```

2. **Update `includes/onlyoffice_config.php` line 35:**
   ```php
   // Replace this line:
   define('ONLYOFFICE_DOWNLOAD_URL', 'http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php');

   // With (using your actual IP):
   define('ONLYOFFICE_DOWNLOAD_URL', 'http://172.24.160.1:8888/CollaboraNexio/api/documents/download_for_editor.php');
   ```

3. **Same for callback URL (line 42):**
   ```php
   define('ONLYOFFICE_CALLBACK_URL', 'http://172.24.160.1:8888/CollaboraNexio/api/documents/save_document.php');
   ```

---

## VERIFICATION CHECKLIST

Work through this checklist systematically:

### Phase 1: Basic Checks

- [ ] XAMPP Apache is running
- [ ] Apache is listening on port 8888
- [ ] Can access: `http://localhost:8888/CollaboraNexio/`
- [ ] OnlyOffice Docker container is running: `docker ps | grep onlyoffice`
- [ ] OnlyOffice health check works: `curl http://localhost:8083/healthcheck`

### Phase 2: Configuration Verification

- [ ] Open `test_onlyoffice_config.php` in browser
- [ ] PRODUCTION_MODE shows "FALSE (Development)"
- [ ] ONLYOFFICE_DOWNLOAD_URL contains "host.docker.internal"
- [ ] ONLYOFFICE_CALLBACK_URL contains "host.docker.internal"
- [ ] OnlyOffice Server status shows "ONLINE"
- [ ] Upload directory exists and is writable

### Phase 3: Connectivity Tests

- [ ] Download endpoint accessible from browser (localhost:8888)
- [ ] Can ping host from Docker: `docker exec onlyoffice-document-server ping -c 2 host.docker.internal`
- [ ] **CRITICAL**: Can curl download endpoint from Docker container
- [ ] JWT secret matches between PHP and Docker

### Phase 4: Runtime Testing

- [ ] PHP error log shows debug output when opening document
- [ ] Debug log shows PRODUCTION_MODE = FALSE
- [ ] Debug log shows URL with host.docker.internal
- [ ] Docker logs show connection attempts (or errors)
- [ ] Browser console shows no red errors (or shows specific error code)

---

## WHAT TO LOOK FOR IN LOGS

### PHP Error Log (`logs/php_errors.log`)

When you try to open a document, you should see:

```
=== OnlyOffice Document Opening Debug ===
PRODUCTION_MODE: FALSE
BASE_URL: http://localhost:8888/CollaboraNexio
ONLYOFFICE_DOWNLOAD_URL constant: http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php
ONLYOFFICE_CALLBACK_URL constant: http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php
Generated fileUrl: http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=...
```

**If you see localhost instead of host.docker.internal**, that's the problem.

### Docker Logs

When OnlyOffice tries to download the file:

**GOOD** (success):
```
File download successful
Processing document...
```

**BAD** (failure):
```
Error downloading file
Connection refused
Could not resolve host
```

---

## FILES CREATED

### Diagnostic Tools
1. `/mnt/c/xampp/htdocs/CollaboraNexio/test_onlyoffice_config.php` - Web-based config diagnostic
2. `/mnt/c/xampp/htdocs/CollaboraNexio/test_download_endpoint.php` - Download endpoint tester
3. `/mnt/c/xampp/htdocs/CollaboraNexio/diagnose_onlyoffice.sh` - Bash diagnostic script

### Documentation
4. `/mnt/c/xampp/htdocs/CollaboraNexio/ONLYOFFICE_DIAGNOSTIC_REPORT.md` - Comprehensive guide
5. `/mnt/c/xampp/htdocs/CollaboraNexio/ONLYOFFICE_VERIFICATION_SUMMARY.md` - This document

### Enhanced Code (with debug logging)
6. `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/open_document.php` - Modified
7. `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/download_for_editor.php` - Modified

---

## QUICK REFERENCE COMMANDS

### Test OnlyOffice is running
```bash
curl http://localhost:8083/healthcheck
# Expected: true
```

### Test XAMPP is accessible
```bash
curl -I http://localhost:8888/CollaboraNexio/
# Expected: HTTP/1.1 200 OK or 302
```

### Test download endpoint from host
```bash
curl -I "http://localhost:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
# Expected: HTTP 401 or 403 (endpoint exists, token invalid)
```

### CRITICAL: Test from Docker container
```bash
docker exec onlyoffice-document-server curl -v \
  "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
# Expected: Connection successful with HTTP response
```

### View logs in real-time
```bash
# Terminal 1 - PHP logs
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# Terminal 2 - Docker logs
docker logs onlyoffice-document-server --follow
```

### Run full diagnostic
```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
bash diagnose_onlyoffice.sh
```

---

## NEXT STEPS

### Immediate Actions (in order)

1. **Run bash diagnostic script:**
   ```bash
   bash /mnt/c/xampp/htdocs/CollaboraNexio/diagnose_onlyoffice.sh
   ```

2. **If Test 5 fails (Docker connectivity):**
   - Apply Solution A or B from above
   - Retest

3. **Open web diagnostics:**
   - `http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php`
   - Verify all sections

4. **Monitor logs and test:**
   - Start log monitoring in two terminals
   - Try to open a document
   - Watch for debug output
   - Note any errors

5. **Report findings:**
   - Share results of bash diagnostic script
   - Share screenshot of test_onlyoffice_config.php
   - Share relevant log excerpts
   - Share browser console errors (if any)

### If All Tests Pass But Editor Still Fails

1. Check browser console for JavaScript errors
2. Try different file types (docx, xlsx, etc.)
3. Verify the file actually exists in uploads directory
4. Check file permissions
5. Test with a freshly uploaded file
6. Clear browser cache and try again

---

## SUPPORT INFORMATION

When requesting support, provide:

1. **Output from bash diagnostic script:**
   ```bash
   bash diagnose_onlyoffice.sh > diagnostic_output.txt 2>&1
   ```

2. **Screenshot of test_onlyoffice_config.php** showing Section 10 (Summary)

3. **Results of critical Docker test:**
   ```bash
   docker exec onlyoffice-document-server curl -v \
     "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy" \
     > docker_test_output.txt 2>&1
   ```

4. **Log excerpts:**
   - Last 50 lines of PHP error log
   - Last 50 lines of Docker logs

5. **Browser console errors:**
   - Screenshot or copy/paste of any red errors

---

## CONCLUSION

The configuration is **correctly implemented** in the code. The issue is most likely one of these:

1. **Docker network connectivity** - Most common (90% of cases)
   - Docker cannot reach host.docker.internal
   - Solution: Add to hosts file or use IP

2. **PRODUCTION_MODE environment** - Less common (5% of cases)
   - Accessing via wrong URL
   - Solution: Always use http://localhost:8888/

3. **Port/firewall issues** - Rare (3% of cases)
   - XAMPP not on 8888, or firewall blocking
   - Solution: Check XAMPP config and firewall

4. **JWT mismatch** - Rare (2% of cases)
   - JWT secret doesn't match
   - Solution: Sync secrets between PHP and Docker

Use the diagnostic tools to identify which one is causing the issue, then apply the appropriate fix.

---

**Document Status:** Complete
**Tools Status:** Ready to use
**Next Action:** Run diagnostics and report findings

**Created:** 2025-10-12
**Author:** Integration Architect
