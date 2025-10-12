# Document Editor API - Quick Start Guide

## 🚀 Getting Started in 5 Minutes

### Step 1: Run Database Migration

```bash
# Connect to MySQL
mysql -u root -p collaboranexio

# Run the migration
source /path/to/CollaboraNexio/database/migrations/006_document_editor.sql

# Verify tables created
SHOW TABLES LIKE 'document_editor%';
```

**Expected Output:**
```
document_editor_sessions
document_editor_locks
document_editor_changes
```

---

### Step 2: Configure OnlyOffice Connection

Edit `/includes/onlyoffice_config.php`:

```php
// Change these values for your environment
define('ONLYOFFICE_SERVER_URL', 'http://YOUR_SERVER:8080');
define('ONLYOFFICE_JWT_SECRET', 'CHANGE-THIS-TO-A-SECURE-SECRET-KEY');
```

**Generate a secure JWT secret:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

---

### Step 3: Test the Installation

Open in browser:
```
http://localhost:8888/CollaboraNexio/test_document_editor_api.php
```

**Checklist:**
- ✅ Configuration shows correct URLs
- ✅ All database tables exist
- ✅ JWT generation/verification works
- ✅ File list shows available documents

---

### Step 4: Test Opening a Document

1. Upload a `.docx`, `.xlsx`, or `.pptx` file via the Files page
2. In the test page, click "Test Open" next to a file
3. Check the API response for success
4. Verify you get a `document_key` and `token`

**Expected Response:**
```json
{
    "success": true,
    "data": {
        "document_key": "file_123_v1_20251012...",
        "mode": "edit",
        "config": {...}
    }
}
```

---

### Step 5: Integrate in Your Frontend

#### HTML Structure
```html
<div id="editor-container" style="width: 100%; height: 600px;"></div>
<script src="http://YOUR_SERVER:8080/web-apps/apps/api/documents/api.js"></script>
```

#### JavaScript Integration
```javascript
async function openDocumentEditor(fileId) {
    // Get configuration from API
    const response = await fetch(
        `/api/documents/open_document.php?file_id=${fileId}`,
        {
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': document.querySelector('[name="csrf_token"]').value
            }
        }
    );

    const result = await response.json();
    if (!result.success) {
        alert('Error: ' + result.error);
        return;
    }

    // Initialize OnlyOffice editor
    const config = {
        ...result.data.config,
        token: result.data.token,
        events: {
            onDocumentReady: () => {
                console.log('Document is ready for editing');
            },
            onError: (event) => {
                console.error('Editor error:', event.data);
            }
        }
    };

    const editor = new DocsAPI.DocEditor("editor-container", config);

    return editor;
}

// Usage
document.getElementById('edit-button').addEventListener('click', () => {
    openDocumentEditor(123); // Replace with actual file ID
});
```

---

## 🔧 Common Configuration

### Update BASE_URL

Ensure your `/config.php` has the correct BASE_URL:
```php
define('BASE_URL', 'https://app.nexiosolution.it');
```

### Configure Upload Path

Check that `UPLOAD_PATH` is writable:
```php
define('UPLOAD_PATH', __DIR__ . '/uploads');
```

Create version storage directory:
```bash
mkdir -p uploads/versions
chmod 755 uploads/versions
```

---

## 📝 Testing Workflow

### Test Open Document
```bash
curl -X GET \
  'http://localhost:8888/CollaboraNexio/api/documents/open_document.php?file_id=1' \
  -H 'Cookie: PHPSESSID=your_session_id' \
  -H 'X-CSRF-Token: your_csrf_token'
```

### Test Get Config
```bash
curl -X GET \
  'http://localhost:8888/CollaboraNexio/api/documents/get_editor_config.php?file_id=1' \
  -H 'Cookie: PHPSESSID=your_session_id'
```

### Test Close Session
```bash
curl -X POST \
  'http://localhost:8888/CollaboraNexio/api/documents/close_session.php' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: PHPSESSID=your_session_id' \
  -H 'X-CSRF-Token: your_csrf_token' \
  -d '{
    "file_id": 1,
    "changes_saved": true
  }'
```

---

## 🐛 Troubleshooting

### Issue: "Token non valido"

**Solution:**
1. Check JWT secret matches in both systems
2. Verify token expiration hasn't passed
3. Check token format is correct (3 parts separated by dots)

```php
// Test JWT generation
$payload = ['test' => 'data'];
$token = generateOnlyOfficeJWT($payload);
echo "Token: $token\n";

$verified = verifyOnlyOfficeJWT($token);
var_dump($verified);
```

---

### Issue: "File non trovato"

**Solution:**
1. Verify file exists in database: `SELECT * FROM files WHERE id = X`
2. Check tenant_id matches current user's tenant
3. Ensure file is not soft-deleted: `deleted_at IS NULL`
4. Verify physical file exists in uploads directory

---

### Issue: "Permessi insufficienti"

**Solution:**
1. Check user role: `SELECT role FROM users WHERE id = X`
2. Verify permission mapping in `onlyoffice_config.php`
3. For basic users, ensure they own the file:
   ```sql
   SELECT * FROM files WHERE id = X AND uploaded_by = Y
   ```

---

### Issue: Editor doesn't load

**Solution:**
1. **Check OnlyOffice server:**
   ```bash
   curl http://localhost:8080/healthcheck
   ```

2. **Verify CORS headers** if on different domain

3. **Check browser console** for JavaScript errors

4. **Verify API script loads:**
   ```html
   <script src="http://YOUR_SERVER:8080/web-apps/apps/api/documents/api.js"></script>
   ```

---

### Issue: Save callback fails

**Solution:**
1. **Check callback URL is accessible from OnlyOffice server:**
   ```bash
   # From OnlyOffice server
   curl https://app.nexiosolution.it/api/documents/save_document.php
   ```

2. **Verify JWT token in callback request**

3. **Check file write permissions:**
   ```bash
   ls -l /path/to/uploads/tenant_id/
   ```

4. **Review OnlyOffice logs:**
   ```bash
   docker logs onlyoffice-document-server
   ```

---

## 📊 Monitoring Active Sessions

### Via Database
```sql
-- Active sessions
SELECT s.*, u.name as user_name, f.name as file_name
FROM document_editor_sessions s
JOIN users u ON s.user_id = u.id
JOIN files f ON s.file_id = f.id
WHERE s.closed_at IS NULL
AND s.deleted_at IS NULL
ORDER BY s.last_activity DESC;

-- Session statistics
SELECT
    tenant_id,
    COUNT(*) as total_sessions,
    SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) as active_sessions,
    AVG(TIMESTAMPDIFF(MINUTE, opened_at, COALESCE(closed_at, NOW()))) as avg_duration_minutes
FROM document_editor_sessions
WHERE deleted_at IS NULL
GROUP BY tenant_id;
```

### Via Stored Procedure
```sql
CALL get_active_editor_sessions(1); -- Replace 1 with tenant_id
```

---

## 🔄 Maintenance Tasks

### Daily: Clean up expired sessions
```sql
CALL cleanup_expired_editor_sessions(2); -- Sessions older than 2 hours
```

### Weekly: Check disk space
```bash
du -sh uploads/
du -sh uploads/versions/
```

### Monthly: Archive old sessions
```sql
-- Soft delete sessions older than 90 days
UPDATE document_editor_sessions
SET deleted_at = NOW()
WHERE deleted_at IS NULL
AND opened_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## 🎯 Performance Tips

### 1. Enable Query Cache
```sql
SET GLOBAL query_cache_size = 1000000;
SET GLOBAL query_cache_type = ON;
```

### 2. Index Optimization
```sql
-- Check index usage
SHOW INDEX FROM document_editor_sessions;

-- Analyze table
ANALYZE TABLE document_editor_sessions;
```

### 3. PHP Configuration
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
```

### 4. Connection Pooling
Already implemented via Database singleton in `/includes/db.php`

---

## ✅ Checklist for Production

- [ ] Change JWT secret from default
- [ ] Configure HTTPS for all endpoints
- [ ] Set up automatic session cleanup (cron/event)
- [ ] Configure backup for uploads directory
- [ ] Set up monitoring for OnlyOffice server
- [ ] Test with large files (50MB+)
- [ ] Verify multi-tenant isolation
- [ ] Test concurrent editing (2+ users)
- [ ] Review audit logs
- [ ] Document server firewall rules
- [ ] Set up error alerting
- [ ] Test disaster recovery

---

## 📚 Additional Resources

### Documentation
- **Full API Docs:** `/api/documents/README.md`
- **Implementation Summary:** `/DOCUMENT_EDITOR_IMPLEMENTATION_SUMMARY.md`
- **OpenSpec:** `/openspec/changes/003-document-editor-integration.md`

### Testing
- **Test Suite:** `/test_document_editor_api.php`
- **Database Migration:** `/database/migrations/006_document_editor.sql`

### Code Examples
- **Authentication Pattern:** `/api/files_tenant.php`
- **Helper Functions:** `/includes/document_editor_helper.php`
- **Configuration:** `/includes/onlyoffice_config.php`

---

## 🆘 Support

### Internal
- Review logs: `/logs/php_errors.log`, `/logs/database_errors.log`
- Check audit trail: `SELECT * FROM audit_logs WHERE entity_type = 'document'`
- Test endpoints: `/test_document_editor_api.php`

### OnlyOffice
- Documentation: https://api.onlyoffice.com/editors/basic
- Community: https://forum.onlyoffice.com
- GitHub Issues: https://github.com/ONLYOFFICE/DocumentServer/issues

---

## 🎉 Success Criteria

You'll know it's working when:
1. ✅ Test page shows all green checkmarks
2. ✅ Can open a DOCX file in editor
3. ✅ Can edit and save changes
4. ✅ Changes persist in database
5. ✅ Version history is tracked
6. ✅ Multiple users can collaborate
7. ✅ Audit logs show all operations

---

**Ready to start?** Open `/test_document_editor_api.php` and begin testing!

**Need help?** Check the troubleshooting section or review the full documentation.

**Questions?** Email: support@nexiosolution.it