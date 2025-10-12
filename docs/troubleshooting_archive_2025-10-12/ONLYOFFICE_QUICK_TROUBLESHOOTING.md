# OnlyOffice API - Quick Troubleshooting Guide

**Quick Access:** `http://localhost:8888/CollaboraNexio/test_onlyoffice_api.php`

---

## 🔴 ERROR: 404 Not Found

### Cause 1: Server Not Running
```bash
# Check if Apache is running on port 8888
netstat -ano | findstr :8888

# If not running, start XAMPP
C:\xampp\xampp-control.exe
```

### Cause 2: Wrong URL Path
```javascript
// ❌ WRONG
fetch('/api/documents/open_document.php?file_id=43')

// ✅ CORRECT
fetch('/CollaboraNexio/api/documents/open_document.php?file_id=43')
```

### Cause 3: File Doesn't Exist
```sql
-- Check if API file exists
-- Windows Command Prompt:
dir C:\xampp\htdocs\CollaboraNexio\api\documents\open_document.php

-- WSL/Linux:
ls -la /mnt/c/xampp/htdocs/CollaboraNexio/api/documents/open_document.php
```

**Expected:** File should exist with size ~7.9 KB

---

## 🔴 ERROR: 401 Unauthorized

### Cause: User Not Logged In

```javascript
// Check if session exists
console.log('Session User ID:', document.getElementById('userId')?.value);
console.log('CSRF Token:', document.getElementById('csrfToken')?.value);

// If null, redirect to login
if (!document.getElementById('csrfToken')) {
  window.location.href = '/CollaboraNexio/index.php';
}
```

### Solution: Ensure User is Authenticated

```php
// In your page (e.g., files.php)
<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
```

---

## 🔴 ERROR: 403 CSRF Token Invalid

### Cause: Missing or Invalid CSRF Token

```javascript
// ✅ CORRECT WAY TO CALL API
fetch('/CollaboraNexio/api/documents/open_document.php?file_id=43', {
  method: 'GET',
  credentials: 'same-origin',  // Important!
  headers: {
    'X-CSRF-Token': document.getElementById('csrfToken').value  // Must include!
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

### Verify CSRF Token Exists in HTML:

```html
<!-- Should be present in your page -->
<input type="hidden" id="csrfToken" value="<?php echo $_SESSION['csrf_token']; ?>">
```

---

## 🔴 ERROR: 500 Internal Server Error

### Check PHP Error Logs:

**Location:** `/logs/php_errors.log`

```bash
# View last 50 lines of error log
tail -n 50 /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# Windows Command Prompt:
type C:\xampp\htdocs\CollaboraNexio\logs\php_errors.log
```

### Common Causes:

1. **Database Connection Failed**
   ```bash
   # Check if MySQL is running
   netstat -ano | findstr :3306
   ```

2. **Missing Database Tables**
   ```sql
   -- Run in phpMyAdmin or MySQL CLI
   USE collaboranexio;
   SHOW TABLES LIKE 'document_editor_sessions';
   ```

3. **File Not Found on Disk**
   ```sql
   -- Check file path in database
   SELECT id, name, file_path, tenant_id
   FROM files
   WHERE id = 43;
   ```

---

## 🔴 OnlyOffice Server Not Accessible

### Check if Docker Container is Running:

```bash
# Check running containers
docker ps | grep onlyoffice

# If not running, start it
cd /mnt/c/xampp/htdocs/CollaboraNexio/docker
docker-compose up -d onlyoffice

# View logs
docker logs onlyoffice-documentserver --tail 100
```

### Test Server Accessibility:

```bash
# Test from command line
curl http://localhost:8083/healthcheck

# Expected response: HTTP 200 OK
```

### Update Configuration if Needed:

**File:** `/includes/onlyoffice_config.php`

```php
// Update this line if port changed
define('ONLYOFFICE_SERVER_URL', 'http://localhost:8083');
```

---

## 🔴 Editor Opens But Can't Load Document

### Cause 1: JWT Token Mismatch

**Check:** JWT secret must match between CollaboraNexio and OnlyOffice

```php
// File: /includes/onlyoffice_config.php
define('ONLYOFFICE_JWT_SECRET', 'your-secret-here');

// Must match OnlyOffice Docker environment variable:
// JWT_SECRET=your-secret-here
```

### Cause 2: Callback URL Not Accessible

**Issue:** OnlyOffice Docker container can't reach XAMPP

```php
// File: /includes/onlyoffice_config.php
// Use host.docker.internal to allow Docker to reach Windows host
define('ONLYOFFICE_CALLBACK_URL', 'http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php');
```

### Cause 3: File Not Readable

```bash
# Check file permissions
ls -l /mnt/c/xampp/htdocs/CollaboraNexio/uploads/{tenant_id}/{file_path}

# Should be readable (644 or 755)
```

---

## 🔴 Document Opens But Can't Save

### Check Callback Endpoint:

```bash
# Test save_document.php exists
ls -la /mnt/c/xampp/htdocs/CollaboraNexio/api/documents/save_document.php
```

### Check OnlyOffice Can Reach Callback:

```bash
# From inside Docker container:
docker exec -it onlyoffice-documentserver bash
curl http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php

# Expected: 403 (needs POST with valid data) NOT 404
```

### Check Write Permissions:

```bash
# Uploads directory must be writable
ls -ld /mnt/c/xampp/htdocs/CollaboraNexio/uploads
# Should show: drwxr-xr-x or similar
```

---

## 🟢 Quick Health Check Commands

### 1. Test Database Connection:
```bash
# Navigate to project
cd /mnt/c/xampp/htdocs/CollaboraNexio

# Open test page
start http://localhost:8888/CollaboraNexio/test_onlyoffice_api.php
```

### 2. Check All Services:
```bash
# Apache (XAMPP)
netstat -ano | findstr :8888

# MySQL
netstat -ano | findstr :3306

# OnlyOffice Docker
docker ps | grep onlyoffice
```

### 3. Verify API Files:
```bash
ls -la /mnt/c/xampp/htdocs/CollaboraNexio/api/documents/
# Should show:
# - open_document.php
# - save_document.php
# - close_session.php
# - get_editor_config.php
# - download_for_editor.php
```

### 4. Test API Manually (Browser Console):
```javascript
// While logged in to CollaboraNexio, run in console:
fetch('/CollaboraNexio/api/documents/open_document.php?file_id=43&mode=edit', {
  method: 'GET',
  credentials: 'same-origin',
  headers: {'X-CSRF-Token': document.getElementById('csrfToken').value}
})
.then(r => r.json())
.then(d => console.log('✅ API Working:', d))
.catch(e => console.error('❌ API Error:', e));
```

---

## 📋 Pre-Flight Checklist

Before testing the API, ensure:

- [ ] ✅ Apache/XAMPP is running on port 8888
- [ ] ✅ MySQL is running on port 3306
- [ ] ✅ Database `collaboranexio` exists
- [ ] ✅ OnlyOffice Docker container is running on port 8083
- [ ] ✅ User is logged in to CollaboraNexio
- [ ] ✅ At least one file exists in database
- [ ] ✅ File is editable format (docx, xlsx, pptx)
- [ ] ✅ CSRF token is present in page HTML
- [ ] ✅ `/api/documents/open_document.php` file exists

---

## 🔧 Common Configuration Issues

### Issue: Base URL Mismatch

**Symptom:** API returns correct data but URLs don't work

**Check:** `/config.php`
```php
// Should match your actual URL
define('BASE_URL', 'http://localhost:8888/CollaboraNexio');
```

### Issue: Session Not Shared

**Symptom:** User appears logged out in API calls

**Check:** `/includes/session_init.php`
```php
// Session name must be consistent
session_name('COLLAB_SID');
```

### Issue: File Upload Path Wrong

**Symptom:** Files upload but can't be opened

**Check:** `/config.php`
```php
// Must be absolute path
define('UPLOAD_PATH', __DIR__ . '/uploads');

// Verify it exists:
// Should be: C:\xampp\htdocs\CollaboraNexio\uploads
```

---

## 🆘 Emergency Commands

### Restart Everything:
```bash
# 1. Stop all services
taskkill /F /IM httpd.exe
taskkill /F /IM mysqld.exe
docker stop onlyoffice-documentserver

# 2. Wait 5 seconds

# 3. Start all services
C:\xampp\apache_start.bat
C:\xampp\mysql_start.bat
docker start onlyoffice-documentserver

# 4. Wait 30 seconds for OnlyOffice to fully start

# 5. Test
start http://localhost:8888/CollaboraNexio/test_onlyoffice_api.php
```

### Reset Session (If Stuck):
```php
<?php
// Create file: reset_session.php
session_start();
session_destroy();
echo "Session cleared. <a href='index.php'>Login again</a>";
?>
```

### View Real-Time Logs:
```bash
# PHP Errors
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log

# Database Errors
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/database_errors.log

# Apache Errors
tail -f C:\xampp\apache\logs\error.log

# OnlyOffice Logs
docker logs -f onlyoffice-documentserver
```

---

## 📞 Get Help

### 1. Run Diagnostic Tool First:
```
http://localhost:8888/CollaboraNexio/test_onlyoffice_api.php
```

### 2. Check Documentation:
- **API Docs:** `/api/documents/README.md`
- **Implementation Report:** `/ONLYOFFICE_API_IMPLEMENTATION_REPORT.md`
- **Database Schema:** `/database/09_document_editor.sql`

### 3. Collect Debug Information:
```bash
# Generate debug report
echo "=== SYSTEM INFO ===" > debug_report.txt
echo "Date: $(date)" >> debug_report.txt
echo "" >> debug_report.txt

echo "=== SERVICES ===" >> debug_report.txt
netstat -ano | findstr :8888 >> debug_report.txt
netstat -ano | findstr :3306 >> debug_report.txt
docker ps | grep onlyoffice >> debug_report.txt
echo "" >> debug_report.txt

echo "=== API FILES ===" >> debug_report.txt
ls -la /mnt/c/xampp/htdocs/CollaboraNexio/api/documents/ >> debug_report.txt
echo "" >> debug_report.txt

echo "=== RECENT ERRORS ===" >> debug_report.txt
tail -n 20 /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log >> debug_report.txt

cat debug_report.txt
```

---

**Last Updated:** 2025-10-12
**Version:** 1.0.0
**Auto-Refresh:** `http://localhost:8888/CollaboraNexio/test_onlyoffice_api.php`
