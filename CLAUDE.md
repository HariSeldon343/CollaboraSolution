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
// Start of every API file
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');

// Use api_response.php helpers
require_once '../../includes/api_response.php';
api_success($data, 'Message');
api_error('Error message', 403);
```

### Database Connection
Use the singleton Database class:
```php
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();
```

### Session Management
- CSRF tokens in `$_SESSION['csrf_token']`
- User data in `$_SESSION['user_id']`, `$_SESSION['tenant_id']`, `$_SESSION['role']`
- Tenant switcher for Admin/Super Admin via `/api/tenant/switch.php`

## Key File Locations

### Core Configuration
- `/config.php` - Main configuration (DB, paths, security)
- `/includes/auth_simple.php` - Authentication class
- `/includes/db.php` - Database singleton class
- `/includes/api_response.php` - JSON response helpers

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
Always start APIs with error suppression and output buffering:
```php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
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
2. Ensure tenant isolation in queries
3. Add CSRF protection to forms
4. Return JSON from all API endpoints
5. Update both sidebar.php instances if adding pages
6. Test with different user roles

## Database Tables (22 total)

Core: `tenants`, `users`, `user_tenant_access`, `audit_logs`
Projects: `projects`, `project_members`, `project_milestones`
Files: `folders`, `files`, `file_shares`, `file_versions`
Tasks: `tasks`, `task_comments`, `task_assignments`
Calendar: `calendar_events`, `calendar_shares`, `event_attendees`
Chat: `chat_channels`, `chat_channel_members`, `chat_messages`, `chat_message_reads`
System: `sessions`, `user_sessions`, `password_resets`, `notifications`, `rate_limits`, `system_settings`, `document_approvals`, `approval_notifications`, `migration_history`

## Important Constants

From config.php:
- `DB_NAME`: 'collaboranexio'
- `BASE_URL`: 'http://localhost:8888/CollaboraNexio'
- `SESSION_LIFETIME`: 7200 (2 hours)
- `MAX_FILE_SIZE`: 104857600 (100MB)
- Environment: 'development' (DEBUG_MODE: true)