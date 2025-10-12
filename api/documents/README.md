# Document Editor API - OnlyOffice Integration

## Overview

This API layer provides integration with OnlyOffice Document Server Community Edition for collaborative document editing within CollaboraNexio.

**Version:** 1.0.0
**Date:** 2025-10-12
**OpenSpec:** COLLAB-2025-003

---

## Architecture

```
┌─────────────────────────────────────────────┐
│         CollaboraNexio (Port 8888)          │
│              PHP + MySQL                     │
└──────────────┬──────────────────────────────┘
               │
               │ API REST + JWT Auth
               │
┌──────────────┴──────────────────────────────┐
│    OnlyOffice Document Server (Port 8080)   │
│          Node.js + RabbitMQ                  │
└──────────────┬──────────────────────────────┘
               │
               │ Shared Storage
               │
┌──────────────┴──────────────────────────────┐
│        File Storage (uploads/)              │
│     Organizzato per tenant_id/folder        │
└─────────────────────────────────────────────┘
```

---

## API Endpoints

### 1. **GET /api/documents/open_document.php**

Opens a document in OnlyOffice editor and returns configuration.

**Authentication:** Required (Session + CSRF)
**Permission:** User must have access to the file's tenant

**Parameters:**
- `file_id` (int, required) - ID of the file to open
- `mode` (string, optional) - Editor mode: 'edit' or 'view' (default: 'edit')

**Response:**
```json
{
    "success": true,
    "message": "Documento aperto con successo",
    "data": {
        "editor_url": "http://localhost:8080",
        "api_url": "http://localhost:8080/web-apps/apps/api/documents/api.js",
        "document_key": "file_123_v5_20251012143022",
        "file_url": "https://app.nexiosolution.it/api/documents/download_for_editor.php?file_id=123&token=...",
        "callback_url": "https://app.nexiosolution.it/api/documents/save_document.php?key=file_123_v5_20251012143022",
        "session_token": "a1b2c3d4...",
        "mode": "edit",
        "user": {
            "id": 5,
            "name": "Mario Rossi"
        },
        "permissions": {
            "comment": true,
            "download": true,
            "edit": true,
            "fillForms": true,
            "modifyContentControl": false,
            "modifyFilter": true,
            "print": true,
            "review": true
        },
        "config": {
            "documentType": "word",
            "document": {
                "fileType": "docx",
                "key": "file_123_v5_20251012143022",
                "title": "Documento Importante.docx",
                "url": "...",
                "permissions": {...}
            },
            "editorConfig": {
                "mode": "edit",
                "lang": "it",
                "region": "it-IT",
                "user": {...},
                "customization": {...}
            }
        },
        "token": "eyJhbGc...",
        "collaborators": [],
        "file_info": {
            "id": 123,
            "name": "Documento Importante.docx",
            "size": 45632,
            "type": "word",
            "extension": "docx",
            "version": 6
        }
    }
}
```

**Errors:**
- `400` - Invalid parameters
- `401` - Not authenticated
- `403` - No access to file or CSRF token invalid
- `404` - File not found

---

### 2. **POST /api/documents/save_document.php**

Callback endpoint called by OnlyOffice to save document changes.

**Authentication:** JWT token in Authorization header
**Called by:** OnlyOffice Document Server

**Request Body (from OnlyOffice):**
```json
{
    "key": "file_123_v5_20251012143022",
    "status": 2,
    "url": "http://localhost:8080/cache/files/document.docx",
    "changesurl": "http://localhost:8080/cache/files/changes.zip",
    "history": {...},
    "users": ["user5"],
    "actions": [...]
}
```

**Status Codes:**
- `0` - No document found
- `1` - Document is being edited
- `2` - Document is ready for saving
- `3` - Document saving error
- `4` - Document closed with no changes
- `6` - Document being edited, force save
- `7` - Error during force save

**Response:**
```json
{
    "error": 0
}
```

**Errors:**
```json
{
    "error": 1,
    "message": "Error description"
}
```

---

### 3. **POST /api/documents/close_session.php**

Manually closes an editing session.

**Authentication:** Required (Session + CSRF)
**Permission:** User must own the session or be manager+

**Request Body:**
```json
{
    "session_token": "a1b2c3d4...",
    "file_id": 123,
    "changes_saved": true,
    "force_close": false
}
```

**Response:**
```json
{
    "success": true,
    "message": "Sessione chiusa con successo",
    "data": {
        "closed": true,
        "session_info": {
            "session_id": 45,
            "file_id": 123,
            "file_name": "Documento.docx",
            "duration": {
                "hours": 0,
                "minutes": 15,
                "seconds": 32,
                "total_seconds": 932
            },
            "changes_saved": true,
            "closed_at": "2025-10-12 14:45:22"
        },
        "active_sessions_remaining": 0,
        "active_users": [],
        "file_status_updated": true
    }
}
```

---

### 4. **GET /api/documents/get_editor_config.php**

Gets complete editor configuration for a file.

**Authentication:** Required (Session)
**CSRF:** Optional for GET

**Parameters:**
- `file_id` (int, required) - ID of the file

**Response:**
```json
{
    "success": true,
    "message": "Configurazione editor ottenuta con successo",
    "data": {
        "width": "100%",
        "height": "100%",
        "documentType": "word",
        "type": "desktop",
        "events": {
            "onAppReady": "onAppReady",
            "onDocumentReady": "onDocumentReady",
            "onError": "onError",
            ...
        },
        "file": {
            "id": 123,
            "name": "Documento.docx",
            "extension": "docx",
            "size": 45632,
            "version": 6,
            "status": "in_approvazione"
        },
        "user": {
            "id": 5,
            "name": "Mario Rossi",
            "email": "mario.rossi@example.com",
            "role": "manager"
        },
        "permissions": {...},
        "features": {
            "collaboration": true,
            "comments": true,
            "review": true,
            "autosave": true,
            "download": true
        },
        "sessions": {
            "has_active": false,
            "is_user_editing": false,
            "active_count": 0,
            "active_users": []
        },
        "ui": {
            "logo": "...",
            "lang": "it",
            "theme": "light",
            ...
        },
        "api": {
            "server_url": "http://localhost:8080",
            "api_url": "...",
            "open_url": "...",
            "close_url": "...",
            "callback_url": "..."
        },
        "metadata": {
            "can_edit": true,
            "is_view_only": false,
            "jwt_enabled": true
        }
    }
}
```

---

### 5. **GET /api/documents/download_for_editor.php**

Secure file download endpoint for OnlyOffice.

**Authentication:** JWT token in query string
**Called by:** OnlyOffice Document Server

**Parameters:**
- `file_id` (int, required) - ID of the file
- `token` (string, required) - JWT token with download permission

**Response:**
- Binary file content with appropriate headers
- Support for HTTP Range requests (partial downloads)

**Headers Sent:**
```
Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document
Content-Length: 45632
Content-Disposition: attachment; filename="Documento.docx"
Cache-Control: private, max-age=3600
```

---

## Permission System

Permissions are role-based:

| Role | View | Edit | Download | Print | Review | Comment |
|------|------|------|----------|-------|--------|---------|
| user | ✅ | ❌* | ✅ | ✅ | ❌ | ✅ |
| manager | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| super_admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

*Users can edit their own files if they are the uploader

---

## Database Schema

### document_editor_sessions

Tracks active editing sessions.

```sql
CREATE TABLE document_editor_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    editor_key VARCHAR(255) NOT NULL,
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    changes_saved BOOLEAN DEFAULT FALSE,
    -- Indexes and foreign keys...
);
```

### Files Table Updates

New columns added:
- `is_editable` - Whether file can be edited in OnlyOffice
- `editor_format` - OnlyOffice format (word/cell/slide)
- `last_edited_by` - User who last edited
- `last_edited_at` - Last edit timestamp
- `version` - Document version number

---

## Security

### Authentication Flow

1. User clicks "Edit" on file
2. CollaboraNexio verifies:
   - User is authenticated
   - File belongs to user's tenant
   - User has edit permission
3. Generate JWT token for session
4. OnlyOffice validates JWT token
5. Editor opens with appropriate permissions
6. On save, OnlyOffice sends callback with JWT token
7. CollaboraNexio validates token and saves file

### JWT Token Structure

```json
{
    "iss": "CollaboraNexio",
    "aud": "OnlyOffice",
    "exp": 1697112000,
    "iat": 1697108400,
    "file_id": 123,
    "user_id": 5,
    "tenant_id": 2,
    "session_token": "a1b2c3d4...",
    "permissions": {
        "edit": true,
        "download": true,
        "print": true
    }
}
```

### Tenant Isolation

All queries include `WHERE tenant_id = ? AND deleted_at IS NULL` to ensure:
- Users can only access files in their tenant
- Soft-deleted files are not accessible
- Cross-tenant access is prevented

---

## Frontend Integration Example

```javascript
// Open document in editor
async function openDocument(fileId) {
    try {
        const response = await fetch(
            `/api/documents/open_document.php?file_id=${fileId}`,
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': getCsrfToken()
                }
            }
        );

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error);
        }

        // Initialize OnlyOffice editor
        const editor = new DocsAPI.DocEditor("editor-container", {
            ...result.data.config,
            token: result.data.token,
            events: {
                onDocumentReady: () => {
                    console.log('Document ready for editing');
                },
                onError: (event) => {
                    console.error('Editor error:', event);
                }
            }
        });

        return editor;
    } catch (error) {
        console.error('Failed to open document:', error);
        throw error;
    }
}

// Close editor session
async function closeEditor(sessionToken, changesSaved) {
    const response = await fetch('/api/documents/close_session.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({
            session_token: sessionToken,
            changes_saved: changesSaved
        })
    });

    return await response.json();
}
```

---

## Configuration

Edit `/includes/onlyoffice_config.php` to customize:

```php
// OnlyOffice Server URL
define('ONLYOFFICE_SERVER_URL', 'http://localhost:8080');

// JWT Secret (CHANGE IN PRODUCTION!)
define('ONLYOFFICE_JWT_SECRET', 'your-secure-secret-key-here');

// Session timeouts
define('ONLYOFFICE_SESSION_TIMEOUT', 3600); // 1 hour
define('ONLYOFFICE_IDLE_TIMEOUT', 1800); // 30 minutes

// Features
define('ONLYOFFICE_ENABLE_COLLABORATION', true);
define('ONLYOFFICE_ENABLE_COMMENTS', true);
define('ONLYOFFICE_ENABLE_REVIEW', true);
```

---

## Maintenance

### Cleanup Expired Sessions

Run periodically (recommended: hourly):

```php
// In a cron job or scheduled task
require_once 'includes/document_editor_helper.php';
cleanupExpiredSessions();
```

Or use MySQL event (automatically runs every hour):

```sql
CALL cleanup_expired_editor_sessions(2); -- 2 hours old
```

---

## Troubleshooting

### Editor Not Loading

1. Check OnlyOffice server is running: `curl http://localhost:8080`
2. Verify JWT secret matches between CollaboraNexio and OnlyOffice
3. Check browser console for JavaScript errors
4. Review logs: `/logs/php_errors.log`

### Save Not Working

1. Check callback URL is accessible from OnlyOffice server
2. Verify JWT token in callback request
3. Check file permissions on upload directory
4. Review OnlyOffice logs

### Permission Denied

1. Verify user has access to tenant
2. Check user role has edit permission
3. Ensure file is not marked as view-only
4. Check `is_editable` flag on file

---

## Supported File Formats

### Editable:
- Word: doc, docx, docm, dot, dotx, dotm, odt, fodt, ott, rtf, txt
- Excel: xls, xlsx, xlsm, xlt, xltx, xltm, ods, fods, ots, csv
- PowerPoint: ppt, pptx, pptm, pot, potx, potm, odp, fodp, otp

### View-Only:
- PDF, DJVU, XPS, EPUB, FB2

---

## License

This integration uses OnlyOffice Document Server Community Edition (AGPLv3).

---

## Support

For issues or questions:
- Email: support@nexiosolution.it
- Internal docs: See OpenSpec COLLAB-2025-003