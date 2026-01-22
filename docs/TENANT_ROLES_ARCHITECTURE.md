# Tenant Roles (Ruoli Aziendali) Architecture Design

**Document Version:** 1.0.0
**Date:** 2025-12-15
**Author:** System Architect
**Status:** DESIGN PROPOSAL

---

## Executive Summary

This document outlines the comprehensive architecture for implementing "Tenant Roles" (Ruoli Aziendali) feature in CollaboraNexio. The feature allows companies (tenants) to define custom roles like "Commerciale", "Tecnico", "Amministrativo", etc., and assign users to these roles.

---

## 1. Current State Analysis

### 1.1 Database Schema (Relevant Tables)

#### users Table
```sql
`role` ENUM('super_admin', 'admin', 'manager', 'user') DEFAULT 'user'
-- This is the SYSTEM role (user type)
```

#### tenants Table
```sql
id INT UNSIGNED NOT NULL AUTO_INCREMENT
name VARCHAR(255) NOT NULL
domain VARCHAR(255)
status ENUM('active', 'inactive', 'trial', 'suspended')
plan_type ENUM('basic', 'professional', 'enterprise')
max_users INT
settings JSON
-- NO role-related columns currently
```

#### user_tenant_access Table
```sql
id INT UNSIGNED NOT NULL AUTO_INCREMENT
user_id INT UNSIGNED NOT NULL  -- FK to users.id
tenant_id INT UNSIGNED NOT NULL  -- FK to tenants.id
role_in_tenant ENUM('admin', 'manager', 'user', 'guest') DEFAULT 'admin'
granted_by INT UNSIGNED NULL
granted_at TIMESTAMP
-- This tracks SYSTEM role per tenant, NOT custom business roles
```

#### workflow_roles Table (Different concept - for document workflow)
```sql
workflow_role ENUM('validator', 'approver')  -- Used for document approval workflow
-- NOT related to business/company roles
```

### 1.2 Terminology Clarification

| Current Term | New Term (Italian) | New Term (English) | Description |
|--------------|-------------------|-------------------|-------------|
| `role` (users table) | Tipo Utente | User Type | System permission level |
| N/A | Ruolo Aziendale | Tenant Role | Company-specific business role |

---

## 2. Requirements Summary

### 2.1 Core Requirements

1. **Terminology Rename**
   - "Ruolo" in UI -> "Tipo Utente" for system roles (super_admin, admin, manager, user)
   - New "Ruolo" / "Ruolo Aziendale" for custom company roles

2. **Tenant Roles Definition**
   - Companies can OPTIONALLY define custom roles
   - Examples: "Commerciale", "Tecnico", "Amministrativo", "Legale", "HR"
   - Roles are tenant-specific (not shared between companies)

3. **User-Role Assignment**
   - Each user can have ONE tenant role per company
   - Role is REQUIRED if the company has defined roles
   - Role is OPTIONAL/hidden if company has no roles defined

4. **Manager User Creation**
   - Managers can create users for their company
   - New users get `user_type='user'` (not admin/manager)
   - If company has roles, manager MUST select one

---

## 3. Database Design

### 3.1 New Table: `tenant_roles`

```sql
-- ============================================
-- TABLE: TENANT_ROLES
-- Purpose: Define custom business roles per tenant
-- ============================================

CREATE TABLE IF NOT EXISTS tenant_roles (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant that owns this role',

    -- Role Definition
    name VARCHAR(100) NOT NULL COMMENT 'Role display name (e.g., Commerciale)',
    code VARCHAR(50) NOT NULL COMMENT 'Role code for programmatic use (e.g., commerciale)',
    description TEXT NULL COMMENT 'Optional description of role responsibilities',
    color VARCHAR(7) NULL DEFAULT '#6366f1' COMMENT 'HEX color for UI badges',
    icon VARCHAR(50) NULL COMMENT 'Optional icon identifier',

    -- Ordering and Status
    sort_order INT UNSIGNED DEFAULT 0 COMMENT 'Display order in lists',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can be temporarily disabled',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'User who created this role',

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_tenant_roles_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_tenant_roles_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique Constraints
    UNIQUE KEY uk_tenant_role_code (tenant_id, code, deleted_at),
    UNIQUE KEY uk_tenant_role_name (tenant_id, name, deleted_at),

    -- Indexes for Multi-Tenant Queries
    INDEX idx_tenant_roles_tenant_active (tenant_id, is_active, deleted_at),
    INDEX idx_tenant_roles_sort (tenant_id, sort_order),
    INDEX idx_tenant_roles_deleted (tenant_id, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Custom business roles defined by each tenant';
```

### 3.2 Modify Table: `user_tenant_access`

```sql
-- ============================================
-- ALTER TABLE: USER_TENANT_ACCESS
-- Add reference to tenant_role
-- ============================================

ALTER TABLE user_tenant_access
ADD COLUMN tenant_role_id INT UNSIGNED NULL
    COMMENT 'FK to tenant_roles.id - company-specific role'
    AFTER role_in_tenant;

ALTER TABLE user_tenant_access
ADD CONSTRAINT fk_user_tenant_access_role
    FOREIGN KEY (tenant_role_id)
    REFERENCES tenant_roles(id)
    ON DELETE SET NULL;

-- Index for quick role-based queries
ALTER TABLE user_tenant_access
ADD INDEX idx_user_tenant_access_role (tenant_id, tenant_role_id);
```

### 3.3 Modify Table: `tenants` (Optional Enhancement)

```sql
-- ============================================
-- ALTER TABLE: TENANTS
-- Add flag to indicate if tenant uses custom roles
-- ============================================

ALTER TABLE tenants
ADD COLUMN has_custom_roles TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'TRUE if tenant has defined custom business roles'
    AFTER settings;

-- Index for filtering tenants with roles
ALTER TABLE tenants
ADD INDEX idx_tenants_custom_roles (has_custom_roles);
```

### 3.4 Column Rename Consideration

**Decision: DO NOT rename `users.role` to `users.user_type`**

Reasons:
1. Would require changing 50+ PHP files
2. High risk of regressions
3. Can handle terminology change via UI labels only
4. Database column name doesn't affect user experience

**Alternative approach:**
- Keep `users.role` as the column name
- Change UI labels from "Ruolo" to "Tipo Utente"
- New feature uses `tenant_roles` table

---

## 4. API Design

### 4.1 Tenant Roles CRUD API

#### 4.1.1 List Tenant Roles
```
GET /api/tenant-roles/list.php?tenant_id={id}

Response:
{
    "success": true,
    "message": "Ruoli aziendali caricati",
    "data": {
        "roles": [
            {
                "id": 1,
                "name": "Commerciale",
                "code": "commerciale",
                "description": "Ruolo commerciale",
                "color": "#10b981",
                "sort_order": 1,
                "is_active": true,
                "user_count": 5
            }
        ],
        "has_roles": true
    }
}
```

#### 4.1.2 Create Tenant Role
```
POST /api/tenant-roles/create.php

Body:
{
    "tenant_id": 11,
    "name": "Commerciale",
    "code": "commerciale",
    "description": "Gestione clienti e vendite",
    "color": "#10b981"
}

Response:
{
    "success": true,
    "message": "Ruolo aziendale creato",
    "data": {
        "role": { ... }
    }
}
```

#### 4.1.3 Update Tenant Role
```
POST /api/tenant-roles/update.php

Body:
{
    "id": 1,
    "tenant_id": 11,
    "name": "Commerciale Senior",
    "is_active": true
}
```

#### 4.1.4 Delete Tenant Role
```
POST /api/tenant-roles/delete.php

Body:
{
    "id": 1,
    "tenant_id": 11
}
```

### 4.2 User Assignment API Modification

#### 4.2.1 Update User Tenant Role
```
POST /api/users/update.php

Body:
{
    "user_id": 32,
    "tenant_id": 11,
    "tenant_role_id": 1  // New field
}
```

#### 4.2.2 Get User with Tenant Role
```
GET /api/users/get.php?id=32

Response:
{
    "success": true,
    "data": {
        "user": {
            "id": 32,
            "name": "Mario Rossi",
            "role": "user",  // System role (tipo utente)
            "tenant_id": 11,
            "tenant_role": {  // Business role (ruolo aziendale)
                "id": 1,
                "name": "Commerciale",
                "code": "commerciale",
                "color": "#10b981"
            }
        }
    }
}
```

### 4.3 Manager User Creation API

```
POST /api/users/create.php

Headers:
X-CSRF-Token: {token}

Body:
{
    "name": "Nuovo Utente",
    "email": "nuovo@azienda.it",
    "role": "user",  // FIXED - managers can only create 'user' type
    "tenant_id": 11,  // AUTO from manager's tenant
    "tenant_role_id": 1  // Required if tenant has roles
}

Validation Rules:
1. If caller is manager, role MUST be 'user'
2. If caller is manager, tenant_id MUST match caller's tenant_id
3. If tenant has_custom_roles=1, tenant_role_id is REQUIRED
4. tenant_role_id must belong to same tenant_id
```

---

## 5. Files to Modify

### 5.1 Database Migrations

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/19_add_tenant_roles.sql` | CREATE | New migration for tenant_roles table |
| `database/migrations/19_add_tenant_roles_rollback.sql` | CREATE | Rollback migration |

### 5.2 API Files (Backend)

| File | Action | Changes |
|------|--------|---------|
| `api/tenant-roles/list.php` | CREATE | List tenant roles |
| `api/tenant-roles/create.php` | CREATE | Create tenant role |
| `api/tenant-roles/update.php` | CREATE | Update tenant role |
| `api/tenant-roles/delete.php` | CREATE | Delete tenant role |
| `api/users/create.php` | MODIFY | Add tenant_role_id handling |
| `api/users/update.php` | MODIFY | Add tenant_role_id handling |
| `api/users/list.php` | MODIFY | Include tenant_role in response |
| `api/tenants/get.php` | MODIFY | Include has_custom_roles flag |
| `api/tenants/create.php` | MODIFY | Initialize has_custom_roles |
| `api/tenants/update.php` | MODIFY | Update has_custom_roles |

### 5.3 PHP Pages (Frontend)

| File | Action | UI Changes |
|------|--------|-----------|
| `utenti.php` | MODIFY | "Ruolo" -> "Tipo Utente", add "Ruolo Aziendale" column |
| `aziende.php` | MODIFY | Add "Ruoli Aziendali" section in edit modal |
| `profilo.php` | MODIFY | Show "Ruolo Aziendale" if applicable |

### 5.4 JavaScript Files

| File | Action | Changes |
|------|--------|---------|
| `assets/js/users.js` | CREATE/MODIFY | Handle tenant_role_id in CRUD |
| `assets/js/aziende.js` | MODIFY | Add role management to company edit |
| `assets/js/tenant_roles.js` | CREATE | New JS manager for tenant roles |

### 5.5 UI Label Changes (Italian)

| File | Line | Current | New |
|------|------|---------|-----|
| `utenti.php` | 875 | `<th>Ruolo</th>` | `<th>Tipo Utente</th>` |
| `utenti.php` | 919 | `<label>Ruolo</label>` | `<label>Tipo Utente</label>` |
| `utenti.php` | 966 | `<label>Ruolo</label>` | `<label>Tipo Utente</label>` |
| Various PHP | - | Comments with "ruolo" | Keep as-is (code comments) |

---

## 6. Implementation Plan

### Phase 1: Database Migration (Day 1)

1. Create and test migration SQL
2. Backup production database
3. Run migration on development
4. Verify indexes and constraints
5. Run migration on production

### Phase 2: API Layer (Day 2-3)

1. Create tenant-roles CRUD API
2. Modify users API for tenant_role_id
3. Modify tenants API for has_custom_roles
4. Add validation rules
5. Write API tests

### Phase 3: UI - Tenant Roles Management (Day 4)

1. Add "Ruoli Aziendali" tab to aziende.php
2. Create role CRUD interface
3. Add color picker for role badges
4. Add drag-drop reordering

### Phase 4: UI - User Assignment (Day 5)

1. Rename "Ruolo" to "Tipo Utente" in utenti.php
2. Add "Ruolo Aziendale" dropdown (conditional)
3. Update user list table to show both
4. Update user edit modal

### Phase 5: Manager User Creation (Day 6)

1. Create manager-specific create user flow
2. Restrict role selection to 'user' only
3. Auto-fill tenant_id from manager session
4. Require tenant_role_id if applicable

### Phase 6: Testing & Polish (Day 7)

1. Full integration testing
2. UI/UX review
3. Documentation update
4. CLAUDE.md update

---

## 7. Detailed Code Changes

### 7.1 Migration SQL

```sql
-- ============================================
-- MIGRATION 19: ADD TENANT ROLES SYSTEM
-- Version: 1.0.0
-- Date: 2025-12-XX
-- Author: System Architect
-- ============================================

USE collaboranexio;

-- Pre-flight check
SELECT 'Starting Migration 19: Add Tenant Roles System' as status;

-- ============================================
-- STEP 1: Create tenant_roles table
-- ============================================

CREATE TABLE IF NOT EXISTS tenant_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant that owns this role',
    name VARCHAR(100) NOT NULL COMMENT 'Role display name',
    code VARCHAR(50) NOT NULL COMMENT 'Role code for programmatic use',
    description TEXT NULL COMMENT 'Optional description',
    color VARCHAR(7) NULL DEFAULT '#6366f1' COMMENT 'HEX color for badges',
    icon VARCHAR(50) NULL COMMENT 'Optional icon identifier',
    sort_order INT UNSIGNED DEFAULT 0 COMMENT 'Display order',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,

    PRIMARY KEY (id),

    CONSTRAINT fk_tenant_roles_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_tenant_roles_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY uk_tenant_role_code (tenant_id, code, deleted_at),
    UNIQUE KEY uk_tenant_role_name (tenant_id, name, deleted_at),

    INDEX idx_tenant_roles_tenant_active (tenant_id, is_active, deleted_at),
    INDEX idx_tenant_roles_sort (tenant_id, sort_order),
    INDEX idx_tenant_roles_deleted (tenant_id, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Custom business roles defined by each tenant';

-- ============================================
-- STEP 2: Add tenant_role_id to user_tenant_access
-- ============================================

-- Check if column exists
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND COLUMN_NAME = 'tenant_role_id'
);

-- Add column if not exists
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD COLUMN tenant_role_id INT UNSIGNED NULL
     COMMENT ''FK to tenant_roles.id - company-specific role''
     AFTER role_in_tenant',
    'SELECT ''Column already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add FK constraint if not exists
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND CONSTRAINT_NAME = 'fk_user_tenant_access_role'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD CONSTRAINT fk_user_tenant_access_role
     FOREIGN KEY (tenant_role_id) REFERENCES tenant_roles(id) ON DELETE SET NULL',
    'SELECT ''FK already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'user_tenant_access'
    AND INDEX_NAME = 'idx_user_tenant_access_tenant_role'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE user_tenant_access
     ADD INDEX idx_user_tenant_access_tenant_role (tenant_id, tenant_role_id)',
    'SELECT ''Index already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- STEP 3: Add has_custom_roles to tenants
-- ============================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'has_custom_roles'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE tenants
     ADD COLUMN has_custom_roles TINYINT(1) NOT NULL DEFAULT 0
     COMMENT ''TRUE if tenant has defined custom business roles''
     AFTER settings',
    'SELECT ''Column already exists'' as status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration 19 - Verification' as section;

-- Check tenant_roles table
SELECT
    'tenant_roles' as table_name,
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles') as exists_check,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_roles') as column_count;

-- Check user_tenant_access modification
SELECT
    'user_tenant_access.tenant_role_id' as column_name,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio'
     AND TABLE_NAME = 'user_tenant_access'
     AND COLUMN_NAME = 'tenant_role_id') as exists_check;

-- Check tenants modification
SELECT
    'tenants.has_custom_roles' as column_name,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio'
     AND TABLE_NAME = 'tenants'
     AND COLUMN_NAME = 'has_custom_roles') as exists_check;

-- Check FK constraints
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
AND CONSTRAINT_NAME LIKE '%tenant_role%';

SELECT 'Migration 19 completed successfully' as status;
```

### 7.2 API: `/api/tenant-roles/list.php`

```php
<?php
/**
 * API: List Tenant Roles
 * GET /api/tenant-roles/list.php?tenant_id={id}
 *
 * Returns all custom business roles for a tenant
 * Auth: Admin, Manager (own tenant), Super Admin (any tenant)
 */

require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Metodo non consentito', 405);
}

verifyApiAuthentication();
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

$db = Database::getInstance();

// Get tenant_id from query or user's tenant
$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : (int)$userInfo['tenant_id'];

// Authorization check
if ($userInfo['role'] !== 'super_admin' && $tenantId !== (int)$userInfo['tenant_id']) {
    api_error('Non autorizzato ad accedere a questo tenant', 403);
}

try {
    // Get roles with user count
    $roles = $db->fetchAll("
        SELECT
            tr.id,
            tr.name,
            tr.code,
            tr.description,
            tr.color,
            tr.icon,
            tr.sort_order,
            tr.is_active,
            tr.created_at,
            (
                SELECT COUNT(*)
                FROM user_tenant_access uta
                WHERE uta.tenant_role_id = tr.id
                AND uta.deleted_at IS NULL
            ) as user_count
        FROM tenant_roles tr
        WHERE tr.tenant_id = ?
        AND tr.deleted_at IS NULL
        ORDER BY tr.sort_order ASC, tr.name ASC
    ", [$tenantId]);

    // Check if tenant has custom roles
    $hasRoles = !empty($roles);

    api_success([
        'roles' => $roles,
        'has_roles' => $hasRoles,
        'tenant_id' => $tenantId
    ], 'Ruoli aziendali caricati');

} catch (Exception $e) {
    error_log('[TENANT-ROLES] List error: ' . $e->getMessage());
    api_error('Errore nel caricamento dei ruoli', 500);
}
```

---

## 8. Testing Checklist

### 8.1 Database Tests

- [ ] Migration runs without errors
- [ ] Rollback works correctly
- [ ] Foreign keys enforced
- [ ] Unique constraints work
- [ ] Indexes created

### 8.2 API Tests

- [ ] List roles - empty tenant
- [ ] List roles - tenant with roles
- [ ] Create role - valid data
- [ ] Create role - duplicate name (should fail)
- [ ] Update role - change name
- [ ] Delete role - with assigned users (should SET NULL)
- [ ] Assign role to user
- [ ] Remove role from user

### 8.3 UI Tests

- [ ] "Tipo Utente" label displays correctly
- [ ] "Ruolo Aziendale" dropdown shows when applicable
- [ ] Manager can create user with role
- [ ] Role badges display with correct colors
- [ ] Role management in company edit

### 8.4 Authorization Tests

- [ ] Admin can manage roles for own tenant
- [ ] Manager can create users with roles
- [ ] Manager cannot create admin/manager users
- [ ] Super admin can manage all tenants
- [ ] User cannot access role management

---

## 9. Rollback Plan

### 9.1 Rollback SQL

```sql
-- ROLLBACK: Migration 19

-- Remove FK from user_tenant_access
ALTER TABLE user_tenant_access DROP FOREIGN KEY fk_user_tenant_access_role;

-- Remove column from user_tenant_access
ALTER TABLE user_tenant_access DROP COLUMN tenant_role_id;

-- Remove column from tenants
ALTER TABLE tenants DROP COLUMN has_custom_roles;

-- Drop tenant_roles table
DROP TABLE IF EXISTS tenant_roles;

SELECT 'Rollback 19 completed' as status;
```

### 9.2 Code Rollback

1. Revert all modified PHP files
2. Remove new API endpoints
3. Remove new JS files
4. Revert UI label changes

---

## 10. CLAUDE.md Updates

Add to CLAUDE.md after implementation:

```markdown
### Tenant Roles System (Ruoli Aziendali)

**Tables:**
- `tenant_roles` - Custom business roles per tenant
- `user_tenant_access.tenant_role_id` - FK to assigned tenant role
- `tenants.has_custom_roles` - Flag indicating if tenant uses custom roles

**Terminology:**
- `users.role` = "Tipo Utente" (User Type) - System permissions
- `tenant_role` = "Ruolo Aziendale" (Tenant Role) - Business role within company

**API Endpoints:**
- `GET /api/tenant-roles/list.php?tenant_id={id}` - List roles
- `POST /api/tenant-roles/create.php` - Create role
- `POST /api/tenant-roles/update.php` - Update role
- `POST /api/tenant-roles/delete.php` - Delete role

**Manager User Creation Rules:**
1. Managers can only create users with `role='user'`
2. `tenant_id` auto-filled from manager's session
3. `tenant_role_id` required if tenant has custom roles

**Key Pattern:**
```php
// Check if tenant has custom roles
$tenant = $db->fetchOne('SELECT has_custom_roles FROM tenants WHERE id = ?', [$tenantId]);
if ($tenant['has_custom_roles'] && empty($data['tenant_role_id'])) {
    api_error('Ruolo aziendale obbligatorio per questa azienda', 400);
}
```
```

---

## 11. Open Questions

1. **Should managers be able to create custom roles?**
   - Current proposal: Only admin/super_admin
   - Alternative: Managers can also create roles

2. **Should roles have permissions attached?**
   - Current proposal: Roles are labels only
   - Alternative: Roles could have associated permissions

3. **Migration of existing users?**
   - Current proposal: `tenant_role_id` is nullable, existing users have NULL
   - Alternative: Create default "Generale" role and assign to all

4. **Audit logging for role changes?**
   - Current proposal: Standard audit_log integration
   - Details TBD

---

## 12. Summary

This architecture provides a clean, extensible solution for tenant-specific business roles while:

1. **Preserving backward compatibility** - No breaking changes to existing schema
2. **Maintaining multi-tenant isolation** - Roles are tenant-specific
3. **Following existing patterns** - Soft delete, audit fields, FK cascade
4. **Enabling future enhancements** - Role permissions could be added later
5. **Providing clear terminology** - "Tipo Utente" vs "Ruolo Aziendale"

---

**Document Status:** READY FOR REVIEW

**Next Steps:**
1. Review and approve design
2. Create migration SQL
3. Implement API layer
4. Implement UI changes
5. Test and deploy
