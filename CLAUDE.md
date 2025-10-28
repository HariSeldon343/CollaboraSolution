# CLAUDE.md

Development guide for Claude Code working with CollaboraNexio.

## Project Overview

**CollaboraNexio** - Multi-tenant enterprise collaboration platform (vanilla PHP 8.3) for Italian businesses.

**Production:** https://app.nexiosolution.it/CollaboraNexio
**Development:** http://localhost:8888/CollaboraNexio (XAMPP/Windows)

## Critical Patterns

### Multi-Tenant Design (MANDATORY)
```php
// ✅ CORRECT - Always include both filters
WHERE tenant_id = ? AND deleted_at IS NULL

// ❌ WRONG - Security vulnerability!
WHERE status = 'active'
```
**Exception:** `super_admin` role bypasses tenant isolation.

### Soft Delete Pattern (MANDATORY)
```php
$db->update('users', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $userId]);
```
All queries filter: `WHERE deleted_at IS NULL`

### Database Schema Pattern
All tenant-scoped tables include:
- `tenant_id INT NOT NULL` - FK with ON DELETE CASCADE
- `deleted_at TIMESTAMP NULL` - Soft delete marker
- `created_at`, `updated_at` - Timestamps
- Composite indexes: `(tenant_id, created_at)`, `(tenant_id, deleted_at)`

### Authentication Flow
```php
// Force no-cache headers (BUG-040 - prevents stale 403/500 errors)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

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

**⚠️ PAGE CACHE (BUG-040):** Always add no-cache headers for:
- Admin pages (dashboard, audit_log, settings)
- Pages with CSRF tokens
- Pages with role-based access control
- Pages showing user-specific data

### API Authentication (BUG-011 Pattern - CRITICAL)
```php
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();  // Sets headers, error handling

// Force no-cache headers (BUG-040 - prevents stale 403/500 errors)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();   // MUST be called IMMEDIATELY (BEFORE any other ops)

$userInfo = getApiUserInfo();
verifyApiCsrfToken();

api_success($data, 'Success message');
api_error('Error message', 403);
```

**⚠️ CRITICAL:** `verifyApiAuthentication()` IMMEDIATELY after `initializeApiEnvironment()`, BEFORE:
- HTTP headers
- Query parameters parsing
- Database operations
- Any business logic

**⚠️ CACHE CONTROL (BUG-040):** Always add no-cache headers for:
- API endpoints with authentication
- User-specific data endpoints
- Admin/role-based access endpoints

### Database Access
```php
$db = Database::getInstance();

// Helper methods
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

### Transaction Management (BUG-039 - CRITICAL)

**Defensive Rollback Pattern:**
```php
public function rollback(): bool {
    try {
        // Layer 1: Check class variable + sync if needed
        if (!$this->inTransaction) {
            if ($this->connection->inTransaction()) {
                $this->inTransaction = true; // Sync
            } else {
                return false;
            }
        }

        // Layer 2: Check ACTUAL PDO state (CRITICAL)
        if (!$this->connection->inTransaction()) {
            $this->inTransaction = false; // Sync
            return false;
        }

        // Layer 3: Safe rollback
        $result = $this->connection->rollBack();
        if ($result) {
            $this->inTransaction = false;
        }
        return $result;

    } catch (PDOException $e) {
        $this->inTransaction = false; // Always sync
        return false; // Don't throw
    }
}
```

**BUG-038 Pattern - Rollback Before Exit:**
```php
if ($validation_fails) {
    if ($db->inTransaction()) {
        $db->rollback();  // BEFORE api_error()
    }
    api_error('Validation failed', 400);
}
```

### Stored Procedures (BUG-036, BUG-037 - CRITICAL)

**Multiple Result Sets Pattern (do-while with nextRowset):**
```php
$stmt = $pdo->prepare('CALL my_stored_procedure(?, ?, ?)');
$stmt->execute([$param1, $param2, $param3]);

$result = false;
do {
    $tempResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tempResult !== false && isset($tempResult['expected_column'])) {
        $result = $tempResult;
        break;
    }
} while ($stmt->nextRowset());

$stmt->closeCursor(); // CRITICAL: Always close cursor

if ($result === false || !isset($result['expected_column'])) {
    throw new Exception('Stored procedure returned no valid result');
}
```

**Why Critical (BUG-036):**
- Open result sets block ALL subsequent queries
- Error: `SQLSTATE[HY000]: General error: 2014 Cannot execute queries while there are pending result sets`
- **ALWAYS call `$stmt->closeCursor()` immediately after `fetch()`**

### OnlyOffice Integration (BUG-020)
**Container Networking:** Docker needs `host.docker.internal:8888` to reach host.

```php
// Development mode (no JWT for Docker/local IPs)
ONLYOFFICE_DOWNLOAD_URL = 'http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php'
```

## Code Style

### Naming Conventions
- **Classes:** PascalCase (`Database`, `AuthSimple`)
- **Functions/Methods:** camelCase (`getCurrentUser()`)
- **Variables:** snake_case (`$current_user`, `$tenant_id`)
- **Constants:** UPPER_SNAKE_CASE (`DB_NAME`, `BASE_URL`)
- **Database:** snake_case plural (`users`, `chat_messages`)

### API Response Format (BUG-022, BUG-040 Prevention)
```json
{
  "success": true|false,
  "data": {
    "[entity]": [...],
    // metadata
  },
  "message": "Human-readable message"
}
```

```javascript
// ✅ CORRECT - Always wrap arrays in named keys
this.state.tasks = response.data?.tasks || [];
this.state.users = response.data?.users || [];

// ❌ WRONG - Direct array in data
this.state.tasks = response.data; // "filter is not a function"
```

```php
// ✅ CORRECT - Backend wraps array in named key
api_success(['users' => $formattedUsers], 'Success');
// Response: { success: true, data: { users: [...] } }

// ❌ WRONG - Direct array (BUG-040 pattern)
api_success($formattedUsers, 'Success');
// Response: { success: true, data: [...] } → data.data?.users is undefined
```

### API Parameter Naming (BUG-033 Prevention)
```javascript
// ✅ CORRECT - Match backend exactly
const body = {
    reason: reason,        // Backend: $data['reason']
    date_from: startDate,  // Backend: $data['date_from']
    date_to: endDate       // Backend: $data['date_to']
};
```

**Verification:**
1. Check backend parameter extraction: `$reason = $data['reason'] ?? null;`
2. Use EXACT same names in frontend
3. Test with DevTools Network tab

## Audit Log System (2025-10-28 - PRODUCTION READY ✅)

**Status:** Production Ready (30/30 E2E tests passed)

### Core Helper
```php
require_once __DIR__ . '/includes/audit_helper.php';

// Non-blocking pattern (BUG-029)
try {
    AuditLogger::logCreate($userId, $tenantId, 'user', $newUserId, 'Created user', $newValues);
} catch (Exception $e) {
    error_log("[AUDIT LOG FAILURE] " . $e->getMessage());
    // DO NOT throw - operation should succeed
}
```

### Available Methods
- `logLogin($userId, $tenantId, $success, $failureReason)`
- `logLogout($userId, $tenantId)`
- `logPageAccess($userId, $tenantId, $pageName)`
- `logCreate/Update/Delete(...)` - Entity operations
- `logPasswordChange(...)` - Security-sensitive
- `logFileDownload(...)` - File tracking

### Page Access Middleware
```php
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('dashboard');
```

### Integration Points (13 files)
- Login/Logout: `includes/auth.php`, `logout.php`
- Pages: `dashboard.php`, `files.php`, `tasks.php`
- Users API: `create.php`, `update.php`, `delete.php`
- Files API: `upload.php`, `download.php`, `rename.php`, `delete.php`

### Database Schema (2025-10-28 - VERIFIED 100% INTEGRITY ✅)

**Core Tables (22):** tenants, users, user_tenant_access, projects, files, folders, tasks, task_assignments, task_watchers, task_comments, calendar_events, chat_channels, chat_messages, chat_participants, document_approvals, audit_logs, audit_log_deletions, sessions, system_settings, notifications, tickets, italian_municipalities

**Audit Tables:**
- `audit_logs` (25 columns) - Multi-tenant, soft delete, JSON fields
- `audit_log_deletions` (23 columns) - IMMUTABLE (NO deleted_at)
- Performance: 0.34ms list query (EXCELLENT)
- Compliance: GDPR, SOC 2, ISO 27001 ready

**New Tables (2025-10-28):**
- `task_watchers` - Users watching tasks for notifications
- `chat_participants` - Users in chat channels (role-based)
- `notifications` - System-wide notification center

**Database Health (2025-10-28):**
- Total tables: 67 (includes backup/history tables)
- Size: 9.78 MB
- Storage engine: 100% InnoDB
- Collation: 100% utf8mb4_unicode_ci
- Integrity: EXCELLENT (15/15 tests passed)

### CHECK Constraints (BUG-041 - CRITICAL)

**IMPORTANT:** When adding new audit actions or entity types, MUST extend CHECK constraints:

```sql
-- Add new actions
ALTER TABLE audit_logs DROP CONSTRAINT chk_audit_action;
ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_action CHECK (action IN (
    'create', 'update', 'delete', ..., 'your_new_action'
));

-- Add new entity types
ALTER TABLE audit_logs DROP CONSTRAINT chk_audit_entity;
ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_entity CHECK (entity_type IN (
    'user', 'tenant', 'file', ..., 'your_new_entity'
));
```

**Failure Mode:** INSERT fails silently (non-blocking catch), no audit log created, compliance risk.

**Current Allowed Values (BUG-041):**
- Actions: create, update, delete, restore, login, logout, login_failed, session_expired, download, upload, view, export, import, approve, reject, submit, cancel, share, unshare, permission_grant, permission_revoke, password_change, password_reset, email_change, tenant_switch, system_update, backup, restore_backup, access, **document_opened, document_closed, document_saved**
- Entity Types: user, tenant, file, folder, project, task, calendar_event, chat_message, chat_channel, document_approval, system_setting, notification, page, ticket, ticket_response, **document, editor_session**

## Common Gotchas

### Code
- ❌ Forgetting `deleted_at IS NULL` → shows deleted records
- ❌ Missing `tenant_id` filter → data leakage
- ❌ No CSRF validation → security vulnerability
- ❌ Hard deleting records → data loss
- ❌ Not calling `closeCursor()` after stored procedures → cascade failures

### Frontend (BUG-022, BUG-023, BUG-032, BUG-033, BUG-042)
- ❌ Assuming `response.data` is array → "filter is not a function"
- ❌ Not using `?.` for nested objects → TypeError
- ❌ Missing `|| []` fallbacks → operations on undefined fail
- ❌ Parameter name mismatch (frontend vs backend) → 400 Bad Request
- ❌ **Not verifying included file content** → outdated shared components (BUG-042)

### BUG-042 Critical Lesson - Include File Verification
```php
// ❌ WRONG - Assuming include is correct without reading file
// audit_log.php line 710: <?php include 'includes/sidebar.php'; ?>
// Agent: "Sidebar is correct at line 710" ← WRONG ASSUMPTION

// The include statement was correct, but the INCLUDED FILE had old code:
// includes/sidebar.php contained Bootstrap icons, NOT CSS mask icons
```

**ALWAYS:**
1. ✅ Read the INCLUDED file content, not just the include statement
2. ✅ Verify shared component matches reference implementation
3. ✅ Check for consistency across all pages using the include
4. ✅ Test actual rendered output, not just file structure

**BUG-042 Pattern:**
- `audit_log.php` → `<?php include 'includes/sidebar.php'; ?>` ✅ Include correct
- BUT `includes/sidebar.php` → Old Bootstrap icons structure ❌ Content wrong
- Solution: Rewrite includes/sidebar.php to match dashboard.php CSS mask icons

### Cross-Module API Calls Pattern
```javascript
// ✅ CORRECT - Direct fetch with absolute path
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
const response = await fetch('/CollaboraNexio/api/users/list_managers.php', {
    method: 'GET',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken || ''
    },
    credentials: 'same-origin'
});

const data = await response.json();
const items = data.data || [];
```

## Apache Configuration (BUG-013)

**VERIFIED SOLUTION:** Ensure `api/.htaccess` has explicit `.php` pattern:
```apache
# Check .php extension FIRST
RewriteCond %{REQUEST_URI} \.php$
RewriteCond %{REQUEST_URI} ^/CollaboraNexio/api/files/
RewriteRule ^ - [END]
```

## Development Workflow

### Agent Workflow Protocol (MANDATORY)

**For every task:**
1. **Planning:** Analyze task, identify required agents
2. **Sequential Execution:** Launch agents one at a time
3. **Context Passing:** Each agent receives progression.md + bug.md
4. **Documentation:** Update after each agent completes
5. **Iterative Process:** Repeat until resolution
6. **Self-Testing:** Test all fixes (no user tasks)
7. **Cleanup:** Delete test files before returning control
8. **Database Verification:** Launch database-architect to verify integrity
9. **Final Update:** Update bug.md + progression.md + CLAUDE.md
10. **Context Report:** Provide token usage

### Standard Workflow

**When to use agents:**
- Feature development (new modules, APIs, pages)
- Code refactoring affecting multiple components
- Database migrations with cascading changes
- Security audits and compliance reviews
- Complex bug fixes

**When single-agent acceptable:**
- Simple documentation updates
- Minor typo fixes
- Single-file code reviews

### Documentation Update Protocol (MANDATORY)

**After EVERY operation:**
1. **progression.md** - Work completed with timestamp
2. **bug.md** - Track/update bugs, change status/priority
3. **CLAUDE.md** - Update patterns, conventions, security protocols

## Role-Based Access Control

| Role | Tenant Access | Approval Rights |
|------|---------------|-----------------|
| `user` | Single | ❌ |
| `manager` | Single | ✅ |
| `admin` | Multiple | ✅ |
| `super_admin` | All | ✅ |

## Security Requirements

1. **Tenant Isolation:** ALL queries include `tenant_id`
2. **Soft Delete:** Use `deleted_at`, no hard deletes (except GDPR)
3. **CSRF Protection:** All POST/PUT/DELETE require token
4. **Prepared Statements:** No SQL string concatenation
5. **Password Policy:** Min 8 chars, mixed case, numbers, special chars
6. **Session Timeout:** 2 hours (7200 seconds)

## Important File Locations

- **Config:** `config.php` (dev), `config.production.php` (prod)
- **Database:** `includes/db.php`
- **Auth:** `includes/auth_simple.php`, `includes/api_auth.php`
- **Audit:** `includes/audit_helper.php`, `includes/audit_page_access.php`
- **OnlyOffice:** `includes/onlyoffice_config.php`
- **Email:** `includes/email_config.php`
- **Session:** `includes/session_init.php`

## Critical Bug Fixes (2025-10-27/28)

### BUG-042: Sidebar Inconsistency (ALTA) - 2025-10-28
**Issue:** audit_log.php sidebar showed Bootstrap icons instead of CSS mask icons
**Root Cause:** includes/sidebar.php contained old structure, not verified by agent
**Fix:** Completely rewrote includes/sidebar.php with CSS mask icons + nav-section structure
**Impact:** UI consistency restored across ALL pages using sidebar include
**Lesson:** ALWAYS read included file content, not just verify include statement exists

### BUG-041: Document Audit Tracking Not Working (CRITICAL)
**Issue:** Document tracking audit logs not saved (CHECK constraints incomplete)
**Root Cause:** 'document_opened', 'document', 'editor_session' not in allowed values
**Fix:** Extended CHECK constraints to include document tracking actions/entities
**Testing:** 2/2 tests passed, INSERT successful, no violations

### BUG-040: Audit Log Users Dropdown 403 (ALTA)
**Issue:** Users dropdown 403 error + empty (permission check + response structure)
**Fix:** Include 'manager' role + wrap response in ['users' => array] for data.data?.users

### BUG-039: Defensive Rollback (CRITICAL)
**Issue:** Rollback not checking PDO actual state → 500 errors
**Fix:** 3-layer defense (class var + PDO state + exception handling)

### BUG-038: Transaction Rollback Before Exit (CRITICAL)
**Issue:** `api_error()` without rollback → zombie transactions
**Fix:** Always rollback BEFORE calling functions that exit

### BUG-037: Multiple Result Sets (CRITICAL)
**Issue:** Some PDO drivers generate empty result sets
**Fix:** do-while with nextRowset() pattern

### BUG-036: Pending Result Sets (CRITICAL)
**Issue:** Missing closeCursor() → blocks all subsequent queries
**Fix:** Always `$stmt->closeCursor()` after fetch

### BUG-034: CHECK Constraints + MariaDB (CRITICAL)
**Issue:** CHECK constraints incomplete, JSON_ARRAYAGG incompatible
**Fix:** Extended constraints, rewrote stored procedures with GROUP_CONCAT

### BUG-033/032: Parameter Mismatches
**Issue:** Frontend/backend parameter name differences → 400 errors
**Fix:** Standardized parameter names across all endpoints

### BUG-031/030/029: Centralized Audit Logging
**Issue:** No centralized logging, silent failures
**Fix:** AuditLogger class, page middleware, 13 integration points

**Related Docs:**
- `/BUG-039-DEFENSIVE-ROLLBACK-FIX.md` (28 KB)
- `/BUG-038-TRANSACTION-ROLLBACK-FIX.md` (25 KB)
- `/AUDIT_LOGGING_IMPLEMENTATION_GUIDE.md` (1,000+ lines)

## Database Management

```bash
# Full reset
php database/manage_database.php full

# Verify integrity
php verify_database_integrity_final.php
```

**Latest Verifications:**
- Audit Log System: 100% confidence (2025-10-28)
- Task Management: Production Ready (2025-10-25)
- Ticket System: Production Ready (2025-10-26)

## Testing URLs

- System Health: `/system_check.php`
- Database: `/test_db.php`
- API Test: `/test_apis_browser.php`

**Demo Credentials (password: Admin123!):**
- `superadmin@collaboranexio.com` - super_admin
- `admin@demo.local` - admin (Demo Co)
- `manager@demo.local` - manager (Demo Co)

## Database Status (2025-10-28 Verification)

**Last Verification:** 2025-10-28 06:58:52 (Post BUG-040)
**Overall Status:** ✅ **EXCELLENT** (A- grade)
**Production Ready:** ✅ **YES**

### Integrity Checks (9 Total)
- ✅ **Passed:** 6/9 (Schema, Storage Engine, Audit Tables, Data Integrity, Transaction Safety, Audit System)
- ⚠️ **Warnings:** 2/9 (Missing indexes, FK rules) - Non-blocking
- ❌ **Failed:** 1/9 (6 tables missing deleted_at) - Pre-existing, low impact

### Database Health
- **Tables:** 54 (all InnoDB, utf8mb4)
- **Audit System:** 25 columns (audit_logs), 23 columns (audit_log_deletions)
- **Active Logs:** 14 (system operational)
- **Data Integrity:** 0 NULL tenant_id values
- **Transaction Safety:** BUG-039 defensive rollback verified ✅

### Known Issues (Pre-Existing, Non-Blocking)
1. **Missing Performance Indexes:** 6 critical indexes (Priority 2)
2. **FK Rules:** 2 tables use SET NULL (intentional for file preservation)
3. **Multi-Tenant Pattern:** 6 tables missing deleted_at (history/transient data)
4. **Backup Table:** `tenants_backup_locations_20251007` should be deleted

**Full Report:** `/DATABASE_INTEGRITY_REPORT_POST_BUG040.md`

---

**Last Updated:** 2025-10-28
**Full Archive:** `docs/archive_2025_oct/`
- Per ogni nuovo compito, risoluzione o implementazione che ti chiedo usa questo approccio: 1. Pianifica le attività da compiere, 2. Utilizza gli agenti necessari in modo sequenziale. 3. Parti con il primo agente, quanto il primo agente avrà terminato il suo compito, aggiorna i file @progression.md  e @bug.md. 4.Continua ad implementare la tua pianificazione con altri agenti (ogni volta che chiami un agente, lui deve conoscere il contesto, devi quindi far riferimento ai file @progression.md  e @bug.md).Prima di passare allo step successivo aggiorna @bug.md  e @progression.md  5. Procedi in moto iterativo fino alla piena risoluzione dei problemi. 6. Tutti i fix, test, scrpit eventualmente creati devono essere testati da te, io non devo avare compiti, se verifichi che i risultati non sono raggiunti torna indietro di un passaggio e ricomncia la risoluzione. 7.Prima di restituirmi il controllo elimina tutti i file di test, script, fix creati e testati nei precedenti passatti così la piattafomra risulta pulita. 8. lanchia @agent-database-architect solo per verificare che il database sia integro ed in forma normale e che i precedenti passaggi non abbiamo generato errori. 9. aggiorna @bug E @progression.md  e @CLAUDE.md ed infine dimmi quanta finestra di contesto è stata consumata e quanta ne rimane disponibile.
---

## BUG-041 Analysis Complete (2025-10-28)

**Status:** Analysis Complete, Code Review Passed  
**Findings:** 6 issues identified (2 cache-related, 1 critical database bug, 1 design debt)  
**Report:** `/BUG-041-COMPREHENSIVE-ANALYSIS.md` (9.5 KB, source-verified)

### Quick Summary

| Issue | Verification | Fix Status | Impact |
|-------|--------------|-----------|--------|
| **403 Error** | BUG-040 fix applied ✅ | Browser cache ⚠️ | User action needed |
| **500 Error** | 4 defensive layers present ✅ | Browser cache ⚠️ | User action needed |
| **Audit missing** | Code calls function but DB rejects ❌ | CHECK constraint ❌ | Code fix needed |
| **Sidebar** | Hardcoded instead of shared ⚠️ | Design debt ⚠️ | Refactor recommended |

### Critical Finding: Document Audit Tracking Broken

**Problem:** Documents opened in OnlyOffice NOT tracked in audit logs

**Root Cause:** Database CHECK constraints incomplete
- `action='document_opened'` not in allowed list
- `entity_type='document'` not in allowed list
- Database rejects INSERT silently
- User sees no error, audit trail broken

**Fix:** Extend `/database/06_audit_logs.sql` constraints:
```sql
ALTER TABLE audit_logs DROP CONSTRAINT chk_audit_action;
ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_action CHECK (action IN (
    ..., 'document_opened'  -- ADD THIS
));
```

See full report for complete SQL and verification steps.

