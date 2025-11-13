# OnlyOffice Quick Reference Guide

## TL;DR - Integration Status

**Status:** ✅ COMPLETE AND OPERATIONAL
**Test Results:** 9/9 tests passed (100%)
**Database:** All tables created and indexed
**APIs:** All 8 endpoints functional
**Server:** Running on http://localhost:8083

---

## Quick Commands

### Check OnlyOffice Status
```bash
docker ps | grep onlyoffice
curl -I http://localhost:8083/
```

### Run Integration Test
```bash
/mnt/c/xampp/php/php.exe test_onlyoffice_integration.php
```

### Start/Stop OnlyOffice
```bash
cd docker
docker-compose up -d    # Start
docker-compose down     # Stop
docker-compose restart  # Restart
```

### View Logs
```bash
docker logs collaboranexio-onlyoffice -f
```

---

## Database Tables

### document_editor_sessions
Tracks active editing sessions
- Primary key: `id`
- Foreign keys: `file_id`, `user_id`, `tenant_id`
- Key field: `editor_key` (OnlyOffice document key)
- Session tracking: `opened_at`, `last_activity`, `closed_at`

### document_editor_config
Per-tenant OnlyOffice configuration
- Default: `editor_enabled` = 'true'
- Default: `max_file_size` = '104857600' (100MB)

### files (OnlyOffice columns)
- `is_editable` (TINYINT)
- `editor_format` (VARCHAR) - 'word', 'cell', or 'slide'
- `last_edited_by` (INT)
- `last_edited_at` (TIMESTAMP)
- `version` (INT)

---

## API Endpoints

All located in: `/api/documents/`

1. **get_editor_config.php** - Get editor configuration
2. **open_document.php** - Open document for editing
3. **save_document.php** - OnlyOffice callback to save
4. **close_session.php** - Close editing session
5. **download_for_editor.php** - Download file for editor
6. **pending.php** - List pending approvals
7. **approve.php** - Approve document
8. **reject.php** - Reject document

---

## Configuration

**File:** `/includes/onlyoffice_config.php`

**Key Settings:**
- Server URL: `http://localhost:8083`
- JWT Secret: `16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af`
- Callback URL: `http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php`
- Max File Size: 100MB
- Session Timeout: 1 hour
- Idle Timeout: 30 minutes

**Supported Formats:**
- Editable: docx, xlsx, pptx, odt, ods, odp, txt, csv, rtf (29 total)
- View-only: pdf, djvu, xps, epub, fb2

---

## Quick Tests

### Test 1: Server Accessibility
```bash
curl -I http://localhost:8083/web-apps/apps/api/documents/api.js
# Expected: HTTP 200
```

### Test 2: Database Tables
```sql
SHOW TABLES LIKE '%editor%';
# Expected: document_editor_sessions, document_editor_config
```

### Test 3: JWT Generation
```php
require 'includes/onlyoffice_config.php';
$token = generateJWT(['test' => 'data'], ONLYOFFICE_JWT_SECRET);
echo $token; // Should output JWT token
```

### Test 4: Active Sessions
```sql
SELECT COUNT(*) FROM document_editor_sessions WHERE closed_at IS NULL;
```

---

## Troubleshooting

### Editor Not Loading
1. Check Docker: `docker ps | grep onlyoffice`
2. Check API: `curl http://localhost:8083/web-apps/apps/api/documents/api.js`
3. Check JWT secret matches in config and docker-compose.yml
4. Restart container: `docker restart collaboranexio-onlyoffice`

### Document Not Saving
1. Check callback URL reachable: `http://host.docker.internal:8888`
2. Check PHP error logs: `/logs/php_errors.log`
3. Verify JWT authentication in save_document.php
4. Check file permissions on uploads directory

### Session Timeout
1. Increase ONLYOFFICE_SESSION_TIMEOUT (default: 3600)
2. Increase ONLYOFFICE_IDLE_TIMEOUT (default: 1800)
3. Check cleanup procedure interval

---

## Files to Review

**Configuration:**
- `/includes/onlyoffice_config.php` - Main config
- `/docker/docker-compose.yml` - Docker setup

**APIs:**
- `/api/documents/*.php` - All API endpoints

**Database:**
- `/database/09_document_editor_fixed.sql` - Schema

**Tests:**
- `test_onlyoffice_integration.php` - Integration test
- `run_onlyoffice_migration.php` - Migration script

---

## Next Steps

### For Frontend Integration
1. Call `open_document.php` to start editing session
2. Initialize OnlyOffice editor with config from `get_editor_config.php`
3. Handle editor events (onDocumentReady, onDocumentStateChange, etc.)
4. Call `close_session.php` when user closes editor

### For Database Integrity Check
1. Check for orphaned sessions (sessions without valid files/users)
2. Verify foreign key constraints
3. Check for stale sessions (closed_at IS NULL and old last_activity)
4. Validate editor_format consistency with file extensions

---

## Production Checklist

- [ ] Change JWT_SECRET to production value (generate new 64-char secret)
- [ ] Update ONLYOFFICE_SERVER_URL to https://
- [ ] Update ONLYOFFICE_CALLBACK_URL to https://
- [ ] Configure SSL certificate for OnlyOffice
- [ ] Set up backup for OnlyOffice volumes
- [ ] Configure monitoring and alerts
- [ ] Load test with expected concurrent users
- [ ] Document disaster recovery procedures

---

## Contact Information

**Report Location:** `/ONLYOFFICE_INTEGRATION_REPORT.md`
**Test Script:** `test_onlyoffice_integration.php`
**Migration Script:** `run_onlyoffice_migration.php`

**Last Verified:** 2025-10-12
**Integration Status:** ✅ COMPLETE
