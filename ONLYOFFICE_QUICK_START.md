# OnlyOffice Editor Error - Quick Start Diagnostic Guide

**Time Required:** 5-10 minutes
**Goal:** Identify why the editor shows "[DocumentEditor] Editor error"

---

## STEP 1: Run Automated Diagnostic (2 minutes)

Open a terminal and run:

```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
bash diagnose_onlyoffice.sh
```

**What it does:** Runs 10 automated tests and tells you what's wrong.

**Look for:** The "DIAGNOSTIC SUMMARY" at the end.

- **All tests passed?** → Go to Step 3
- **Test 5 failed?** → This is the problem! Go to Step 2
- **Other tests failed?** → Follow the suggestions in the output

---

## STEP 2: Fix Docker Connectivity (Most Common Issue)

**Problem:** Docker cannot reach XAMPP on the host.

### Quick Fix A: Add to Docker Hosts

```bash
docker exec -it onlyoffice-document-server bash
echo "172.17.0.1 host.docker.internal" >> /etc/hosts
exit
docker restart onlyoffice-document-server
```

**Then retest:** Run Step 1 again.

### Quick Fix B: Use Your IP Address

1. **Get your IP:**
   ```bash
   ip addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}'
   ```
   Example output: `172.24.160.1`

2. **Edit config file:**
   Open: `/mnt/c/xampp/htdocs/CollaboraNexio/includes/onlyoffice_config.php`

   Find line 35 and replace with your IP:
   ```php
   define('ONLYOFFICE_DOWNLOAD_URL', 'http://172.24.160.1:8888/CollaboraNexio/api/documents/download_for_editor.php');
   ```

   Find line 42 and replace with your IP:
   ```php
   define('ONLYOFFICE_CALLBACK_URL', 'http://172.24.160.1:8888/CollaboraNexio/api/documents/save_document.php');
   ```

3. **Save and test:** Try opening a document again.

---

## STEP 3: Verify in Browser (3 minutes)

Open this URL in your browser:
```
http://localhost:8888/CollaboraNexio/test_onlyoffice_config.php
```

**Check these sections:**

1. **Section 1 (Environment):**
   - Should show: `PRODUCTION_MODE: FALSE (Development)`
   - ❌ If TRUE or UNDEFINED → Wrong environment

2. **Section 4 (URLs):**
   - Should show: `http://host.docker.internal:8888/...` (or your IP)
   - ❌ If shows `http://localhost:8888/...` → Configuration not applied

3. **Section 6 (Connectivity):**
   - Should show: `OnlyOffice Server is REACHABLE`
   - ❌ If not reachable → Docker container not running

4. **Section 10 (Summary):**
   - All checks should have green ✓
   - Any red ✗ indicates a problem

---

## STEP 4: Test from Docker Container (1 minute)

**This is the critical test** - it simulates what OnlyOffice actually does:

```bash
docker exec onlyoffice-document-server curl -v \
  "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
```

**What you should see:**
- Connection successful
- HTTP response (even if 401/403 - that's OK!)

**If you see error:**
- "Could not resolve host" → Apply Step 2
- "Connection refused" → XAMPP not running on port 8888
- "Connection timed out" → Firewall issue

---

## STEP 5: Try Opening Document (1 minute)

1. **Open two terminals for logs:**

   Terminal 1:
   ```bash
   tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
   ```

   Terminal 2:
   ```bash
   docker logs onlyoffice-document-server --follow
   ```

2. **Try to open a document in the editor**

3. **Watch the logs:**
   - PHP log should show debug output with URLs
   - Docker log should show download attempt

---

## QUICK REFERENCE: Common Issues & Fixes

### Issue: "Test 5 failed" in diagnostic script

**Cause:** Docker can't reach XAMPP
**Fix:** Apply Step 2 (either Fix A or Fix B)

### Issue: URLs show "localhost" instead of "host.docker.internal"

**Cause:** PRODUCTION_MODE is TRUE or config not loaded
**Fix:** Verify you're accessing via `http://localhost:8888/` (not nexiosolution.it)

### Issue: OnlyOffice container not running

**Cause:** Docker container stopped
**Fix:**
```bash
docker start onlyoffice-document-server
docker ps | grep onlyoffice
```

### Issue: XAMPP not accessible on port 8888

**Cause:** Apache not running or wrong port
**Fix:**
1. Start Apache in XAMPP Control Panel
2. Verify: `curl http://localhost:8888/`
3. Check httpd.conf has `Listen 8888`

### Issue: JWT secret mismatch

**Cause:** PHP and Docker have different secrets
**Fix:**
```bash
# View Docker secret
docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json | grep -A 2 "secret"

# Update PHP config to match
# Edit: includes/onlyoffice_config.php line 24
```

---

## Success Indicators

You'll know it's working when:

1. ✅ Diagnostic script shows all tests passed
2. ✅ test_onlyoffice_config.php shows all green checkmarks
3. ✅ Docker curl test returns HTTP 401/403 (connection successful)
4. ✅ PHP log shows URLs with host.docker.internal (or your IP)
5. ✅ Document opens in editor without error

---

## Still Having Issues?

1. **View comprehensive guide:**
   - `/mnt/c/xampp/htdocs/CollaboraNexio/ONLYOFFICE_DIAGNOSTIC_REPORT.md`

2. **Use detailed diagnostic tool:**
   - `http://localhost:8888/CollaboraNexio/test_download_endpoint.php`

3. **Check browser console:**
   - Open files.php
   - Press F12
   - Try to open document
   - Look for red errors in Console tab

4. **Collect support information:**
   ```bash
   bash diagnose_onlyoffice.sh > diagnostic_results.txt 2>&1
   tail -50 logs/php_errors.log > php_log_excerpt.txt
   docker logs onlyoffice-document-server --tail 50 > docker_log_excerpt.txt
   ```

   Share these three files when asking for help.

---

## Files You Can Use

- **`diagnose_onlyoffice.sh`** - Automated command-line diagnostic
- **`test_onlyoffice_config.php`** - Web-based configuration viewer
- **`test_download_endpoint.php`** - Download endpoint tester
- **`ONLYOFFICE_DIAGNOSTIC_REPORT.md`** - Comprehensive troubleshooting guide
- **`ONLYOFFICE_VERIFICATION_SUMMARY.md`** - Detailed status report

---

**Last Updated:** 2025-10-12
**Estimated Fix Time:** 5-15 minutes depending on the issue
