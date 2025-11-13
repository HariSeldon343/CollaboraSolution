# CollaboraNexio Document Editor - Frontend Integration

## Overview

The CollaboraNexio Document Editor is a professional, enterprise-grade integration with OnlyOffice Document Server Community Edition. It provides seamless in-browser document editing capabilities with full collaboration support, Italian language, and role-based permissions.

**Version:** 1.0.0
**Date:** 2025-10-12
**OpenSpec:** COLLAB-2025-003

---

## Features

### Core Functionality
- ✅ **Full-Screen Modal Editor** - Distraction-free editing experience
- ✅ **Real-Time Collaboration** - Multiple users can edit simultaneously
- ✅ **Auto-Save** - Automatic document saving every 30 seconds
- ✅ **Role-Based Permissions** - Enforced user/manager/admin/super_admin permissions
- ✅ **Multi-Tenant Isolation** - Complete data segregation between tenants
- ✅ **Italian Language** - Complete UI and messages in Italian
- ✅ **Responsive Design** - Works on desktop, tablet, and mobile devices

### Supported File Formats

#### Editable Formats:
- **Word Processing:** DOCX, DOC, DOCM, DOT, DOTX, DOTM, ODT, FODT, RTF, TXT
- **Spreadsheets:** XLSX, XLS, XLSM, XLT, XLTX, XLTM, ODS, FODS, CSV
- **Presentations:** PPTX, PPT, PPTM, POT, POTX, POTM, ODP, FODP

#### View-Only Formats:
- **Documents:** PDF, DJVU, XPS, EPUB, FB2

---

## Architecture

```
┌─────────────────────────────────────────────┐
│         CollaboraNexio (Port 8888)          │
│         files.php + documentEditor.js       │
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

## Installation

### Prerequisites

1. **OnlyOffice Document Server** running on `http://localhost:8080`
2. **CollaboraNexio Backend API** with document endpoints configured
3. **Modern Browser** (Chrome 90+, Firefox 88+, Safari 14+, Edge 90+)

### Integration Steps

#### 1. Include CSS

Add to the `<head>` section of your page:

```html
<link rel="stylesheet" href="assets/css/documentEditor.css">
```

#### 2. Include JavaScript

Add before closing `</body>` tag:

```html
<script src="assets/js/documentEditor.js"></script>
```

#### 3. Required Hidden Inputs

Ensure these hidden inputs exist on your page:

```html
<input type="hidden" id="csrfToken" value="<?php echo $csrfToken; ?>">
<input type="hidden" id="userRole" value="<?php echo $currentUser['role']; ?>">
```

#### 4. Integration with File Manager

The document editor automatically integrates with the file manager. Edit buttons will be added to compatible file cards.

---

## Usage

### Basic Usage

Open a document programmatically:

```javascript
// Open document in edit mode
window.documentEditor.openDocument(fileId, 'edit');

// Open document in view-only mode
window.documentEditor.openDocument(fileId, 'view');
```

### Integration with File Cards

The editor automatically adds "Modifica" buttons to file cards. To manually add buttons:

```javascript
// Called automatically by fileManager.js
window.addDocumentEditorButtons(fileElement, fileData);
```

Example file data structure:

```javascript
const fileData = {
    id: 123,
    name: 'Documento.docx',
    type: 'file',
    uploaded_by: 5,
    current_user_id: 5, // Current logged-in user
    tenant_id: 2
};
```

### Event Handling

The editor handles all events internally, but you can access the state:

```javascript
// Check if editor is open
if (window.documentEditor.state.isEditorOpen) {
    console.log('Editor is currently open');
}

// Get current file ID
const fileId = window.documentEditor.state.currentFileId;

// Check for unsaved changes
const hasChanges = window.documentEditor.state.hasUnsavedChanges;
```

---

## API Reference

### DocumentEditor Class

#### Constructor

```javascript
new DocumentEditor(options)
```

**Options:**
- `apiBaseUrl` (string) - Base URL for API endpoints (default: `/api/documents`)
- `onlyOfficeApiUrl` (string) - OnlyOffice API script URL (default: `http://localhost:8080/web-apps/apps/api/documents/api.js`)
- `autoSaveInterval` (number) - Auto-save interval in milliseconds (default: `30000`)
- `csrfToken` (string) - CSRF token for API requests
- `userRole` (string) - Current user role

#### Methods

##### openDocument(fileId, mode)

Opens a document in the editor.

**Parameters:**
- `fileId` (number) - ID of the file to open
- `mode` (string) - Editor mode: `'edit'` or `'view'` (default: `'edit'`)

**Returns:** `Promise<void>`

**Example:**
```javascript
window.documentEditor.openDocument(123, 'edit')
    .then(() => console.log('Document opened'))
    .catch(error => console.error('Error:', error));
```

##### closeEditor(force)

Closes the editor.

**Parameters:**
- `force` (boolean) - Force close without confirmation (default: `false`)

**Returns:** `Promise<void>`

**Example:**
```javascript
// Close with confirmation if unsaved changes
window.documentEditor.closeEditor();

// Force close without confirmation
window.documentEditor.closeEditor(true);
```

##### showToast(message, type)

Shows a toast notification.

**Parameters:**
- `message` (string) - Message to display
- `type` (string) - Type: `'success'`, `'error'`, `'warning'`, `'info'`

**Example:**
```javascript
window.documentEditor.showToast('Documento salvato con successo', 'success');
```

##### isFileEditable(filename)

Checks if a file can be edited based on its extension.

**Parameters:**
- `filename` (string) - Filename with extension

**Returns:** `boolean`

**Example:**
```javascript
if (window.documentEditor.isFileEditable('document.docx')) {
    console.log('File is editable');
}
```

##### getDocumentType(filename)

Gets the OnlyOffice document type from filename.

**Parameters:**
- `filename` (string) - Filename with extension

**Returns:** `string` - Document type: `'word'`, `'cell'`, or `'slide'`

**Example:**
```javascript
const type = window.documentEditor.getDocumentType('spreadsheet.xlsx');
console.log(type); // 'cell'
```

---

## Permissions System

### Role-Based Access Control

| Role | View | Edit | Download | Print | Review | Comment |
|------|------|------|----------|-------|--------|---------|
| `user` | ✅ | ❌* | ✅ | ✅ | ❌ | ✅ |
| `manager` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `admin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `super_admin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

*Users can edit their own files (files they uploaded)

### Permission Enforcement

Permissions are enforced at multiple levels:

1. **Frontend:** Edit buttons only shown for users with permissions
2. **API:** Backend validates user permissions before opening document
3. **OnlyOffice:** Document permissions sent to OnlyOffice editor

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `ESC` | Close editor (with confirmation if unsaved changes) |
| `Ctrl+S` / `Cmd+S` | Save document (handled by OnlyOffice) |

---

## Error Handling

### Error Types

The editor handles these error scenarios gracefully:

1. **OnlyOffice Server Offline**
   - Message: "Impossibile caricare l'API di OnlyOffice. Verifica che il server sia attivo."
   - Action: Shows error toast, prevents editor from opening

2. **File Not Found**
   - Message: "File non trovato"
   - Action: Shows error toast, closes editor

3. **Permission Denied**
   - Message: "Permessi insufficienti"
   - Action: Shows error toast, prevents editor from opening

4. **Network Timeout**
   - Message: "Timeout durante il caricamento del documento"
   - Action: Shows error toast, closes editor

5. **Unsupported Format**
   - Message: "Formato file non supportato"
   - Action: Shows error toast, prevents editor from opening

### Error Messages

All error messages are in Italian and actionable:

```javascript
// Error format codes from OnlyOffice
const errorMessages = {
    '-1': 'Errore sconosciuto durante il caricamento dell\'editor',
    '-2': 'Timeout durante la conversione del documento',
    '-3': 'Errore di conversione del documento',
    '-4': 'Errore durante il download del documento per la modifica',
    '-5': 'Formato file non supportato',
    '-6': 'Errore nel caricamento del file',
    '-8': 'Formato file non corretto o documento corrotto'
};
```

---

## Styling & Customization

### CSS Custom Properties

The editor respects CollaboraNexio's design system. You can customize colors by overriding CSS variables:

```css
:root {
    --color-primary: #2563EB;
    --color-success: #10B981;
    --color-warning: #F59E0B;
    --color-error: #EF4444;
}
```

### Custom Styling

To override editor styles, use specific selectors:

```css
/* Custom header background */
.document-editor-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* Custom close button */
.editor-close-btn {
    background: rgba(255, 255, 255, 0.2);
}

/* Custom save status colors */
.editor-save-status.status-saved {
    color: #10B981;
}
```

---

## Testing

### Manual Testing Page

Access the test page at:

```
http://localhost:8888/CollaboraNexio/test_document_editor_frontend.html
```

### Test Checklist

#### Basic Functionality
- [ ] Editor opens in full-screen modal
- [ ] Document loads and displays correctly
- [ ] Content can be edited
- [ ] Changes auto-save
- [ ] Close button works
- [ ] ESC key closes editor (with confirmation)
- [ ] Save status indicator updates correctly

#### Permissions
- [ ] User role: view-only mode
- [ ] Manager role: full edit access
- [ ] Admin role: full edit access
- [ ] Super admin: full edit access

#### File Formats
- [ ] DOCX (Word) works correctly
- [ ] XLSX (Excel) works correctly
- [ ] PPTX (PowerPoint) works correctly
- [ ] ODT (OpenDocument) works correctly
- [ ] TXT works correctly
- [ ] PDF view-only works

#### Error Handling
- [ ] File not found error handled
- [ ] Permission denied error handled
- [ ] OnlyOffice offline error handled
- [ ] Error messages in Italian

#### Responsive Design
- [ ] Desktop (1920x1080) works
- [ ] Tablet (768px) works
- [ ] Mobile (375px) works

### Automated Testing

Run the test suite:

```bash
# Open browser console on test page
# Run commands in console

// Test file detection
console.log(window.documentEditor.isFileEditable('test.docx')); // true
console.log(window.documentEditor.isFileEditable('test.pdf')); // false

// Test document type detection
console.log(window.documentEditor.getDocumentType('doc.xlsx')); // 'cell'
console.log(window.documentEditor.getDocumentType('doc.pptx')); // 'slide'

// Test error handling
window.documentEditor.showToast('Test error', 'error');
```

---

## Troubleshooting

### Editor Not Loading

**Problem:** Editor doesn't open or shows blank screen

**Solutions:**
1. Check OnlyOffice server is running: `curl http://localhost:8080`
2. Open browser console and check for JavaScript errors
3. Verify OnlyOffice API script loaded: `console.log(window.DocsAPI)`
4. Check CSRF token is present: `console.log(document.getElementById('csrfToken').value)`

### Save Not Working

**Problem:** Changes don't persist after closing editor

**Solutions:**
1. Check callback URL is accessible from OnlyOffice server
2. Verify JWT token configuration matches between frontend and backend
3. Check file permissions on upload directory
4. Review PHP error logs: `/logs/php_errors.log`

### Permission Denied

**Problem:** User can't edit documents they should have access to

**Solutions:**
1. Verify user role: `console.log(document.getElementById('userRole').value)`
2. Check user has access to tenant in database
3. Verify file ownership in database
4. Check `is_editable` flag on file record

### OnlyOffice Connection Failed

**Problem:** "Impossibile caricare l'API di OnlyOffice" error

**Solutions:**
1. Verify OnlyOffice server is running on port 8080
2. Check CORS configuration in OnlyOffice
3. Verify network connectivity between browser and OnlyOffice
4. Check firewall settings

---

## Performance Optimization

### Loading Performance

The editor is optimized for fast loading:

1. **Lazy Loading:** OnlyOffice API script loads on-demand
2. **Minimal CSS:** Only 500 lines of optimized CSS
3. **No Dependencies:** Pure vanilla JavaScript, no frameworks
4. **Efficient DOM:** Modal created only when needed

### Runtime Performance

1. **Auto-Save Throttling:** Saves at most every 30 seconds
2. **Event Debouncing:** State changes debounced to prevent excessive updates
3. **Memory Management:** Editor instance properly destroyed on close
4. **Network Optimization:** JWT tokens cached, minimal API calls

---

## Browser Compatibility

### Supported Browsers

| Browser | Minimum Version | Notes |
|---------|----------------|-------|
| Chrome | 90+ | Full support |
| Firefox | 88+ | Full support |
| Safari | 14+ | Full support |
| Edge | 90+ | Full support |
| Opera | 76+ | Full support |

### Unsupported Browsers

- Internet Explorer (all versions)
- Legacy Edge (EdgeHTML)
- Chrome < 90
- Firefox < 88
- Safari < 14

---

## Security Considerations

### CSRF Protection

All API requests include CSRF token:

```javascript
headers: {
    'X-CSRF-Token': this.options.csrfToken
}
```

### JWT Authentication

OnlyOffice communication secured with JWT tokens:

```javascript
token: data.token  // JWT token from API
```

### XSS Prevention

All user input is escaped before rendering:

```javascript
escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
```

### Tenant Isolation

All API calls respect tenant isolation:

```php
// Backend enforces tenant_id in queries
WHERE tenant_id = ? AND deleted_at IS NULL
```

---

## Accessibility

### Keyboard Navigation

- Full keyboard navigation support
- Focus management for modal
- ESC key to close
- Tab navigation within editor

### Screen Readers

- Semantic HTML structure
- ARIA labels where appropriate
- Status announcements for save state

### Reduced Motion

Respects `prefers-reduced-motion` setting:

```css
@media (prefers-reduced-motion: reduce) {
    * {
        animation: none !important;
        transition: none !important;
    }
}
```

---

## Migration & Upgrade

### From Previous Versions

This is the initial version (1.0.0). Future upgrades will be documented here.

### Backwards Compatibility

The editor maintains backwards compatibility with:
- CollaboraNexio file manager
- Existing file records
- Current permission system
- Multi-tenant architecture

---

## FAQ

### Q: Can I use this without OnlyOffice?

**A:** No, OnlyOffice Document Server is required for the editor to function. However, the file manager will continue to work without it.

### Q: Does this work offline?

**A:** No, both OnlyOffice server and CollaboraNexio API must be accessible.

### Q: Can multiple users edit simultaneously?

**A:** Yes, OnlyOffice supports real-time collaborative editing. Active users are shown in the editor header.

### Q: What happens if I lose connection during editing?

**A:** OnlyOffice attempts to reconnect automatically. If reconnection fails, you'll see an error message. Recent changes may be lost if not auto-saved.

### Q: Can I customize the editor toolbar?

**A:** Yes, customize via OnlyOffice configuration in `/includes/onlyoffice_config.php`.

### Q: Does it work on mobile devices?

**A:** Yes, the editor is responsive and works on tablets and phones, though editing large documents is better on desktop.

---

## Support & Contact

### Internal Support

- **Email:** support@nexiosolution.it
- **Documentation:** See OpenSpec COLLAB-2025-003
- **Issue Tracking:** Internal project management system

### External Resources

- [OnlyOffice API Documentation](https://api.onlyoffice.com/editors/basic)
- [OnlyOffice Community Forum](https://forum.onlyoffice.com/)
- [CollaboraNexio Project Documentation](../../../OVERVIEW.md)

---

## License

This integration is part of CollaboraNexio and follows the project's licensing.

OnlyOffice Document Server Community Edition is licensed under AGPLv3.

---

## Changelog

### Version 1.0.0 (2025-10-12)

**Initial Release:**
- Full-screen modal editor
- Role-based permissions (user/manager/admin/super_admin)
- Support for Word, Excel, PowerPoint documents
- Italian language interface
- Auto-save functionality
- Collaborative editing support
- Responsive design (desktop/tablet/mobile)
- Comprehensive error handling
- Integration with CollaboraNexio file manager

---

**Last Updated:** 2025-10-12
**Version:** 1.0.0
**Maintained by:** CollaboraNexio Development Team
