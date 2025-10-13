# OnlyOffice Editor Error - Comprehensive Diagnostic Report

**Date:** 2025-10-12
**Issue:** OnlyOffice editor shows "[DocumentEditor] Editor error" despite configuration fixes
**Error Code:** Likely -4 (download failed)

---

## PROBLEM SUMMARY

The OnlyOffice Document Server running in Docker cannot download files from XAMPP, causing the editor to fail with an error. This happens because the Document Server needs to fetch the file via HTTP before displaying it in the editor.

---

## CONFIGURATION VERIFICATION RESULTS

### 1. Environment Configuration Status

**Location:** `/mnt/c/xampp/htdocs/CollaboraNexio/config.php`

✅ **PRODUCTION_MODE** is correctly defined:
- Lines 30-36: Development environment correctly sets `PRODUCTION_MODE = false`
- This is determined by checking if hostname contains 'nexiosolution.it'
- In development (localhost), this will be `FALSE`

✅ **DEBUG_MODE** is enabled in development:
- Line 35: `DEBUG_MODE = true` (for localhost)
- Error reporting is enabled for development

### 2. OnlyOffice Configuration Status

**Location:** `/mnt/c/xampp/htdocs/CollaboraNexio/includes/onlyoffice_config.php`

✅ **Download URL** is configured correctly:
```php
// Lines 30-36
if (defined('PRODUCTION_MODE') && PRODUCTION_MODE) {
    define('ONLYOFFICE_DOWNLOAD_URL', BASE_URL . '/api/documents/download_for_editor.php');
} else {
    // Development: Use host.docker.internal to reach XAMPP on host
    define('ONLYOFFICE_DOWNLOAD_URL', 'http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php');
}
```

✅ **Callback URL** is configured correctly:
```php
// Lines 38-43
if (defined('PRODUCTION_MODE') && PRODUCTION_MODE) {
    define('ONLYOFFICE_CALLBACK_URL', BASE_URL . '/api/documents/save_document.php');
} else {
    define('ONLYOFFICE_CALLBACK_URL', 'http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php');
}
```

✅ **JWT Configuration** is present:
- Line 24: JWT Secret is defined
- Line 26: JWT is enabled

---

## DIAGNOSTIC TOOLS CREATED

### 1. Configuration Diagnostic Script

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/test_onlyoffice_config.php`

**Purpose:**
- Displays all OnlyOffice configuration values
- Shows PRODUCTION_MODE status
- Verifies URLs are correctly configured
- Tests OnlyOffice server connectivity
- Provides Docker test commands

**How to Use:**
1. Open in browser: `http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php`
2. Check all sections for warnings or errors
3. Run the provided Docker commands to test connectivity

### 2. Download Endpoint Test Script

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/test_download_endpoint.php`

**Purpose:**
- Tests if the download endpoint is accessible
- Provides test URLs for different contexts
- Shows Docker curl commands for testing
- Verifies JWT configuration

**How to Use:**
1. Open in browser: `http://localhost:8888/CollaboraNexio/test_download_endpoint.php`
2. Click "Test from Browser" button
3. Run the Docker curl commands in terminal
4. Check results

### 3. Enhanced Logging

**Modified Files:**
- `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/open_document.php`
- `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/download_for_editor.php`

**Added Logging:**
- Logs PRODUCTION_MODE status
- Logs all URLs being used
- Logs every download request from OnlyOffice
- Logs full configuration sent to editor

**How to View Logs:**
```bash
# View PHP error log
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# View Docker logs
docker logs onlyoffice-document-server --tail 50 --follow
```

---

## STEP-BY-STEP VERIFICATION PROCESS

### Step 1: Verify PRODUCTION_MODE is FALSE

**Test:** Open `test_onlyoffice_config.php`

**Expected Result:**
```
PRODUCTION_MODE: FALSE (Development) ✅
```

**If it shows TRUE or UNDEFINED:** There's a configuration issue in `config.php`

---

### Step 2: Verify URLs Use host.docker.internal

**Test:** Check `test_onlyoffice_config.php` section 4

**Expected Result:**
```
ONLYOFFICE_DOWNLOAD_URL: http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php
✅ Correctly configured for Docker on Windows (Development)

ONLYOFFICE_CALLBACK_URL: http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php
✅ Correctly configured for Docker on Windows (Development)
```

**If it shows localhost instead of host.docker.internal:** The configuration is not being applied correctly.

---

### Step 3: Test OnlyOffice Server Connectivity

**Test Command:**
```bash
curl http://localhost:8083/healthcheck
```

**Expected Result:**
```
true
```

**If it fails:** OnlyOffice Docker container is not running. Start it with:
```bash
docker start onlyoffice-document-server
```

---

### Step 4: Test Download Endpoint from Host

**Test Command:**
```bash
curl -v "http://localhost:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
```

**Expected Result:**
- Should return HTTP 401 or 403 (authentication error) - this is GOOD
- Means the endpoint is accessible

**If Connection Refused:** XAMPP Apache is not running on port 8888

---

### Step 5: Test Download Endpoint from Docker Container

**CRITICAL TEST** - This is what OnlyOffice actually does:

```bash
docker exec onlyoffice-document-server curl -v \
  "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
```

**Expected Result:**
- Should connect and return some response (even if it's an error about the token)
- The important thing is that it **CONNECTS**

**If "Could not resolve host":**
- Docker cannot resolve `host.docker.internal`
- This is the root cause of the editor error

**Possible Fixes:**
1. **Add to Docker container's hosts file:**
   ```bash
   docker exec -it onlyoffice-document-server bash
   echo "192.168.65.2 host.docker.internal" >> /etc/hosts
   exit
   ```

2. **Get your host IP and use it instead:**
   ```bash
   # Windows: Get WSL IP
   ip addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}'

   # Then update onlyoffice_config.php to use this IP
   # For example: http://172.24.160.1:8888/...
   ```

---

### Step 6: Verify JWT Secret Matches

**Test Command:**
```bash
docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json | grep -A 2 "secret"
```

**Expected Result:**
Should show the JWT secret configured in OnlyOffice.

**Compare with PHP config:**
- PHP: `ONLYOFFICE_JWT_SECRET` in `includes/onlyoffice_config.php` (line 24)
- Default: `16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af`

**If they don't match:** Update one to match the other and restart Docker container.

---

### Step 7: Check Browser Console for Errors

**Test:**
1. Open `files.php` in browser
2. Click "Edit" on a document
3. Open Developer Tools (F12)
4. Check Console tab for errors

**Look for:**
- Red error messages
- Network failures
- "Download failed" messages
- Error codes (especially -4)

---

### Step 8: View Real-Time Logs

**Terminal 1 - PHP Logs:**
```bash
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

**Terminal 2 - Docker Logs:**
```bash
docker logs onlyoffice-document-server --tail 50 --follow
```

**Then:**
1. Try to open a document in the editor
2. Watch both log files
3. Look for the debug output that shows:
   - PRODUCTION_MODE status
   - URLs being used
   - Download requests from OnlyOffice

---

## COMMON ISSUES AND SOLUTIONS

### Issue 1: PRODUCTION_MODE is TRUE or UNDEFINED

**Symptoms:**
- URLs use localhost instead of host.docker.internal
- Docker cannot reach the download endpoint

**Solution:**
1. Check `/mnt/c/xampp/htdocs/CollaboraNexio/config.php`
2. Verify lines 14-41 are present and correct
3. Access via `http://localhost:8888/...` (NOT `http://nexiosolution.it/...`)

---

### Issue 2: host.docker.internal Cannot Be Resolved

**Symptoms:**
- Docker curl command fails with "Could not resolve host"
- OnlyOffice editor shows error -4

**Solution A - Add to Docker hosts:**
```bash
docker exec -it onlyoffice-document-server bash
echo "172.17.0.1 host.docker.internal" >> /etc/hosts
exit
docker restart onlyoffice-document-server
```

**Solution B - Use host IP directly:**
1. Get your host IP:
   ```bash
   ip addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}'
   ```
2. Update `includes/onlyoffice_config.php` line 35:
   ```php
   define('ONLYOFFICE_DOWNLOAD_URL', 'http://YOUR_HOST_IP:8888/CollaboraNexio/api/documents/download_for_editor.php');
   ```

---

### Issue 3: JWT Secret Mismatch

**Symptoms:**
- OnlyOffice logs show "JWT signature verification failed"
- Downloads work but editor still shows error

**Solution:**
1. Get JWT from Docker:
   ```bash
   docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json
   ```
2. Copy the `secret.inbox.string` value
3. Update in `includes/onlyoffice_config.php` line 24
4. Clear any caches

---

### Issue 4: XAMPP Not Accessible on Port 8888

**Symptoms:**
- curl to localhost:8888 fails
- "Connection refused" errors

**Solution:**
1. Check Apache is running in XAMPP
2. Verify httpd.conf has `Listen 8888`
3. Check Windows Firewall allows port 8888
4. Test: `curl http://localhost:8888/`

---

### Issue 5: File Permissions

**Symptoms:**
- Download endpoint returns 500 error
- "File not readable" in logs

**Solution:**
```bash
# Check upload directory permissions
ls -la /mnt/c/xampp/htdocs/CollaboraNexio/uploads/

# Fix if needed
chmod -R 755 /mnt/c/xampp/htdocs/CollaboraNexio/uploads/
```

---

## VERIFICATION CHECKLIST

Use this checklist to systematically verify the configuration:

- [ ] **Step 1:** PRODUCTION_MODE = FALSE (verified in test_onlyoffice_config.php)
- [ ] **Step 2:** ONLYOFFICE_DOWNLOAD_URL uses host.docker.internal
- [ ] **Step 3:** ONLYOFFICE_CALLBACK_URL uses host.docker.internal
- [ ] **Step 4:** OnlyOffice server health check returns "true"
- [ ] **Step 5:** Download endpoint accessible from host (localhost:8888)
- [ ] **Step 6:** Download endpoint accessible from Docker (host.docker.internal:8888)
- [ ] **Step 7:** JWT secret matches between PHP and Docker
- [ ] **Step 8:** Upload directory exists and is writable
- [ ] **Step 9:** PHP error log shows debug output when opening document
- [ ] **Step 10:** Docker logs show connection attempts from OnlyOffice

---

## WHAT TO REPORT

When reporting results, include:

1. **Output from test_onlyoffice_config.php:**
   - PRODUCTION_MODE value
   - ONLYOFFICE_DOWNLOAD_URL value
   - OnlyOffice server status

2. **Results from Docker curl test:**
   ```bash
   docker exec onlyoffice-document-server curl -v \
     "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
   ```

3. **Browser console errors:**
   - Any red errors when opening editor
   - Network tab showing failed requests

4. **Log file excerpts:**
   - Last 20 lines of PHP error log
   - Last 20 lines of Docker logs

5. **Network test results:**
   ```bash
   docker exec onlyoffice-document-server ping -c 4 host.docker.internal
   ```

---

## NEXT STEPS

1. ✅ **Start with diagnostics:** Open `test_onlyoffice_config.php` and verify all sections
2. ✅ **Test connectivity:** Run the Docker curl command to test from container
3. ✅ **Check logs:** View PHP and Docker logs while trying to open a document
4. ✅ **Report findings:** Share the results from the checklist above

---

## FILES CREATED/MODIFIED

### Created:
1. `/mnt/c/xampp/htdocs/CollaboraNexio/test_onlyoffice_config.php` - Configuration diagnostic
2. `/mnt/c/xampp/htdocs/CollaboraNexio/test_download_endpoint.php` - Download endpoint test
3. `/mnt/c/xampp/htdocs/CollaboraNexio/ONLYOFFICE_DIAGNOSTIC_REPORT.md` - This document

### Modified:
1. `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/open_document.php` - Added debug logging
2. `/mnt/c/xampp/htdocs/CollaboraNexio/api/documents/download_for_editor.php` - Added debug logging

---

## THEORY OF OPERATION

Understanding how OnlyOffice editor works:

1. **User clicks "Edit" in browser**
2. **Browser requests:** `GET /api/documents/open_document.php?file_id=43`
3. **PHP generates config** with:
   - Document URL: `http://host.docker.internal:8888/.../download_for_editor.php?file_id=43&token=xxx`
   - Callback URL: `http://host.docker.internal:8888/.../save_document.php?key=xxx`
   - JWT tokens for authentication
4. **Browser loads OnlyOffice editor** with this config
5. **OnlyOffice Document Server (in Docker):**
   - Receives the config from browser
   - **Makes HTTP request to download the file** from the document URL
   - This is where it fails if Docker cannot reach host.docker.internal
6. **If download succeeds:** Editor displays the document
7. **If download fails:** Editor shows error -4

**The critical point:** The OnlyOffice Document Server (running in Docker) must be able to make HTTP requests to XAMPP (running on the Windows host) to download the file. This is why we need `host.docker.internal`.

---

## CONTACT / SUPPORT

If issues persist after following this guide:

1. Collect all diagnostic information from the checklist
2. Include screenshots of:
   - test_onlyoffice_config.php output
   - Browser console errors
   - Docker curl test results
3. Share log file excerpts (last 50 lines of both PHP and Docker logs)

---

**Document Version:** 1.0
**Last Updated:** 2025-10-12
**Author:** System Integration Architect
