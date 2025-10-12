# Project Context

## Purpose

**CollaboraNexio** is a multi-tenant enterprise collaboration platform that provides a complete suite of tools for project management, file sharing, document approval workflows, calendar management, tasks, and real-time chat. The platform is designed for Italian businesses and supports complete data isolation between organizations while allowing administrators to manage multiple tenants seamlessly.

**Key Goals:**
- Provide secure, isolated collaboration spaces for multiple organizations
- Implement document approval workflows for manager-level oversight
- Support Italian business requirements (CF, P.IVA, PEC, province/municipality data)
- Enable seamless multi-tenant administration
- Maintain simplicity without framework dependencies

**Production Deployment:** https://app.nexiosolution.it/CollaboraNexio

## Tech Stack

### Backend
- **PHP 8.3** - Modern PHP without frameworks (vanilla PHP)
- **MySQL 8.0** - Relational database with strict ACID compliance
- **PDO** - Database abstraction with prepared statements for SQL injection prevention
- **Apache 2.4** - Web server via XAMPP (port 8888 in development)

### Frontend
- **Vanilla JavaScript (ES6+)** - No frameworks, direct DOM manipulation
- **CSS3** - Custom stylesheets with responsive design
- **Bootstrap Icons** - Icon library
- **Fetch API** - Modern AJAX requests for API communication

### Development Environment
- **XAMPP** - Windows-based development stack
- **Development URL:** http://localhost:8888/CollaboraNexio
- **Production URL:** https://app.nexiosolution.it/CollaboraNexio

### Security & Standards
- **CSRF Tokens** - Protection on all state-changing operations
- **Prepared Statements** - 100% PDO prepared statements (no string concatenation)
- **Password Hashing** - PHP `password_hash()` with bcrypt
- **Session Management** - Secure session configuration with `session_init.php`
- **Input Validation** - Server-side validation on all inputs using `filter_var()`

### External Services
- **Email:** SMTP via PHPMailer (Infomaniak mail server configured)
- **AI Integration:** Claude Agent SDK for enhanced features

## Project Conventions

### Code Style

**PHP Naming Conventions:**
- **Classes:** PascalCase (e.g., `Database`, `AuthSimple`)
- **Functions/Methods:** camelCase (e.g., `getCurrentUser()`, `checkAuth()`)
- **Variables:** snake_case (e.g., `$current_user`, `$tenant_id`)
- **Constants:** UPPER_SNAKE_CASE (e.g., `DB_NAME`, `BASE_URL`, `PRODUCTION_MODE`)
- **Database tables:** snake_case plural (e.g., `users`, `chat_messages`)
- **Database columns:** snake_case (e.g., `tenant_id`, `deleted_at`, `created_at`)

**File Naming:**
- **PHP pages:** lowercase with underscores (e.g., `document_approvals.php`)
- **API endpoints:** lowercase with underscores (e.g., `files_complete.php`)
- **Includes:** lowercase with underscores (e.g., `auth_simple.php`)
- **CSS:** lowercase with hyphens (e.g., `sidebar-responsive.css`)
- **JavaScript:** camelCase (e.g., `fileManager.js`)

**Code Organization:**
```php
<?php
// 1. Session initialization (ALWAYS FIRST)
require_once __DIR__ . '/includes/session_init.php';

// 2. Authentication
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();
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

**Formatting:**
- **Indentation:** 4 spaces (no tabs)
- **Line length:** Soft limit 120 characters
- **Braces:** Opening brace on same line (K&R style)
- **SQL:** Uppercase keywords, formatted with line breaks for readability

### Architecture Patterns

**1. Multi-Tenancy Pattern (MANDATORY)**

Every query MUST include tenant isolation:

```php
// ❌ WRONG - Security vulnerability!
SELECT * FROM users WHERE status = 'active'

// ✅ CORRECT - Tenant-isolated
SELECT * FROM users
WHERE tenant_id = ?
AND status = 'active'
AND deleted_at IS NULL
```

**Exception:** `super_admin` role can bypass tenant isolation when explicitly needed for system administration.

**2. Soft Delete Pattern (MANDATORY)**

Never hard-delete records. Always use `deleted_at` timestamp:

```php
// Mark as deleted
$db->update('users',
    ['deleted_at' => date('Y-m-d H:i:s')],
    ['id' => $userId]
);

// All queries MUST filter deleted records
WHERE deleted_at IS NULL
```

**3. Singleton Pattern (Database)**

Single database instance shared across the application:

```php
$db = Database::getInstance();
$users = $db->fetchAll('SELECT * FROM users WHERE tenant_id = ?', [$tenantId]);
```

**4. Repository/Helper Pattern**

Use Database class helper methods instead of raw SQL:

```php
// ✅ Preferred
$db->insert('users', ['name' => $name, 'email' => $email]);
$db->update('users', ['status' => 'active'], ['id' => $userId]);

// ❌ Avoid (use only when necessary)
$stmt = $pdo->prepare("INSERT INTO users...");
```

**5. CSRF Protection Pattern**

All state-changing operations require CSRF token:

```php
// Generate in page/session
$csrfToken = $auth->generateCSRFToken();

// Include in forms
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

// Validate in API
verifyApiCsrfToken();
```

**6. API Response Standardization**

Use centralized API authentication:

```php
require_once '../../includes/api_auth.php';
initializeApiEnvironment(); // Headers, error handling, CORS
verifyApiAuthentication();   // Check session/auth
$userInfo = getApiUserInfo(); // Get user context
verifyApiCsrfToken();         // CSRF validation

// Consistent responses
api_success($data, 'Success message');
api_error('Error message', 403);
```

**7. Role-Based Access Control (RBAC)**

Hierarchy: `user` → `manager` → `admin` → `super_admin`

| Role | Tenant Access | Approval Rights | Description |
|------|---------------|-----------------|-------------|
| `user` | Single | ❌ No | Read-only access to assigned resources |
| `manager` | Single | ✅ Yes | Full CRUD, can approve documents |
| `admin` | Multiple | ✅ Yes | Manager rights across multiple tenants |
| `super_admin` | All | ✅ Yes | Complete system control, bypass tenant isolation |

### Testing Strategy

**Manual Testing Approach:**
- Test all features with different user roles (user, manager, admin, super_admin)
- Verify multi-tenant isolation (data leakage prevention)
- Check soft delete behavior (deleted records not visible)
- Validate CSRF protection (state-changing operations protected)
- Test Italian-specific features (CF, P.IVA, province/municipality validation)

**Demo Credentials (all use password: Admin123!):**
| Email | Role | Tenant | Purpose |
|-------|------|--------|---------|
| superadmin@collaboranexio.com | super_admin | All | System-wide testing |
| admin@demo.local | admin | Demo Co | Multi-tenant admin testing |
| manager@demo.local | manager | Demo Co | Document approval testing |
| user1@demo.local | user | Demo Co | Limited access testing |

**Test URLs:**
- System Health: `/system_check.php`
- Database Test: `/test_db.php`
- API Test: `/test_apis_browser.php`
- Companies Test: `/test_aziende_system_complete.php` (22 automated tests)
- System Integrity: `/verify_system_integrity.php`

**Database Management:**
```bash
# Full reset with demo data
php database/manage_database.php full

# Check structure
php check_database_structure.php

# Install Italian locations
php run_italian_locations_migration.php

# Install tenant soft-delete procedures
php run_tenant_delete_migration.php
```

### Git Workflow

**Current Workflow:**
- Single branch: `main`
- Direct commits to main (no PR process currently)
- Commit messages in Italian or English
- Focus on functional commits with clear descriptions

**Recommended Commit Message Format:**
```
Brief description in imperative mood

- Detailed change 1
- Detailed change 2
- Related issue/reference if applicable
```

**Examples:**
```
Add tenant soft-delete filter to companies list API

- Updated api/companies/list.php to filter deleted_at IS NULL
- Updated api/files_tenant_fixed.php getTenantList() function
- Prevents deleted companies from appearing in tenant dropdown
- Fixes issue where deleted companies still showed in file manager
```

## Domain Context

**Italian Business Requirements:**

CollaboraNexio is designed specifically for Italian businesses and includes:

1. **Tax Codes:**
   - **Codice Fiscale (CF):** 16-character alphanumeric Italian tax code for individuals
   - **Partita IVA (P.IVA):** 11-digit VAT number for businesses
   - Both validated with checksums and format validation

2. **Certified Email:**
   - **PEC (Posta Elettronica Certificata):** Required for legal communications
   - Separate from regular email, validated for proper format

3. **Administrative Divisions:**
   - **107 Italian provinces** (`italian_provinces` table)
   - **7,000+ municipalities (comuni)** (`italian_municipalities` table)
   - Real-time validation during company creation
   - Foreign key constraints ensure data integrity

4. **Company Information:**
   - Sede legale (legal headquarters): indirizzo, comune, provincia, CAP
   - Sedi operative (operational locations): JSON array of multiple locations
   - Rappresentante legale (legal representative)
   - Capitale sociale (share capital)
   - Settore merceologico (business sector)

**Document Approval Workflow:**

Files follow a state machine:
```
draft → in_approvazione → [approvato | rifiutato]
```

- Only Manager+ roles can approve/reject
- Approval history tracked in `document_approvals` table
- Notifications sent via `approval_notifications` table
- Audit trail maintained for compliance

**Multi-Tenant Architecture:**

- **Tenant Isolation:** Every table (except system tables) has `tenant_id` column
- **Data Segregation:** Users can only see their tenant's data (except super_admin)
- **Admin Cross-Tenant Access:** Admins can access multiple tenants via `user_tenant_access` table
- **Tenant Switching:** UI component allows admins to switch between accessible tenants

## Important Constraints

**Technical Constraints:**

1. **No Framework Dependency:** Must remain vanilla PHP 8.3 (no Laravel, Symfony, etc.)
2. **XAMPP Compatibility:** Must work in Windows XAMPP environment (Apache, MySQL, PHP)
3. **Port 8888:** Development server runs on port 8888 (XAMPP default)
4. **Session Sharing:** Same session name (`COLLAB_SID`) between dev and production
5. **File Size Limit:** 100MB max file upload (`MAX_FILE_SIZE` constant)
6. **MySQL 8.0 Required:** Uses MySQL 8.0 features (JSON functions, CTEs, window functions)

**Security Constraints:**

1. **Tenant Isolation Mandatory:** ALL queries must include `tenant_id` filter (except super_admin operations)
2. **Soft Delete Required:** Hard deletes only for GDPR compliance or specific cases
3. **CSRF Protection:** All POST/PUT/DELETE operations require CSRF token
4. **Prepared Statements Only:** No string concatenation in SQL queries
5. **Password Complexity:** Minimum 8 characters, mixed case, numbers, special chars
6. **Session Timeout:** 2 hours (7200 seconds) of inactivity

**Business Constraints:**

1. **Italian Language:** All user-facing text in Italian
2. **Italian Tax Codes:** Must validate CF and P.IVA with proper checksums
3. **Italian Locations:** Must use official ISTAT data for provinces/municipalities
4. **Document Approval:** Manager+ role required for approval operations
5. **Audit Trail:** All sensitive operations must be logged to `audit_logs` table

**Database Constraints:**

1. **Soft Delete Pattern:** All main tables must have `deleted_at TIMESTAMP NULL` column
2. **Tenant Column:** All tenant-specific tables must have `tenant_id INT NOT NULL` column
3. **Timestamps:** All tables must have `created_at` and `updated_at` TIMESTAMP columns
4. **Foreign Keys:** Use `ON DELETE RESTRICT` to prevent accidental deletions
5. **Index Requirements:** All tenant_id and deleted_at columns must be indexed

## External Dependencies

**Email Services:**
- **Provider:** Infomaniak mail server
- **Configuration:** `includes/email_config.php` (credentials in separate file)
- **Library:** PHPMailer for SMTP
- **Usage:** Password resets, notifications, document approval alerts
- **Sender:** noreply@nexiosolution.it

**Italian Location Data:**
- **Source:** ISTAT (Istituto Nazionale di Statistica)
- **Data:** 107 provinces + 7,000+ municipalities
- **Tables:** `italian_provinces`, `italian_municipalities`
- **Update Frequency:** Annual (ISTAT releases)
- **No API:** Data stored locally, no external API calls

**AI Integration:**
- **Provider:** Anthropic Claude
- **SDK:** @anthropic-ai/claude-agent-sdk
- **Purpose:** Enhanced features and automation
- **Integration Points:** Custom agents in `.claude/agents/` directory

**Session Storage:**
- **Type:** File-based (default PHP sessions)
- **Location:** System temp directory
- **Session Name:** `COLLAB_SID` (shared between dev/prod)
- **Future:** Consider Redis for production scalability

**File Storage:**
- **Current:** Local filesystem in `uploads/` directory
- **Organization:** `/uploads/{tenant_id}/{folder_structure}/`
- **Future:** Consider AWS S3 or equivalent cloud storage at scale

**Database:**
- **Current:** MySQL 8.0 local instance (XAMPP)
- **Connection:** PDO with persistent connections
- **Character Set:** utf8mb4 (full Unicode support including emoji)
- **Collation:** utf8mb4_unicode_ci (case-insensitive, accent-sensitive)
- **Future:** Consider read replicas for high traffic

**Browser Requirements:**
- **Minimum:** Modern evergreen browsers (Chrome 90+, Firefox 88+, Safari 14+, Edge 90+)
- **JavaScript:** ES6+ features required (Fetch API, Promises, async/await)
- **CSS:** CSS3 features (Grid, Flexbox, custom properties)
- **Progressive Enhancement:** Core functionality works without JavaScript

---

## Quick Reference

**New Feature Checklist:**
- [ ] Add tenant isolation to ALL queries (`WHERE tenant_id = ? AND deleted_at IS NULL`)
- [ ] Use `api_auth.php` for new API endpoints
- [ ] Add CSRF protection to forms
- [ ] Return JSON from all API endpoints
- [ ] Use Database helper methods (not raw SQL)
- [ ] Test with different user roles
- [ ] Test multi-tenant scenarios
- [ ] Add audit logging for sensitive operations
- [ ] Document schema changes in `/database/migrations/`
- [ ] Update OVERVIEW.md if adding significant features

**Common Gotchas:**
- ❌ Forgetting `deleted_at IS NULL` filter → shows deleted records
- ❌ Missing `tenant_id` filter → data leakage between tenants
- ❌ No CSRF validation → security vulnerability
- ❌ Using string concatenation in SQL → SQL injection risk
- ❌ Hard deleting records → data loss and foreign key violations
- ❌ Not using `api_auth.php` → inconsistent API responses

**Emergency Contacts:**
- **Production URL:** https://app.nexiosolution.it/CollaboraNexio
- **Database Name:** collaboranexio
- **Session Name:** COLLAB_SID
- **Super Admin:** superadmin@collaboranexio.com / Admin123!
