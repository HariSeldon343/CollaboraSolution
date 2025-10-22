# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**CollaboraNexio** is a multi-tenant enterprise collaboration platform built with vanilla PHP 8.3, designed specifically for Italian businesses. It provides secure, isolated workspaces for multiple organizations with features including file management, document approval workflows, OnlyOffice integration, calendar, tasks, and real-time chat.

**Production:** https://app.nexiosolution.it/CollaboraNexio
**Development:** http://localhost:8888/CollaboraNexio (XAMPP on Windows)

## Architecture

### Multi-Tenant Design (CRITICAL)

Every database query MUST enforce tenant isolation:

```php
// ✅ CORRECT - Always include both filters
WHERE tenant_id = ? AND deleted_at IS NULL

// ❌ WRONG - Security vulnerability!
WHERE status = 'active'
```

**Exception:** `super_admin` role can bypass tenant isolation for system administration.

### Soft Delete Pattern (MANDATORY)

Never hard-delete records. Use `deleted_at` timestamp:

```php
$db->update('users', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $userId]);
```

All queries must filter: `WHERE deleted_at IS NULL`

### Database Schema Pattern

All tenant-scoped tables include:
- `tenant_id INT NOT NULL` - Foreign key with ON DELETE CASCADE
- `deleted_at TIMESTAMP NULL` - Soft delete marker
- `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
- `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

Indexes: Every table has composite indexes on `(tenant_id, created_at)` and `(tenant_id, deleted_at)`.

### Authentication Flow

1. **Session Init:** Always require `includes/session_init.php` FIRST
2. **Auth Check:** Use `AuthSimple` class from `includes/auth_simple.php`
3. **User Context:** Get via `$auth->getCurrentUser()`
4. **CSRF Tokens:** Generate with `$auth->generateCSRFToken()`

```php
<?php
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new AuthSimple();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();
```

### API Authentication (CRITICAL SECURITY)

Use centralized API auth in `includes/api_auth.php`:

```php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// 1. Initialize API environment
initializeApiEnvironment();  // Sets headers, error handling

// 2. IMMEDIATELY verify authentication (BEFORE any other operations!)
verifyApiAuthentication();   // Checks session/auth - MUST be called first!

// 3. ONLY NOW: proceed with other operations
$userInfo = getApiUserInfo(); // Gets user context
verifyApiCsrfToken();         // CSRF validation
header('Cache-Control: ...'); // ✅ OK after auth check

// Standard responses
api_success($data, 'Success message');
api_error('Error message', 403);
```

**⚠️ CRITICAL SECURITY RULE (BUG-011):**

> `verifyApiAuthentication()` MUST be called IMMEDIATELY after `initializeApiEnvironment()`, BEFORE any other operation:
> - ❌ BEFORE sending HTTP headers
> - ❌ BEFORE parsing query parameters
> - ❌ BEFORE database operations
> - ❌ BEFORE any business logic
>
> **Why:** Sending headers BEFORE auth check can cause HTTP 200 status to be set prematurely, preventing proper 401 Unauthorized responses for unauthenticated requests.

**✅ CORRECT Pattern:**
```php
initializeApiEnvironment();
verifyApiAuthentication();  // ✅ FIRST!
header('Cache-Control: ...'); // ✅ After auth
// ... rest of endpoint
```

**❌ WRONG Pattern (Security Vulnerability!):**
```php
initializeApiEnvironment();
header('Cache-Control: ...'); // ❌ BEFORE auth!
verifyApiAuthentication();  // ❌ Too late - headers already sent
```

### Database Access

Use singleton Database class (`includes/db.php`):

```php
$db = Database::getInstance();

// Preferred helper methods
$db->insert('users', ['name' => $name, 'email' => $email]);
$db->update('users', ['status' => 'active'], ['id' => $userId]);
$db->fetchAll('SELECT * FROM users WHERE tenant_id = ?', [$tenantId]);
$db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);

// Transactions
$db->beginTransaction();
try {
    // operations
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

### OnlyOffice Document Editor Integration

Document editing workflow uses stored procedures:

```php
// Check if editable
$isEditable = isFileEditableInOnlyOffice($extension);

// Open session (creates lock)
CALL open_editor_session(file_id, user_id, tenant_id, ...)

// Record changes (OnlyOffice callback)
CALL record_document_change(session_token, callback_status, ...)

// Close session (releases lock)
CALL close_editor_session(session_token, changes_saved)
```

**Key tables:**
- `document_editor_sessions` - Active editing sessions
- `document_editor_locks` - Exclusive/shared locks
- `document_editor_changes` - Change history from OnlyOffice callbacks

**Lock mechanism:**
- Exclusive lock: Single user editing
- Collaborative mode: Multiple users when `is_collaborative = TRUE`
- Auto-expires after 2 hours (configurable)

## Development Commands

### Database Management

```bash
# Full reset with demo data
php database/manage_database.php full

# Check database structure
php check_database_structure.php

# Install Italian locations (provinces/municipalities)
php run_italian_locations_migration.php

# Install tenant soft-delete stored procedures
php run_tenant_delete_migration.php
```

### Testing

**Test URLs:**
- System Health: `/system_check.php`
- Database Test: `/test_db.php`
- API Test: `/test_apis_browser.php`
- Companies Test: `/test_aziende_system_complete.php` (22 automated tests)

**Demo Credentials (password: Admin123!):**
- `superadmin@collaboranexio.com` - super_admin (all tenants)
- `admin@demo.local` - admin (Demo Co, multi-tenant access)
- `manager@demo.local` - manager (Demo Co, approval rights)
- `user1@demo.local` - user (Demo Co, limited access)

### OnlyOffice Setup

```bash
# Start OnlyOffice in Docker (Windows)
.\docker\start_onlyoffice.sh

# Stop OnlyOffice
.\docker\stop_onlyoffice.sh

# Restart OnlyOffice
.\docker\restart_onlyoffice.sh

# Manage with PowerShell
.\manage_onlyoffice.ps1
```

**OnlyOffice Configuration** (`includes/onlyoffice_config.php`):
- Server URL: `http://localhost:8888` (dev) / configured in production
- JWT enabled for security in production
- Callback URL for document saves

## Code Style

### Naming Conventions

- **Classes:** PascalCase (`Database`, `AuthSimple`)
- **Functions/Methods:** camelCase (`getCurrentUser()`, `checkAuth()`)
- **Variables:** snake_case (`$current_user`, `$tenant_id`)
- **Constants:** UPPER_SNAKE_CASE (`DB_NAME`, `BASE_URL`)
- **Database tables:** snake_case plural (`users`, `chat_messages`)
- **Database columns:** snake_case (`tenant_id`, `deleted_at`)
- **Files:** lowercase_underscore.php (`document_approvals.php`)
- **CSS:** lowercase-hyphen.css (`sidebar-responsive.css`)

### File Structure

```
/api/               # REST API endpoints
  /auth/            # Authentication endpoints
  /documents/       # OnlyOffice document API
  /files/           # File management API
  /tenants/         # Tenant management API
  /users/           # User management API
/assets/            # Frontend assets
  /css/             # Stylesheets
  /js/              # JavaScript
  /images/          # Images/icons
/database/          # Database schema and migrations
  /migrations/      # Migration scripts
  /functions/       # SQL stored procedures/functions
/includes/          # PHP includes and classes
/docker/            # Docker configuration (OnlyOffice)
/openspec/          # OpenSpec change specifications
/logs/              # Application logs
/uploads/           # Uploaded files (tenant-scoped)
```

### Page Structure Template

```php
<?php
// 1. Session initialization (ALWAYS FIRST)
require_once __DIR__ . '/includes/session_init.php';

// 2. Authentication
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new AuthSimple();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// 3. Get current user context
$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();

// 4. Role-based access control
if (!in_array($currentUser['role'], ['manager', 'admin', 'super_admin'])) {
    die('Access denied');
}

// 5. Page logic with tenant isolation
$db = Database::getInstance();
$data = $db->fetchAll(
    'SELECT * FROM table WHERE tenant_id = ? AND deleted_at IS NULL',
    [$currentUser['tenant_id']]
);
?>
```

## Italian Business Requirements

### Tax Codes
- **Codice Fiscale (CF):** 16-character tax code with checksum validation
- **Partita IVA (P.IVA):** 11-digit VAT number with checksum validation
- Validation functions in `includes/italian_provinces.php`

### Administrative Divisions
- **107 Italian provinces** in `italian_provinces` table
- **7,000+ municipalities** in `italian_municipalities` table
- Source: ISTAT (official Italian statistics)
- Real-time validation during company creation

### Document Approval Workflow
Files follow state machine: `draft → in_approvazione → [approvato | rifiutato]`

- Only Manager+ roles can approve/reject
- History tracked in `document_approvals` table
- Notifications via `approval_notifications` table
- Audit trail in `audit_logs` table

## Role-Based Access Control

| Role | Tenant Access | Approval Rights | Description |
|------|---------------|-----------------|-------------|
| `user` | Single | ❌ | Read-only access to assigned resources |
| `manager` | Single | ✅ | Full CRUD + document approval |
| `admin` | Multiple | ✅ | Manager rights across multiple tenants |
| `super_admin` | All | ✅ | Complete system control, bypass tenant isolation |

## Security Requirements

1. **Tenant Isolation:** ALL queries must include `tenant_id` filter
2. **Soft Delete:** Use `deleted_at` timestamp, no hard deletes (except GDPR)
3. **CSRF Protection:** All POST/PUT/DELETE require CSRF token validation
4. **Prepared Statements:** No string concatenation in SQL (SQL injection prevention)
5. **Password Policy:** Minimum 8 chars, mixed case, numbers, special chars
6. **Session Timeout:** 2 hours (7200 seconds) of inactivity

## Development Workflow Conventions

### Standard Workflow (MANDATORY)

**For any complex operation, ALWAYS start by invoking the Orchestrator Agent:**

```
The Orchestrator Agent coordinates specialized agents to ensure:
- Proper task decomposition and sequencing
- Multi-domain coverage (backend, frontend, database, security, testing)
- Adherence to CollaboraNexio's multi-tenant architecture
- Quality assurance across all outputs
- Integrated, coherent deliverables
```

**When to use Orchestrator:**
- Feature development (new modules, API endpoints, pages)
- Code refactoring affecting multiple components
- Database migrations requiring cascading changes
- Security audits and compliance reviews
- Performance optimization initiatives
- Complex bug fixes involving multiple subsystems

**When single-agent is acceptable:**
- Simple documentation updates
- Minor typo fixes
- Single-file code reviews
- Trivial configuration changes

### Documentation Update Protocol (MANDATORY)

**After EVERY completed operation, update:**

1. **progression.md** - Document the work completed:
   ```markdown
   ### [YYYY-MM-DD] - [Feature/Module Title]
   **Stato:** Completato
   **Sviluppatore:** [Name]
   **Commit:** [Hash or Pending]

   **Descrizione:**
   [What was done]

   **Modifiche:**
   - [Change 1]
   - [Change 2]

   **File Modificati/Creati:**
   - `/absolute/path/to/file.php`

   **Testing:**
   - [Test 1 performed]
   - [Test 2 performed]

   **Note:**
   [Additional notes or considerations]
   ```

2. **bug.md** - Update when:
   - New bug discovered: Add to "Bug Aperti" section
   - Bug fixed: Move to "Bug Risolti" with fix details
   - Bug status changed: Update priority/assignment/notes

3. **CLAUDE.md** (this file) - Update when:
   - New architectural patterns introduced
   - New conventions established
   - New critical dependencies added
   - Security protocols changed
   - New development commands added

### Quality Gates

Before marking work complete:
- [ ] All code follows PSR-12 standards
- [ ] Tenant isolation enforced in all queries
- [ ] Soft-delete pattern applied
- [ ] CSRF protection implemented
- [ ] Error handling and logging present
- [ ] Testing performed and documented
- [ ] Documentation updated (progression.md, bug.md, CLAUDE.md as needed)

## Common Development Tasks

### Adding a New API Endpoint

1. Create file in appropriate `/api/` subdirectory
2. Use `api_auth.php` for authentication boilerplate
3. Enforce tenant isolation in all queries
4. Add CSRF validation for state-changing operations
5. Return JSON responses with `api_success()` or `api_error()`

Example:
```php
<?php
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken(); // For POST/PUT/DELETE

$userInfo = getApiUserInfo();
$db = Database::getInstance();

// Query with tenant isolation
$data = $db->fetchAll(
    'SELECT * FROM table WHERE tenant_id = ? AND deleted_at IS NULL',
    [$userInfo['tenant_id']]
);

api_success($data, 'Success message');
```

### Adding a Database Migration

1. Create SQL file in `/database/migrations/`
2. Use naming convention: `###_descriptive_name.sql`
3. Include tenant_id and deleted_at columns
4. Add composite indexes on tenant_id
5. Test rollback scenario

### Creating a New Page

1. Follow page structure template (see above)
2. Include session_init.php FIRST
3. Add authentication check
4. Implement role-based access control
5. Enforce tenant isolation in all data queries
6. Generate and validate CSRF tokens for forms

## Important File Locations

- **Config:** `config.php` (dev), `config.production.php` (prod)
- **Database Class:** `includes/db.php`
- **Auth Class:** `includes/auth_simple.php`
- **API Auth:** `includes/api_auth.php`
- **OnlyOffice Config:** `includes/onlyoffice_config.php`
- **Document Editor Helper:** `includes/document_editor_helper.php`
- **Email Config:** `includes/email_config.php`
- **Session Init:** `includes/session_init.php`

## Project Documentation

### Core Documentation Files
- **CLAUDE.md** (this file) - Development guide for Claude Code
- **progression.md** - Complete development history and progress tracking
- **bug.md** - Bug tracker with all reported issues and their status
- **openspec/project.md** - Comprehensive project specifications including:
  - Complete tech stack details
  - Security standards
  - External dependencies
  - Business constraints
  - Testing strategies

### Development Tracking
When completing work:
1. Update `progression.md` with new developments
2. Update `bug.md` if fixing bugs or discovering new issues
3. Update `CLAUDE.md` if adding new patterns or architectural changes

## Common Gotchas

### Code and Architecture
- ❌ Forgetting `deleted_at IS NULL` → shows deleted records
- ❌ Missing `tenant_id` filter → data leakage between tenants
- ❌ No CSRF validation → security vulnerability
- ❌ String concatenation in SQL → SQL injection risk
- ❌ Hard deleting records → data loss and FK violations
- ❌ Not using `api_auth.php` → inconsistent API responses
- ❌ Session init not first → session conflicts
- ❌ Forgetting to check user role → unauthorized access
- ❌ Incorrect include order in API endpoints → "Class not found" errors
- ❌ `.htaccess` rewrite rules blocking direct .php file access → 404 errors on API endpoints

### Apache Configuration (.htaccess)
**CRITICAL:** API endpoints in subdirectories (like `api/files/upload.php`) MUST be accessible directly. Ensure `api/.htaccess` has explicit bypass rules for existing files:

```apache
# Allow direct access to existing files (bypass router)
# Simplified version - checks if file exists, bypasses router if true
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]
```

**Why this version works:**
- Checks only if requested file exists (`-f` flag)
- If file exists, passes request directly (bypass router)
- No regex patterns that can have issues with `RewriteBase`
- Simpler, more reliable, more performant
- Works for ALL file types (PHP, CSS, JS, images, etc.)

This rule must come BEFORE any other rewrite rules to prevent 404 errors. See BUG-008 for detailed explanation and verification tests.

### Workflow
- ❌ Not using specialized agents for complex tasks → incomplete or inconsistent implementation
- ❌ Forgetting to update documentation files (bug.md, progression.md, CLAUDE.md) → knowledge loss
- ❌ Not reading bug.md and progression.md before starting work → duplicating effort or missing context
- ❌ Not providing context consumption statistics → tracking issues

## Apache Management Scripts (2025-10-21)

### PowerShell Scripts per Windows/XAMPP

**Start-ApacheXAMPP.ps1** - Avvio automatico Apache con diagnostica completa
```powershell
cd C:\xampp\htdocs\CollaboraNexio
.\Start-ApacheXAMPP.ps1
```

**Test-ApacheStatus.ps1** - Verifica completa stato sistema
```powershell
.\Test-ApacheStatus.ps1
```

**Comandi Rapidi:**
```powershell
# Fermare Apache
taskkill /IM httpd.exe /F

# Verificare porta 8888
netstat -ano | findstr :8888

# Ultimi errori Apache
Get-Content C:\xampp\apache\logs\error.log -Tail 10

# Ultimi errori PHP
Get-Content C:\xampp\htdocs\CollaboraNexio\logs\php_errors.log -Tail 10
```

### Test Upload Endpoint
```bash
php test_upload_endpoint.php
```

**File di Supporto:**
- `APACHE_STARTUP_GUIDE.md` - Guida completa con troubleshooting
- `test_upload_endpoint.php` - Script PHP per test upload API

### Browser Cache Troubleshooting Tools (2025-10-22)

Quando gli utenti segnalano errori 404 su endpoint che funzionano correttamente lato server, il problema è spesso cache del browser che ha "memorizzato" vecchi errori.

**Clear-BrowserCache.ps1** - Script automatico pulizia cache
```powershell
cd C:\xampp\htdocs\CollaboraNexio
.\Clear-BrowserCache.ps1
```
- Chiude automaticamente tutti i browser (Chrome, Firefox, Edge, IE)
- Pulisce cache e dati temporanei
- Verifica endpoint dopo pulizia
- Test automatico per conferma fix
- Output colorato e gestione errori

**test_upload_cache_bypass.html** - Test diagnostico web
```
http://localhost:8888/CollaboraNexio/test_upload_cache_bypass.html
```
- Interface web professionale per diagnostica
- Bypass completo cache con timestamp random
- Headers HTTP no-cache forzati
- Test automatici (Apache, endpoint, cache headers)
- Console log real-time
- Test upload interattivo
- Indicatori visivi stato

**CACHE_FIX_GUIDE.md** - Guida troubleshooting completa
- Spiegazione tecnica problema cache
- 3 metodi risoluzione (automatico/web/manuale)
- Istruzioni passo-passo
- FAQ e troubleshooting avanzato

**Uso Rapido:**
1. **Script automatico (30 sec)**: `.\Clear-BrowserCache.ps1`
2. **Test diagnostico**: Apri `test_upload_cache_bypass.html` nel browser
3. **Manuale**: `CTRL+SHIFT+DELETE` → Cancella tutto → Riavvia browser

**Quando Usare:**
- Utente vede 404 ma server risponde correttamente (verificato con PowerShell)
- Dopo fix di configurazione Apache/.htaccess
- Dopo aggiornamenti endpoint API
- Quando test PowerShell funziona ma browser no

### Cache Busting Automatico (2025-10-22)

**Il sistema ora include cache busting automatico integrato nel codice!**

**Modifiche Implementate:**

1. **JavaScript Client** (`assets/js/filemanager_enhanced.js`):
   - Timestamp random aggiunto a ogni richiesta upload
   - Headers no-cache su XMLHttpRequest
   - Funziona sia per upload standard che chunked

2. **PHP Server** (`api/files/upload.php`):
   - Headers no-cache nella risposta HTTP
   - Previene caching lato server

3. **Pagina Refresh** (`refresh_files.html`):
   - Countdown animato 3 secondi
   - Pulizia automatica cache browser
   - Redirect automatico a files.php

**Come Funziona:**
```javascript
// Ogni richiesta ha URL univoco con timestamp
const cacheBustUrl = '/api/files/upload.php?_t=' + Date.now() + Math.random();

// Headers no-cache forzati
xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
```

**Uso Utente (se necessario):**
```
1. Apri: http://localhost:8888/CollaboraNexio/refresh_files.html
2. Attendi countdown
3. Upload funziona!
```

**Alternativa:** `CTRL+F5` per hard refresh

**Vantaggi:**
- ✅ Nessun intervento manuale richiesto
- ✅ Fix permanente nel codice
- ✅ Previene futuri problemi cache
- ✅ Funziona automaticamente

**Note Importanti:**
- Script richiedono esecuzione come Amministratore
- Apache può essere avviato come servizio Windows o processo standalone
- Gli script includono health checks automatici e log analysis
- Output colorato per facile lettura diagnostica

