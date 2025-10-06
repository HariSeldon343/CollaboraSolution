# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CollaboraNexio is a multi-tenant enterprise collaboration platform built with vanilla PHP 8.3 (no frameworks). It's designed for XAMPP on Windows, running on port 8888.

## Critical Commands

### Database Setup & Migration
```bash
# Initial database setup (creates all tables and demo data)
php /mnt/c/xampp/htdocs/CollaboraNexio/database/manage_database.php full

# Run missing tables migration
php /mnt/c/xampp/htdocs/CollaboraNexio/execute_final_migration.php

# Check database structure
php /mnt/c/xampp/htdocs/CollaboraNexio/check_database_structure.php

# Safe migration for approval system
php /mnt/c/xampp/htdocs/CollaboraNexio/run_migration.php
```

### System Verification
```bash
# Check system health (access via browser)
http://localhost:8888/CollaboraNexio/system_check.php

# Test database connection
http://localhost:8888/CollaboraNexio/test_db.php

# Verify API endpoints
http://localhost:8888/CollaboraNexio/test_apis_browser.php
```

### Development URLs
- Login: `http://localhost:8888/CollaboraNexio/` or `/login.php`
- Dashboard: `/dashboard.php`
- Users Management: `/utenti.php`
- Files: `/files.php`
- Calendar: `/calendar.php`
- Tasks: `/tasks.php`
- Projects: `/progetti.php`
- Chat: `/chat.php`
- Document Approvals: `/document_approvals.php`

## Architecture & Key Patterns

### Multi-Tenant Architecture
Every table includes `tenant_id` for data isolation. Key tables:
- `tenants` - Organizations
- `users` - Users with role field
- `user_tenant_access` - Multi-tenant access for Admin/Super Admin
- All data tables have `tenant_id` foreign key

### Role Hierarchy
```
user → manager → admin → super_admin

- user: Single tenant, view only, NO approval rights
- manager: Single tenant, can approve documents, full CRUD
- admin: Multiple tenants access, manager rights
- super_admin: All tenants, complete system control
```

### Document Approval Workflow
Files have status: `in_approvazione`, `approvato`, `rifiutato`
- New/modified documents start as `in_approvazione`
- Only Manager/Admin/Super Admin can approve
- Approval history tracked in `document_approvals` table

### Authentication Pattern
All pages follow this pattern:
```php
session_start();
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}
$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();
```

### API Response Pattern
All APIs must return JSON even on errors:
```php
// NEW STANDARD: Use centralized API authentication
require_once '../../includes/api_auth.php';
initializeApiEnvironment(); // Sets headers, error handling, CORS

// Verify authentication and get user info
verifyApiAuthentication();
$userInfo = getApiUserInfo(); // Returns user_id, role, tenant_id
verifyApiCsrfToken(); // Validate CSRF token

// Use api_response.php helpers for output
api_success($data, 'Message');
api_error('Error message', 403);
```

**Legacy Pattern** (still used in some files):
```php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../includes/api_response.php';
```

### Database Connection
Use the singleton Database class with helper methods:
```php
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();
$conn = $db->getConnection(); // For raw PDO

// Helper methods (recommended over raw SQL):
$db->insert('users', ['email' => $email, 'name' => $name]);
$db->update('users', ['status' => 'active'], ['id' => $userId]);
$db->delete('users', ['id' => $userId]);
$users = $db->fetchAll('SELECT * FROM users WHERE tenant_id = ?', [$tenantId]);
$user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
$count = $db->count('users', ['tenant_id' => $tenantId]);

// Transactions
$db->beginTransaction();
try {
    $db->insert('users', $userData);
    $db->insert('audit_logs', $auditData);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Session Management
- CSRF tokens in `$_SESSION['csrf_token']`
- User data in `$_SESSION['user_id']`, `$_SESSION['tenant_id']`, `$_SESSION['role']`
- Tenant switcher for Admin/Super Admin via `/api/tenant/switch.php`

## Key File Locations

### Core Configuration
- `/config.php` - Main configuration (DB, paths, security, environment detection)
- `/includes/session_init.php` - Session initialization (called before session_start())
- `/includes/auth_simple.php` - Authentication class (AuthSimple/Auth)
- `/includes/api_auth.php` - **NEW**: Centralized API authentication & environment setup
- `/includes/db.php` - Database singleton class with CRUD helpers
- `/includes/api_response.php` - JSON response helpers (api_success, api_error, api_response)

### API Endpoints
- `/api/users/` - User management (list, create, update, delete, toggle-status, tenants)
- `/api/documents/` - Document approval (approve, reject, pending)
- `/api/tenant/switch.php` - Tenant switching for multi-tenant users
- `/api/files_complete.php` - Complete file management
- `/api/projects_complete.php` - Project management

### Database Scripts
- `/database/manage_database.php` - Main database management
- `/database/03_complete_schema.sql` - Complete schema (22 tables)
- `/database/04_demo_data.sql` - Demo data with ON DUPLICATE KEY
- `/database/05_approval_system.sql` - Approval system additions

### Components
- `/includes/sidebar.php` - Reusable sidebar navigation
- `/includes/tenant_switcher.php` - Tenant dropdown for Admin/Super Admin

## Common Issues & Solutions

### API Returns HTML Instead of JSON
Use the centralized `initializeApiEnvironment()` from `api_auth.php`:
```php
require_once '../../includes/api_auth.php';
initializeApiEnvironment();
```

**Legacy fix** (if not using api_auth.php):
```php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
```

### Soft Delete vs Hard Delete
CRITICAL: Always check for soft-deleted records:
```php
// WRONG: Will show deleted users
SELECT * FROM users WHERE tenant_id = ?

// CORRECT: Filter soft-deleted
SELECT * FROM users WHERE tenant_id = ? AND deleted_at IS NULL
```

### Foreign Key Constraint Errors
Disable checks before operations:
```sql
SET FOREIGN_KEY_CHECKS = 0;
-- operations
SET FOREIGN_KEY_CHECKS = 1;
```

### Session/CSRF Issues
Ensure session is started and CSRF validated:
```php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

## Testing Credentials

### Demo Users (password: Admin123!)
- `admin@demo.local` - Admin role
- `manager@demo.local` - Manager role
- `user1@demo.local` - User role
- `superadmin@collaboranexio.com` - Super Admin (if migration run)

## Development Workflow

When modifying the system:
1. Check role permissions for the feature
2. **CRITICAL**: Ensure tenant isolation in ALL queries (add `tenant_id = ?` WHERE clause)
3. **CRITICAL**: Filter soft-deleted records (add `deleted_at IS NULL` WHERE clause)
4. Add CSRF protection to forms
5. Use `api_auth.php` for new APIs (not legacy api_response.php pattern)
6. Return JSON from all API endpoints
7. Use Database helper methods (insert/update/delete) instead of raw SQL
8. Update both sidebar.php instances if adding pages
9. Test with different user roles (user, manager, admin, super_admin)
10. Test multi-tenant scenarios (switch tenants, verify data isolation)

## Critical Security Patterns

### Tenant Isolation (Multi-Tenancy)
EVERY query must include tenant isolation:
```php
// WRONG - Security vulnerability!
$users = $db->fetchAll("SELECT * FROM users WHERE status = 'active'");

// CORRECT - Tenant-isolated
$users = $db->fetchAll(
    "SELECT * FROM users WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL",
    [$tenant_id]
);
```

Exception: `super_admin` role can bypass tenant isolation when explicitly needed.

### Soft Delete Pattern
Never hard-delete. Always set `deleted_at`:
```php
// Mark as deleted
$db->update('users', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $userId]);

// Restore (if needed)
$db->update('users', ['deleted_at' => null], ['id' => $userId]);

// Hard delete (rare, only for compliance/GDPR)
$db->delete('users', ['id' => $userId]);
```

## Database Tables (22 total)

Core: `tenants`, `users`, `user_tenant_access`, `audit_logs`
Projects: `projects`, `project_members`, `project_milestones`
Files: `folders`, `files`, `file_shares`, `file_versions`
Tasks: `tasks`, `task_comments`, `task_assignments`
Calendar: `calendar_events`, `calendar_shares`, `event_attendees`
Chat: `chat_channels`, `chat_channel_members`, `chat_messages`, `chat_message_reads`
System: `sessions`, `user_sessions`, `password_resets`, `notifications`, `rate_limits`, `system_settings`, `document_approvals`, `approval_notifications`, `migration_history`

### Files Table - Actual Schema

IMPORTANT: The `files` table uses these column names (not the documented alternatives):
- `file_size` (NOT `size_bytes`) - BIGINT (no UNSIGNED)
- `file_path` (NOT `storage_path`) - VARCHAR(500)
- `uploaded_by` (NOT `owner_id`) - INT UNSIGNED

Additional columns in production:
- `original_tenant_id` - For tracking file reassignments
- `is_public`, `public_token` - For public file sharing
- `shared_with` - TEXT field for sharing data
- `download_count`, `last_accessed_at` - Usage tracking
- `reassigned_at`, `reassigned_by` - Reassignment tracking
- `status` - ENUM for approval workflow ('in_approvazione', 'approvato', 'rifiutato', 'draft')

**Schema Drift Note**: The documentation was corrected to match the actual production database. Always use `file_size`, `file_path`, and `uploaded_by` when working with files.

**Naming Convention Exception**: The `file_versions` table intentionally uses different naming:
- `size_bytes` (not file_size) - emphasizes historical archival size
- `storage_path` (not file_path) - emphasizes separate archival storage
- This semantic distinction between "current file" vs "historical snapshot" is intentional and correct.

## Important Constants

From config.php:
- `DB_NAME`: 'collaboranexio'
- `BASE_URL`: Dynamic (dev: 'http://localhost:8888/CollaboraNexio', prod: 'https://app.nexiosolution.it/CollaboraNexio')
- `SESSION_LIFETIME`: 7200 (2 hours)
- `SESSION_NAME`: 'COLLAB_SID' (shared across dev/prod for cross-domain sessions)
- `MAX_FILE_SIZE`: 104857600 (100MB)
- Environment: Auto-detected (checks for 'nexiosolution.it' in hostname)
- `PRODUCTION_MODE`: Auto-set based on hostname
- `DEBUG_MODE`: true in development, false in production

## Environment Detection

The application auto-detects production vs development:
```php
// In config.php - automatic environment detection
if (strpos($_SERVER['HTTP_HOST'], 'nexiosolution.it') !== false) {
    define('PRODUCTION_MODE', true);
    define('DEBUG_MODE', false);
} else {
    define('PRODUCTION_MODE', false);
    define('DEBUG_MODE', true);
}
```

### Session Sharing (Dev/Prod)
Sessions are shared between development and production using:
- Common `SESSION_NAME`: 'COLLAB_SID'
- Domain-aware cookies: `.nexiosolution.it` in production, empty in dev
- Allows testing production features locally with same session