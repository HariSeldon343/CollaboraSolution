# File Manager API Documentation

## Overview

The File Manager API provides comprehensive file and folder management capabilities for CollaboraNexio, including upload, download, creation, deletion, and organization of files with full multi-tenant isolation.

## Base URL

```
Development: http://localhost:8888/CollaboraNexio/api/files/
Production: https://app.nexiosolution.it/CollaboraNexio/api/files/
```

## Authentication

All endpoints require:
- Active session (user must be logged in)
- CSRF token for state-changing operations (POST, PUT, DELETE)
- Tenant isolation (files are automatically filtered by tenant_id)

## Common Headers

```http
Content-Type: application/json
X-CSRF-Token: {csrf_token}
```

## Error Responses

All endpoints return consistent error responses:

```json
{
    "success": false,
    "error": "Error message in Italian",
    "data": {} // Optional additional error details
}
```

Common HTTP status codes:
- `200` - Success
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `409` - Conflict
- `500` - Internal Server Error

---

## Endpoints

### 1. Upload Files

Upload one or multiple files with support for large file chunking.

**Endpoint:** `POST /api/files/upload.php`

**Headers:**
```http
Content-Type: multipart/form-data
```

**Form Data:**
- `files[]` - File(s) to upload (multiple allowed)
- `folder_id` - (optional) Target folder ID, null for root
- `csrf_token` - CSRF token
- `is_chunked` - (optional) "true" for chunk upload
- `chunk_index` - (optional) Current chunk index (0-based)
- `total_chunks` - (optional) Total number of chunks
- `file_id` - (optional) Unique file ID for chunked upload

**Success Response:**
```json
{
    "success": true,
    "message": "3 file caricati con successo",
    "data": {
        "files": [
            {
                "id": 123,
                "name": "documento.docx",
                "size": 45678,
                "path": "/uploads/2/documento.docx",
                "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                "extension": "docx",
                "is_editable": true,
                "editor_format": "word",
                "thumbnail_path": null,
                "icon": "file-word",
                "formatted_size": "44.61 KB",
                "uploaded_at": "2025-01-12 10:30:00"
            }
        ],
        "errors": []
    }
}
```

**Chunked Upload Response:**
```json
{
    "success": true,
    "message": "Chunk ricevuto",
    "data": {
        "chunk_received": true,
        "chunk_index": 0,
        "total_chunks": 10,
        "file_id": "upload_5f3e4d2c1a9b8"
    }
}
```

**Restrictions:**
- Max file size: 100MB
- Chunk size: 1MB
- Allowed extensions: See `FileHelper::ALLOWED_EXTENSIONS`
- Blocked extensions: PHP, EXE, BAT, SH, etc. (executables)

**Example JavaScript:**
```javascript
// Simple upload
const formData = new FormData();
formData.append('files[]', fileInput.files[0]);
formData.append('folder_id', '5');
formData.append('csrf_token', csrfToken);

fetch('/api/files/upload.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => console.log(data));

// Chunked upload for large files
async function uploadLargeFile(file, folderId) {
    const chunkSize = 1024 * 1024; // 1MB
    const chunks = Math.ceil(file.size / chunkSize);
    const fileId = 'upload_' + Date.now();

    for (let i = 0; i < chunks; i++) {
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('file', chunk, file.name);
        formData.append('folder_id', folderId);
        formData.append('is_chunked', 'true');
        formData.append('chunk_index', i);
        formData.append('total_chunks', chunks);
        formData.append('file_id', fileId);
        formData.append('csrf_token', csrfToken);

        await fetch('/api/files/upload.php', {
            method: 'POST',
            body: formData
        });
    }
}
```

---

### 2. Create Document

Create a new empty document (DOCX, XLSX, PPTX, or TXT).

**Endpoint:** `POST /api/files/create_document.php`

**Request Body:**
```json
{
    "type": "docx",
    "name": "Nuovo Documento",
    "folder_id": null,
    "csrf_token": "..."
}
```

**Parameters:**
- `type` - Document type: "docx", "xlsx", "pptx", or "txt"
- `name` - Document name (extension added automatically)
- `folder_id` - (optional) Parent folder ID, null for root
- `csrf_token` - CSRF token

**Success Response:**
```json
{
    "success": true,
    "message": "Documento creato con successo",
    "data": {
        "file": {
            "id": 124,
            "name": "Nuovo Documento.docx",
            "path": "/uploads/2/Nuovo Documento.docx",
            "type": "docx",
            "size": 3456,
            "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "is_editable": true,
            "editor_format": "word",
            "icon": "file-word",
            "formatted_size": "3.38 KB",
            "created_at": "2025-01-12 10:35:00"
        }
    }
}
```

---

### 3. List Files

Get paginated list of files and folders with sorting and filtering.

**Endpoint:** `GET /api/files/list.php`

**Query Parameters:**
- `folder_id` - (optional) Folder ID to list contents, null for root
- `page` - (optional) Page number, default 1
- `limit` - (optional) Items per page (1-100), default 50
- `sort` - (optional) Sort by: "name", "size", "created_at", "updated_at"
- `order` - (optional) Sort order: "ASC" or "DESC"
- `search` - (optional) Search term for file names
- `file_type` - (optional) Filter by type: "document", "spreadsheet", "presentation", "image", "video", "archive"
- `show_deleted` - (optional) Include deleted files: "true" or "false"

**Success Response:**
```json
{
    "success": true,
    "message": "File caricati con successo",
    "data": {
        "files": [
            {
                "id": 123,
                "name": "report.pdf",
                "size": 234567,
                "mime_type": "application/pdf",
                "extension": "pdf",
                "path": "/uploads/2/report.pdf",
                "is_folder": false,
                "is_editable": false,
                "thumbnail_path": null,
                "uploaded_by": "Mario Rossi",
                "created_at": "2025-01-12 09:00:00",
                "updated_at": "2025-01-12 09:00:00",
                "formatted_size": "229.07 KB",
                "icon": "file-pdf",
                "item_type": "file"
            }
        ],
        "folders": [
            {
                "id": 122,
                "name": "Documenti",
                "is_folder": true,
                "created_at": "2025-01-11 14:00:00",
                "item_count": 15,
                "icon": "folder"
            }
        ],
        "breadcrumb": [
            {
                "id": null,
                "name": "I Miei File",
                "path": "/",
                "is_root": true
            },
            {
                "id": 5,
                "name": "Documenti",
                "path": "/folder/5",
                "is_root": false
            }
        ],
        "pagination": {
            "total": 45,
            "page": 1,
            "pages": 1,
            "limit": 50,
            "has_more": false
        },
        "storage": {
            "total_files": 156,
            "total_folders": 12,
            "total_size": 52428800,
            "formatted_size": "50.00 MB"
        },
        "current_folder": 5
    }
}
```

---

### 4. Create Folder

Create a new folder.

**Endpoint:** `POST /api/files/create_folder.php`

**Request Body:**
```json
{
    "name": "Documenti Aziendali",
    "parent_id": null,
    "csrf_token": "..."
}
```

**Parameters:**
- `name` - Folder name (max 100 characters)
- `parent_id` - (optional) Parent folder ID, null for root
- `csrf_token` - CSRF token

**Success Response:**
```json
{
    "success": true,
    "message": "Cartella creata con successo",
    "data": {
        "folder": {
            "id": 125,
            "name": "Documenti Aziendali",
            "path": "uploads/2/documenti_aziendali",
            "parent_id": null,
            "created_by": "Mario Rossi",
            "created_at": "2025-01-12 10:40:00",
            "item_count": 0,
            "icon": "folder"
        }
    }
}
```

---

### 5. Rename File/Folder

Rename a file or folder.

**Endpoint:** `POST /api/files/rename.php`

**Request Body:**
```json
{
    "file_id": 123,
    "new_name": "Report Annuale 2024",
    "csrf_token": "..."
}
```

**Parameters:**
- `file_id` - File or folder ID
- `new_name` - New name (extension preserved for files)
- `csrf_token` - CSRF token

**Success Response:**
```json
{
    "success": true,
    "message": "Rinominato con successo",
    "data": {
        "id": 123,
        "name": "Report Annuale 2024.pdf",
        "old_name": "report.pdf"
    }
}
```

---

### 6. Move Files/Folders

Move one or more files/folders to a new location.

**Endpoint:** `POST /api/files/move.php`

**Request Body:**
```json
{
    "file_ids": [123, 124, 125],
    "target_folder_id": 10,
    "csrf_token": "..."
}
```

**Parameters:**
- `file_ids` - Array of file/folder IDs to move
- `target_folder_id` - Target folder ID, null for root
- `csrf_token` - CSRF token

**Success Response:**
```json
{
    "success": true,
    "message": "3 elementi spostati con successo",
    "data": {
        "moved": [
            {
                "id": 123,
                "name": "report.pdf",
                "new_folder_id": 10
            }
        ],
        "errors": []
    }
}
```

**Errors:**
- Cannot move a folder into itself
- Cannot move a folder into its own subfolder
- Duplicate names in target location

---

### 7. Delete Files/Folders

Soft delete (or permanently delete for super_admin).

**Endpoint:** `POST /api/files/delete.php`

**Request Body:**
```json
{
    "file_ids": [123, 124],
    "permanent": false,
    "csrf_token": "..."
}
```

**Parameters:**
- `file_ids` - Array of file/folder IDs to delete
- `permanent` - (optional) true for permanent deletion (super_admin only)
- `csrf_token` - CSRF token

**Success Response:**
```json
{
    "success": true,
    "message": "2 elementi eliminati con successo",
    "data": {
        "deleted": [
            {
                "id": 123,
                "name": "old_report.pdf",
                "type": "file"
            },
            {
                "id": 124,
                "name": "Archivio",
                "type": "folder"
            }
        ],
        "errors": [],
        "permanent": false
    }
}
```

**Notes:**
- Soft delete sets `deleted_at` timestamp
- Deleting a folder soft deletes all its contents
- Only super_admin can permanently delete
- Permanent deletion removes physical files and database records

---

### 8. Download File

Download a file with access control.

**Endpoint:** `GET /api/files/download.php`

**Query Parameters:**
- `file_id` - File ID to download
- `thumbnail` - (optional) "true" to download thumbnail instead
- `inline` - (optional) "true" to display in browser (PDFs, images)
- `token` - (optional) Download token for external access

**Response:**
- Binary file data with appropriate headers
- Content-Type based on file MIME type
- Content-Disposition: attachment (or inline if requested)

**Example:**
```javascript
// Direct download link
window.location.href = `/api/files/download.php?file_id=123`;

// Display PDF inline
window.open(`/api/files/download.php?file_id=123&inline=true`, '_blank');

// Download thumbnail
fetch(`/api/files/download.php?file_id=123&thumbnail=true`)
    .then(response => response.blob())
    .then(blob => {
        const url = URL.createObjectURL(blob);
        // Use thumbnail URL
    });
```

**Features:**
- Range request support for video/audio streaming
- Automatic MIME type detection
- Audit logging for downloads
- Thumbnail generation for images

---

## File Helper Class

The `FileHelper` class (`/includes/file_helper.php`) provides utility functions:

### Key Methods:

```php
// Get MIME type from file
FileHelper::getMimeType($filePath)

// Check if file is editable in OnlyOffice
FileHelper::isEditable($extension)

// Get editor format for OnlyOffice
FileHelper::getEditorFormat($mimeType)

// Generate safe filename
FileHelper::generateSafeFilename($originalName, $folderPath)

// Format file size
FileHelper::formatFileSize($bytes)

// Validate file extension
FileHelper::isAllowedExtension($extension)

// Create image thumbnail
FileHelper::createThumbnail($sourcePath, $thumbnailPath, $maxWidth, $maxHeight)

// Validate uploaded file
FileHelper::validateUploadedFile($file)

// Get tenant upload path
FileHelper::getTenantUploadPath($tenantId, $subFolder)

// Get file icon
FileHelper::getFileIcon($extension)

// Calculate file hash
FileHelper::getFileHash($filePath)

// Check if file is image
FileHelper::isImage($mimeType)

// Get image dimensions
FileHelper::getImageDimensions($filePath)
```

---

## Security Features

1. **CSRF Protection**: All POST requests require valid CSRF token
2. **Tenant Isolation**: Files are automatically filtered by tenant_id
3. **Extension Validation**: Whitelist/blacklist of file extensions
4. **MIME Type Validation**: Additional security check beyond extension
5. **Path Traversal Prevention**: Sanitization of file/folder names
6. **Size Limits**: 100MB max file size
7. **Access Control**: Users can only access their tenant's files
8. **Soft Delete**: Files are marked as deleted, not physically removed
9. **Audit Logging**: All file operations are logged

---

## Integration with Document Editor

Files marked as `is_editable` can be opened in OnlyOffice editor:

1. Upload or create a document
2. Check `is_editable` flag in response
3. Use `/api/documents/open_document.php` to open in editor
4. Editor format determines OnlyOffice document type:
   - `word` - Word processor
   - `cell` - Spreadsheet
   - `slide` - Presentation

---

## File Organization

Files are organized in this structure:
```
/uploads/
  /{tenant_id}/
    /file1.pdf
    /file2.docx
    /folder1/
      /subfolder/
        /file3.xlsx
    /thumbnails/
      /image1_thumb.jpg
```

---

## Error Handling

Common error scenarios:

1. **File Too Large**: Return 400 with size limit message
2. **Invalid Extension**: Return 400 with allowed extensions
3. **Duplicate Name**: Return 409 conflict
4. **Folder Not Found**: Return 404
5. **Access Denied**: Return 403 for cross-tenant access
6. **Disk Full**: Return 507 insufficient storage
7. **Invalid CSRF**: Return 403 forbidden

---

## Performance Considerations

1. **Chunked Upload**: Use for files > 10MB
2. **Pagination**: Limit file lists to 50-100 items
3. **Thumbnails**: Generated asynchronously for images
4. **Caching**: File metadata cached in session
5. **Indexes**: Database indexed on tenant_id, folder_id, deleted_at

---

## Testing

Test scenarios to verify:

1. **Upload**: Single file, multiple files, large file with chunks
2. **Create**: New documents of each type
3. **List**: Pagination, sorting, filtering, search
4. **Folders**: Create, rename, move, delete with contents
5. **Security**: Cross-tenant access, CSRF validation
6. **Edge Cases**: Special characters, long names, deep nesting

Example test commands:
```bash
# Test upload
curl -X POST http://localhost:8888/CollaboraNexio/api/files/upload.php \
  -H "Cookie: COLLAB_SID=..." \
  -F "files[]=@test.pdf" \
  -F "folder_id=5" \
  -F "csrf_token=..."

# Test list
curl "http://localhost:8888/CollaboraNexio/api/files/list.php?folder_id=5&sort=name&order=ASC" \
  -H "Cookie: COLLAB_SID=..."

# Test create document
curl -X POST http://localhost:8888/CollaboraNexio/api/files/create_document.php \
  -H "Cookie: COLLAB_SID=..." \
  -H "Content-Type: application/json" \
  -d '{"type":"docx","name":"Test Document","csrf_token":"..."}'
```

---

## Migration

To apply database changes:

```bash
mysql -u root collaboranexio < database/migrations/files_upload_system.sql
```

This migration adds:
- Missing columns to files table
- document_editor table
- document_versions table
- editor_sessions table
- Required indexes and foreign keys

---

## Support

For issues or questions about the File Manager API:
1. Check error logs in `/logs/`
2. Verify database structure matches migration
3. Ensure upload directory has write permissions
4. Check PHP upload limits in php.ini