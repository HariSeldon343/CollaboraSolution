# Workflow Activation System - Quick Reference

## Overview

**Purpose:** Enable/disable document approval workflow per tenant or folder with inheritance.

**Migration Files:**
- `/database/migrations/workflow_activation_system.sql` (476 lines)
- `/database/migrations/workflow_activation_system_rollback.sql` (147 lines)

**Database Objects:**
- Table: `workflow_settings` (1 table)
- Function: `get_workflow_enabled_for_folder()` (MySQL helper)

---

## Table Structure

### `workflow_settings`

```sql
CREATE TABLE workflow_settings (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Scope
    tenant_id INT UNSIGNED NOT NULL,
    scope_type ENUM('tenant', 'folder') NOT NULL,
    folder_id INT UNSIGNED NULL,  -- NULL if scope='tenant'

    -- Configuration
    workflow_enabled TINYINT(1) NOT NULL DEFAULT 0,
    auto_create_workflow TINYINT(1) NOT NULL DEFAULT 1,
    require_validation TINYINT(1) NOT NULL DEFAULT 1,
    require_approval TINYINT(1) NOT NULL DEFAULT 1,
    auto_approve_on_validation TINYINT(1) NOT NULL DEFAULT 0,

    -- Inheritance
    inherit_from_parent TINYINT(1) NOT NULL DEFAULT 1,
    override_parent TINYINT(1) NOT NULL DEFAULT 0,

    -- Metadata
    settings_metadata JSON NULL,
    configured_by_user_id INT UNSIGNED NULL,
    configuration_reason TEXT NULL,

    -- Soft delete + audit
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Key Features:**
- ✅ Multi-tenant isolation (tenant_id, CASCADE delete)
- ✅ Soft delete pattern (deleted_at)
- ✅ Scope: tenant-wide OR folder-specific
- ✅ Inheritance: folder → parent folders → tenant → default (0)
- ✅ Audit trail (configured_by_user_id, configuration_reason)
- ✅ JSON metadata for future extensions

---

## Helper Function

### `get_workflow_enabled_for_folder(tenant_id, folder_id)`

Returns `1` (enabled) or `0` (disabled) with inheritance resolution.

**Inheritance Logic:**
1. Check folder-specific setting (explicit)
2. Walk up parent folders (recursive, max depth 10)
3. Check tenant-wide setting (fallback)
4. Return 0 (default disabled)

**Usage:**
```sql
-- Check if workflow enabled for file in folder 42
SELECT get_workflow_enabled_for_folder(1, 42) as workflow_enabled;
-- Returns: 1 (enabled) or 0 (disabled)
```

---

## Common Operations

### 1. Enable Workflow for Entire Tenant

**Use Case:** Company-wide workflow for all documents.

```sql
INSERT INTO workflow_settings (
    tenant_id,
    scope_type,
    folder_id,
    workflow_enabled,
    auto_create_workflow,
    require_validation,
    require_approval,
    configured_by_user_id,
    configuration_reason
) VALUES (
    1,          -- tenant_id
    'tenant',   -- scope_type
    NULL,       -- folder_id (tenant-wide)
    1,          -- workflow_enabled
    1,          -- auto_create_workflow
    1,          -- require_validation
    1,          -- require_approval
    5,          -- configured_by_user_id (manager)
    'Enable workflow for all company documents per compliance requirements'
)
ON DUPLICATE KEY UPDATE
    workflow_enabled = 1,
    configured_by_user_id = 5,
    configuration_reason = 'Enable workflow for all company documents per compliance requirements',
    updated_at = CURRENT_TIMESTAMP;
```

---

### 2. Enable Workflow for Specific Folder

**Use Case:** Workflow ONLY for "Contratti" folder (not rest of tenant).

```sql
INSERT INTO workflow_settings (
    tenant_id,
    scope_type,
    folder_id,
    workflow_enabled,
    auto_create_workflow,
    require_validation,
    require_approval,
    override_parent,
    configured_by_user_id,
    configuration_reason
) VALUES (
    1,          -- tenant_id
    'folder',   -- scope_type
    42,         -- folder_id (Contratti folder)
    1,          -- workflow_enabled
    1,          -- auto_create_workflow
    1,          -- require_validation
    1,          -- require_approval
    1,          -- override_parent (explicit override)
    5,          -- configured_by_user_id
    'Enable workflow for Contratti folder - legal compliance'
)
ON DUPLICATE KEY UPDATE
    workflow_enabled = 1,
    override_parent = 1,
    configured_by_user_id = 5,
    configuration_reason = 'Enable workflow for Contratti folder - legal compliance',
    updated_at = CURRENT_TIMESTAMP;
```

---

### 3. Disable Workflow for Tenant

**Use Case:** Disable workflow for all tenant documents.

```sql
UPDATE workflow_settings
SET workflow_enabled = 0,
    configured_by_user_id = 5,
    configuration_reason = 'Disable workflow temporarily for system migration',
    updated_at = CURRENT_TIMESTAMP
WHERE tenant_id = 1
  AND scope_type = 'tenant'
  AND deleted_at IS NULL;
```

---

### 4. Remove Folder Configuration (Soft Delete)

**Use Case:** Remove folder override, inherit from parent/tenant again.

```sql
-- Method A: Soft delete (preserves audit trail)
UPDATE workflow_settings
SET deleted_at = CURRENT_TIMESTAMP
WHERE tenant_id = 1
  AND scope_type = 'folder'
  AND folder_id = 42
  AND deleted_at IS NULL;

-- Method B: Disable without deleting (keeps record active but disabled)
UPDATE workflow_settings
SET workflow_enabled = 0,
    configured_by_user_id = 5,
    configuration_reason = 'Disable workflow for Contratti folder',
    updated_at = CURRENT_TIMESTAMP
WHERE tenant_id = 1
  AND scope_type = 'folder'
  AND folder_id = 42
  AND deleted_at IS NULL;
```

---

### 5. Check Workflow Status for File

**Use Case:** Before file upload, check if workflow required.

```sql
-- Get workflow status for file in folder 42
SELECT
    f.id,
    f.name,
    f.folder_id,
    fo.name as folder_name,
    get_workflow_enabled_for_folder(f.tenant_id, f.folder_id) as workflow_enabled,
    CASE
        WHEN get_workflow_enabled_for_folder(f.tenant_id, f.folder_id) = 1
        THEN 'Workflow required - will create in bozza state'
        ELSE 'No workflow - file immediately available'
    END as status_message
FROM files f
INNER JOIN folders fo ON f.folder_id = fo.id
WHERE f.id = 123
  AND f.tenant_id = 1
  AND f.deleted_at IS NULL;
```

---

## PHP Integration Examples

### Check Workflow Enabled (API Upload Endpoint)

```php
<?php
// File: /api/files/upload.php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/audit_helper.php';

$db = Database::getInstance();
$tenantId = $_SESSION['tenant_id'];
$folderId = $_POST['folder_id'] ?? null;
$userId = $_SESSION['user_id'];

// Step 1: Check if workflow enabled for target folder
$workflowEnabled = 0;
if ($folderId) {
    $sql = "SELECT get_workflow_enabled_for_folder(?, ?) as workflow_enabled";
    $result = $db->fetchOne($sql, [$tenantId, $folderId]);
    $workflowEnabled = $result['workflow_enabled'] ?? 0;
}

// Step 2: Upload file
$fileId = uploadFile($_FILES['file'], $tenantId, $folderId, $userId);

// Step 3: If workflow enabled, create document_workflow record
if ($workflowEnabled == 1) {
    $db->beginTransaction();

    try {
        $db->insert('document_workflow', [
            'tenant_id' => $tenantId,
            'file_id' => $fileId,
            'current_state' => 'bozza',
            'created_by_user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Audit log
        AuditLogger::logCreate($userId, $tenantId, 'document_workflow', $fileId,
            'Document uploaded in bozza state (workflow enabled)',
            ['state' => 'bozza', 'workflow_enabled' => true]
        );

        if (!$db->commit()) {
            throw new Exception('Commit failed');
        }

        api_success([
            'file_id' => $fileId,
            'workflow_enabled' => true,
            'current_state' => 'bozza',
            'message' => 'File uploaded - workflow enabled, document in bozza state'
        ]);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log("[WORKFLOW] Failed to create workflow: " . $e->getMessage());
        api_error('Errore creazione workflow', 500);
    }
} else {
    // No workflow - file immediately available
    api_success([
        'file_id' => $fileId,
        'workflow_enabled' => false,
        'message' => 'File uploaded - no workflow required'
    ]);
}
?>
```

---

### Enable Workflow Configuration (Manager API)

```php
<?php
// File: /api/workflow/settings/update.php

require_once __DIR__ . '/../../../includes/api_auth.php';

initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

// Authorization: Only Manager or Super Admin
if (!in_array($userInfo['role'], ['manager', 'admin', 'super_admin'])) {
    api_error('Non autorizzato - solo Manager o Admin', 403);
}

$db = Database::getInstance();
$tenantId = $userInfo['tenant_id'];
$userId = $userInfo['user_id'];

// Parse request
$data = json_decode(file_get_contents('php://input'), true);
$scopeType = $data['scope_type'] ?? null;  // 'tenant' or 'folder'
$folderId = $data['folder_id'] ?? null;
$workflowEnabled = $data['workflow_enabled'] ?? 0;
$reason = $data['configuration_reason'] ?? '';

// Validation
if (!in_array($scopeType, ['tenant', 'folder'])) {
    api_error('scope_type deve essere "tenant" o "folder"', 400);
}

if ($scopeType === 'folder' && !$folderId) {
    api_error('folder_id richiesto per scope_type=folder', 400);
}

if ($scopeType === 'tenant' && $folderId !== null) {
    api_error('folder_id deve essere NULL per scope_type=tenant', 400);
}

$db->beginTransaction();

try {
    // Upsert workflow_settings
    $sql = "
        INSERT INTO workflow_settings (
            tenant_id,
            scope_type,
            folder_id,
            workflow_enabled,
            auto_create_workflow,
            require_validation,
            require_approval,
            override_parent,
            configured_by_user_id,
            configuration_reason
        ) VALUES (?, ?, ?, ?, 1, 1, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            workflow_enabled = VALUES(workflow_enabled),
            auto_create_workflow = VALUES(auto_create_workflow),
            override_parent = VALUES(override_parent),
            configured_by_user_id = VALUES(configured_by_user_id),
            configuration_reason = VALUES(configuration_reason),
            updated_at = CURRENT_TIMESTAMP
    ";

    $params = [
        $tenantId,
        $scopeType,
        $folderId,
        $workflowEnabled,
        $scopeType === 'folder' ? 1 : 0,  // override_parent
        $userId,
        $reason
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Audit log
    AuditLogger::logUpdate($userId, $tenantId, 'workflow_setting', null,
        'Workflow configuration updated',
        [],
        [
            'scope_type' => $scopeType,
            'folder_id' => $folderId,
            'workflow_enabled' => $workflowEnabled,
            'reason' => $reason
        ]
    );

    if (!$db->commit()) {
        throw new Exception('Commit failed');
    }

    api_success([
        'message' => 'Configurazione workflow aggiornata',
        'scope_type' => $scopeType,
        'folder_id' => $folderId,
        'workflow_enabled' => $workflowEnabled
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    error_log("[WORKFLOW SETTINGS] Update failed: " . $e->getMessage());
    api_error('Errore aggiornamento configurazione workflow', 500);
}
?>
```

---

### Get Workflow Configuration (Read API)

```php
<?php
// File: /api/workflow/settings/get.php

require_once __DIR__ . '/../../../includes/api_auth.php';

initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

verifyApiAuthentication();
$userInfo = getApiUserInfo();

$db = Database::getInstance();
$tenantId = $userInfo['tenant_id'];
$folderId = $_GET['folder_id'] ?? null;

try {
    if ($folderId) {
        // Get effective workflow status for folder (with inheritance)
        $sql = "SELECT get_workflow_enabled_for_folder(?, ?) as workflow_enabled";
        $result = $db->fetchOne($sql, [$tenantId, $folderId]);

        // Get explicit configuration (if exists)
        $sql2 = "
            SELECT
                ws.*,
                u.name as configured_by_name,
                f.name as folder_name,
                f.path as folder_path
            FROM workflow_settings ws
            LEFT JOIN users u ON ws.configured_by_user_id = u.id
            LEFT JOIN folders f ON ws.folder_id = f.id
            WHERE ws.tenant_id = ?
              AND ws.scope_type = 'folder'
              AND ws.folder_id = ?
              AND ws.deleted_at IS NULL
        ";
        $config = $db->fetchOne($sql2, [$tenantId, $folderId]);

        api_success([
            'effective_workflow_enabled' => $result['workflow_enabled'],
            'explicit_configuration' => $config,
            'inherited' => $config === null,
            'folder_id' => $folderId
        ]);

    } else {
        // Get tenant-wide configuration
        $sql = "
            SELECT
                ws.*,
                u.name as configured_by_name
            FROM workflow_settings ws
            LEFT JOIN users u ON ws.configured_by_user_id = u.id
            WHERE ws.tenant_id = ?
              AND ws.scope_type = 'tenant'
              AND ws.deleted_at IS NULL
        ";
        $config = $db->fetchOne($sql, [$tenantId]);

        api_success([
            'tenant_configuration' => $config,
            'tenant_id' => $tenantId
        ]);
    }

} catch (Exception $e) {
    error_log("[WORKFLOW SETTINGS] Get failed: " . $e->getMessage());
    api_error('Errore lettura configurazione workflow', 500);
}
?>
```

---

## Frontend Integration Example

### JavaScript - Check Workflow Before Upload

```javascript
// File: /assets/js/filemanager_enhanced.js

class FileManager {
    async uploadFile(file, folderId) {
        try {
            // Step 1: Check if workflow enabled for target folder
            const workflowEnabled = await this.checkWorkflowEnabled(folderId);

            // Step 2: Show warning if workflow enabled
            if (workflowEnabled) {
                const confirmed = confirm(
                    'ATTENZIONE: Questo folder ha il workflow attivato.\n\n' +
                    'Il documento sarà caricato in stato "bozza" e richiederà:\n' +
                    '1. Validazione da parte di un validatore\n' +
                    '2. Approvazione da parte di un approvatore\n\n' +
                    'Continuare con il caricamento?'
                );

                if (!confirmed) {
                    return; // User cancelled
                }
            }

            // Step 3: Upload file
            const formData = new FormData();
            formData.append('file', file);
            formData.append('folder_id', folderId);

            const response = await fetch('/CollaboraNexio/api/files/upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                if (result.data.workflow_enabled) {
                    this.showToast(
                        'File caricato in bozza - richiede approvazione',
                        'info'
                    );
                } else {
                    this.showToast('File caricato con successo', 'success');
                }

                await this.loadFiles(folderId);
            } else {
                this.showToast(result.message || 'Errore upload', 'error');
            }

        } catch (error) {
            console.error('[FileManager] Upload failed:', error);
            this.showToast('Errore durante il caricamento', 'error');
        }
    }

    async checkWorkflowEnabled(folderId) {
        if (!folderId) return false;

        try {
            const response = await fetch(
                `/CollaboraNexio/api/workflow/settings/get.php?folder_id=${folderId}`,
                {
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': this.getCsrfToken()
                    }
                }
            );

            const result = await response.json();

            if (result.success) {
                return result.data.effective_workflow_enabled === 1;
            }

            return false;

        } catch (error) {
            console.error('[FileManager] Workflow check failed:', error);
            return false; // Fail-safe: assume no workflow on error
        }
    }
}
```

---

## Testing Queries

### Test Inheritance Chain

```sql
-- Create test folder hierarchy
INSERT INTO folders (tenant_id, parent_id, name, path, owner_id) VALUES
(1, NULL, 'Root', '/Root', 1),
(1, 1, 'Documenti', '/Root/Documenti', 1),
(1, 2, 'Contratti', '/Root/Documenti/Contratti', 1),
(1, 3, 'Contratti 2025', '/Root/Documenti/Contratti/2025', 1);

-- Enable workflow for "Contratti" folder only
INSERT INTO workflow_settings (
    tenant_id, scope_type, folder_id, workflow_enabled,
    configured_by_user_id, configuration_reason
) VALUES (
    1, 'folder', 3, 1, 1, 'Test inheritance'
);

-- Test: Contratti 2025 folder inherits from parent "Contratti"
SELECT
    get_workflow_enabled_for_folder(1, 4) as workflow_enabled,
    'Expected: 1 (inherits from Contratti parent)' as expected;

-- Test: Documenti folder has no workflow
SELECT
    get_workflow_enabled_for_folder(1, 2) as workflow_enabled,
    'Expected: 0 (no workflow configured)' as expected;

-- Test: Contratti folder has explicit workflow
SELECT
    get_workflow_enabled_for_folder(1, 3) as workflow_enabled,
    'Expected: 1 (explicit configuration)' as expected;
```

---

## Performance Considerations

**Caching Recommended:**
- Workflow settings change infrequently
- Cache `get_workflow_enabled_for_folder()` results for 5-10 minutes
- Clear cache on workflow_settings UPDATE/INSERT

**PHP Cache Example:**
```php
$cacheKey = "workflow_enabled_{$tenantId}_{$folderId}";
$workflowEnabled = apcu_fetch($cacheKey);

if ($workflowEnabled === false) {
    $result = $db->fetchOne(
        "SELECT get_workflow_enabled_for_folder(?, ?) as workflow_enabled",
        [$tenantId, $folderId]
    );
    $workflowEnabled = $result['workflow_enabled'] ?? 0;
    apcu_store($cacheKey, $workflowEnabled, 300); // Cache 5 minutes
}
```

---

## Migration Execution

### Apply Migration

```bash
# Navigate to project directory
cd /path/to/CollaboraNexio

# Execute migration
mysql -u root -p collaboranexio < database/migrations/workflow_activation_system.sql

# Verify
mysql -u root -p collaboranexio -e "SELECT * FROM workflow_settings; SHOW FUNCTION STATUS WHERE Name = 'get_workflow_enabled_for_folder';"
```

### Rollback Migration

```bash
# Execute rollback
mysql -u root -p collaboranexio < database/migrations/workflow_activation_system_rollback.sql

# Verify
mysql -u root -p collaboranexio -e "SHOW TABLES LIKE 'workflow_settings'; SHOW TABLES LIKE 'workflow_settings_backup_%';"
```

---

## Summary

**What This Schema Provides:**

✅ **Granular Control:** Enable/disable workflow per tenant OR per folder
✅ **Inheritance:** Folders inherit from parents → tenant → default (0)
✅ **Flexibility:** JSON metadata for future extensions
✅ **Audit Trail:** Tracks who, when, why configuration changed
✅ **Performance:** MySQL helper function + indexes
✅ **Soft Delete:** Preserves configuration history
✅ **Multi-Tenant:** 100% tenant isolation
✅ **Production Ready:** Follows all CollaboraNexio patterns

**Next Steps:**

1. ✅ Execute migration SQL
2. ⬜ Create API endpoints (`/api/workflow/settings/`)
3. ⬜ Update file upload logic to check workflow status
4. ⬜ Add frontend UI for workflow configuration (Manager only)
5. ⬜ Add workflow badges on file list (bozza, in_validazione, etc.)
6. ⬜ Update documentation (CLAUDE.md)

---

**Last Updated:** 2025-11-02
**Database Architect:** CollaboraNexio Team
**Migration Version:** 1.0.0
