# CollaboraNexio - Codebase Overview

## Project Summary

**CollaboraNexio** is a multi-tenant enterprise collaboration platform built with vanilla PHP 8.3 without frameworks. It provides a complete suite of collaboration tools including project management, file sharing, calendar, tasks, and real-time chat.

**Key Characteristics:**
- Framework-free PHP 8.3 application
- Multi-tenant architecture with strict data isolation
- XAMPP-based development environment (Windows, port 8888)
- Production deployment on nexiosolution.it
- Document approval workflow system
- Role-based access control (RBAC)
- Soft delete pattern throughout

---

## Architecture Patterns

### 1. Multi-Tenancy Pattern

Every data table includes a `tenant_id` column for strict data isolation between organizations.

```
┌─────────────────────────────────────┐
│         Application Layer           │
└─────────────────────────────────────┘
                 │
        ┌────────┴────────┐
        │  Tenant Filter   │  ← Every query filtered by tenant_id
        └────────┬────────┘
                 │
┌─────────────────────────────────────┐
│         Database Layer              │
│  ┌──────────┐ ┌──────────┐        │
│  │ Tenant 1 │ │ Tenant 2 │  ...   │
│  └──────────┘ └──────────┘        │
└─────────────────────────────────────┘
```

**Implementation:**
- All queries include `WHERE tenant_id = ?` clause
- Exception: Super Admin can bypass for cross-tenant operations
- `user_tenant_access` table enables admin users to access multiple tenants

### 2. Role-Based Access Control (RBAC)

**Hierarchy:** `user → manager → admin → super_admin`

| Role | Tenant Access | Approval Rights | Description |
|------|---------------|-----------------|-------------|
| `user` | Single | ❌ No | Read-only access to assigned resources |
| `manager` | Single | ✅ Yes | Full CRUD operations, can approve documents |
| `admin` | Multiple | ✅ Yes | Manager rights across multiple tenants |
| `super_admin` | All | ✅ Yes | Complete system control, bypass tenant isolation |

**Access Control Flow:**
```php
// 1. Authentication check
$auth = new Auth();
if (!$auth->checkAuth()) {
    redirect to login
}

// 2. Role verification
$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== 'manager') {
    deny access
}

// 3. Tenant isolation
WHERE tenant_id = $currentUser['tenant_id']
```

### 3. Singleton Pattern (Database Connection)

Single database instance shared across the entire application:

```php
// Database.php - Singleton implementation
class Database {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

// Usage everywhere
$db = Database::getInstance();
$users = $db->fetchAll('SELECT * FROM users WHERE tenant_id = ?', [$tenantId]);
```

### 4. Repository/Helper Pattern

The Database class provides helper methods abstracting raw SQL:

```php
// Instead of raw SQL
$stmt = $pdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
$stmt->execute([$name, $email]);

// Use helper methods
$db->insert('users', ['name' => $name, 'email' => $email]);
$db->update('users', ['status' => 'active'], ['id' => $userId]);
$db->delete('users', ['id' => $userId]);
```

### 5. Soft Delete Pattern

Never hard-delete records. Use `deleted_at` timestamp:

```
Active Record:     deleted_at = NULL
Deleted Record:    deleted_at = '2025-10-08 10:30:00'
```

**Critical Rule:** All queries MUST filter `deleted_at IS NULL`:
```php
// WRONG - Shows deleted records
SELECT * FROM users WHERE tenant_id = ?

// CORRECT - Only active records
SELECT * FROM users WHERE tenant_id = ? AND deleted_at IS NULL
```

### 6. Document Approval Workflow

Files follow a state machine pattern:

```
┌───────────┐
│   draft   │  ← Initial state
└─────┬─────┘
      │ Submit
      ▼
┌──────────────────┐
│ in_approvazione  │  ← Awaiting review
└────┬─────────────┘
     │
     ├─ Approve ──→ ┌───────────┐
     │              │ approvato │
     │              └───────────┘
     │
     └─ Reject ───→ ┌───────────┐
                    │ rifiutato │
                    └───────────┘
```

**Business Rules:**
- Only Manager+ roles can approve/reject
- Approval history tracked in `document_approvals` table
- Notifications sent via `approval_notifications` table

### 7. API Response Standardization

**New Pattern** (centralized via `api_auth.php`):
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

**Legacy Pattern** (still in some files):
```php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json');
require_once '../../includes/api_response.php';
```

### 8. CSRF Protection Pattern

All state-changing operations require CSRF token validation:

```php
// Generate in page/session
$csrfToken = $auth->generateCSRFToken();

// Include in forms
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

// Validate in API
verifyApiCsrfToken();
```

### 9. Session Management Pattern

```php
// Initialize with custom configuration
require_once __DIR__ . '/includes/session_init.php';
session_start();

// Shared session name across dev/prod
SESSION_NAME = 'COLLAB_SID'

// Session data structure
$_SESSION = [
    'user_id' => int,
    'tenant_id' => int,
    'role' => string,
    'csrf_token' => string,
    'last_activity' => timestamp
];
```

---

## Technology Stack

### Backend
- **PHP 8.3** - Modern PHP without frameworks
- **MySQL 8.0** - Relational database with strict ACID compliance
- **PDO** - Database abstraction with prepared statements

### Frontend
- **Vanilla JavaScript (ES6+)** - No frameworks
- **CSS3** - Custom styles, responsive design
- **Bootstrap Icons** - Icon library
- **Fetch API** - Modern AJAX requests

### Server
- **Apache** - via XAMPP on Windows
- **Development**: localhost:8888
- **Production**: nexiosolution.it

### Security
- **CSRF Tokens** - On all state-changing operations
- **Prepared Statements** - SQL injection prevention
- **Password Hashing** - PHP password_hash() with bcrypt
- **Session Management** - Secure session configuration
- **Input Validation** - Server-side validation on all inputs

---

## Directory Structure

```
CollaboraNexio/
├── api/                          # API endpoints (RESTful-style)
│   ├── users/                    # User management APIs
│   ├── documents/                # Document approval APIs
│   ├── tenants/                  # Tenant management
│   │   └── locations/            # Tenant locations sub-API
│   ├── files_complete.php        # File operations
│   └── projects_complete.php     # Project operations
│
├── includes/                     # Core PHP includes
│   ├── config.php                # Main configuration
│   ├── db.php                    # Database singleton class
│   ├── auth_simple.php           # Authentication class
│   ├── api_auth.php              # NEW: Centralized API auth
│   ├── api_response.php          # JSON response helpers
│   ├── session_init.php          # Session initialization
│   ├── sidebar.php               # Reusable navigation
│   ├── tenant_switcher.php       # Multi-tenant switcher
│   └── mailer.php                # Email functionality
│
├── database/                     # SQL schemas and migrations
│   ├── manage_database.php       # Database management CLI
│   ├── 03_complete_schema.sql    # Full schema (22 tables)
│   ├── 04_demo_data.sql          # Demo data with credentials
│   ├── 05_approval_system.sql    # Approval workflow tables
│   └── migrations/               # Version-controlled migrations
│
├── assets/                       # Static assets
│   ├── css/                      # Stylesheets
│   ├── js/                       # JavaScript modules
│   └── images/                   # Images and logos
│
├── logs/                         # Application logs
│   ├── php_errors.log
│   └── database_errors.log
│
├── uploads/                      # User-uploaded files
│   └── [organized by tenant/folder structure]
│
├── *.php                         # Frontend pages
│   ├── index.php                 # Login page
│   ├── dashboard.php             # Main dashboard
│   ├── utenti.php                # User management
│   ├── progetti.php              # Projects
│   ├── files.php                 # File manager
│   ├── calendar.php              # Calendar
│   ├── tasks.php                 # Task management
│   ├── chat.php                  # Real-time chat
│   └── document_approvals.php    # Approval workflow
│
└── Documentation Files           # Extensive documentation
    ├── CLAUDE.md                 # AI assistant instructions
    ├── OVERVIEW.md               # This file
    ├── README.md                 # Project readme
    └── [50+ implementation/verification docs]
```

---

## Database Schema (24 Tables)

### Core Multi-Tenancy
- `tenants` - Organizations/companies (with optional fields: telefono, pec, data_costituzione, manager_user_id)
- `users` - Users with role field
- `user_tenant_access` - Multi-tenant access for admins

### Projects Module
- `projects` - Project definitions
- `project_members` - Project team membership
- `project_milestones` - Project milestones

### Files Module
- `folders` - Folder hierarchy
- `files` - File metadata with approval status
- `file_shares` - Sharing permissions
- `file_versions` - Version history

### Tasks Module
- `tasks` - Task definitions
- `task_comments` - Task discussions
- `task_assignments` - Task assignments to users

### Calendar Module
- `calendar_events` - Calendar events
- `calendar_shares` - Calendar sharing
- `event_attendees` - Event participants

### Chat Module
- `chat_channels` - Chat rooms
- `chat_channel_members` - Channel membership
- `chat_messages` - Messages
- `chat_message_reads` - Read receipts

### System Tables
- `sessions` - Active sessions
- `user_sessions` - Session tracking
- `password_resets` - Password reset tokens
- `notifications` - System notifications
- `rate_limits` - API rate limiting
- `system_settings` - System configuration
- `document_approvals` - Approval workflow history
- `approval_notifications` - Approval notifications
- `migration_history` - Database migration tracking
- `audit_logs` - Audit trail

### Italian Locations (System Data)
- `italian_provinces` - 107 Italian provinces (NO tenant_id, NO deleted_at)
- `italian_municipalities` - 7,000+ Italian comuni with province relationships

---

## Critical Security Patterns

### 1. Tenant Isolation (Multi-Tenancy)

**EVERY query must include tenant isolation:**

```php
// ❌ WRONG - Security vulnerability!
$users = $db->fetchAll("SELECT * FROM users WHERE status = 'active'");

// ✅ CORRECT - Tenant-isolated
$users = $db->fetchAll(
    "SELECT * FROM users
     WHERE tenant_id = ?
     AND status = 'active'
     AND deleted_at IS NULL",
    [$tenant_id]
);
```

**Exception:** `super_admin` role can bypass tenant isolation when explicitly needed.

### 2. Soft Delete Pattern

**Never hard-delete. Always set `deleted_at`:**

```php
// Mark as deleted
$db->update('users',
    ['deleted_at' => date('Y-m-d H:i:s')],
    ['id' => $userId]
);

// Restore (if needed)
$db->update('users',
    ['deleted_at' => null],
    ['id' => $userId]
);

// Hard delete (rare, only for compliance/GDPR)
$db->delete('users', ['id' => $userId]);
```

### 3. Input Validation & Sanitization

```php
// Always validate input
$email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    api_error('Invalid email format', 400);
}

// Use prepared statements (prevents SQL injection)
$db->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
```

### 4. Password Security

```php
// Hash passwords with bcrypt
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Verify passwords
if (password_verify($inputPassword, $storedHash)) {
    // Password correct
}
```

---

## Environment Configuration

### Auto-Detection

The application auto-detects production vs development:

```php
// config.php - Automatic environment detection
if (strpos($_SERVER['HTTP_HOST'], 'nexiosolution.it') !== false) {
    define('PRODUCTION_MODE', true);
    define('DEBUG_MODE', false);
    define('BASE_URL', 'https://app.nexiosolution.it/CollaboraNexio');
} else {
    define('PRODUCTION_MODE', false);
    define('DEBUG_MODE', true);
    define('BASE_URL', 'http://localhost:8888/CollaboraNexio');
}
```

### Constants

| Constant | Development | Production |
|----------|-------------|------------|
| `DB_NAME` | collaboranexio | collaboranexio |
| `BASE_URL` | http://localhost:8888/CollaboraNexio | https://app.nexiosolution.it/CollaboraNexio |
| `PRODUCTION_MODE` | false | true |
| `DEBUG_MODE` | true | false |
| `SESSION_NAME` | COLLAB_SID | COLLAB_SID (shared) |
| `SESSION_LIFETIME` | 7200 seconds (2 hours) | 7200 seconds |
| `MAX_FILE_SIZE` | 104857600 (100MB) | 104857600 (100MB) |

---

## Development Workflow

### Standard Page Development

```php
<?php
// 1. Session initialization
require_once __DIR__ . '/includes/session_init.php';
session_start();

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

### Standard API Development

```php
<?php
// Use centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (headers, error handling)
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();
$userInfo = getApiUserInfo(); // Returns user_id, role, tenant_id

// CSRF validation
verifyApiCsrfToken();

// API logic with tenant isolation
$db = Database::getInstance();
$results = $db->fetchAll(
    'SELECT * FROM table WHERE tenant_id = ? AND deleted_at IS NULL',
    [$userInfo['tenant_id']]
);

// Standard response
api_success($results, 'Data retrieved successfully');
?>
```

### Checklist for New Features

- [ ] Ensure tenant isolation in ALL queries (`tenant_id = ?`)
- [ ] Filter soft-deleted records (`deleted_at IS NULL`)
- [ ] Add CSRF protection to forms
- [ ] Use `api_auth.php` for new APIs
- [ ] Return JSON from all API endpoints
- [ ] Use Database helper methods (not raw SQL)
- [ ] Update sidebar navigation if adding pages
- [ ] Test with different user roles
- [ ] Test multi-tenant scenarios
- [ ] Add audit logging for sensitive operations
- [ ] Document any schema changes

---

## Testing

### Demo Credentials

All demo users use password: **Admin123!**

| Email | Role | Tenant | Notes |
|-------|------|--------|-------|
| admin@demo.local | admin | Demo Co | Multi-tenant access |
| manager@demo.local | manager | Demo Co | Can approve documents |
| user1@demo.local | user | Demo Co | Read-only access |
| superadmin@collaboranexio.com | super_admin | All | System-wide access |

### Key Test URLs

- Login: http://localhost:8888/CollaboraNexio/
- System Health: http://localhost:8888/CollaboraNexio/system_check.php
- Database Test: http://localhost:8888/CollaboraNexio/test_db.php
- API Test: http://localhost:8888/CollaboraNexio/test_apis_browser.php
- **Companies System Test**: http://localhost:8888/CollaboraNexio/test_aziende_system_complete.php (22 automated tests)
- **System Integrity**: http://localhost:8888/CollaboraNexio/verify_system_integrity.php (Comprehensive health check)

### Database Management

```bash
# Full database reset (creates all tables + demo data)
php /mnt/c/xampp/htdocs/CollaboraNexio/database/manage_database.php full

# Check database structure
php /mnt/c/xampp/htdocs/CollaboraNexio/check_database_structure.php

# Run missing tables migration
php /mnt/c/xampp/htdocs/CollaboraNexio/execute_final_migration.php

# Install Italian locations system
php /mnt/c/xampp/htdocs/CollaboraNexio/run_italian_locations_migration.php

# Install tenant soft-delete procedures
php /mnt/c/xampp/htdocs/CollaboraNexio/run_tenant_delete_migration.php
```

---

## Common Issues & Solutions

### 1. API Returns HTML Instead of JSON

**Cause:** PHP errors/warnings being output before JSON
**Solution:** Use centralized `initializeApiEnvironment()`:

```php
require_once '../../includes/api_auth.php';
initializeApiEnvironment();
```

### 2. Foreign Key Constraint Errors

**Cause:** Deleting records with existing relationships
**Solution:** Use soft delete or temporarily disable checks:

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- operations
SET FOREIGN_KEY_CHECKS = 1;
```

### 3. Seeing Deleted Records

**Cause:** Not filtering `deleted_at IS NULL`
**Solution:** Add to all queries:

```php
WHERE tenant_id = ? AND deleted_at IS NULL
```

### 4. Session/CSRF Issues

**Cause:** Session not started or CSRF token mismatch
**Solution:** Always initialize session properly:

```php
require_once __DIR__ . '/includes/session_init.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

---

## Design Principles

### 1. Security First
- Tenant isolation is mandatory (except for super_admin)
- All queries use prepared statements
- CSRF tokens on all state-changing operations
- Soft delete prevents data loss
- Comprehensive audit logging

### 2. Simplicity Over Frameworks
- No framework dependencies
- Direct PHP code is easier to understand and debug
- Fewer abstractions = more control
- Suitable for XAMPP/Windows environments

### 3. Multi-Tenancy by Default
- Every feature must support multiple tenants
- Data isolation is enforced at database level
- Admin users can access multiple tenants seamlessly

### 4. Progressive Enhancement
- Works without JavaScript (forms submit normally)
- JavaScript adds better UX (AJAX, real-time updates)
- Graceful degradation for older browsers

### 5. Developer Experience
- Extensive documentation (50+ docs)
- Clear naming conventions
- Helper methods reduce boilerplate
- Comprehensive error logging

---

## Recent Enhancements (October 2025)

### Italian Locations System

**Database Schema:**
- `italian_provinces` table with 107 Italian provinces
- `italian_municipalities` table with 7,000+ Italian comuni
- Foreign key constraints for data integrity
- Optimized indexes for fast lookup (<1ms queries)

**API Endpoints:**
- `GET /api/locations/list_provinces.php` - Returns all Italian provinces
- `GET /api/locations/list_municipalities.php?province=RM` - Municipalities by province
- `GET /api/locations/validate_municipality.php?municipality=Roma&province=RM` - Validates comune/provincia pairs

**Features:**
- Real-time validation during company creation
- Case-insensitive matching
- Comprehensive coverage of Italian administrative divisions
- No authentication required (public validation)

**Frontend Integration:**
- Dynamic province dropdowns
- Comune validation on blur
- User-friendly error messages in Italian
- Pre-submission validation to prevent invalid data

### Company Management Improvements

**Optional Fields:**
The following fields are now optional (nullable) when creating companies:
- `telefono` - Phone number
- `pec` - Certified email (Posta Elettronica Certificata)
- `data_costituzione` - Constitution date
- `manager_user_id` - Assigned manager (can create company without manager)

**Universal Tenant Deletion (OpenSpec COLLAB-2025-002):**
✨ **NEW:** All tenants including tenant ID 1 can now be deleted with proper safeguards:

**Features:**
- Double confirmation required for system tenant (ID 1)
- Enhanced warning modal with detailed consequences
- Platform access control when no tenants exist
- Super admin bypass for maintenance access
- Clear error messages: "Accesso negato: non hai aziende associate"

**Security Layers:**
1. **UI Layer:** Enhanced warning modal with visual indicators
2. **Application Layer:** Explicit `confirm_system_tenant` parameter required
3. **Database Layer:** Soft delete with restore capability
4. **Access Control:** Platform-wide tenant existence check on all protected pages

**Platform Access Control:**
- When no active tenants exist:
  - Super admins: Full platform access (maintenance mode)
  - All other users: Redirected to login with access denied message
- Middleware: `requireTenantAccess()` on all 15 protected pages
- API endpoint: `GET /api/auth/check-platform-access.php`

**Stored Procedures:**
- `sp_soft_delete_tenant_complete` - Cascade soft-delete across 27 tables (updated to allow tenant ID 1)
- `sp_restore_tenant` - Restore soft-deleted tenants with all related data
- `fn_count_tenant_records` - Count records across all tables for a tenant

### Testing & Verification

**Automated Test Suite:**
- `test_aziende_system_complete.php` - 22 automated tests
- Database integrity verification
- API endpoint testing
- Frontend validation testing
- Optional fields validation
- System tenant protection verification

**System Integrity Verification:**
- `verify_system_integrity.php` - Comprehensive health check
- Schema validation across 22+ tables
- Foreign key integrity checks
- Performance benchmarking
- Link verification across all pages
- Configuration consistency checks

---

## Future Considerations

### Potential Improvements
1. **API Documentation**: OpenAPI/Swagger specification
2. **Extended Location Data**: Add CAP (postal codes) and geographic coordinates
3. **Cache Layer**: Redis for session storage and caching
4. **Queue System**: Background job processing
5. **WebSocket Server**: Real-time chat improvements
6. **Docker Containerization**: Easier deployment
7. **CI/CD Pipeline**: Automated testing and deployment
8. **Monitoring**: Application performance monitoring (APM)

### Scalability Notes
- Current design supports ~1000 concurrent users
- File storage should move to S3/cloud storage at scale
- Database read replicas recommended for high traffic
- Consider microservices for chat/notifications

---

## Contributing

When contributing to this codebase:

1. Follow existing patterns (see CLAUDE.md)
2. Ensure tenant isolation in all queries
3. Use soft delete, never hard delete
4. Add comprehensive error handling
5. Document complex logic
6. Test with multiple roles and tenants
7. Update relevant documentation files

---

## License & Support

**Project:** CollaboraNexio
**Version:** 1.0 (Active Development)
**Environment:** PHP 8.3 / MySQL 8.0 / XAMPP
**Deployment:** https://app.nexiosolution.it/CollaboraNexio

For technical details, see [CLAUDE.md](CLAUDE.md)
For quick start, see [QUICK_START_GUIDE.txt](QUICK_START_GUIDE.txt)
